-- 七牛域名自动申请 HTTPS 证书的任务队列（添加域名后自动入队，也可在页面手动申请）。
CREATE TABLE IF NOT EXISTS qiniu_https_jobs (
 domainname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 state VARCHAR(10) NOT NULL DEFAULT 'pending',
 message VARCHAR(300) NOT NULL DEFAULT '',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 requested_by VARCHAR(40) NOT NULL DEFAULT '',
 requested_at BIGINT NOT NULL,
 next_try_at BIGINT NOT NULL DEFAULT 0,
 updated_at BIGINT NOT NULL,
 KEY idx_qiniu_https_state (state,next_try_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
