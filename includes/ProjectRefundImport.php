<?php
require_once __DIR__ . '/ProjectIntake.php';
require_once __DIR__ . '/ProjectBusiness.php';
require_once __DIR__ . '/ProjectAiFallback.php';

function ps_refund_website_business($business)
{
    return in_array(ps_business_normalize($business), ['网站模板', 'AI网站定制', '网站续费', '网站修改'], true);
}

function ps_refund_after_sales($actor)
{
    if (($actor['role'] ?? '') === 'finance') return true;
    $q = db()->prepare('SELECT department FROM employees WHERE id=?');
    $q->execute([(int)($actor['employee_id'] ?? 0)]);
    return in_array((string)$q->fetchColumn(), ['售后退款部', '综合售后部'], true);
}

function ps_refund_order_access($actor, $orderId)
{
    if (ps_refund_after_sales($actor)) return true;
    $q = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
    $q->execute([(int)$orderId, (int)($actor['employee_id'] ?? 0)]);
    return (bool)$q->fetchColumn();
}

function ps_refund_columns()
{
    return [
        'order_no' => ['订单编号', '订单号', '交易订单号', '原订单号', '商户订单号', '店铺订单号'],
        'refund_date' => ['退款日期', '退款时间', '支付宝退款时间', '日期'],
        'amount' => ['退款金额', '实际退款金额', '支付宝退款金额', '退款金额（元）', '金额'],
        'reference' => ['支付宝退款流水号', '支付宝流水号', '退款流水号', '退款单号', '退款编号', '流水号'],
        'reason' => ['退款原因', '原因', '备注', '退款说明'],
        'method' => ['退款方式', '退款渠道', '支付方式', '渠道', '退款类型'],
        'business' => ['店铺+业务', '业务类型', '业务'],
    ];
}

function ps_refund_header_map(array $head)
{
    $normalize = function ($s) { return preg_replace('/[\s\x{3000}：:()（）]+/u', '', mb_strtolower(trim((string)$s))); };
    $map = [];
    foreach (ps_refund_columns() as $key => $aliases) {
        foreach ($head as $i => $label) {
            if (in_array($normalize($label), array_map($normalize, $aliases), true)) { $map[$key] = $i; break; }
        }
    }
    return isset($map['order_no'], $map['amount']) ? $map : null;
}

function ps_refund_amount($value)
{
    $text = str_replace([',', '，', '¥', '￥', ' '], '', trim((string)$value));
    if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $text) || (float)$text <= 0 || (float)$text > 999999999999.99) return null;
    return number_format((float)$text, 2, '.', '');
}

