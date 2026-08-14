# -*- coding: utf-8 -*-
"""HTTP 上传：把月度汇总CSV发送到服务器 kefu/upload.php"""
import os
import urllib.request


def upload_file(cfg, file_path):
    """上传文件，返回服务器返回的文本。抛异常表示失败。"""
    url = cfg.get('server', 'url', fallback='')
    token = cfg.get('server', 'token', fallback='')
    if not url:
        raise RuntimeError('config.ini 未配置 server.url')

    boundary = '----CSPerf' + os.urandom(8).hex()
    fname = os.path.basename(file_path)

    with open(file_path, 'rb') as f:
        body = f.read()

    parts = []
    # token 字段
    parts.append(('--' + boundary).encode())
    parts.append(b'Content-Disposition: form-data; name="token"\r\n\r\n')
    parts.append(token.encode('utf-8'))
    parts.append(b'\r\n')
    # 文件字段
    parts.append(('--' + boundary).encode())
    parts.append(('Content-Disposition: form-data; name="file"; filename="%s"\r\n' % fname).encode())
    parts.append(b'Content-Type: text/csv\r\n\r\n')
    parts.append(body)
    parts.append(b'\r\n')
    parts.append(('--' + boundary + '--').encode())
    parts.append(b'\r\n')

    data = b''.join(parts)
    req = urllib.request.Request(url, data=data, method='POST')
    req.add_header('Content-Type', 'multipart/form-data; boundary=%s' % boundary)
    req.add_header('User-Agent', 'cs-perf-waiter/1.0')

    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.read().decode('utf-8', 'replace')
