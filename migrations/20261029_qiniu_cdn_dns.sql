-- 七牛 CDN 域名当前的公网解析（是否已 CNAME 到七牛），由 jobs/qiniu_cdn_traffic_sync.php 顺带检查。
CREATE TABLE IF NOT EXISTS qiniu_cdn_dns (
 domainname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 expected_cname VARCHAR(255) NOT NULL DEFAULT '',
 resolved VARCHAR(255) NOT NULL DEFAULT '',
 state VARCHAR(10) NOT NULL DEFAULT '',
 checked_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
