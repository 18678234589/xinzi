-- 订单表增加"项目/来源"字段，用于区分同一员工不同项目的订单
-- 执行方式：在 phpMyAdmin 中选择数据库后导入此文件

ALTER TABLE `orders`
  ADD COLUMN `project` VARCHAR(100) DEFAULT '' COMMENT '项目/业务来源' AFTER `order_date`;

-- 添加索引便于查询和筛选
ALTER TABLE `orders`
  ADD INDEX `idx_project_employee` (`employee_id`, `project`);
