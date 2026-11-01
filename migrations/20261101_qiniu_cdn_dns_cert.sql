-- 记录每个七牛域名当前绑定的 HTTPS 证书编号（列表页用来显示证书剩余天数；七牛列表接口不返回证书）。
ALTER TABLE qiniu_cdn_dns ADD COLUMN cert_id VARCHAR(64) NOT NULL DEFAULT '';
