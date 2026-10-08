CREATE TABLE IF NOT EXISTS project_review_rule_policies (
    rule_scope VARCHAR(16) NOT NULL,
    rule_id INT NOT NULL,
    allow_no_receipt TINYINT(1) NOT NULL DEFAULT 0,
    calculation_period VARCHAR(16) NOT NULL,
    updated_by_admin INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rule_scope,rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO project_review_rule_policies (rule_scope,rule_id,allow_no_receipt,calculation_period)
SELECT 'order',id,
    CASE WHEN rate=0 AND per_order_subsidy>0 AND low_profit_threshold IS NULL THEN 1 ELSE 0 END,
    'order' FROM project_commission_rules WHERE is_active=1;

-- 网站售后单量沿用现用月度算法，不启用已停发的逐单补助。
INSERT IGNORE INTO project_review_rule_policies (rule_scope,rule_id,allow_no_receipt,calculation_period)
SELECT 'monthly',id,1,'monthly' FROM project_monthly_rules
WHERE id IN (4069,4070) AND rule_type='legacy_sheet' AND scope_business='网站续费' AND is_active=1;
