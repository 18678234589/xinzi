-- 订单域名：业务订单与域名的对应关系（自动来源由 jobs/order_domains_sync.php 重建，registration / manual 永久保留）。
CREATE TABLE IF NOT EXISTS bizcn_order_domains (
 order_id BIGINT UNSIGNED NOT NULL,
 domainname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source VARCHAR(12) NOT NULL DEFAULT 'renewal',
 expires_on DATE NULL,
 ns_hosts VARCHAR(300) NOT NULL DEFAULT '',
 ns_provider VARCHAR(12) NOT NULL DEFAULT '',
 ns_checked_at BIGINT NOT NULL DEFAULT 0,
 created_by VARCHAR(40) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (order_id,domainname),
 KEY idx_order_domain_name (domainname),
 KEY idx_order_domain_ns (ns_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