/** 表格/手录共用核对：不创建订单，不把退款当作负数售价。 */
function ps_refund_preview_row(array $input, $fingerprint, $actor, $fileId = null, $sheet = '', $line = 0)
{
    $row = ['order_no' => trim((string)($input['order_no'] ?? '')), 'refund_date' => ps_import_date($input['refund_date'] ?? ''),
        'amount' => ps_refund_amount($input['amount'] ?? ''), 'reference' => trim((string)($input['reference'] ?? '')),
        'reason' => trim((string)($input['reason'] ?? '')), 'method' => trim((string)($input['method'] ?? '')),
        'fingerprint' => $fingerprint, 'file_id' => $fileId, 'sheet' => $sheet, 'line' => $line, 'status' => '可登记', 'error' => '', 'order_id' => 0, 'business' => ''];
    if ($row['method'] === '') $row['method'] = '支付宝';
    if ($row['order_no'] === '' || !$row['refund_date'] || $row['amount'] === null) $row['error'] = '请核对订单号、退款日期和正数退款金额（最多两位小数）';
    elseif (mb_strpos($row['method'], '支付宝') === false) $row['error'] = '退款类型不是“支付宝”，请先在原表核对实际退款渠道';
    elseif (trim((string)($input['business'] ?? '')) !== '' && mb_strpos((string)$input['business'], '网站') === false) $row['error'] = '原表标记为非网站业务，已排除';
    elseif (mb_strlen($row['reference']) > 150 || mb_strlen($row['reason']) > 240) $row['error'] = '流水号或原因过长';
    else {
        $q = db()->prepare('SELECT id,project_type,settlement_status,receipt_amount,refund_amount FROM project_orders WHERE order_no=? LIMIT 1');
        $q->execute([$row['order_no']]);
        $order = $q->fetch();
        if (!$order) $row['error'] = '系统中没有对应项目订单，请先关联或建单；不会自动创建退款订单';
        elseif (!ps_refund_website_business($order['project_type'])) $row['error'] = '订单不属于网站业务，请核对订单号';
        elseif (!ps_refund_order_access($actor, (int)$order['id'])) $row['error'] = '不是本人参与的订单，不能提交该行；售后退款部或财务可统一上传';
        elseif (($actor['role'] ?? '') === 'finance' && (float)$row['amount'] > round((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2)) $row['error'] = '退款超过已审核实收的可退余额，请先核对实收/已退款';
        else { $row['order_id'] = (int)$order['id']; $row['business'] = $order['project_type']; $row['settlement_status'] = $order['settlement_status']; $row['available'] = number_format((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2, '.', ''); }
        if (!$row['error']) {
            $q = db()->prepare('SELECT id FROM project_refund_import_rows WHERE fingerprint=?');
            $q->execute([$fingerprint]);
            if ($q->fetchColumn()) $row['error'] = '这笔支付宝退款已提交过；如被驳回请联系财务复核，不会重复扣回';
        }
    }
    if ($row['error']) $row['status'] = '需处理';
    return $row;
}

function ps_refund_parse_file($fileRow, $actor)
{
    $rows = []; $reports = []; $fileHash = hash('sha256', $fileRow['content']);
    foreach (ps_import_file_sheets($fileRow) as $sheet => $grid) {
        if (!$grid) continue;
        $map = null; $headerAt = -1; $aiNote = '';
        foreach (array_slice($grid, 0, 12) as $i => $head) if ($map = ps_refund_header_map((array)$head)) { $headerAt = $i; break; }
        if (!$map) {
            $head = (array)($grid[0] ?? []);
            $map = ps_ai_import_columns('网站支付宝退款', $head, array_slice($grid, 1, 5), ps_refund_columns(), $actor, $aiNote);
            if ($map && isset($map['amount'])) $headerAt = 0; else $map = null;
        }
        if (!$map) { $reports[$sheet] = '未识别订单号/退款金额列，已跳过'; continue; }
        $reports[$sheet] = ($aiNote ? $aiNote . '；' : '') . '已识别 ' . count($grid) . ' 行';
        for ($i = $headerAt + 1; $i < count($grid); $i++) {
            if (count($rows) >= 1500) throw new RuntimeException('退款表超过 1500 行，请拆分上传');
            $cells = (array)$grid[$i];
            if (!array_filter($cells, function ($v) { return trim((string)$v) !== ''; })) continue;
            $input = [];
            foreach ($map as $key => $col) $input[$key] = $cells[$col] ?? '';
            if (trim((string)($input['order_no'] ?? '')) === '' && trim((string)($input['amount'] ?? '')) === '') continue;
            $ref = trim((string)($input['reference'] ?? ''));
            $fingerprint = $ref !== ''
                ? hash('sha256', 'alipay-ref|' . trim((string)($input['order_no'] ?? '')) . '|' . $ref)
                : hash('sha256', 'alipay-file|' . $fileHash . '|' . $sheet . '|' . ($i + 1));
            $rows[] = ps_refund_preview_row($input, $fingerprint, $actor, (int)$fileRow['id'], (string)$sheet, $i + 1);
        }
    }
    if (!$rows) throw new RuntimeException('没有可核对的支付宝退款行。' . implode('；', $reports));
    return [$rows, $reports];
}

function ps_refund_commit_rows(array $rows, array $selected, $actor, $month)
{
    $finance = ($actor['role'] ?? '') === 'finance';
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('请选择调整计入月份');
    $pdo = db(); $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_alipay_refunds'); else $pdo->beginTransaction();
    $done = 0;
    try {
        foreach ($rows as $i => $row) {
            if (!in_array((string)$i, $selected, true)) continue;
            if ($row['error']) throw new RuntimeException('第 ' . ($row['line'] ?: $i + 1) . ' 行未通过核对，请取消勾选');
            $q = $pdo->prepare('SELECT id,project_type,settlement_status,receipt_amount,refund_amount FROM project_orders WHERE id=? FOR UPDATE');
            $q->execute([(int)$row['order_id']]); $order = $q->fetch();
            if (!$order || !ps_refund_website_business($order['project_type']) || !ps_refund_order_access($actor, (int)$row['order_id'])) throw new RuntimeException('订单状态或权限已变更，请重新预览');
            if ($finance && (float)$row['amount'] > round((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2)) throw new RuntimeException('订单 ' . $row['order_no'] . ' 的可退余额已变化，请重新预览');
            $q = $pdo->prepare('SELECT id FROM project_refund_import_rows WHERE fingerprint=? FOR UPDATE');
            $q->execute([$row['fingerprint']]);
            if ($q->fetchColumn()) throw new RuntimeException('订单 ' . $row['order_no'] . ' 的退款已登记，请重新预览');
            $pdo->prepare('INSERT INTO project_refund_import_rows (fingerprint,order_id,order_no,refund_date,amount,payment_method,payment_reference,reason,import_file_id,source_sheet,source_row,review_status,submitted_by_type,submitted_by_id,submitted_employee_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$row['fingerprint'], $row['order_id'], $row['order_no'], $row['refund_date'], $row['amount'], '支付宝', $row['reference'], $row['reason'], $row['file_id'], $row['sheet'], $row['line'], 'pending', $actor['type'], $actor['id'], $actor['employee_id'] ?? null]);
            $refundId = (int)$pdo->lastInsertId();
            ps_audit('refund_import', $refundId, 'submit', $actor, ['order_id' => $row['order_id'], 'amount' => $row['amount'], 'file_id' => $row['file_id'], 'sheet' => $row['sheet'], 'line' => $row['line']]);
            if ($finance) ps_refund_review($refundId, 'approved', $actor, $month, true);
            $done++;
        }
        if (!$done) throw new RuntimeException('请勾选至少一笔已通过核对的退款');
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_alipay_refunds'); else $pdo->commit();
        return $done;
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_alipay_refunds');
        elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** 财务审核时才真正记入退款和分成调整；售后/客服/技术上传本身不影响项目报酬。 */
function ps_refund_review($id, $decision, $actor, $month, $nested = false)
{
    if (($actor['role'] ?? '') !== 'finance' || !in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('仅财务可审核退款');
    $pdo = db();
    if (!$nested) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_refund_import_rows WHERE id=? FOR UPDATE');
        $q->execute([(int)$id]); $row = $q->fetch();
        if (!$row || $row['review_status'] !== 'pending') throw new RuntimeException('退款记录已处理，请刷新');
        if ($decision === 'approved') {
            $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
            $q->execute([(int)$row['order_id']]); $order = $q->fetch();
            if (!$order || !ps_refund_website_business($order['project_type'])) throw new RuntimeException('原项目订单不存在或业务不符');
            if ((float)$row['amount'] > round((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2)) throw new RuntimeException('退款超过已审核实收的可退余额，请先核对实收/已退款');
            $reason = mb_substr('支付宝线下退款 ' . $row['refund_date'] . ($row['payment_reference'] !== '' ? ' 流水号 ' . $row['payment_reference'] : '') . ($row['reason'] !== '' ? '；' . $row['reason'] : ''), 0, 300);
            if (in_array($order['settlement_status'], ['approved', 'locked'], true)) ps_post_adjustment((int)$row['order_id'], $actor, $row['amount'], 0, $reason, $month);
            else {
                $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'refund',?,?,'approved',?,?,?,NOW())")
                    ->execute([(int)$row['order_id'], $row['amount'], $reason, $actor['type'], $actor['id'], $actor['id']]);
                ps_recalculate_cash((int)$row['order_id']);
            }
        }
        $pdo->prepare('UPDATE project_refund_import_rows SET review_status=?,reviewed_by_admin=?,reviewed_at=NOW() WHERE id=?')->execute([$decision, $actor['id'], (int)$id]);
        ps_audit('refund_import', (int)$id, 'review_' . $decision, $actor, ['order_id' => $row['order_id'], 'amount' => $row['amount'], 'month' => $month]);
        if (!$nested) $pdo->commit();
    } catch (Throwable $e) { if (!$nested && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
