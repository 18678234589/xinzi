<?php
/**
 * 分成金额“计算过程”弹窗 + 分成更正申请。
 * - ps_explain_calc_steps()：把 ps_calc_person() 的结果拆成逐步公式（实时口径）。
 * - ps_explain_snapshot_steps()：已审核订单按审核时保存的快照还原。
 * - ps_corr_*：更正申请的建表、提交、查询、处理（财务/管理员在 project/corrections.php 处理）。
 * 以后改分成算法时，只需同步 ps_calc_person() 与这里的步骤文案。
 */

function ps_yuan($value)
{
    return '¥' . number_format((float)$value, 2, '.', ',');
}

function ps_pct($rate)
{
    return rtrim(rtrim(number_format((float)$rate * 100, 4, '.', ''), '0'), '.') . '%';
}

/** @return array<int,array{0:string,1:string,2:string}> [步骤名, 算式, 结果] */
function ps_explain_calc_steps(array $c, array $order)
{
    if (!empty($c['sub_calcs'])) {
        $rows = [];
        $rows[] = ['① 订单售价', '订单合同金额', ps_yuan($c['contract'] ?? 0)];
        $receipt = round((float)($order['receipt_amount'] ?? 0), 2);
        $refund = round((float)($order['refund_amount'] ?? 0), 2);
        if (!empty($c['income_estimated'])) {
            $rows[] = ['② 收入', '尚未录入收款，按 售价 ' . ps_yuan($c['contract'] ?? 0) . ' − 退款 ' . ps_yuan($refund) . ' 预估', ps_yuan($c['income'] ?? 0)];
        } else {
            $rows[] = ['② 收入（净实收）', '已收款 ' . ps_yuan($receipt) . ' − 退款 ' . ps_yuan($refund), ps_yuan($c['income'] ?? 0)];
        }
        $rows[] = ['③ 兼任岗位说明', '一人兼任多个技术岗位交付，分别按各岗位成本分摊与提成规则独立计提并汇总', '多岗位合并'];
        foreach ($c['sub_calcs'] as $sub) {
            $sRole = $sub['role'];
            $sc = $sub['calc'];
            $formula = '(收入 ' . ps_yuan($sc['income']) . ' − 成本 ' . ps_yuan($sc['cost_basis']) . ' − 服务费 ' . ps_yuan($sc['fee_part']) . ') × 比例 ' . ps_pct($sc['rate']);
            $rows[] = ['【' . $sRole . '】提成', $formula, ps_yuan($sc['share'])];
            if ($sc['subsidy'] > 0) {
                $rows[] = ['【' . $sRole . '】补助', '每单补助', ps_yuan($sc['subsidy'])];
            }
        }
        $rows[] = ['④ 预计合计', $c['note'], ps_yuan($c['share'] + $c['subsidy'])];
        return $rows;
    }
    $pool = $c['mode'] === 'pool';
    $w = (float)$c['weight'];
    $rows = [];
    $rows[] = ['① 订单售价', '订单合同金额', ps_yuan($c['contract'])];
    $receipt = round((float)($order['receipt_amount'] ?? 0), 2);
    $refund = round((float)($order['refund_amount'] ?? 0), 2);
    if (!empty($c['income_estimated'])) {
        $rows[] = ['② 收入', '尚未录入任何收款，按 售价 ' . ps_yuan($c['contract']) . ' − 退款 ' . ps_yuan($refund) . ' 预估', ps_yuan($c['income'])];
    } else {
        $rows[] = ['② 收入（净实收）', '已收款 ' . ps_yuan($receipt) . ' − 退款 ' . ps_yuan($refund), ps_yuan($c['income'])];
    }
    $feeText = '售价 ' . ps_yuan($c['contract']) . ' × 服务费率 ' . ps_pct($c['fee_rate']) . ' = ' . ps_yuan($c['fee']);
    if ($pool && $w < 1) $feeText .= '，再 × 组内权重 ' . ps_pct($w);
    $rows[] = ['③ 服务费', $feeText, ps_yuan($c['fee_part'])];
    if ($c['floor_applied']) {
        $costText = '实际成本 ' . ps_yuan($c['raw_cost']) . ' 低于 售价 × ' . ps_pct($c['min_cost_rate']) . ' = ' . ps_yuan($c['contract'] * $c['min_cost_rate']) . '，规则设有成本下限，按下限计';
    } else {
        $costText = '已审核的直接成本 ' . ps_yuan($c['raw_cost']) . ($c['min_cost_rate'] > 0 ? '（高于成本下限，按实际计）' : '');
    }
    if (!$pool && $w < 1) $costText .= '；独立计提按权重 ' . ps_pct($w) . ' 分摊';
    $rows[] = ['④ 计提成本', $costText, ps_yuan($c['cost_basis'])];
    $baseText = '收入 ' . ps_yuan($c['income']) . ' − 成本 ' . ps_yuan($c['cost_basis']) . ' − 服务费 ' . ps_yuan($c['fee_part']);
    $rows[] = ['⑤ 计提基数', $baseText, ps_yuan($c['base'])];
    if ($c['blocked']) {
        $rows[] = ['⑥ 分成', '售价低于规则最低售价 ' . ps_yuan($c['min_contract']) . '，本单不计分成也不计补助', ps_yuan(0)];
    } else {
        $baseUsed = $c['allow_negative'] ? $c['base'] : max($c['base'], 0);
        $shareText = ($c['base'] < 0 && !$c['allow_negative'] ? '基数为负，单笔最低按 0 计；' : '') . '基数 ' . ps_yuan($baseUsed) . ' × 比例 ' . ps_pct($c['rate']) . ($pool && $w < 1 ? ' × 组内权重 ' . ps_pct($w) : '');
        $rows[] = ['⑥ 提成', $shareText, ps_yuan($c['share'])];
        $subText = $c['subsidy'] > 0 ? '规则每单固定补助' . ($c['low_applied'] ? '（整单利润 ' . ps_yuan($c['order_profit']) . ' 低于 ' . ps_yuan($c['low_threshold']) . '，按低档）' : '') : '此规则无每单补助';
        $rows[] = ['⑦ 每单补助', $subText, ps_yuan($c['subsidy'])];
    }
    $rows[] = ['⑧ 预计合计', '提成 ' . ps_yuan($c['share']) . ' + 补助 ' . ps_yuan($c['subsidy']), ps_yuan($c['share'] + $c['subsidy'])];
    return $rows;
}

