<?php
/**
 * 我的分成算法：把规则中心里与登录者相关的“按单分成规则”和“月度规则”整理成人话展示；
 * 算法不对时可提交更正申请（填入认为正确的数值、详细说明，可选上传订单算法示例），管理员与财务审核。
 * 审核只记录结论并回复申请人，不会自动改规则（规则变更由财务在“规则中心”按新版本生效，已审核订单不受影响）。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectBusiness.php';

const PRA_GROUPS = ['technical' => '技术', 'customer_service' => '客服'];
const PRA_MONTHLY_TYPES = ['tier_rate' => '阶梯比例', 'threshold_bonus' => '达标奖金', 'ranking' => '排名奖', 'dept_share' => '部门主管提成', 'fixed' => '固定补助', 'per_unit' => '计件奖励', 'base_fee' => '固定服务费', 'attendance_bonus' => '全勤奖', 'overtime_pay' => '超时补贴', 'manual' => '手工项', 'profit_pool' => '利润池分配', 'perf_rank' => '绩效排名', 'order_count' => '单量奖励', 'sales_package' => '套餐销售'];

function pra_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_rule_requests (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      rule_kind ENUM('order','monthly','other') NOT NULL DEFAULT 'order',
      rule_id BIGINT UNSIGNED NULL,
      business VARCHAR(100) NOT NULL DEFAULT '',
      rule_title VARCHAR(240) NOT NULL DEFAULT '',
      rule_snapshot TEXT NULL,
      proposed_rate DECIMAL(8,4) NULL,
      proposed_fee_rate DECIMAL(8,4) NULL,
      proposed_subsidy DECIMAL(12,2) NULL,
      proposed_min DECIMAL(12,2) NULL,
      proposed_text VARCHAR(500) NOT NULL DEFAULT '',
      detail TEXT NOT NULL,
      example_name VARCHAR(200) NOT NULL DEFAULT '',
      example_stored VARCHAR(80) NOT NULL DEFAULT '',
      example_mime VARCHAR(80) NOT NULL DEFAULT '',
      applicant_type VARCHAR(20) NOT NULL,
      applicant_id INT NOT NULL,
      applicant_employee_id INT NULL,
      applicant_name VARCHAR(80) NOT NULL DEFAULT '',
      status ENUM('pending','resolved','rejected') NOT NULL DEFAULT 'pending',
      handler_name VARCHAR(80) NULL,
      handle_note TEXT NULL,
      handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_rule_req_status (status, created_at),
      KEY idx_rule_req_applicant (applicant_type, applicant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function pra_money($v) { return '¥' . rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); }
function pra_pct($v) { return rtrim(rtrim(number_format((float)$v * 100, 2, '.', ''), '0'), '.') . '%'; }

/**
 * 一条按单规则 → 人话。返回 ['headline'=>算式, 'details'=>[说明…], 'subsidy'=>本人每单补助]。
 * $employeeId 用于判断“每单补助只发给指定员工”的限定。
 */
function pra_formula(array $rule, $employeeId = 0, $businessFee = 0.0)
{
    $rate = (float)($rule['rate'] ?? 0);
    $pool = ($rule['calc_mode'] ?? 'pool') !== 'individual';
    $fee = isset($rule['service_fee_rate']) && $rule['service_fee_rate'] !== null && $rule['service_fee_rate'] !== '' ? (float)$rule['service_fee_rate'] : (float)$businessFee;
    $minCost = (float)($rule['min_cost_rate'] ?? 0);
    $subsidy = (float)($rule['per_order_subsidy'] ?? 0);
    $only = array_filter(array_map('intval', preg_split('/[^\d]+/', (string)($rule['subsidy_employee_ids'] ?? ''))));
    $subsidyMine = $subsidy > 0 && (!$only || in_array((int)$employeeId, $only, true));
    $details = [];
    $base = '(售价 − 成本' . ($fee > 0 ? ' − 服务费' : '') . ')';
    if ($rate > 0) $headline = $base . ' × ' . pra_pct($rate) . ($pool ? ' × 你的分配权重' : '');
    else $headline = $subsidyMine ? '' : '不按利润提成';
    if ($subsidyMine) $headline = trim($headline . ($headline !== '' ? ' + ' : '') . '每单 ' . pra_money($subsidy));
    if ($fee > 0) $details[] = '服务费 = 售价 × ' . pra_pct($fee);
    if ($minCost > 0) $details[] = '成本按“实际成本”和“售价 × ' . pra_pct($minCost) . '”两者取较大值';
    if ($pool && $rate > 0) $details[] = '组池：同组多人合接时，按各自权重分摊';
    if ($subsidy > 0 && !$subsidyMine) $details[] = '每单补助 ' . pra_money($subsidy) . ' 仅发给规则指定的员工';
    if ((float)($rule['min_contract_amount'] ?? 0) > 0) $details[] = '售价低于 ' . pra_money($rule['min_contract_amount']) . ' 的订单不计分成与补助';
    if (($rule['low_profit_threshold'] ?? null) !== null && $rule['low_profit_threshold'] !== '' && $subsidyMine) $details[] = '订单利润低于 ' . pra_money($rule['low_profit_threshold']) . ' 时，每单补助改按 ' . pra_money($rule['low_profit_subsidy'] ?? 0);
    if (!empty($rule['allow_negative'])) $details[] = '亏损、退款冲减按负数计入';
    return ['headline' => $headline, 'details' => $details, 'subsidy' => $subsidyMine ? $subsidy : 0.0];
}

