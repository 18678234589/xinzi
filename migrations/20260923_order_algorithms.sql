-- 订单模板与成本算法整合（《程序表记录》《网站核算》《小程序部门核算标准》）。
-- 只增列、不改历史快照；apply_project.php 对“列/索引已存在”错误幂等跳过。

-- 成本中心：网站程序套餐（空间+域名+商投）与上游采购价（“孙姐要成本”）分开保存。
ALTER TABLE project_cost_templates MODIFY category ENUM('domain','server','program','certificate','certification','api','plugin','outsourcing','other') NOT NULL;
ALTER TABLE project_cost_templates ADD COLUMN supplier_price DECIMAL(14,2) NULL AFTER price;
ALTER TABLE project_cost_templates ADD COLUMN business_scope VARCHAR(100) NOT NULL DEFAULT '' AFTER category;
ALTER TABLE project_costs ADD COLUMN supplier_amount DECIMAL(14,2) NULL AFTER amount;

-- 订单类型（新订单/定制/续费…）用于匹配分成规则。
ALTER TABLE project_orders ADD COLUMN order_kind VARCHAR(40) NOT NULL DEFAULT '' AFTER project_type;
ALTER TABLE project_order_resources ADD COLUMN program_template_id BIGINT UNSIGNED NULL AFTER server_template_id;

-- 分成规则：按岗位、订单类型匹配；支持组池分摊 / 个人独立计提、服务费、每单补助和最低售价。
ALTER TABLE project_commission_rules ADD COLUMN role_name VARCHAR(80) NOT NULL DEFAULT '*' AFTER project_type;
ALTER TABLE project_commission_rules ADD COLUMN order_kind VARCHAR(40) NOT NULL DEFAULT '*' AFTER role_name;
ALTER TABLE project_commission_rules ADD COLUMN calc_mode ENUM('pool','individual') NOT NULL DEFAULT 'pool' AFTER order_kind;
ALTER TABLE project_commission_rules ADD COLUMN service_fee_rate DECIMAL(7,6) NULL AFTER rate;
ALTER TABLE project_commission_rules ADD COLUMN per_order_subsidy DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER service_fee_rate;
ALTER TABLE project_commission_rules ADD COLUMN min_contract_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER per_order_subsidy;
ALTER TABLE project_commission_rules ADD COLUMN note VARCHAR(200) NOT NULL DEFAULT '' AFTER min_contract_amount;

-- 快照记录每人的计算口径，结算单与报酬中心可逐项追溯。
ALTER TABLE project_commission_snapshots ADD COLUMN role_name VARCHAR(80) NOT NULL DEFAULT '' AFTER commission_group;
ALTER TABLE project_commission_snapshots ADD COLUMN calc_mode VARCHAR(20) NOT NULL DEFAULT 'pool' AFTER rule_id;
ALTER TABLE project_commission_snapshots ADD COLUMN service_fee DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER direct_cost;
ALTER TABLE project_commission_snapshots ADD COLUMN subsidy_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER commission_amount;
ALTER TABLE project_commission_snapshots ADD COLUMN calc_note VARCHAR(500) NOT NULL DEFAULT '' AFTER subsidy_amount;

-- 合作人员在某业务中的默认岗位（外包前端、售后、资料员、定制客服等），录入订单时自动带入以匹配分成规则。
CREATE TABLE IF NOT EXISTS project_employee_roles (
  employee_id INT NOT NULL,
  business_name VARCHAR(100) NOT NULL,
  commission_group ENUM('technical','customer_service') NOT NULL,
  role_name VARCHAR(80) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id, business_name, commission_group),
  CONSTRAINT fk_project_employee_role_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
