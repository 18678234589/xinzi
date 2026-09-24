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

function ps_refund_can_view_order($actor, $orderId)
{
    if (($actor['role'] ?? '') === 'finance') return true;
    $q = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
    $q->execute([(int)$orderId, (int)($actor['employee_id'] ?? 0)]);
    return (bool)$q->fetchColumn();
}

function ps_refund_columns()
{
    return [
        'order_no' => ['订单编号', '订单号', '交易订单号', '原订单号', '商户订单号', '店铺订单号'],
        'source_reference' => ['原支付流水号', '原交易流水号', '原微信交易流水号', '微信交易流水号', '微信支付订单号', '淘宝交易号', '原支付宝交易号', '原银行卡流水号', '收款流水号'],
        'refund_date' => ['退款日期', '退款时间', '支付宝退款时间', '返现日期', '返款日期', '日期'],
        'amount' => ['退款金额', '实际退款金额', '支付宝退款金额', '退款金额（元）', '返现金额', '返款金额', '金额'],
        'reference' => ['支付宝退款流水号', '支付宝流水号', '微信退款流水号', '银行卡退款流水号', '退款交易流水号', '退款流水号', '退款单号', '退款编号', '流水号'],
        'reason' => ['退款原因', '原因', '备注', '退款说明', '客服记录'],
        'method' => ['退款方式', '退款渠道', '返现方式', '返款方式', '支付方式', '渠道', '退款类型'],
        'business' => ['店铺+业务', '业务类型', '业务'],
        'completion' => ['是否完成', '退款状态', '返现状态'],
        'payer_note' => ['支付宝', '退款账户', '返款账户'],
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
    return isset($map['amount']) && (isset($map['order_no']) || isset($map['source_reference']) || isset($map['reference'])) ? $map : null;
}

function ps_refund_method($value)
{
    $value = trim((string)$value);
    if ($value === '') return '待核渠道';
    if (preg_match('/支付宝/u', $value)) return '支付宝';
    if (preg_match('/微信/u', $value)) return '微信';
    if (preg_match('/银行|银行卡|对公|转账/u', $value)) return '银行卡';
    if (preg_match('/淘宝|店铺|售中退款|原路退/u', $value)) return '店铺';
    return '待核渠道';
}

function ps_refund_fingerprint(array $input)
{
    $method = ps_refund_method($input['method'] ?? '');
    $reference = mb_strtolower(trim((string)($input['reference'] ?? '')));
    if ($reference !== '') return hash('sha256', 'refund-reference-v2|' . $method . '|' . $reference);
    return hash('sha256', 'refund-without-reference-v2|' . $method . '|' . trim((string)($input['order_no'] ?? '')) . '|' . mb_strtolower(trim((string)($input['source_reference'] ?? ''))) . '|' . (ps_import_date($input['refund_date'] ?? '') ?: trim((string)($input['refund_date'] ?? ''))) . '|' . (ps_refund_amount($input['amount'] ?? '') ?: trim((string)($input['amount'] ?? ''))));
}

/** 只按确定的订单号或原付款流水唯一关联；退款流水本身不能冒充原订单号。 */
function ps_refund_resolve_order($orderNo, $sourceReference)
{
    $orderNo = trim((string)$orderNo); $sourceReference = trim((string)$sourceReference);
    $byNo = null; $byReference = null;
    if ($orderNo !== '') {
        $q = db()->prepare('SELECT * FROM project_orders WHERE order_no=? LIMIT 1');
        $q->execute([$orderNo]); $byNo = $q->fetch() ?: null;
    }
    $lookupReference = $sourceReference !== '' ? $sourceReference : ($byNo ? '' : $orderNo);
    if ($lookupReference !== '') {
        $q = db()->prepare("SELECT o.* FROM project_orders o JOIN project_order_sources s ON s.order_id=o.id WHERE s.payment_reference=? AND s.payment_reference<>'' LIMIT 2");
        $q->execute([$lookupReference]); $matches = $q->fetchAll();
        if (count($matches) > 1) return [null, '原支付流水关联了多张订单，请财务核对'];
        $byReference = $matches[0] ?? null;
    }
    if ($byNo && $byReference && (int)$byNo['id'] !== (int)$byReference['id']) return [null, '原订单号与原支付流水指向不同订单，请财务核对'];
    $order = $byNo ?: $byReference;
    return [$order, $order && !$byNo ? '已按原支付流水自动关联订单 ' . $order['order_no'] : ''];
}

/** 原订单后补上传时自动补关联；仅改变待审线索的指向，不产生退款或分成。 */
function ps_refund_reconcile_pending($actor, $limit = 200)
{
    if (($actor['role'] ?? '') !== 'finance') return 0;
    $limit = max(1, min((int)$limit, 500)); $linked = 0;
    $rows = db()->query("SELECT r.id,r.order_no,r.source_payment_reference FROM project_refund_import_rows r WHERE r.review_status='pending' AND r.order_id IS NULL AND (EXISTS (SELECT 1 FROM project_orders o WHERE o.order_no=r.order_no AND r.order_no<>'') OR EXISTS (SELECT 1 FROM project_order_sources s WHERE s.payment_reference<>'' AND (s.payment_reference=r.source_payment_reference OR (r.source_payment_reference='' AND s.payment_reference=r.order_no)))) ORDER BY r.id DESC LIMIT " . $limit)->fetchAll();
    $update = db()->prepare("UPDATE project_refund_import_rows SET order_id=?,order_no=? WHERE id=? AND order_id IS NULL AND review_status='pending'");
    foreach ($rows as $row) {
        [$order] = ps_refund_resolve_order($row['order_no'], $row['source_payment_reference']);
        if (!$order) continue;
        $update->execute([(int)$order['id'], $order['order_no'], (int)$row['id']]);
        if ($update->rowCount()) { $linked++; ps_audit('refund_import', (int)$row['id'], 'auto_link_order', $actor, ['order_id' => (int)$order['id']]); }
    }
    return $linked;
}

function ps_refund_amount($value)
{
    $text = str_replace([',', '，', '¥', '￥', ' '], '', trim((string)$value));
    if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $text) || (float)$text <= 0 || (float)$text > 999999999999.99) return null;
    return number_format((float)$text, 2, '.', '');
}