function ps_explain_snapshot_steps(array $s)
{
    $mode = $s['calc_mode'] === 'individual' ? 'individual' : 'pool';
    $rows = [];
    $rows[] = ['① 收入', '审核时的净实收', ps_yuan($s['income_amount'])];
    $rows[] = ['② 直接成本', '审核时已审核的成本', ps_yuan($s['direct_cost'])];
    $rows[] = ['③ 服务费', '按规则费率计算', ps_yuan($s['service_fee'])];
    $rows[] = ['④ 比例 / 权重', ($mode === 'individual' ? '个人独立计提' : '组池分摊') . ' · 比例 ' . ps_pct($s['rate']) . ' · 组内权重 ' . ps_pct($s['group_weight']), ps_pct($s['rate'])];
    $rows[] = ['⑤ 计算式', (string)$s['calc_note'], ''];
    $rows[] = ['⑥ 提成', '按上式计算，分到分位后归集尾差', ps_yuan($s['commission_amount'])];
    $rows[] = ['⑦ 每单补助', '规则每单固定补助', ps_yuan($s['subsidy_amount'])];
    $rows[] = ['⑧ 最终核算金额', '提成 + 补助（已入 ' . ($s['payroll_month'] ?: '结算月') . ' 报酬）', ps_yuan($s['commission_amount'] + $s['subsidy_amount'])];
    return $rows;
}

