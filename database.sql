-- ============================================================
-- 财务薪资结算系统 - 数据库结构
-- 数据库: caiwu
-- 字符集: utf8mb4
-- 适用: MySQL 8.0 / phpMyAdmin
-- ============================================================
--
-- 导入步骤（重要！解决权限报错 #1044）：
-- 1. 先在 phpMyAdmin 左侧点击"新建" → 数据库名填 caiwu → 排序规则选 utf8mb4_unicode_ci → 点击"创建"
-- 2. 在左侧列表中点击选中刚创建的 caiwu 数据库
-- 3. 再点击"导入" → 上传本文件 → 执行
--
-- 注意：本文件不含 CREATE DATABASE 语句，需先手动建库，
--       否则会出现 #1044 Access denied 权限错误。
-- ============================================================

-- ------------------------------------------------------------
-- 部门表
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE COMMENT '部门名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序(越小越靠前)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 示例数据
INSERT INTO `departments` (`name`, `sort`) VALUES
('销售一部', 1),
('销售二部', 2);

-- ------------------------------------------------------------
-- 管理员表（系统登录）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(32) NOT NULL COMMENT 'MD5加密',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 默认管理员账号: admin / admin123
INSERT INTO `admins` (`username`, `password`) VALUES
('admin', MD5('admin123'));

-- ------------------------------------------------------------
-- 员工表
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `department` VARCHAR(100) NOT NULL,
  `base_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '基本工资',
  `commission_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT '提成比例(0.05=5%)',
  `password` VARCHAR(32) NOT NULL COMMENT 'MD5加密',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 示例数据
INSERT INTO `employees` (`name`, `department`, `base_salary`, `commission_rate`, `password`) VALUES
('张三', '销售一部', 5000.00, 0.0500, MD5('123456')),
('李四', '销售一部', 4500.00, 0.0300, MD5('123456')),
('王五', '销售二部', 6000.00, 0.0800, MD5('123456')),
('赵六', '销售二部', 4800.00, 0.0600, MD5('123456'));

-- ------------------------------------------------------------
-- 订单表
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT NOT NULL,
  `order_amount` DECIMAL(12,2) NOT NULL COMMENT '订单金额',
  `order_date` DATE NOT NULL COMMENT '订单日期',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_employee` (`employee_id`),
  INDEX `idx_date` (`order_date`),
  CONSTRAINT `fk_order_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 示例数据
INSERT INTO `orders` (`employee_id`, `order_amount`, `order_date`) VALUES
(1, 12000.00, '2026-06-05'),
(1, 8500.00, '2026-06-12'),
(1, 3000.00, '2026-06-20'),
(2, 15000.00, '2026-06-08'),
(2, 7000.00, '2026-06-15'),
(3, 20000.00, '2026-06-03'),
(3, 11000.00, '2026-06-18'),
(4, 9500.00, '2026-06-10'),
(4, 6800.00, '2026-06-22');

-- ------------------------------------------------------------
-- 薪资表
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `salaries`;
CREATE TABLE `salaries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT NOT NULL,
  `month` VARCHAR(7) NOT NULL COMMENT '月份 YYYY-MM',
  `order_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '当月订单总额',
  `commission` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '提成金额',
  `net_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '实发工资',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_employee_month` (`employee_id`, `month`),
  CONSTRAINT `fk_salary_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
