<?php
require_once __DIR__ . '/ProjectAnnouncements.php';

/** 2026-10 政策的显式发布工具。只由 CLI 发布调用，不在访问页面时修改规则或群发。 */
function pwr_publish_october_notice($actor, $expectedEmployeeIds)
{
    if (PHP_SAPI !== 'cli' || ($actor['type'] ?? '') !== 'admin') throw new RuntimeException('只允许授权的 CLI 发布');
    $pdo = db();
    if ($pdo->inTransaction()) throw new RuntimeException('请使用独立发布事务');
    $pdo->beginTransaction();
    try {
        $recipients = $pdo->query("SELECT DISTINCT e.id AS employee_id,e.name FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.role='customer_service' AND u.is_active=1 AND e.department='网站客服' ORDER BY e.id")->fetchAll();
        $actualIds = array_map('intval', array_column($recipients, 'employee_id'));
        $expectedEmployeeIds = array_map('intval', $expectedEmployeeIds);
        sort($expectedEmployeeIds);
        if ($actualIds !== $expectedEmployeeIds || !$actualIds) throw new RuntimeException('网站客服接收人员已变化，请重新核对后发布');
        $lock = $pdo->prepare("SELECT status FROM project_payroll_periods WHERE period='2026-10' FOR UPDATE");
        $lock->execute();
        if ($lock->fetchColumn() === 'locked') throw new RuntimeException('2026-10 已锁定，需先核对调整方案，不能改写历史结算');
        $base = $pdo->query("SELECT * FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='AI网站定制' AND role_name='*' AND order_kind='*' AND is_active=1 AND effective_from<'2026-10-01' ORDER BY effective_from DESC,id DESC LIMIT 1 FOR UPDATE")->fetch();
        if (!$base || $base['calc_mode'] !== 'pool' || (float)$base['per_order_subsidy'] !== 10.0 || (float)$base['rate'] !== .1 || (float)$base['service_fee_rate'] !== .03 || (float)$base['min_cost_rate'] !== .65) throw new RuntimeException('旧单量规则不符合预期，请先核对');
        $new = $base;
        unset($new['id'], $new['created_at']);
        $new['effective_from'] = '2026-10-01';
        $new['per_order_subsidy'] = 20;
        $new['note'] = '自 2026-10 订单起：定制客服利润分成 10% 不变，单量奖励 20 元/单；两人合接比例分成和单量奖励均按权重分配，50%/50% 时各 10 元单量奖励。博山定制成本按售价 65% 计，华梦外包按实际 80%。';
        $q = $pdo->query("SELECT * FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='AI网站定制' AND is_active=1 AND effective_from>='2026-10-01' ORDER BY id FOR UPDATE");
        $future = $q->fetchAll();
        $ruleId = null;
        foreach ($future as $r) {
            foreach ($new as $key => $value) {
                if ($key === 'note') continue;
                if ((string)$r[$key] !== (string)$value && !(is_numeric($r[$key]) && is_numeric($value) && (float)$r[$key] === (float)$value)) throw new RuntimeException('发现不同的 10 月或未来定制客服规则，请先人工核对');
            }
            if ($ruleId !== null) throw new RuntimeException('存在重复生效规则，请先核对');
            $ruleId = (int)$r['id'];
        }
        if ($ruleId === null) {
            $keys = array_keys($new);
            $pdo->prepare('INSERT INTO project_commission_rules (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute(array_values($new));
            $ruleId = (int)$pdo->lastInsertId();
            ps_audit('commission_rule', $ruleId, 'october_notice_upgrade', $actor, ['previous_rule_id' => (int)$base['id'], 'effective_from' => '2026-10-01', 'per_order_subsidy' => 20, 'historical_rules_preserved' => true]);
        }
        $ranking = $pdo->query("SELECT * FROM project_monthly_rules WHERE name='定制客服月度第一名奖' AND scope_business='AI网站定制' AND scope_group='customer_service' AND rule_type='ranking' AND effective_from='2026-10' AND is_active=1 FOR UPDATE")->fetchAll();
        if (count($ranking) !== 1) throw new RuntimeException('定制客服排名规则数量异常，请核对');
        $rank = $ranking[0];
        // 通知未规定三项权重：沿用综合考评人工确认名次，不擅自改成三项等权或利润单项排名。
        $params = ['awards' => [500], 'unique_positions' => true, 'eligible_department' => '网站客服', 'assessment_dimensions' => ['单量', '收入', '利润']];
        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);
        $rankNote = '网站客服 AI 建站接单综合考评：综合单量、收入、利润，第一名额外奖励 500 元，政策不变。未规定权重，由财务/主管确认名次后在规则中心填写；同月本项只奖励一位第一名，尚未确认不预发，不采用利润单项排名。';
        if ($rank['metric'] !== 'manual' || json_decode($rank['params_json'], true) !== $params || $rank['note'] !== $rankNote) {
            $pdo->prepare('UPDATE project_monthly_rules SET metric=?,params_json=?,note=?,updated_by_admin=? WHERE id=?')->execute(['manual', $paramsJson, $rankNote, $actor['id'], $rank['id']]);
            ps_audit('monthly_rule', (int)$rank['id'], 'october_notice_align', $actor, ['before' => $rank, 'after' => ['metric' => 'manual', 'params' => $params, 'note' => $rankNote]]);
        }
        $body = "📢通知：\n根据领导安排，自 2026 年 10 月订单起，网站客服 AI 建站订单奖励升级！\n\n✅单量提成：由 10 元 / 单上调至 20 元 / 单\n✅排名奖励：接单综合考评（考核维度：单量、收入、利润）第一名，额外奖励 500 元，政策保持不变。\n\n奖励加码，多接多得！请各位同事再接再厉，勇创佳绩💪";
        $insert = $pdo->prepare("INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,'announcement',?,?,?,?)");
        $sent = 0;
        foreach ($recipients as $person) {
            $insert->execute([(int)$person['employee_id'], '📢 网站客服 AI 建站订单奖励升级', $body, '/project/knowledge_rules.php', 'website_ai_reward_202610_v1']);
            $sent += $insert->rowCount();
        }
        if ($sent) ps_audit('announcement', 0, 'publish', $actor, ['dedupe_key' => 'website_ai_reward_202610_v1', 'employee_ids' => $actualIds, 'sent' => $sent, 'commission_rule_id' => $ruleId, 'ranking_rule_id' => (int)$rank['id']]);
        $pdo->commit();
        return ['commission_rule_id' => $ruleId, 'ranking_rule_id' => (int)$rank['id'], 'new_messages' => $sent, 'recipients' => $recipients];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