/** 表格/手录共用核对：不创建订单，不把退款当作负数售价或项目成本。 */
function ps_refund_preview_row(array $input, $fingerprint, $actor, $fileId = null, $sheet = '', $line = 0)
{
    $row = ['order_no' => trim((string)($input['order_no'] ?? '')), 'refund_date' => ps_import_date($input['refund_date'] ?? ''),
        'amount' => ps_refund_amount($input['amount'] ?? ''), 'reference' => trim((string)($input['reference'] ?? '')),
        'source_reference' => trim((string)($input['source_reference'] ?? '')),
        'source_business' => trim((string)($input['business'] ?? '')),
        'completion' => trim((string)($input['completion'] ?? '')),
        'payer_note' => trim((string)($input['payer_note'] ?? '')),
        'reason' => mb_substr(trim((string)($input['reason'] ?? '')), 0, 300), 'method' => ps_refund_method($input['method'] ?? ''),
        'fingerprint' => $fingerprint, 'file_id' => $fileId, 'sheet' => $sheet, 'line' => $line, 'status' => '可登记', 'error' => '', 'warning' => '', 'order_id' => 0, 'business' => '', 'available' => '—'];
    if (!$row['refund_date'] || $row['amount'] === null) $row['error'] = '请补正退款日期和正数退款金额（最多两位小数）';
    elseif (mb_strlen($row['reference']) > 150 || mb_strlen($row['source_reference']) > 200 || mb_strlen($row['order_no']) > 100) $row['error'] = '订单号或流水号过长';
    else {
        if ($row['completion'] !== '' && !in_array($row['completion'], ['是', '已完成', '完成', '已退款', '已返款', '已返现'], true)) $row['warning'] = '原表未标记完成，请财务核实实际退款';
        if (preg_match('/微信|个人|垫付/u', $row['reason'] . ' ' . $row['payer_note']) && $row['method'] !== '微信') $row['warning'] .= ($row['warning'] ? '；' : '') . '备注涉及微信、个人返款或垫付，请财务核对实际渠道';
        if ($row['method'] === '待核渠道') $row['warning'] .= ($row['warning'] ? '；' : '') . '退款渠道不明确，请财务确认支付宝、微信、银行卡或店铺';
        if ($row['method'] === '店铺') $row['warning'] .= ($row['warning'] ? '；' : '') . '店铺退款需核对是否已从订单实收冲减，避免重复扣回';
        if ($row['reference'] === '') $row['warning'] .= ($row['warning'] ? '；' : '') . '无退款流水号，需财务人工核实并按原单、日期、金额防重';
        [$order, $matchNote] = ps_refund_resolve_order($row['order_no'], $row['source_reference']);
        $canView = $order && ps_refund_can_view_order($actor, (int)$order['id']);
        if ($matchNote !== '' && ($canView || ($actor['role'] ?? '') === 'finance' || !$order)) $row['warning'] .= ($row['warning'] ? '；' : '') . $matchNote;
        if (!$order) {
            $row['status'] = '待财务匹配订单';
            $row['warning'] .= ($row['warning'] ? '；' : '') . '尚未唯一匹配原订单，先留档待核，不影响项目结算';
        } elseif (!ps_refund_order_access($actor, (int)$order['id'])) {
            // 任意登录人员都可提交退款线索；非本人订单不透露金额/参与人，也不能自行生效。
            $row['order_id'] = (int)$order['id'];
            $row['status'] = '待财务核实归属';
            $row['warning'] .= ($row['warning'] ? '；' : '') . '该单非本人参与，仅提交财务核实，不展示原单金额';
        } else $row['order_id'] = (int)$order['id'];
        if ($order) {
            if ($canView || ($actor['role'] ?? '') === 'finance') {
                $row['order_no'] = $order['order_no'];
                $row['business'] = $order['project_type'];
                $row['settlement_status'] = $order['settlement_status'];
                $row['available'] = number_format((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2, '.', '');
                if ($row['source_business'] !== '' && ps_business_normalize($row['source_business']) !== ps_business_normalize($order['project_type']) && mb_strpos($row['source_business'], $order['project_type']) === false) $row['warning'] .= ($row['warning'] ? '；' : '') . '表格业务与原订单不一致，按原订单业务算法核算，请财务确认';
                if (($actor['role'] ?? '') === 'finance' && (float)$row['amount'] > round((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2)) $row['warning'] .= ($row['warning'] ? '；' : '') . '退款超过当前已审核实收余额，先核对实收/历史退款后再审核';
            }
        } else $row['business'] = $row['source_business'];
        if (!$row['error']) {
            $q = db()->prepare("SELECT id FROM project_refund_import_rows WHERE fingerprint=? AND review_status IN ('pending','approved') LIMIT 1");
            $q->execute([$fingerprint]);
            if ($q->fetchColumn()) $row['error'] = '这笔退款已提交过，不会重复扣回';
            elseif ($row['reference'] !== '') {
                $q = db()->prepare("SELECT id FROM project_refund_import_rows WHERE payment_method=? AND payment_reference=? AND review_status IN ('pending','approved') LIMIT 1");
                $q->execute([$row['method'], $row['reference']]);
                if ($q->fetchColumn()) $row['error'] = '同渠道退款流水号已提交过，不会重复扣回';
            } elseif ($row['order_id']) {
                $q = db()->prepare("SELECT id FROM project_refund_import_rows WHERE order_id=? AND refund_date=? AND amount=? AND payment_method=? AND payment_reference='' AND review_status IN ('pending','approved') LIMIT 1");
                $q->execute([$row['order_id'], $row['refund_date'], $row['amount'], $row['method']]);
                if ($q->fetchColumn()) $row['error'] = '原订单同日同金额退款已登记，请核对是否重复';
            }
            if (!$row['error'] && $row['warning'] && $row['order_id']) $row['status'] = '待财务核实';
        }
    }
    if ($row['error']) $row['status'] = '需处理';
    return $row;
}

function ps_refund_parse_file($fileRow, $actor)
{
    $rows = []; $reports = [];
    foreach (ps_import_file_sheets($fileRow) as $sheet => $grid) {
        if (!$grid) continue;
        $map = null; $headerAt = -1; $aiNote = '';
        foreach (array_slice($grid, 0, 12) as $i => $head) if ($map = ps_refund_header_map((array)$head)) { $headerAt = $i; break; }
        if (!$map) {
            $head = (array)($grid[0] ?? []);
            $map = ps_ai_import_columns('网站支付宝退款', $head, array_slice($grid, 1, 5), ps_refund_columns(), $actor, $aiNote);
            if ($map && isset($map['amount']) && (isset($map['order_no']) || isset($map['source_reference']) || isset($map['reference']))) $headerAt = 0; else $map = null;
        }
        if (!$map) { $reports[$sheet] = '未识别退款金额及订单号/交易流水列，已跳过'; continue; }
        $reports[$sheet] = ($aiNote ? $aiNote . '；' : '') . '已识别 ' . count($grid) . ' 行';
        for ($i = $headerAt + 1; $i < count($grid); $i++) {
            if (count($rows) >= 1500) throw new RuntimeException('退款表超过 1500 行，请拆分上传');
            $cells = (array)$grid[$i];
            if (!array_filter($cells, function ($v) { return trim((string)$v) !== ''; })) continue;
            $input = [];
            foreach ($map as $key => $col) $input[$key] = $cells[$col] ?? '';
            // 合计行不是退款明细；无店铺订单号但有原支付流水仍可导入。
            if (trim((string)($input['order_no'] ?? '')) === '' && trim((string)($input['source_reference'] ?? '')) === '' && trim((string)($input['reference'] ?? '')) === '') continue;
            $rows[] = ps_refund_preview_row($input, ps_refund_fingerprint($input), $actor, (int)$fileRow['id'], (string)$sheet, $i + 1);
        }
    }
    if (!$rows) throw new RuntimeException('没有可核对的退款明细。' . implode('；', $reports));
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
            [$matchedOrder, $matchWarning] = ps_refund_resolve_order($row['order_no'], $row['source_reference'] ?? '');
            $orderId = $matchedOrder ? (int)$matchedOrder['id'] : null;
            if ($row['reference'] !== '') {
                $q = $pdo->prepare("SELECT id FROM project_refund_import_rows WHERE payment_method=? AND payment_reference=? AND review_status IN ('pending','approved') LIMIT 1 FOR UPDATE");
                $q->execute([$row['method'], $row['reference']]);
                if ($q->fetchColumn()) throw new RuntimeException('第 ' . ($row['line'] ?: $i + 1) . ' 行退款流水已登记，请勿重复提交');
            } elseif ($orderId) {
                $q = $pdo->prepare("SELECT id FROM project_refund_import_rows WHERE order_id=? AND refund_date=? AND amount=? AND payment_method=? AND payment_reference='' AND review_status IN ('pending','approved') LIMIT 1 FOR UPDATE");
                $q->execute([$orderId, $row['refund_date'], $row['amount'], $row['method']]);
                if ($q->fetchColumn()) throw new RuntimeException('第 ' . ($row['line'] ?: $i + 1) . ' 行原订单同日同金额退款已登记，请核对重复');
            }
            $q = $pdo->prepare('SELECT id,review_status FROM project_refund_import_rows WHERE fingerprint=? FOR UPDATE');
            $q->execute([$row['fingerprint']]); $prior = $q->fetch();
            if ($prior && $prior['review_status'] !== 'rejected') throw new RuntimeException('第 ' . ($row['line'] ?: $i + 1) . ' 行退款已登记，请勿重复提交');
            $values = [$orderId, $matchedOrder['order_no'] ?? $row['order_no'], $row['refund_date'], $row['amount'], $row['method'], $row['source_reference'] ?? '', $row['reference'], $row['reason'], $row['file_id'], $row['sheet'], $row['line'], $actor['type'], $actor['id'], $actor['employee_id'] ?? null];
            if ($prior) {
                $pdo->prepare("UPDATE project_refund_import_rows SET order_id=?,order_no=?,refund_date=?,amount=?,payment_method=?,source_payment_reference=?,payment_reference=?,reason=?,import_file_id=?,source_sheet=?,source_row=?,submitted_by_type=?,submitted_by_id=?,submitted_employee_id=?,review_status='pending',reviewed_by_admin=NULL,reviewed_at=NULL WHERE id=?")
                    ->execute(array_merge($values, [(int)$prior['id']]));
                $refundId = (int)$prior['id'];
            } else {
                $pdo->prepare("INSERT INTO project_refund_import_rows (fingerprint,order_id,order_no,refund_date,amount,payment_method,source_payment_reference,payment_reference,reason,import_file_id,source_sheet,source_row,review_status,submitted_by_type,submitted_by_id,submitted_employee_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?,?)")
                    ->execute(array_merge([$row['fingerprint']], $values));
                $refundId = (int)$pdo->lastInsertId();
            }
            ps_audit('refund_import', $refundId, $prior ? 'resubmit' : 'submit', $actor, ['order_id' => $orderId, 'amount' => $row['amount'], 'channel' => $row['method'], 'file_id' => $row['file_id'], 'sheet' => $row['sheet'], 'line' => $row['line'], 'match_warning' => $matchWarning]);
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
function ps_refund_review($id, $decision, $actor, $month, $nested = false, $correctedOrderNo = '', $correctedSourceReference = '', $correctedMethod = '', $correctedRefundReference = '')
{
    if (($actor['role'] ?? '') !== 'finance' || !in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('仅财务可审核退款');
    $pdo = db(); $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_refund_review'); else $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_refund_import_rows WHERE id=? FOR UPDATE');
        $q->execute([(int)$id]); $row = $q->fetch();
        if (!$row || $row['review_status'] !== 'pending') throw new RuntimeException('退款记录已处理，请刷新');
        if ($decision === 'approved') {
            $orderNo = trim((string)$correctedOrderNo) ?: $row['order_no'];
            $sourceReference = trim((string)$correctedSourceReference) ?: ($row['source_payment_reference'] ?? '');
            $method = ps_refund_method($correctedMethod !== '' ? $correctedMethod : $row['payment_method']);
            $reference = trim((string)$correctedRefundReference) ?: $row['payment_reference'];
            if ($method === '待核渠道') throw new RuntimeException('请先确认退款渠道（支付宝、微信、银行卡或店铺）');
            if (mb_strlen($sourceReference) > 200 || mb_strlen($reference) > 150) throw new RuntimeException('交易流水号过长');
            [$resolved, $matchWarning] = ps_refund_resolve_order($orderNo, $sourceReference);
            if (!$resolved) throw new RuntimeException($matchWarning ?: '原项目订单尚未匹配，请填写原订单号或原支付流水号');
            $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
            $q->execute([(int)$resolved['id']]); $order = $q->fetch();
            if (!$order) throw new RuntimeException('原项目订单已变化，请重新核对');
            if ((float)$row['amount'] > round((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2)) throw new RuntimeException('退款超过已审核实收的可退余额，请先核对实收/已退款');
            if ($reference !== '') {
                $repeat = $pdo->prepare("SELECT id FROM project_refund_import_rows WHERE payment_method=? AND payment_reference=? AND review_status='approved' AND id<>? LIMIT 1");
                $repeat->execute([$method, $reference, (int)$id]);
                if ($repeat->fetchColumn()) throw new RuntimeException('同渠道退款流水号已审核，不能重复扣回');
            } else {
                $repeat = $pdo->prepare("SELECT id FROM project_refund_import_rows WHERE order_id=? AND refund_date=? AND amount=? AND payment_method=? AND payment_reference='' AND review_status='approved' AND id<>? LIMIT 1");
                $repeat->execute([(int)$order['id'], $row['refund_date'], $row['amount'], $method, (int)$id]);
                if ($repeat->fetchColumn()) throw new RuntimeException('原订单同日同金额退款已审核，请先核对是否重复');
            }
            $reason = mb_substr($method . '退款 ' . $row['refund_date'] . ($reference !== '' ? ' 流水号 ' . $reference : '') . ($row['reason'] !== '' ? '；' . $row['reason'] : ''), 0, 300);
            if (in_array($order['settlement_status'], ['approved', 'locked'], true)) ps_post_adjustment((int)$order['id'], $actor, $row['amount'], 0, $reason, $month);
            else {
                $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'refund',?,?,'approved',?,?,?,NOW())")
                    ->execute([(int)$order['id'], $row['amount'], $reason, $actor['type'], $actor['id'], $actor['id']]);
                ps_recalculate_cash((int)$order['id']);
            }
            $pdo->prepare('UPDATE project_refund_import_rows SET order_id=?,order_no=?,payment_method=?,source_payment_reference=?,payment_reference=? WHERE id=?')->execute([(int)$order['id'], $order['order_no'], $method, $sourceReference, $reference, (int)$id]);
        }
        $pdo->prepare('UPDATE project_refund_import_rows SET review_status=?,reviewed_by_admin=?,reviewed_at=NOW() WHERE id=?')->execute([$decision, $actor['id'], (int)$id]);
        ps_audit('refund_import', (int)$id, 'review_' . $decision, $actor, ['order_id' => isset($order) ? (int)$order['id'] : $row['order_id'], 'amount' => $row['amount'], 'month' => $month, 'channel' => $method ?? $row['payment_method']]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_refund_review'); else $pdo->commit();
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_refund_review');
        elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
