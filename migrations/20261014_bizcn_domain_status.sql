-- 域名状态栏目：后台任务逐个域名查询商务中国后保存的实名 / 命名审核状态（只读数据，可随时重建）。
CREATE TABLE IF NOT EXISTS bizcn_domain_status (
 provider_account VARCHAR(100) NOT NULL,
 domainname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 audit_status VARCHAR(20) NOT NULL DEFAULT '',
 audit_code VARCHAR(20) NOT NULL DEFAULT '',
 audit_reason VARCHAR(300) NOT NULL DEFAULT '',
 dnvc_status VARCHAR(6) NOT NULL DEFAULT '',
 whois_status VARCHAR(20) NOT NULL DEFAULT '',
 status_text VARCHAR(200) NOT NULL DEFAULT '',
 expire_date DATE NULL,
 checked_at BIGINT NOT NULL,
 error VARCHAR(200) NOT NULL DEFAULT '',
 PRIMARY KEY (provider_account,domainname),
 KEY idx_bizcn_status_audit (provider_account,audit_status),
 KEY idx_bizcn_status_checked (provider_account,checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
