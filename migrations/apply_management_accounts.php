<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('仅允许命令行执行'); }
require_once __DIR__ . '/../includes/ProjectSettlement.php';
$column = db()->query("SHOW COLUMNS FROM project_users LIKE 'role'")->fetch();
if (!$column) throw new RuntimeException('project_users.role 不存在');
if (strpos($column['Type'], "'management'") === false) {
    // 已有未知角色必须先人工核对，避免缩小枚举造成数据丢失。
    if ($column['Type'] !== "enum('technical','customer_service','governance','vault')") throw new RuntimeException('账户角色结构已变化，请先核对');
    db()->exec("ALTER TABLE project_users MODIFY COLUMN role ENUM('technical','customer_service','governance','vault','management') NOT NULL");
    echo "管理账户角色已添加\n";
} else {
    echo "管理账户角色已存在\n";
}
db()->exec("CREATE TABLE IF NOT EXISTS project_account_management (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  scope ENUM('assigned','company') NOT NULL DEFAULT 'assigned',
  title VARCHAR(80) NOT NULL DEFAULT '管理',
  fixed_pay_only TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_account_management_user FOREIGN KEY (user_id) REFERENCES project_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$column = db()->query("SHOW COLUMNS FROM project_account_management LIKE 'fixed_pay_only'")->fetch();
if (!$column) db()->exec('ALTER TABLE project_account_management ADD COLUMN fixed_pay_only TINYINT(1) NOT NULL DEFAULT 0 AFTER title');
