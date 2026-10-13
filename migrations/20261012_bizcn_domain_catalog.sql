CREATE TABLE IF NOT EXISTS bizcn_domain_imports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider_account VARCHAR(100) NOT NULL,
 file_sha256 CHAR(64) NOT NULL,
 source_name VARCHAR(255) NOT NULL,
 source_as_of DATE NOT NULL,
 rows_total INT UNSIGNED NOT NULL,
 created_count INT UNSIGNED NOT NULL DEFAULT 0,
 updated_count INT UNSIGNED NOT NULL DEFAULT 0,
 unchanged_count INT UNSIGNED NOT NULL DEFAULT 0,
 actor_admin_id INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uk_bizcn_import (provider_account,file_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bizcn_domain_catalog (
 provider_account VARCHAR(100) NOT NULL,
 domainname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 display_name VARCHAR(500) NOT NULL DEFAULT '',
 provider_domain_id VARCHAR(40) NOT NULL DEFAULT '',
 registrar VARCHAR(100) NOT NULL DEFAULT '',
 owner_username VARCHAR(100) NOT NULL DEFAULT '',
 regdate DATE NULL,
 expireddate DATE NULL,
 status VARCHAR(200) NOT NULL DEFAULT '',
 source_as_of DATE NOT NULL,
 import_id BIGINT UNSIGNED NOT NULL,
 source_row INT UNSIGNED NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (provider_account,domainname),
 KEY idx_bizcn_expiry (provider_account,expireddate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bizcn_domain_import_items (
 import_id BIGINT UNSIGNED NOT NULL,
 domainname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 before_json MEDIUMTEXT NULL,
 after_json MEDIUMTEXT NOT NULL,
 PRIMARY KEY (import_id,domainname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
