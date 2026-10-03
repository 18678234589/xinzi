-- 知识库独立建表，不改变订单、成本或结算数据。
CREATE TABLE IF NOT EXISTS project_kb_super_admins (
 admin_id INT NOT NULL PRIMARY KEY,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO project_kb_super_admins (admin_id) SELECT id FROM admins WHERE username='admin';

CREATE TABLE IF NOT EXISTS project_kb_editors (
 employee_id INT NOT NULL PRIMARY KEY,
 assigned_by INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_kb_articles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(200) NOT NULL,
 body MEDIUMTEXT NOT NULL,
 category VARCHAR(60) NOT NULL DEFAULT '经验与方法',
 tags VARCHAR(255) NOT NULL DEFAULT '',
 visibility ENUM('all','department','private') NOT NULL DEFAULT 'private',
 department VARCHAR(100) NOT NULL DEFAULT '',
 status ENUM('draft','published') NOT NULL DEFAULT 'draft',
 owner_type VARCHAR(20) NOT NULL,
 owner_id INT NOT NULL,
 revision INT NOT NULL DEFAULT 1,
 source_provider VARCHAR(20) DEFAULT NULL,
 source_key VARCHAR(190) DEFAULT NULL,
 source_url VARCHAR(1000) NOT NULL DEFAULT '',
 source_hash CHAR(64) NOT NULL DEFAULT '',
 incoming_title VARCHAR(200) DEFAULT NULL,
 incoming_body MEDIUMTEXT DEFAULT NULL,
 incoming_hash CHAR(64) DEFAULT NULL,
 synced_at DATETIME DEFAULT NULL,
 deleted_at DATETIME DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uk_kb_source (source_provider,source_key),
 KEY idx_kb_list (status,visibility,deleted_at,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_kb_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 article_id BIGINT UNSIGNED NOT NULL,
 revision INT NOT NULL,
 title VARCHAR(200) NOT NULL,
 body MEDIUMTEXT NOT NULL,
 category VARCHAR(60) NOT NULL,
 tags VARCHAR(255) NOT NULL DEFAULT '',
 visibility VARCHAR(20) NOT NULL,
 department VARCHAR(100) NOT NULL DEFAULT '',
 status VARCHAR(20) NOT NULL,
 actor_type VARCHAR(20) NOT NULL,
 actor_id INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uk_kb_revision (article_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_kb_bookmarks (
 article_id BIGINT UNSIGNED NOT NULL,
 actor_type VARCHAR(20) NOT NULL,
 actor_id INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (article_id,actor_type,actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_kb_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(100) NOT NULL,
 url VARCHAR(1000) NOT NULL,
 url_hash CHAR(64) NOT NULL,
 keywords VARCHAR(255) NOT NULL DEFAULT '',
 category VARCHAR(60) NOT NULL DEFAULT '常用工具',
 description VARCHAR(255) NOT NULL DEFAULT '',
 sort_order INT NOT NULL DEFAULT 100,
 revision INT NOT NULL DEFAULT 1,
 owner_type VARCHAR(20) NOT NULL,
 owner_id INT NOT NULL,
 deleted_at DATETIME DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uk_kb_link_url (url_hash),
 KEY idx_kb_link_list (deleted_at,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_kb_integrations (
 provider VARCHAR(20) NOT NULL PRIMARY KEY,
 app_id VARCHAR(160) NOT NULL,
 secret_cipher TEXT NOT NULL,
 operator_id VARCHAR(160) NOT NULL DEFAULT '',
 updated_by INT NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO project_kb_links (title,url,url_hash,keywords,category,description,sort_order,owner_type,owner_id) VALUES
('雅云','https://www.yayuncdn.com/',SHA2('https://www.yayuncdn.com/',256),'雅云','云与域名','云资源与网站加速',10,'system',0),
('阿里云','https://account.aliyun.com/',SHA2('https://account.aliyun.com/',256),'阿里云','云与域名','阿里云账号与资源入口',20,'system',0),
('腾讯云','https://cloud.tencent.com/',SHA2('https://cloud.tencent.com/',256),'腾讯云','云与域名','腾讯云产品与控制台',30,'system',0),
('微信开放平台','https://open.weixin.qq.com/',SHA2('https://open.weixin.qq.com/',256),'微信开放平台','开发与协作','微信应用与开放能力',40,'system',0),
('AI 服务器','https://os.findtoken.net/',SHA2('https://os.findtoken.net/',256),'ai服务器,AI 服务器','AI 与创作','AI 算力与服务入口',50,'system',0),
('AI 视频','https://video.laibangwo.com/',SHA2('https://video.laibangwo.com/',256),'ai视频,AI 视频','AI 与创作','让视频创作更轻松',60,'system',0),
('SkillHub','https://skillhub.cn/',SHA2('https://skillhub.cn/',256),'skillhub','学习与成长','技能探索与学习',70,'system',0),
('DNSPod','https://console.dnspod.cn/',SHA2('https://console.dnspod.cn/',256),'dnspod','云与域名','域名解析管理',80,'system',0),
('商务中国','https://www.bizcn.com/',SHA2('https://www.bizcn.com/',256),'商务中国','云与域名','域名与企业互联网服务',90,'system',0),
('游学','https://youxue.laibangwo.com/',SHA2('https://youxue.laibangwo.com/',256),'游学','学习与成长','一起发现新的成长方向',100,'system',0);
