import paramiko, os, pathlib, sys

c = paramiko.SSHClient()
c.load_system_host_keys()
key = pathlib.Path(os.environ.get('HEZUOSHANG_SSH_KEY', str(pathlib.Path.home()/'.ssh/id_ed25519_tfdev')))
c.connect('58.58.97.174', port=5024, username='root', key_filename=str(key))

if len(sys.argv) > 1 and os.path.exists(sys.argv[1]):
    with open(sys.argv[1], 'r', encoding='utf-8') as f:
        php_code = f.read()
elif len(sys.argv) > 1:
    php_code = sys.argv[1]
elif os.path.exists('tools/remote_query.php'):
    with open('tools/remote_query.php', 'r', encoding='utf-8') as f:
        php_code = f.read()
else:
    php_code = 'echo "hello";'

cmd = "docker exec -i hezuoshang-php php << 'EOF'\n" + php_code + "\nEOF\n"
_, out, err = c.exec_command(cmd)
raw_out = out.read()
raw_err = err.read()
sys.stdout.buffer.write(raw_out)
sys.stderr.buffer.write(raw_err)
c.close()
