-- ============================================================
-- 升级脚本：新增部门管理表
-- 适用：已导入旧版 database.sql 的用户
-- 用法：在 phpMyAdmin 中选中 caiwu 库后导入本文件
-- ============================================================

-- ------------------------------------------------------------
-- 部门表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE COMMENT '部门名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序(越小越靠前)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初始化部门数据（从现有员工数据中提取去重，避免重复）
INSERT IGNORE INTO `departments` (`name`, `sort`) VALUES
('销售一部', 1),
('销售二部', 2);
