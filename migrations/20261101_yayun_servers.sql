-- 雅云服务器管理：商品价格 / 库存缓存（后台同步）与开通订单记录。
CREATE TABLE IF NOT EXISTS yayun_prices (
 pid INT UNSIGNED NOT NULL PRIMARY KEY,
 cycles_json TEXT NOT NULL,
 stock INT NULL,
 stock_control TINYINT NOT NULL DEFAULT 0,
 from_price DECIMAL(12,2) NULL,
 updated_at BIGINT NOT NULL,
 error VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS yayun_orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 token_hash CHAR(64) NOT NULL UNIQUE,
 actor_key VARCHAR(60) NOT NULL,
 pid INT UNSIGNED NOT NULL,
 cycle VARCHAR(20) NOT NULL,
 quote_json TEXT NOT NULL,
 amount_cents INT UNSIGNED NOT NULL,
 status VARCHAR(10) NOT NULL DEFAULT 'quoted',
 message VARCHAR(300) NOT NULL DEFAULT '',
 upstream_host_id BIGINT UNSIGNED NULL,
 invoice_id BIGINT UNSIGNED NULL,
 created_at BIGINT NOT NULL,
 started_at BIGINT NULL,
 finished_at BIGINT NULL,
 KEY idx_yayun_orders_status (status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
