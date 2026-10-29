# -*- coding: utf-8 -*-
"""Upload Let's Encrypt wildcard cert to Qiniu and enable HTTPS for derczn.com domains."""
import os, sys, json, pathlib

REPO_ROOT = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO_ROOT / "api对接"))

# Patch qiniu_cdn credentials path before import if needed
credential_file = REPO_ROOT / "api对接" / "七牛云httpsportal.qiniu.comcdn.txt"
os.chdir(str(REPO_ROOT / "api对接"))

import qiniu_cdn
from qiniu_cdn import upload_cert, sslize, list_certs, get_domain

KEY_FILE = REPO_ROOT / "tools" / "acme_config" / "derczn.com_ecc" / "derczn.com.key"
FULLCHAIN_FILE = REPO_ROOT / "tools" / "acme_config" / "derczn.com_ecc" / "fullchain.cer"

pri_pem = KEY_FILE.read_text(encoding="utf-8")
ca_pem = FULLCHAIN_FILE.read_text(encoding="utf-8")

CERT_NAME = "derczn-wildcard-2026-10"
print(f"== Uploading cert '{CERT_NAME}' to Qiniu Fusion ==")
status, body = upload_cert(CERT_NAME, pri_pem, ca_pem)
print("Upload status:", status, "body:", body[:300])

cert_id = ""
try:
    d = json.loads(body)
    if isinstance(d, dict):
        cert_id = d.get("certId") or d.get("certID") or ""
        if not cert_id and isinstance(d.get("data"), dict):
            cert_id = d["data"].get("certId") or d["data"].get("certID") or ""
        elif not cert_id and isinstance(d.get("data"), str):
            cert_id = d.get("data")
except Exception as e:
    print("JSON parse exception:", e)

if not cert_id:
    # Query cert list to locate cert_id by name
    print("Finding certId in list_certs...")
    s2, b2 = list_certs(50)
    try:
        cl = json.loads(b2)
        items = cl.get("certs") or cl.get("items") or cl.get("data") or []
        for c in items:
            if c.get("name") == CERT_NAME:
                cert_id = c.get("cert_id") or c.get("certId") or c.get("certID")
                print("Found certId:", cert_id)
                break
    except Exception as e:
        print("list_certs parse error:", e)

if not cert_id:
    print("ERROR: Failed to retrieve certId!", file=sys.stderr)
    sys.exit(1)

print("SUCCESS: Retrieved certId:", cert_id)

DOMAINS = ["www.derczn.com", "derczn.com", "cdn.derczn.com"]
for dname in DOMAINS:
    print(f"\n== Enabling HTTPS for {dname} ==")
    # forceHttps=False so both HTTP and HTTPS work smoothly
    s3, b3 = sslize(dname, cert_id, forceHttps=False, http2Enable=True)
    print(f"sslize {dname} status: {s3}, body: {b3[:300]}")

print("\n== Checking domains state on Qiniu ==")
for dname in DOMAINS:
    s4, b4 = get_domain(dname)
    try:
        dj = json.loads(b4)
        print(f"{dname}: operatingState={dj.get('operatingState')}, protocol={dj.get('protocol')}, https={dj.get('https')}")
    except Exception:
        print(f"{dname}: status={s4}")
