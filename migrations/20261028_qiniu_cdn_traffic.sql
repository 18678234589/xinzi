-- 七牛 CDN 每日流量（字节）与峰值带宽（bit/s），由 jobs/qiniu_cdn_traffic_sync.php 拉取，页面只读本表。
CREATE TABLE IF NOT EXISTS qiniu_cdn_traffic (
 domainname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 day DATE NOT NULL,
 flux_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 peak_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
 updated_at BIGINT NOT NULL,
 PRIMARY KEY (domainname,day),
 KEY idx_qiniu_traffic_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
