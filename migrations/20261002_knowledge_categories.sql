-- 分类库增量升级：保留原有分类，不从私有/部门文章提取名称。
CREATE TABLE IF NOT EXISTS project_kb_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    owner_type VARCHAR(20) NOT NULL DEFAULT 'admin',
    owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_kb_category_name (name),
    KEY idx_kb_category_owner (owner_type,owner_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO project_kb_categories (name) VALUES
('云与域名'),('开发与协作'),('AI 与创作'),('学习与成长'),('常用工具'),
('经验与方法'),('流程与规范'),('业务知识'),('常见问题'),('外部知识');

INSERT IGNORE INTO project_kb_categories (name)
SELECT DISTINCT category FROM project_kb_links WHERE deleted_at IS NULL AND category<>'';

INSERT IGNORE INTO project_kb_categories (name)
SELECT DISTINCT category FROM project_kb_articles WHERE visibility='all' AND status='published' AND deleted_at IS NULL AND category<>'';
