-- 轮值董事长脑洞改为按工作日划期：从起算日到任期末每 6 个工作日（周一至周六，不含法定节假日）为一期，
-- 每期未提交扣“董事长奖金池 ÷ 本任期期数”，整任期不交正好扣光，任期结束随未获得额度转入全员福利池。
UPDATE project_governance_rules
SET cadence_note='每 6 个工作日',
    penalty_amount=NULL,
    rule_text='轮值董事长从起算日到任期末，每 6 个工作日（周一至周六，不含法定节假日）为一期，系统自动算出本任期最少提交期数；每期至少提交一条有效三天脑洞（通过默认 +100）。某期没有有效提交或被监委判为无效，自动扣“董事长奖金池 ÷ 本任期期数”，整任期不交即扣光；扣减在任期结束时随未获得额度转入全员福利池。监委会可写明理由逐笔豁免。',
    version=version+1
WHERE rule_code='chair_idea' AND cadence_note<>'每 6 个工作日';

-- 新口径自 2026-09-28 起算（此前不追扣）
UPDATE project_governance_rotation_guard g JOIN project_governance_rotations r ON r.id=g.rotation_id
SET g.penalty_effective_from='2026-09-28'
WHERE r.start_date='2026-09-15' AND g.penalty_effective_from<'2026-09-28';

-- 2026 年法定节假日（国办发明电〔2025〕7号）：中秋 9/25–27、国庆 10/1–7
INSERT INTO project_holidays (holiday_date,name) VALUES
('2026-09-25','中秋节'),('2026-09-26','中秋节'),('2026-09-27','中秋节'),
('2026-10-01','国庆节'),('2026-10-02','国庆节'),('2026-10-03','国庆节'),('2026-10-04','国庆节'),('2026-10-05','国庆节'),('2026-10-06','国庆节'),('2026-10-07','国庆节')
ON DUPLICATE KEY UPDATE name=VALUES(name);
