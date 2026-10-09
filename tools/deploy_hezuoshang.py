#!/usr/bin/env python3
"""Deploy selected application files to the migrated host, with optimistic locks."""
import argparse
import hashlib
import json
import os
import pathlib
import sys
import tempfile
import time
import paramiko

HOST, PORT, ROOT = '58.58.97.174', 5024, '/myprograms/hezuoshang'
DENIED = {'config', 'storage', 'tests', 'tools', 'ops', '.git', '.deploy'}


def relative(value):
    p = pathlib.PurePosixPath(value.replace('\\', '/'))
    if p.is_absolute() or '..' in p.parts or not p.parts or p.parts[0] in DENIED:
        raise ValueError('Protected or invalid application path: ' + value)
    if any(part.startswith('.') for part in p.parts) or p.suffix not in {'.php', '.js', '.css'}:
        raise ValueError('Only application PHP, JS and CSS files may be deployed')
    return str(p)


def remote(client, code):
    stdin, stdout, stderr = client.exec_command('python3 -', timeout=300)
    stdin.write(code.encode()); stdin.flush(); stdin.channel.shutdown_write()
    out, err = stdout.read(), stderr.read()
    if stdout.channel.recv_exit_status():
        raise RuntimeError(err.decode(errors='replace')[-3000:])
    return json.loads(out)


def guard(client):
    result = remote(client, '''import pathlib,socket,json
assert socket.gethostname()=='vps4', 'Wrong deployment host'
assert pathlib.Path('/myprograms/hezuoshang/project/import.php').is_file()
assert pathlib.Path('/root/hezuoshang-migration-20261008/cutover/complete').exists()
print(json.dumps({'host':socket.gethostname(),'root':'/myprograms/hezuoshang'}))
''')
    return result


def hashes(client, paths):
    return remote(client, 'import pathlib,hashlib,json\npaths=' + repr(paths) + '''
root=pathlib.Path('/myprograms/hezuoshang')
print(json.dumps({p:hashlib.sha256((root/p).read_bytes()).hexdigest() if (root/p).is_file() else None for p in paths}))
''')


def smoke(client):
    return remote(client, '''import urllib.request,urllib.error,json
class Stop(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args):return None
opener=urllib.request.build_opener(Stop);result={}
for path,expected in [('/login.php',200),('/project/index.php',302),('/studio/',200),('/config/database.php',403)]:
 try:r=opener.open(urllib.request.Request('http://10.99.0.4'+path,headers={'Host':'me.laibangwo.com'}),timeout=20)
 except urllib.error.HTTPError as e:r=e
 body=r.read();assert r.status==expected,(path,r.status)
 assert not any(x in body for x in [b'Fatal error',b'Parse error'])
 result[path]=r.status
print(json.dumps(result))
''')


