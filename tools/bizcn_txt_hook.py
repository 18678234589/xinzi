# -*- coding: utf-8 -*-
"""Bizcn DNS TXT hook for acme.sh DNS-01 verification."""
import sys
import paramiko

def split_domain(fulldomain):
    labels = fulldomain.rstrip(".").split(".")
    # For derczn.com, 2 labels for zone
    zone = ".".join(labels[-2:])
    sub = ".".join(labels[:-2]) if len(labels) > 2 else "@"
    return zone, sub

def main():
    if len(sys.argv) < 4:
        print("Usage: bizcn_txt_hook.py <add|del> <fulldomain> <txtvalue>", file=sys.stderr)
        sys.exit(1)

    action = sys.argv[1].lower()
    fulldomain = sys.argv[2]
    txtvalue = sys.argv[3]

    zone, sub = split_domain(fulldomain)
    print(f"[bizcn_txt_hook] action={action} zone={zone} sub={sub} val_prefix={txtvalue[:10]}...")

    k = paramiko.Ed25519Key.from_private_key_file(r'C:\Users\Administrator\.ssh\id_ed25519_tfdev')
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect('58.58.97.174', port=5024, username='root', pkey=k, timeout=15)

    if action == "add":
        php_code = f"""<?php
require '/myprograms/hezuoshang/includes/BizcnDomains.php';
$r = bd_call('addDnsRecordService', 'addDnsRecord', [
    'add' => [
        'domainname' => {repr(zone)},
        'resolvetype' => 'TXT',
        'resolvehost' => {repr(sub)},
        'resolvevalue' => {repr(txtvalue)},
        'mxlevel' => '10'
    ]
]);
echo json_encode($r);
"""
    elif action == "del":
        php_code = f"""<?php
require '/myprograms/hezuoshang/includes/BizcnDomains.php';
$r = bd_call('delDnsRecordService', 'delDnsRecord', [
    'del' => [
        'domainname' => {repr(zone)},
        'resolvetype' => 'TXT',
        'resolvehost' => {repr(sub)},
        'resolveoldvalue' => {repr(txtvalue)},
        'mxlevel' => '10'
    ]
]);
echo json_encode($r);
"""
    else:
        print(f"Unknown action: {action}", file=sys.stderr)
        c.close()
        sys.exit(1)

    stdin, stdout, stderr = c.exec_command("docker exec -i hezuoshang-php php", timeout=30)
    stdin.write(php_code)
    stdin.channel.shutdown_write()
    out = stdout.read().decode().strip()
    err = stderr.read().decode().strip()
    c.close()

    print(f"[bizcn_txt_hook] response: {out}")
    if err:
        print(f"[bizcn_txt_hook] stderr: {err}", file=sys.stderr)

    if "Command completed successfully" in out or "200" in out:
        sys.exit(0)
    else:
        print(f"[bizcn_txt_hook] failed", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
