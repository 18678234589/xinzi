<?php
/**
 * 结算核对标记：财务对每位合作人员每月的结算金额做核对，标记 绿 / 黄 / 红 并写理由（可通知本人补充成本等）。
 * 对照金额 ref_net = 财务自己的报酬表里该人的实发金额；与系统实结算差距 ≤ 5% 财务可标绿，绿色后本人才能申请结算。
 * 本人只看到颜色和理由，看不到对照金额。
 */
require_once __DIR__ . '/ProjectSettlement.php';

const PPF_TOLERANCE = 0.05;

function ppf_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_payroll_flags (
        employee_id INT NOT NULL, payroll_month CHAR(7) NOT NULL,
        color ENUM('green','yellow','red') NULL, reason VARCHAR(500) NOT NULL DEFAULT '', ref_net DECIMAL(12,2) NULL,
        notified_at DATETIME NULL, set_by VARCHAR(60) NOT NULL DEFAULT '', updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (employee_id, payroll_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** [employee_id => 行]；只含该月有标记或对照金额的人。 */
function ppf_all($month)
{
    ppf_ensure();
    $q = db()->prepare('SELECT * FROM project_payroll_flags WHERE payroll_month=?');
    $q->execute([$month]);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['employee_id']] = $r;
    return $out;
}

/** 差距：(系统 − 对照) / 对照；没有对照金额返回 null。 */
function ppf_diff($sysNet, $ref)
{
    if ($ref === null || $ref === '' || abs((float)$ref) < 0.005) return null;
    return ((float)$sysNet - (float)$ref) / abs((float)$ref);
}

/** 本人这个月能不能申请结算：财务已标绿。 */
function ppf_is_green($employeeId, $month)
{
    ppf_ensure();
    $q = db()->prepare("SELECT color, reason FROM project_payroll_flags WHERE employee_id=? AND payroll_month=?");
    $q->execute([(int)$employeeId, $month]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ($row['color'] === 'green') return true;
    if ($row['color'] !== 'red' && preg_match('/一致|无异议|差距在\s*5%\s*以内/u', (string)$row['reason'])) {
        return true;
    }
    return false;
}

function ppf_label($color)
{
    return ['green' => '已核对 · 可申请结算', 'yellow' => '财务核对中', 'red' => '需补充 / 待处理'][$color] ?? '待财务核对';
}

/**
 * 系统自动列出的差异原因：[['text' => 说明, 'who' => 'finance'|'staff', 'link' => 财务可点的处理链接]]。
 * who = finance：财务能直接处理（审核订单、核对收款）；staff：需要本人 / 技术补充（成本、完成确认、订单资料）。
 */
function ppf_reasons($employeeId, $month, array $row = [], $ref = null)
{
    $employeeId = (int)$employeeId;
    $from = $month . '-01'; $until = date('Y-m-d', strtotime($from . ' +1 month'));
    $out = [];
    $review = BASE_URL . '/project/batch_review.php?month=' . rawurlencode($month) . '&emp=' . $employeeId;
    $q = db()->prepare("SELECT COALESCE(a.state,'queued') st,COUNT(DISTINCT o.id) n FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.employee_id=? LEFT JOIN project_auto_reviews a ON a.order_id=o.id WHERE o.order_date>=? AND o.order_date<? AND o.settlement_status IN ('draft','review') GROUP BY st");
    $q->execute([$employeeId, $from, $until]);
    $st = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $draft = array_sum($st);
    $text = ['wait_data' => ['待补资料（多为技术还没点“已完成”、域名空间资源待确认）', 'staff'], 'wait_sync' => ['等待收款确认（财务核对 / 补传收款流水）', 'finance'],
        'exception' => ['核对异常（退款待核验、实付状态、成本凭证等）', 'finance'], 'wait_finance' => ['成本已登记，等财务核验', 'finance'], 'queued' => ['还没被系统核对', 'finance'], 'ready' => ['核对通过，只差财务审核', 'finance']];
    foreach ($text as $state => [$t, $who]) if (!empty($st[$state])) $out[] = ['text' => $st[$state] . ' 单' . $t, 'who' => $who, 'link' => $who === 'finance' ? $review : ''];
    if ($draft) array_unshift($out, ['text' => '本月有 ' . $draft . ' 张订单还没审核，系统按“预计”计入分成，审核后才是正式金额', 'who' => 'finance', 'link' => $review]);

    $nc = db()->prepare("SELECT COUNT(*) FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.employee_id=? WHERE o.order_date>=? AND o.order_date<? AND o.settlement_status IN ('draft','review') AND o.project_type IN ('网站模板','AI网站定制','小程序开发','环境配置') AND NOT EXISTS (SELECT 1 FROM project_costs c WHERE c.order_id=o.id AND c.review_status<>'rejected')");
    $nc->execute([$employeeId, $from, $until]);
    if ($n = (int)$nc->fetchColumn()) $out[] = ['text' => $n . ' 张订单还没有成本，利润按 0 成本估算会偏高，请补充成本', 'who' => 'staff', 'link' => ''];
    $pc = db()->prepare("SELECT COUNT(DISTINCT o.id) FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.employee_id=? JOIN project_costs c ON c.order_id=o.id AND c.review_status='pending' WHERE o.order_date>=? AND o.order_date<?");
    $pc->execute([$employeeId, $from, $until]);
    if ($n = (int)$pc->fetchColumn()) $out[] = ['text' => $n . ' 张订单的成本是“待确认”（技术确认套餐 / 资源后生效）', 'who' => 'staff', 'link' => ''];

    $any = db()->prepare('SELECT COUNT(DISTINCT o.id) FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.employee_id=? WHERE o.order_date>=? AND o.order_date<?');
    $any->execute([$employeeId, $from, $until]);
    $orders = (int)$any->fetchColumn();
    $sys = (float)($row['net'] ?? 0);
    $diff = ppf_diff($sys, $ref);
    if ($diff !== null && abs($diff) > PPF_TOLERANCE) {
        if (!$orders && (float)$ref > $sys) $out[] = ['text' => '本月系统里没有该人员参与的订单，可能订单还没上传或没关联到本人', 'who' => 'staff', 'link' => ''];
        if (($row['days'] ?? null) === null && ((float)($row['bonus'] ?? 0)) == 0) $out[] = ['text' => '没有本月服务时长确认（考勤）记录，全勤奖 / 固定服务费折算可能不对', 'who' => 'finance', 'link' => BASE_URL . '/attendance/index.php'];
        if (!$draft && $orders && $diff < 0) $out[] = ['text' => '订单都已审核，系统金额低于对照金额：可能分成规则 / 比例与财务表口径不同，请财务核对规则', 'who' => 'finance', 'link' => BASE_URL . '/project/rules.php'];
        if (!$draft && !$orders && $diff < 0) $out[] = ['text' => '没有订单也没有对应规则（如督导、部门提成），金额可能需要在“本月加项”里补录', 'who' => 'finance', 'link' => ''];
        if ($diff > 0 && $draft) $out[] = ['text' => '系统金额偏高：多为未审核订单的“预计分成”高于实际（成本未录 / 订单未成交）', 'who' => 'finance', 'link' => $review];
    }
    if (!$out) $out[] = ['text' => '没有发现明显原因，请财务逐项核对', 'who' => 'finance', 'link' => ''];
    return $out;
}

/** 保存标记。$color 为空 = 只更新对照金额。$notify = 保存后给本人发站内信。仅财务 / 管理账号。 */
function ppf_save($actor, $employeeId, $month, $color, $reason, $ref, $notify)
{
    require_once __DIR__ . '/ProjectPartnerProfile.php';
    if (!ppp_can($actor)) throw new RuntimeException('仅财务和管理员可以核对结算金额');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('月份无效');
    if (!in_array($color, ['green', 'yellow', 'red', ''], true)) throw new RuntimeException('颜色无效');
    $reason = trim((string)$reason);
    if (mb_strlen($reason) > 500) throw new RuntimeException('理由不超过 500 字');
    if (in_array($color, ['yellow', 'red'], true) && mb_strlen($reason) < 2) throw new RuntimeException('标黄 / 标红请写明理由，对方才知道补充什么');
    $refText = trim((string)$ref);
    if ($refText !== '' && !preg_match('/^-?\d{1,9}(?:\.\d{1,2})?$/', $refText)) throw new RuntimeException('对照金额须为数字，最多两位小数');
    $exists = db()->prepare('SELECT COUNT(*) FROM employees WHERE id=?'); $exists->execute([(int)$employeeId]);
    if (!(int)$exists->fetchColumn()) throw new RuntimeException('合作人员不存在');
    ppf_ensure();
    $by = ($actor['type'] ?? '') . ':' . (int)($actor['id'] ?? 0);
    db()->prepare('INSERT INTO project_payroll_flags (employee_id,payroll_month,color,reason,ref_net,set_by) VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE color=VALUES(color),reason=VALUES(reason),ref_net=VALUES(ref_net),set_by=VALUES(set_by)')
        ->execute([(int)$employeeId, $month, $color === '' ? null : $color, $reason, $refText === '' ? null : $refText, $by]);
    ps_audit('payroll_flag', (int)$employeeId, 'set', $actor, ['month' => $month, 'color' => $color, 'reason' => $reason, 'ref' => $refText]);
    if ($notify && $color !== '') ppf_notify($employeeId, $month, $color, $reason);
}

/** 站内信：绿色 = 可以申请结算；黄 / 红 = 请按理由补充。 */
function ppf_notify($employeeId, $month, $color, $reason)
{
    try {
        $title = $color === 'green' ? $month . ' 的结算金额已核对，可以申请结算' : $month . ' 的结算金额需要你补充 / 确认';
        $body = $color === 'green' ? '财务已核对你 ' . $month . ' 的结算金额，在首页“项目报酬表”里点“申请结算”即可。' . ($reason !== '' ? "\n财务备注：" . $reason : '')
            : '财务核对 ' . $month . ' 的结算金额时发现需要补充：' . "\n" . $reason . "\n请补充后联系财务重新核对；核对通过（绿色）后才能申请结算。";
        db()->prepare('INSERT INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$employeeId, 'payroll_check', $title, $body, '/project/index.php?pm=' . $month, 'pflag:' . (int)$employeeId . ':' . $month . ':' . date('YmdHis')]);
        db()->prepare('UPDATE project_payroll_flags SET notified_at=NOW() WHERE employee_id=? AND payroll_month=?')->execute([(int)$employeeId, $month]);
    } catch (Throwable $e) { /* 站内信失败不影响标记 */ }
}
