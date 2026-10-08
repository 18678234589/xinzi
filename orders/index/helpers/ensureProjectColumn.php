<?php
function ensureProjectColumn() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'project'")->fetchAll();
        if (empty($cols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `project` VARCHAR(100) DEFAULT '' COMMENT '项目/业务来源' AFTER `order_date`");
            db()->exec("ALTER TABLE `orders` ADD INDEX `idx_project_employee` (`employee_id`, `project`)");
        }
        // 售后部字段：店铺
        $shopCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'shop'")->fetchAll();
        if (empty($shopCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `shop` VARCHAR(100) DEFAULT '' COMMENT '店铺' AFTER `project`");
        }
        // 售后部字段：付款旺旺
        $wwCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'wangwang'")->fetchAll();
        if (empty($wwCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `wangwang` VARCHAR(100) DEFAULT '' COMMENT '付款旺旺' AFTER `shop`");
        }
        // 异常标记字段
        $abnCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'is_abnormal'")->fetchAll();
        if (empty($abnCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `is_abnormal` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0正常 1异常' AFTER `wangwang`");
            db()->exec("ALTER TABLE `orders` ADD COLUMN `abnormal_reason` VARCHAR(200) DEFAULT '' COMMENT '异常原因' AFTER `is_abnormal`");
        }
        // 退款关联原订单字段
        $refundCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'refund_for_order_id'")->fetchAll();
        if (empty($refundCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `refund_for_order_id` INT NULL DEFAULT NULL COMMENT '退款订单关联的原订单ID' AFTER `is_abnormal`");
            db()->exec("ALTER TABLE `orders` ADD INDEX `idx_refund_for` (`refund_for_order_id`)");
        }
        // 上传时间字段
        $ctCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'created_at'")->fetchAll();
        if (empty($ctCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '上传时间' AFTER `abnormal_reason`");
        }
        // 归属范围：personal=个人订单，department=部门订单
        $scopeCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'order_scope'")->fetchAll();
        if (empty($scopeCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `order_scope` VARCHAR(20) NOT NULL DEFAULT 'personal' COMMENT 'personal=个人,department=部门' AFTER `created_at`");
        }
        // 回收站：软删除标记
        $delCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'is_deleted'")->fetchAll();
        if (empty($delCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=正常 1=已删除(回收站)' AFTER `order_scope`");
            db()->exec("ALTER TABLE `orders` ADD INDEX `idx_deleted` (`is_deleted`)");
        }
        // 售后部扩展字段
        $remarkCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'remark'")->fetchAll();
        if (empty($remarkCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `platform_no` VARCHAR(100) DEFAULT '' COMMENT '平台编号' AFTER `wangwang`");
            db()->exec("ALTER TABLE `orders` ADD COLUMN `remark` VARCHAR(500) DEFAULT '' COMMENT '特殊情况备注' AFTER `platform_no`");
            db()->exec("ALTER TABLE `orders` ADD COLUMN `split_amount` DECIMAL(10,2) DEFAULT 0 COMMENT '分单备注金额' AFTER `remark`");
        }
        // 原始行数据字段（存完整的原始列 JSON）
        $rawCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'raw_data'")->fetchAll();
        if (empty($rawCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `raw_data` TEXT DEFAULT NULL COMMENT '原始行数据JSON' AFTER `split_amount`");
        }
        // 上传批次表头记录表
        db()->exec("CREATE TABLE IF NOT EXISTS `upload_batches` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL,
            `headers` TEXT NOT NULL COMMENT '表头JSON数组',
            `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // 移除 employee_id 外键约束（部门订单需要存 0）
        try {
            $fkRows = db()->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND REFERENCED_TABLE_NAME='employees'"
    )->fetchAll();
            foreach ($fkRows as $fk) {
                db()->exec("ALTER TABLE `orders` DROP FOREIGN KEY `" . $fk['CONSTRAINT_NAME'] . "`");
            }
        } catch (\Throwable $e) {}

        // 复合索引：加速按合作人员+月份查询（删除/项目报酬计算常用）
        try {
            $idxExists = db()->query("SHOW INDEX FROM `orders` WHERE Key_name = 'idx_emp_date'")->fetchAll();
            if (empty($idxExists)) {
                db()->exec("ALTER TABLE `orders` ADD INDEX `idx_emp_date` (`employee_id`, `order_date`)");
            }
        } catch (\Throwable $e) {}
    } catch (\Throwable $e) {}
}