function ps_explain_table(array $rows)
{
    $html = '<table class="table table-sm table-bordered mb-3"><thead class="thead-light"><tr><th style="width:26%">步骤</th><th>算式</th><th class="text-right text-nowrap" style="width:18%">结果</th></tr></thead><tbody>';
    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $r) {
        $last = $i === $lastIndex;
        $html .= '<tr' . ($last ? ' class="table-success font-weight-bold"' : '') . '><td class="text-nowrap">' . e($r[0]) . '</td><td class="small">' . e($r[1]) . '</td><td class="text-right text-nowrap">' . e($r[2]) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

/**
 * 一位参与人的弹窗（含“申请更正”表单与历史申请）。
 * $amountEstimatedCalc 有值且与当前不同时，同一弹窗里并列显示“预估”一版。
 */
/** 弹窗内容（header + body + footer）；订单页整块渲染，列表页由 calc_modal.php 按需返回。 */
function ps_render_calc_content($modalId, array $person, array $order, $calc, $estimated, $snapshot, $orderId, array $corrections, $canSubmit)
{
    $name = $person['name'] . ' · ' . ps_label('group', $person['commission_group']) . ' · ' . ($person['role_name'] ?: '—');
    $html = '<div class="modal-header"><h5 class="modal-title">分成计算过程 · ' . e($name) . '</h5><button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button></div><div class="modal-body">';
    $html .= '<p class="small text-muted mb-2">订单 ' . e($order['order_no']) . ' · ' . e($order['project_type']) . ($order['order_kind'] !== '' ? ' · ' . e($order['order_kind']) : '') . ' · 订单日期 ' . e($order['order_date']) . '</p>';
    if ($snapshot) {
        $html .= '<h6>已审核金额（订单审核时保存的快照）</h6>' . ps_explain_table(ps_explain_snapshot_steps($snapshot));
    } elseif ($calc) {
        $html .= '<h6>' . (!empty($calc['income_estimated']) ? '当前口径（按已收款，尚未收款所以是 0）' : '当前口径（已收款 + 已审核成本）') . '</h6>' . ps_explain_table(ps_explain_calc_steps($calc, $order));
        if ($estimated && (abs($estimated['share'] - $calc['share']) > 0.004 || abs($estimated['income'] - $calc['income']) > 0.004)) {
            $html .= '<h6>' . (!empty($estimated['income_estimated']) ? '预估口径（收入按售价）' : '预估口径（待审成本全部通过后）') . '</h6>' . ps_explain_table(ps_explain_calc_steps($estimated, $order));
        }
        if (!empty($person['rule']['note'])) $html .= '<p class="small text-muted mb-2">匹配规则：' . e($person['rule']['note']) . '（规则 #' . (int)$person['rule']['id'] . '）</p>';
    } else {
        $html .= '<p class="text-danger">未匹配到分成规则，无法计算。</p>';
    }
    $html .= '<p class="small text-muted">最终以订单审核时的核算为准；审核前收款、成本、参与人变化都会改变金额。</p>';

    $mine = array_values(array_filter($corrections, function ($row) use ($person) { return (int)$row['target_employee_id'] === (int)$person['employee_id'] && $row['target_group'] === $person['commission_group']; }));
    if ($mine) {
        $html .= '<hr><h6>更正申请记录</h6><ul class="list-unstyled small">';
        foreach ($mine as $row) {
            $label = ['pending' => '<span class="badge badge-warning">待处理</span>', 'resolved' => '<span class="badge badge-success">已处理</span>', 'rejected' => '<span class="badge badge-secondary">未采纳</span>'][$row['status']] ?? '';
            $html .= '<li class="mb-1">' . $label . ' ' . e(substr($row['created_at'], 0, 16)) . ' ' . e($row['applicant_name']) . '：' . e($row['reason']) . ($row['expected_amount'] !== null ? '（认为应为 ' . e(ps_yuan($row['expected_amount'])) . '）' : '') . ($row['handle_note'] !== null && $row['handle_note'] !== '' ? '<div class="text-muted pl-3">处理回复：' . e($row['handle_note']) . '</div>' : '') . '</li>';
        }
        $html .= '</ul>';
    }
    if ($canSubmit) {
        $formId = $modalId . '-form';
        $html .= '<hr><button type="button" class="btn btn-outline-warning btn-sm" data-toggle="collapse" data-target="#' . e($formId) . '"><i class="fas fa-flag mr-1"></i>金额有误？申请更正</button>';
        $html .= '<form method="post" action="' . e(BASE_URL . '/project/order.php?id=' . (int)$orderId) . '" class="collapse mt-3" id="' . e($formId) . '"><input type="hidden" name="csrf" value="' . e(ps_csrf_token()) . '"><input type="hidden" name="action" value="submit_commission_correction"><input type="hidden" name="target_employee_id" value="' . (int)$person['employee_id'] . '"><input type="hidden" name="target_group" value="' . e($person['commission_group']) . '">';
        $html .= '<div class="form-group"><label>更正理由（必填，请写清哪里算得不对）</label><textarea name="reason" class="form-control" rows="3" maxlength="1000" required minlength="5" placeholder="例如：这单我实际是两人合接，权重应是 50%；或成本里的服务器费用已由客户承担"></textarea></div>';
        $html .= '<div class="form-group"><label>你认为正确的金额（选填）</label><input name="expected_amount" type="number" step="0.01" min="0" class="form-control" placeholder="元"></div>';
        $html .= '<button class="btn btn-warning btn-sm">提交给财务/管理员</button> <small class="text-muted">提交后财务和管理员会在“更正申请”里收到，处理结果会回复到站内信。</small></form>';
    }
    return $html . '</div><div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">关闭</button></div>';
}

function ps_render_calc_modal($modalId, array $person, array $order, $calc, $estimated, $snapshot, $orderId, array $corrections, $canSubmit)
{
    return '<div class="modal fade calc-modal" id="' . e($modalId) . '" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable" role="document"><div class="modal-content">'
        . ps_render_calc_content($modalId, $person, $order, $calc, $estimated, $snapshot, $orderId, $corrections, $canSubmit) . '</div></div></div>';
}

/* ---------- 更正申请 ---------- */

function ps_corr_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_commission_corrections (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      target_employee_id INT NOT NULL,
      target_group VARCHAR(20) NOT NULL,
      applicant_type VARCHAR(20) NOT NULL,
      applicant_id INT NOT NULL,
      applicant_employee_id INT NULL,
      applicant_name VARCHAR(80) NOT NULL,
      reason TEXT NOT NULL,
      expected_amount DECIMAL(12,2) NULL,
      shown_amount DECIMAL(12,2) NULL,
      snapshot_json MEDIUMTEXT NULL,
      status ENUM('pending','resolved','rejected') NOT NULL DEFAULT 'pending',
      handler_name VARCHAR(80) NULL,
      handle_note TEXT NULL,
      handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_corr_status (status, created_at),
      KEY idx_corr_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function ps_corr_actor_name($actor)
{
    if (!empty($actor['employee_id'])) {
        $q = db()->prepare('SELECT name FROM employees WHERE id=?');
        $q->execute([(int)$actor['employee_id']]);
        $name = $q->fetchColumn();
        if ($name) return (string)$name;
    }
    return (string)($actor['username'] ?? ($actor['type'] === 'admin' ? '管理员' : ''));
}

function ps_corr_submit($orderId, $actor, $targetEmployeeId, $group, $reason, $expected, $shownAmount, array $detail)
{
    ps_corr_ensure();
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 5) throw new RuntimeException('请写清更正理由（至少 5 个字）');
    if (!in_array($group, ['technical', 'customer_service'], true)) throw new RuntimeException('分成组无效');
    $part = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? AND commission_group=? LIMIT 1');
    $part->execute([(int)$orderId, (int)$targetEmployeeId, $group]);
    if (!$part->fetchColumn()) throw new RuntimeException('该合作人员不在此订单的对应分成组');
    if (($actor['role'] ?? '') !== 'finance' && (int)$actor['employee_id'] !== (int)$targetEmployeeId) {
        $mine = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
        $mine->execute([(int)$orderId, (int)$actor['employee_id']]);
        if (!$mine->fetchColumn()) throw new RuntimeException('你不是此订单的参与人，不能提交更正');
    }
    $dup = db()->prepare("SELECT 1 FROM project_commission_corrections WHERE order_id=? AND target_employee_id=? AND target_group=? AND status='pending' LIMIT 1");
    $dup->execute([(int)$orderId, (int)$targetEmployeeId, $group]);
    if ($dup->fetchColumn()) throw new RuntimeException('这一项已有待处理的更正申请，请等待财务回复');
    $expected = ($expected === '' || $expected === null) ? null : round((float)$expected, 2);
    db()->prepare('INSERT INTO project_commission_corrections (order_id,target_employee_id,target_group,applicant_type,applicant_id,applicant_employee_id,applicant_name,reason,expected_amount,shown_amount,snapshot_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$orderId, (int)$targetEmployeeId, $group, $actor['type'], (int)$actor['id'], $actor['employee_id'] ?? null, ps_corr_actor_name($actor), $reason, $expected, $shownAmount, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    ps_audit('order', (int)$orderId, 'commission_correction_submit', $actor, ['target_employee_id' => (int)$targetEmployeeId, 'group' => $group, 'reason' => $reason]);
}

function ps_corr_for_order($orderId)
{
    ps_corr_ensure();
    $q = db()->prepare('SELECT * FROM project_commission_corrections WHERE order_id=? ORDER BY id DESC');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

function ps_corr_pending_count()
{
    try {
        ps_corr_ensure();
        return (int)db()->query("SELECT COUNT(*) FROM project_commission_corrections WHERE status='pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function ps_corr_handle($id, $actor, $decision, $note)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务或管理员可处理更正申请');
    if (!in_array($decision, ['resolved', 'rejected'], true)) throw new RuntimeException('处理结果无效');
    ps_corr_ensure();
    $note = trim((string)$note);
    if ($decision === 'rejected' && $note === '') throw new RuntimeException('不采纳时请写明原因');
    $q = db()->prepare("SELECT * FROM project_commission_corrections WHERE id=? AND status='pending'");
    $q->execute([(int)$id]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('申请不存在或已处理');
    db()->prepare('UPDATE project_commission_corrections SET status=?, handler_name=?, handle_note=?, handled_at=NOW() WHERE id=?')
        ->execute([$decision, ps_corr_actor_name($actor), $note, (int)$id]);
    ps_audit('order', (int)$row['order_id'], 'commission_correction_' . $decision, $actor, ['correction_id' => (int)$id, 'note' => $note]);
    if (!empty($row['applicant_employee_id'])) {
        try {
            db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')->execute([
                (int)$row['applicant_employee_id'], 'commission_correction',
                '你提交的分成更正申请已' . ($decision === 'resolved' ? '处理' : '回复（未采纳）'),
                '订单 #' . (int)$row['order_id'] . "：\n处理回复：" . ($note !== '' ? $note : '已按你的理由调整，请刷新订单查看。'),
                '/project/order.php?id=' . (int)$row['order_id'], 'corr_done:' . (int)$id,
            ]);
        } catch (Throwable $e) {
            // 站内信表未迁移时不影响处理结果
        }
    }
}