/** (业务, 分组, 岗位) 三元组：来自近 18 个月实际参与的订单、已分配的业务和已设置的岗位。 */
function pra_pairs(array $actor)
{
    $eid = (int)($actor['employee_id'] ?? 0);
    if (!$eid) return [];
    $pairs = [];
    $add = function ($business, $group, $role) use (&$pairs) {
        if ($business === '' || !isset(PRA_GROUPS[$group])) return;
        $pairs[$business . '|' . $group . '|' . $role] = [$business, $group, $role];
    };
    $q = db()->prepare("SELECT DISTINCT o.project_type,p.commission_group,p.role_name FROM project_participants p JOIN project_orders o ON o.id=p.order_id WHERE p.employee_id=? AND o.order_date>=DATE_SUB(CURDATE(), INTERVAL 18 MONTH)");
    $q->execute([$eid]);
    foreach ($q->fetchAll() as $r) $add(ps_business_normalize($r['project_type']), $r['commission_group'], (string)$r['role_name']);
    $q = db()->prepare('SELECT business_name,commission_group,role_name FROM project_employee_roles WHERE employee_id=?');
    $q->execute([$eid]);
    foreach ($q->fetchAll() as $r) $add($r['business_name'], $r['commission_group'], (string)$r['role_name']);
    $group = ($actor['role'] ?? '') === 'technical' ? 'technical' : (($actor['role'] ?? '') === 'customer_service' ? 'customer_service' : '');
    if ($group !== '') {
        try { foreach (ps_actor_businesses($actor) as $b) $add($b, $group, ''); } catch (Throwable $e) { /* 业务未配置时只用历史参与 */ }
    }
    return array_values($pairs);
}

/**
 * 我的全部分成算法：[ ['business'=>…, 'groups'=>[ ['group'=>, 'label'=>, 'roles'=>[], 'rules'=>[…]] ], 'monthly'=>[…] ], … ]
 */
