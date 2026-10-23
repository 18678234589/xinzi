<?php
/** 订单登记金额与已核算报酬分开显示；使用 EXISTS 避免多人参与造成订单/金额重复累加。 */
function ps_payroll_order_summary($employeeId, $month, $pdo = null)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', (string)$month)) throw new InvalidArgumentException('月份无效');
    $start = $month . '-01'; $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
    $params = [$start, $end, $start, $start, $end];
    $where = '((o.order_date>=? AND o.order_date<?) OR (o.order_date<? AND o.created_at>=? AND o.created_at<?))';
    if ((int)$employeeId > 0) { $where .= ' AND EXISTS (SELECT 1 FROM project_participants p WHERE p.order_id=o.id AND p.employee_id=?)'; $params[] = (int)$employeeId; }
    $pdo = $pdo ?: db();
    $q = $pdo->prepare('SELECT o.id,o.project_type,o.delivery_status,o.contract_amount,o.receipt_amount,o.refund_amount,o.settlement_status,a.state,a.reasons_json,d.details_json FROM project_orders o LEFT JOIN project_auto_reviews a ON a.order_id=o.id LEFT JOIN project_order_details d ON d.order_id=o.id WHERE ' . $where . ' ORDER BY o.project_type,o.id');
    $q->execute($params);
    $out = ['count'=>0,'contract'=>0.0,'received'=>0.0,'refunded'=>0.0,'pending'=>0,'businesses'=>[]];
    foreach ($q->fetchAll() as $row) {
        $type = $row['project_type'];
        if (!isset($out['businesses'][$type])) $out['businesses'][$type] = ['project_type'=>$type,'count'=>0,'contract'=>0.0,'received'=>0.0,'refunded'=>0.0,'pending'=>0,'reasons'=>[],'pieces'=>0.0,'pieces_settled'=>0.0,'pieces_shipped'=>0.0,'pieces_unshipped'=>0.0,'orders_shipped'=>0,'orders_unshipped'=>0];
        $b = &$out['businesses'][$type]; $b['count']++; $out['count']++;
        if ($type === '商标') { // 商标件数（原表“商标个数”，留空不计）：已核算 / 待核算分开
            $tm = json_decode((string)($row['details_json'] ?? ''), true);
            if (is_array($tm) && isset($tm['trademark_count']) && is_numeric($tm['trademark_count']) && (float)$tm['trademark_count'] > 0) {
                $b['pieces'] += (float)$tm['trademark_count'];
                // 只有到账且已发货的订单才结算；未发货的不结算，已发货但还没到账的等到账确认后结算。
                if (in_array($row['settlement_status'], ['approved', 'locked'], true)) $b['pieces_settled'] += (float)$tm['trademark_count'];
                elseif ($row['delivery_status'] === 'finished') $b['pieces_shipped'] += (float)$tm['trademark_count'];
                else $b['pieces_unshipped'] += (float)$tm['trademark_count'];
            }
        }
        foreach (['contract'=>'contract_amount','received'=>'receipt_amount','refunded'=>'refund_amount'] as $key=>$field) { $b[$key] += (float)$row[$field]; $out[$key] += (float)$row[$field]; }
        if ($row['settlement_status'] === 'draft' || $row['settlement_status'] === 'review') {
            $b['pending']++; $out['pending']++;
            if ($row['delivery_status'] === 'finished') $b['orders_shipped']++; else $b['orders_unshipped']++;
            $reasons = json_decode((string)($row['reasons_json'] ?? ''), true) ?: [];
            if (!$reasons) $reasons = [['code'=>'unchecked','text'=>'等待系统或财务核对']];
            $seen = [];
            foreach ($reasons as $reason) {
                $code = (string)($reason['code'] ?? 'unchecked');
                if (isset($seen[$code])) continue; $seen[$code] = true;
                if (!isset($b['reasons'][$code])) $b['reasons'][$code] = ['text'=>(string)($reason['text'] ?? '等待核对'),'count'=>0];
                $b['reasons'][$code]['count']++;
            }
        }
        unset($b);
    }
    $out['piece'] = null;
    if ((int)$employeeId > 0 && !empty($out['businesses']['商标']['pieces']) && function_exists('ps_rules_for_person')) {
        // 商标资料专员 / 提交专员按件计酬：每件单价取规则中心的“每单补助”，件数乘以单价就是预计报酬。
        try {
            $roles = $pdo->prepare("SELECT role_name FROM project_employee_roles WHERE employee_id=? AND business_name='商标' AND commission_group='technical'");
            $roles->execute([(int)$employeeId]);
            foreach ($roles->fetchAll(PDO::FETCH_COLUMN) as $role) {
                $rule = ps_rules_for_person('technical', '商标', $start, $role, '')[0] ?? null;
                if ($rule && (float)$rule['per_order_subsidy'] > 0 && (float)$rule['rate'] == 0.0) {
                    $b = $out['businesses']['商标']; $unit = (float)$rule['per_order_subsidy'];
                    $out['piece'] = ['role' => $role, 'unit' => $unit, 'pieces' => $b['pieces'], 'settled' => $b['pieces_settled'], 'shipped' => $b['pieces_shipped'], 'unshipped' => $b['pieces_unshipped'],
                        'settled_amount' => round($b['pieces_settled'] * $unit, 2), 'shipped_amount' => round($b['pieces_shipped'] * $unit, 2)];
                    break;
                }
            }
        } catch (Throwable $e) { $out['piece'] = null; }
    }
    return $out;
}