def push(client, args):
    stage = pathlib.Path(args.directory).resolve()
    manifest = json.loads(pathlib.Path(args.manifest).read_text(encoding='utf8'))
    files = {relative(p.relative_to(stage).as_posix()): p for p in stage.rglob('*') if p.is_file() and p.name != 'manifest.json'}
    if not files:
        raise ValueError('No files in staging directory')
    if any(p not in manifest for p in files):
        raise ValueError('Every deployed path needs a fetch baseline, including new paths')
    baseline = {p: manifest[p] for p in files}
    if hashes(client, list(files)) != baseline:
        raise RuntimeError('Remote files changed since fetch; fetch and reconcile again')
    deployment = time.strftime('%Y%m%d_%H%M%S', time.gmtime()) + '_' + os.urandom(3).hex()
    remote_stage = '/root/hezuoshang-deploy-stage/' + deployment
    remote(client, 'import pathlib,json\np=pathlib.Path(' + repr(remote_stage) + ");p.mkdir(parents=True,mode=0o700);print(json.dumps({'staging_ready':True}))")
    sftp = client.open_sftp()
    for path, local in files.items():
        dest = remote_stage + '/' + path
        remote(client, 'import pathlib,json\np=pathlib.Path(' + repr(dest) + ");p.parent.mkdir(parents=True,exist_ok=True);print(json.dumps({'parent_ready':True}))")
        sftp.put(str(local), dest); sftp.chmod(dest, 0o600)
    sftp.close()
    digests = {p: hashlib.sha256(local.read_bytes()).hexdigest() for p, local in files.items()}
    result = remote(client, 'import pathlib,subprocess,json,hashlib\nstage=pathlib.Path(' + repr(remote_stage) + ')\nfiles=' + repr(digests) + '''
for path,h in files.items():
 p=stage/path;assert hashlib.sha256(p.read_bytes()).hexdigest()==h
 if p.suffix=='.php':
  r=subprocess.run(['docker','exec','-i','hezuoshang-php','php','-l'],input=p.read_bytes(),capture_output=True)
  assert r.returncode==0,(path,r.stderr.decode(errors='replace'))
print(json.dumps({'files_checked':len(files),'php_syntax_ok':True}))
''')
    if args.dry_run:
        return {'dry_run': True, **result}
    result = remote(client, 'import pathlib,json,hashlib,os,shutil,subprocess\nfiles=' + repr(digests) + '\nbaseline=' + repr(baseline) + '\nstage=pathlib.Path(' + repr(remote_stage) + ')\nbackup=pathlib.Path(' + repr('/root/hezuoshang-deploy-backups/' + deployment) + ''')
root=pathlib.Path('/myprograms/hezuoshang')
for path,h in baseline.items():
 p=root/path;assert (hashlib.sha256(p.read_bytes()).hexdigest() if p.is_file() else None)==h, 'Concurrent change: '+path
backup.mkdir(parents=True,mode=0o700)
for path,h in baseline.items():
 if h is not None:
  p=backup/path;p.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(root/path,p)
(backup/'manifest.json').write_text(json.dumps({'before':baseline,'after':files}))
written=[]
try:
 for path,h in files.items():
  dest=root/path;dest.parent.mkdir(parents=True,exist_ok=True);assert not dest.is_symlink()
  temp=dest.with_name(dest.name+'.deploy-new');shutil.copy2(stage/path,temp);os.chmod(temp,0o644);os.chown(temp,0,33);os.replace(temp,dest);written.append(path)
  assert hashlib.sha256(dest.read_bytes()).hexdigest()==h
except Exception:
 for path in reversed(written):
  if baseline[path] is None:(root/path).unlink()
  else:shutil.copy2(backup/path,root/path)
 raise
subprocess.run(['docker','exec','hezuoshang-php','kill','-USR2','1'],check=True)
print(json.dumps({'deployed':len(files),'backup':str(backup)}))
''')
    return {**result, 'smoke': smoke(client)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='action', required=True)
    commands.add_parser('check'); commands.add_parser('smoke')
    rollback = commands.add_parser('rollback'); rollback.add_argument('backup')
    fetch = commands.add_parser('fetch'); fetch.add_argument('paths', nargs='+'); fetch.add_argument('--out')
    deploy = commands.add_parser('push'); deploy.add_argument('directory'); deploy.add_argument('--manifest', required=True); deploy.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()
    client = paramiko.SSHClient(); client.load_system_host_keys()
    key = pathlib.Path(os.environ.get('HEZUOSHANG_SSH_KEY', str(pathlib.Path.home()/'.ssh/id_ed25519_tfdev')))
    client.connect(HOST, port=PORT, username='root', key_filename=str(key), look_for_keys=False, allow_agent=False, timeout=25)
    try:
        result = guard(client)
        if args.action == 'fetch':
            paths = [relative(p) for p in args.paths]
            before = hashes(client, paths)
            output = pathlib.Path(args.out or tempfile.mkdtemp(prefix='hezuoshang-fetch-')).resolve(); output.mkdir(parents=True, exist_ok=True)
            sftp = client.open_sftp()
            for path,h in before.items():
                if h is None: continue
                dest = output/path; dest.parent.mkdir(parents=True,exist_ok=True); sftp.get(ROOT+'/'+path,str(dest))
                if hashlib.sha256(dest.read_bytes()).hexdigest()!=h: raise RuntimeError('Changed during fetch: '+path)
            sftp.close(); (output/'manifest.json').write_text(json.dumps(before,indent=2),encoding='utf8')
            result = {'output':str(output),'manifest':str(output/'manifest.json'),'files':len(paths)}
        elif args.action == 'push': result = push(client,args)
        elif args.action == 'rollback':
            name = pathlib.PurePosixPath(args.backup).name
            if not name or any(c not in '0123456789abcdef_' for c in name):
                raise ValueError('Invalid deployment backup name')
            result = remote(client, 'import pathlib,json,hashlib,shutil,subprocess\nbackup=pathlib.Path(' + repr('/root/hezuoshang-deploy-backups/'+name) + ''')
root=pathlib.Path('/myprograms/hezuoshang');m=json.loads((backup/'manifest.json').read_text())
for path,h in m['after'].items():
 p=root/path;assert p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest()==h, 'Later change: '+path
for path,h in m['before'].items():
 if h is None:(root/path).unlink()
 else:shutil.copy2(backup/path,root/path)
subprocess.run(['docker','exec','hezuoshang-php','kill','-USR2','1'],check=True)
print(json.dumps({'rolled_back':len(m['before'])}))
''')
            result['smoke'] = smoke(client)
        else: result = {**result,'smoke':smoke(client)}
        print(json.dumps(result,ensure_ascii=False))
    finally: client.close()


if __name__ == '__main__':
    try: main()
    except Exception as error:
        print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