function pra_my_algorithms(array $actor)
{
    $eid = (int)($actor['employee_id'] ?? 0);
    $pairs = pra_pairs($actor);
    if (!$pairs) return [];
    $catalog = ps_business_catalog();
    $byBusiness = [];
    foreach ($pairs as [$business, $group, $role]) {
        $byBusiness[$business][$group]['roles'][$role] = $role;
    }
    $out = [];
    foreach ($byBusiness as $business => $groups) {
        $entry = ['business' => $business, 'groups' => [], 'monthly' => [], 'rule_count' => 0];
        $bizFee = (float)($catalog[$business]['service_fee_rate'] ?? 0);
        foreach ($groups as $group => $info) {
            $roles = array_values(array_filter($info['roles'], 'strlen'));
            $keys = [];
            foreach ($roles as $r) foreach (ps_role_keys($r) as $k) $keys[$k] = true;
            $q = db()->prepare("SELECT * FROM project_commission_rules WHERE commission_group=? AND project_type=? AND is_active=1 AND effective_from<=CURDATE() ORDER BY effective_from DESC,id DESC");
            $q->execute([$group, $business]);
            $seen = []; $rules = [];
            foreach ($q->fetchAll() as $rule) {
                $ruleRole = (string)$rule['role_name']; $anyRole = in_array($ruleRole, ['*', ''], true);
                if (!$anyRole && !isset($keys[$ruleRole])) continue;
                $key = $ruleRole . '|' . $rule['order_kind'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $rule['formula'] = pra_formula($rule, $eid, $bizFee);
                $rules[] = $rule;
            }
            usort($rules, function ($a, $b) { return [$a['role_name'] === '*' ? 1 : 0, $a['order_kind'] === '*' ? 1 : 0, $a['order_kind']] <=> [$b['role_name'] === '*' ? 1 : 0, $b['order_kind'] === '*' ? 1 : 0, $b['order_kind']]; });
            $entry['groups'][] = ['group' => $group, 'label' => PRA_GROUPS[$group], 'roles' => $roles, 'rules' => $rules];
            $entry['rule_count'] += count($rules);
        }
        $out[$business] = $entry;
    }
    // 月度规则：指定给本人的，或适用于“全部 / 本人业务、分组、岗位”的
    try {
        $month = date('Y-m');
        $q = db()->prepare("SELECT * FROM project_monthly_rules WHERE is_active=1 AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY id");
        $q->execute([$month, $month]);
        $myGroups = []; $myRoles = [];
        foreach ($pairs as [$b, $g, $r]) { $myGroups[$g] = true; if ($r !== '') foreach (ps_role_keys($r) as $k) $myRoles[$k] = true; }
        $generic = [];
        foreach ($q->fetchAll() as $m) {
            $mine = (int)$m['employee_id'] === $eid && $eid > 0;
            if (!$mine) {
                if ($m['employee_id'] !== null && $m['employee_id'] !== '') continue;
                if ($m['scope_group'] !== '*' && !isset($myGroups[$m['scope_group']])) continue;
                if ($m['scope_role'] !== '*' && !isset($myRoles[$m['scope_role']])) continue;
            }
            $biz = array_values(array_filter(array_map('trim', explode(',', (string)$m['scope_business']))));
            if ($mine && in_array($m['rule_type'], ['base_fee', 'attendance_bonus', 'fixed'], true)) continue; // 已在“每月固定收入”展示
            $m['type_label'] = PRA_MONTHLY_TYPES[$m['rule_type']] ?? $m['rule_type'];
            $m['text'] = pra_monthly_text($m);
            $m['mine'] = $mine;
            // 适用于“全部业务”的月度规则：只有指定给本人的才展示（手工调整、其他岗位的绩效排名等财务内部项目不展示给所有人）
            if (!$biz || in_array('*', $biz, true)) { if ($mine) $generic[] = $m; continue; }
            foreach ($biz as $b) if (isset($out[$b])) $out[$b]['monthly'][] = $m;
            elseif ($mine) { $out[$b] = ['business' => $b, 'groups' => [], 'monthly' => [$m], 'rule_count' => 0]; }
        }
        if ($generic) $out['其他每月项目'] = ['business' => '其他每月项目', 'groups' => [], 'monthly' => $generic, 'rule_count' => 0];
    } catch (Throwable $e) { /* 月度规则表未迁移时只展示按单规则 */ }
    return array_values($out);
}

/** 月度规则 → 人话。返回 ['headline'=>…, 'details'=>[…]]。 */
function pra_monthly_text(array $m)
{
    $p = json_decode((string)($m['params_json'] ?? ''), true); $p = is_array($p) ? $p : [];
    $type = $m['rule_type'];
    $amount = isset($p['amount']) ? (float)$p['amount'] : null;
    $details = [];
    $headline = '';
    if ($type === 'base_fee') {
        $headline = '每月 ' . pra_money($amount ?? 0);
        $details[] = !empty($p['no_prorate']) ? '不按考勤折算，按月固定发放' : '按考勤折算：请假 ≤ 4 天，金额 − 金额 ÷ 30 × 请假天数；请假 > 4 天，金额 ÷ 30 × 实际出勤天数';
        $details[] = '财务可在当月填写金额覆盖默认值（如每月不同的补单提成）';
    } elseif ($type === 'attendance_bonus') {
        $headline = '全勤奖 ' . pra_money($amount ?? 0) . ' / 月';
        $details[] = '默认不发，财务在“全勤奖审批”批准后按批准金额计入；考勤只作建议：请假 < 4 小时全额、≥ 4 小时减半、≥ 8 小时不发';
    } elseif ($type === 'overtime_pay') {
        $headline = '固定服务费 ÷ 30 × 延时服务天数 × 倍率';
        $details[] = '节假日当天（元旦、除夕、春节初一 / 初二、清明、5.1、端午、中秋、10.1）按 ' . rtrim(rtrim(number_format((float)($p['holiday_rate'] ?? 1.5), 2, '.', ''), '0'), '.') . ' 倍，其余日期按 ' . rtrim(rtrim(number_format((float)($p['normal_rate'] ?? 1), 2, '.', ''), '0'), '.') . ' 倍';
        $details[] = '延时服务天数以考勤表为准：“26+2”= 延时服务 2 天，节假日延时服务写“26+1(10.1)”';
    } elseif ($type === 'fixed') {
        $headline = '每月固定 ' . pra_money($amount ?? 0);
        if (!empty($p['separate'])) $details[] = '由关联公司另行支付，单列展示，不计入应结算金额';
    } elseif ($type === 'threshold_bonus') {
        $headline = '(月指标 − 门槛 ' . pra_money($p['threshold'] ?? 0) . ') × ' . pra_pct($p['rate'] ?? 0);
        $details[] = '只有超过门槛的部分才计奖金';
    } elseif ($type === 'ranking') {
        $a = array_map('pra_money', (array)($p['awards'] ?? []));
        $headline = '按月排名依次奖励 ' . implode(' / ', $a);
    } elseif ($type === 'tier_rate') {
        $tiers = []; foreach ((array)($p['tiers'] ?? []) as $t) $tiers[] = '月指标 ≥ ' . pra_money($t['from'] ?? 0) . ' → ' . pra_pct($t['rate'] ?? 0);
        $headline = $tiers ? implode('；', $tiers) : '阶梯比例';
        $details[] = '全部业绩统一按落到的那一档比例计算，补发或扣回与逐单比例的差额';
    } elseif ($type === 'dept_share') {
        $headline = '(' . (($p['base'] ?? '') === 'revenue' ? '部门收入' : '部门毛利') . ' − 扣除项) × ' . pra_pct($p['rate'] ?? 0) . ' × 分配比例 ' . pra_pct($p['share'] ?? 1);
    } elseif ($type === 'per_unit') {
        $headline = '每件 ' . pra_money($amount ?? 0); $details[] = '件数由财务当月在规则中心填写';
    } elseif ($type === 'order_count') {
        $headline = '每单 ' . pra_money($amount ?? 0);
    } else {
        $headline = (string)($m['note'] ?? '') !== '' ? (string)$m['note'] : (PRA_MONTHLY_TYPES[$type] ?? $type);
    }
    if ((string)($m['note'] ?? '') !== '' && $type !== 'manual' && !in_array($m['note'], [$headline], true)) $details[] = (string)$m['note'];
    return ['headline' => $headline, 'details' => $details, 'amount' => $amount];
}

/** 每月固定收入：固定服务费、全勤奖、固定补助（只取指定给本人的规则）。 */
function pra_my_fixed_income(array $actor)
{
    $eid = (int)($actor['employee_id'] ?? 0);
    if (!$eid) return [];
    try {
        $month = date('Y-m');
        $q = db()->prepare("SELECT * FROM project_monthly_rules WHERE is_active=1 AND employee_id=? AND rule_type IN ('base_fee','attendance_bonus','fixed') AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY FIELD(rule_type,'base_fee','attendance_bonus','fixed'),id");
        $q->execute([$eid, $month, $month]);
        $out = [];
        foreach ($q->fetchAll() as $m) {
            $m['type_label'] = PRA_MONTHLY_TYPES[$m['rule_type']] ?? $m['rule_type'];
            $m['text'] = pra_monthly_text($m);
            $out[] = $m;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/* ---------- 更正申请 ---------- */

function pra_actor_name(array $actor)
{
    if (!empty($actor['employee_id'])) {
        $q = db()->prepare('SELECT name FROM employees WHERE id=?'); $q->execute([(int)$actor['employee_id']]);
        $n = (string)$q->fetchColumn(); if ($n !== '') return $n;
    }
    return (string)($actor['username'] ?? '管理员');
}

function pra_number($v, $max, $label)
{
    if ($v === '' || $v === null) return null;
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > $max) throw new RuntimeException($label . '不正确。');
    return round((float)$v, 4);
}

function pra_submit(array $in, array $actor)
{
    pra_ensure();
    $detail = trim((string)($in['detail'] ?? ''));
    if (mb_strlen($detail) < 10) throw new RuntimeException('请写清楚哪里不对、你的算法是怎样的（至少 10 个字）。');
    if (mb_strlen($detail) > 3000) throw new RuntimeException('详细说明不能超过 3000 字。');
    $kind = (string)($in['rule_kind'] ?? 'order'); if (!in_array($kind, ['order', 'monthly', 'other'], true)) $kind = 'other';
    $ruleId = (int)($in['rule_id'] ?? 0); $business = mb_substr(trim((string)($in['business'] ?? '')), 0, 100);
    $title = ''; $snapshot = null;
    if ($kind === 'order' && $ruleId) {
        $q = db()->prepare('SELECT * FROM project_commission_rules WHERE id=?'); $q->execute([$ruleId]); $r = $q->fetch();
        if (!$r) throw new RuntimeException('这条规则不存在，可能已被更新，请刷新页面。');
        $business = $r['project_type']; $title = $business . ' · ' . PRA_GROUPS[$r['commission_group']] . ' · ' . ($r['order_kind'] === '*' ? '所有订单类型' : $r['order_kind']);
        $snapshot = $r;
    } elseif ($kind === 'monthly' && $ruleId) {
        $q = db()->prepare('SELECT * FROM project_monthly_rules WHERE id=?'); $q->execute([$ruleId]); $r = $q->fetch();
        if (!$r) throw new RuntimeException('这条月度规则不存在，请刷新页面。');
        $title = '月度规则 · ' . $r['name']; $snapshot = $r;
    } else {
        $kind = 'other'; $ruleId = 0; $title = ($business !== '' ? $business . ' · ' : '') . '规则缺失 / 其他';
    }
    $text = mb_substr(trim((string)($in['proposed_text'] ?? '')), 0, 500);
    $rate = pra_number($in['proposed_rate'] ?? '', 100, '建议比例');
    $fee = pra_number($in['proposed_fee_rate'] ?? '', 100, '建议服务费率');
    $subsidy = pra_number($in['proposed_subsidy'] ?? '', 99999999, '建议每单补助');
    $min = pra_number($in['proposed_min'] ?? '', 99999999, '建议起算售价');
    $dup = db()->prepare("SELECT 1 FROM project_rule_requests WHERE applicant_type=? AND applicant_id=? AND rule_kind=? AND rule_id<=>? AND business=? AND status='pending'");
    $dup->execute([$actor['type'], (int)$actor['id'], $kind, $ruleId ?: null, $business]);
    if ($dup->fetchColumn()) throw new RuntimeException('这一项你已提交过更正申请，请等待审核结果；需要补充内容可在下方“我的申请”里查看。');
    $cnt = db()->prepare('SELECT COUNT(*) FROM project_rule_requests WHERE applicant_type=? AND applicant_id=? AND created_at>=CURRENT_DATE');
    $cnt->execute([$actor['type'], (int)$actor['id']]);
    if ((int)$cnt->fetchColumn() >= 20) throw new RuntimeException('今天提交的申请较多，请明天再继续。');
    db()->prepare('INSERT INTO project_rule_requests (rule_kind,rule_id,business,rule_title,rule_snapshot,proposed_rate,proposed_fee_rate,proposed_subsidy,proposed_min,proposed_text,detail,applicant_type,applicant_id,applicant_employee_id,applicant_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$kind, $ruleId ?: null, $business, mb_substr($title, 0, 240), $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null, $rate, $fee, $subsidy, $min, $text, $detail, $actor['type'], (int)$actor['id'], $actor['employee_id'] ?? null, pra_actor_name($actor)]);
    $id = (int)db()->lastInsertId();
    ps_audit('rule_request', $id, 'submit', $actor, ['title' => $title, 'rate' => $rate, 'fee' => $fee, 'subsidy' => $subsidy, 'min' => $min]);
    return $id;
}

/** 为已提交的申请上传“订单算法示例”（图片 / PDF / Excel / Word，≤ 8MB），仅申请人本人、且仅一次。 */
function pra_attach(array $actor, $id, array $file)
{
    pra_ensure();
    $q = db()->prepare('SELECT * FROM project_rule_requests WHERE id=? AND applicant_type=? AND applicant_id=?');
    $q->execute([(int)$id, $actor['type'], (int)$actor['id']]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('申请不存在。');
    if ($row['example_stored'] !== '') throw new RuntimeException('这个申请已上传过示例文件。');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) < 1 || (int)$file['size'] > 8 * 1024 * 1024) throw new RuntimeException('示例文件须为不超过 8 MB 的图片、PDF、Excel 或 Word。');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('文件上传无效。');
    $head = (string)file_get_contents($tmp, false, null, 0, 8);
    $name = trim(str_replace(["\r", "\n", '/', '\\'], '', (string)($file['name'] ?? '示例')));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = ps_detect_file_mime($tmp); $store = null;
    if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'], true)) $store = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime];
    elseif (substr($head, 0, 4) === "PK\x03\x04" && in_array($ext, ['xlsx', 'docx'], true)) { $store = $ext; $mime = $ext === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; }
    elseif (substr($head, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" && in_array($ext, ['xls', 'doc'], true)) { $store = $ext; $mime = $ext === 'xls' ? 'application/vnd.ms-excel' : 'application/msword'; }
    if ($store === null) throw new RuntimeException('示例文件只支持 PNG、JPG、WebP、PDF、Excel（xls / xlsx）或 Word（doc / docx）。');
    $stored = bin2hex(random_bytes(20)) . '.' . $store;
    ps_private_store('rule_requests', $tmp, $stored);
    @unlink($tmp);
    db()->prepare('UPDATE project_rule_requests SET example_name=?,example_stored=?,example_mime=? WHERE id=?')->execute([mb_substr($name !== '' ? $name : '订单算法示例.' . $store, 0, 200), $stored, $mime, (int)$id]);
    ps_audit('rule_request', (int)$id, 'attach_example', $actor, ['mime' => $mime]);
}

function pra_request_visible(array $row, array $actor)
{
    return ($actor['role'] ?? '') === 'finance' || ($row['applicant_type'] === $actor['type'] && (int)$row['applicant_id'] === (int)$actor['id']);
}

function pra_my_requests(array $actor)
{
    pra_ensure();
    $q = db()->prepare('SELECT * FROM project_rule_requests WHERE applicant_type=? AND applicant_id=? ORDER BY id DESC LIMIT 30');
    $q->execute([$actor['type'], (int)$actor['id']]);
    return $q->fetchAll();
}

function pra_requests($status)
{
    pra_ensure();
    $where = in_array($status, ['pending', 'resolved', 'rejected'], true) ? 'status=' . db()->quote($status) : '1=1';
    return db()->query("SELECT * FROM project_rule_requests WHERE $where ORDER BY (status='pending') DESC, id DESC LIMIT 200")->fetchAll();
}

function pra_pending_count()
{
    try { pra_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_rule_requests WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function pra_handle($id, $decision, $note, array $actor)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅管理员或财务可以审核。');
    pra_ensure();
    if (!in_array($decision, ['resolved', 'rejected'], true)) throw new RuntimeException('处理结果无效。');
    $note = trim((string)$note);
    if ($decision === 'rejected' && $note === '') throw new RuntimeException('不采纳时请写明原因。');
    $q = db()->prepare("SELECT * FROM project_rule_requests WHERE id=? AND status='pending'"); $q->execute([(int)$id]); $row = $q->fetch();
    if (!$row) throw new RuntimeException('申请不存在或已处理。');
    $handler = pra_actor_name($actor);
    db()->prepare("UPDATE project_rule_requests SET status=?,handler_name=?,handle_note=?,handled_at=NOW() WHERE id=? AND status='pending'")->execute([$decision, $handler, $note, (int)$id]);
    ps_audit('rule_request', (int)$id, 'handle_' . $decision, $actor, ['note' => $note]);
    if (!empty($row['applicant_employee_id'])) {
        try {
            db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')->execute([
                (int)$row['applicant_employee_id'], 'rule_request', '你提交的分成算法更正已' . ($decision === 'resolved' ? '采纳' : '回复（未采纳）'),
                $row['rule_title'] . "\n处理回复：" . ($note !== '' ? $note : '已采纳，财务会在规则中心按新版本调整，之后新订单按新算法计算。') . '（处理人：' . $handler . '）',
                '/project/messages.php', 'rule_done:' . (int)$id]);
        } catch (Throwable $e) { /* 站内信表异常不影响审核 */ }
    }
}