function ps_payroll_pending_reason_text($summary, $business = null)
{
    $reasons = [];
    foreach ($summary['businesses'] as $type=>$b) {
        if ($business !== null && $business !== $type) continue;
        foreach ($b['reasons'] as $code=>$r) { if (!isset($reasons[$code])) $reasons[$code] = $r; else $reasons[$code]['count'] += $r['count']; }
    }
    $parts = [];
    foreach ($reasons as $r) $parts[] = $r['text'] . '（' . (int)$r['count'] . ' 单）';
    return ($parts ? implode('；', $parts) : '等待系统或财务核对') . '。同单可能有多项待核对原因；未核算的报酬暂不计入应结算合计，营业额仍完整列出。';
}

function ps_payroll_pieces_text($n)
{
    return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.') ?: '0';
}

function ps_payroll_order_summary_render($s)
{
    ?>
    <details class="card mb-3 pp-fold"><summary class="card-header">本期订单与登记金额 <small>点击展开</small></summary><div class="card-body">
    <p class="text-muted small">按订单号和已登记网站项目去重；同单附加项并入订单，重复上传不增加单量。以下是订单登记金额，应结算合计是已核算报酬，两者分别显示。跨月旧单仅纳入本期实际补录的订单。</p>
    <div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>业务</th><th>系统订单数</th><th>登记售价</th><th>登记实收</th><th>退款</th><th>待核算订单数</th><th>商标件数</th></tr></thead><tbody>
    <?php foreach ($s['businesses'] as $b): ?><tr><td><?php echo e($b['project_type']); ?></td><td><?php echo (int)$b['count']; ?></td><td>¥<?php echo money($b['contract']); ?></td><td>¥<?php echo money($b['received']); ?></td><td>¥<?php echo money($b['refunded']); ?></td><td><?php echo (int)$b['pending']; ?><?php if ($b['project_type'] === '商标' && $b['pending']): ?><small class="text-muted d-block">已发货待到账 <?php echo (int)$b['orders_shipped']; ?> · 未发货 <?php echo (int)$b['orders_unshipped']; ?>（不核算）</small><?php endif; ?></td><td><?php if ($b['project_type'] === '商标'): echo ps_payroll_pieces_text($b['pieces']); ?> 件<small class="text-muted d-block">已结算 <?php echo ps_payroll_pieces_text($b['pieces_settled']); ?> · 已发货待到账 <?php echo ps_payroll_pieces_text($b['pieces_shipped']); ?> · 未发货 <?php echo ps_payroll_pieces_text($b['pieces_unshipped']); ?></small><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
    <tr class="font-weight-bold"><td>合计</td><td><?php echo (int)$s['count']; ?></td><td>¥<?php echo money($s['contract']); ?></td><td>¥<?php echo money($s['received']); ?></td><td>¥<?php echo money($s['refunded']); ?></td><td><?php echo (int)$s['pending']; ?></td><td></td></tr>
    </tbody></table></div>
    <?php if (!empty($s['piece'])): $pc = $s['piece']; ?><div class="alert alert-info mt-3 mb-0"><strong>商标<?php echo e($pc['role']); ?>按件计酬</strong>：每件 ¥<?php echo money($pc['unit']); ?>，<strong>订单到账并发货后才结算</strong>，未发货的订单不结算。本期商标共 <?php echo ps_payroll_pieces_text($pc['pieces']); ?> 件：<br>① 已结算 <?php echo ps_payroll_pieces_text($pc['settled']); ?> 件，<strong>¥<?php echo money($pc['settled_amount']); ?></strong>（已计入应结算合计）；<br>② 已发货、等收款确认 <?php echo ps_payroll_pieces_text($pc['shipped']); ?> 件，到账确认后可结算 ¥<?php echo money($pc['shipped_amount']); ?>；<br>③ 未发货 <?php echo ps_payroll_pieces_text($pc['unshipped']); ?> 件，<strong>不结算</strong>，发货确认后再算。</div><?php endif; ?></div></details>
    <?php
}
