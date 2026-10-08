<?php
/** Deterministic review policy. No database, network, AI or clock side effects. */
const PA_POLICY_VERSION = '2026-10-08.2';

function pa_cents($value)
{
    if (!is_scalar($value) || is_bool($value)) return null;
    $s = trim(str_replace(['￥', '¥', '元'], '', (string)$value));
    if (strpos($s, ',') !== false && !preg_match('/^-?\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D', $s)) return null;
    $s = str_replace(',', '', $s);
    if (!preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/D', $s)) return null;
    return (int)round((float)$s * 100);
}

function pa_payment_evidence(array $sources, $shop, $asof = null)
{
    $shops = []; $paid = []; $refund = 0; $refs = []; $warnings = []; $referenceOrders = [];
    foreach ($sources as $source) {
        $sourceShop = trim((string)($source['shop'] ?? ''));
        if ($shop !== '' && $sourceShop !== $shop) continue;
        $shops[$sourceShop] = true;
        if (!empty($source['matched_by_reference'])) $referenceOrders[(string)($source['order_no'] ?? $source['id'])] = true;
        $raw = $source['raw'] ?? [];
        if (!is_array($raw)) continue;
        $isEtmll = ($raw['__financial_source__'] ?? '') === 'etmll_paid';
        $trusted = ($raw['__financial_source__'] ?? '') === 'shop_statement' || $isEtmll;
        if ((int)($raw['__etmll_observed_refund_cents__'] ?? 0) > (pa_cents($raw['退款金额'] ?? 0) ?? 0)) $warnings['linked_refund_conflict'] = '居间最新退款额与店铺导出不一致；保留原数据，待核对退款，不能按旧利润自动通过';
        // ETMLL's total_amount / __original_price__ are sale amounts, not payment evidence.
        $status = trim((string)($raw['__order_status__'] ?? $raw['订单状态'] ?? ''));
        if (preg_match('/退款中|退款申请|退款处理中|售后中|交易关闭/', $status)) $warnings['source_after_sale'] = '交易来源存在退款或关闭状态，需要核对实际收退款';
        if (!$trusted) continue;
        if ($isEtmll) {
            $payTime = (string)($raw['__actual_pay_time__'] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $payTime);
            if (!$date || $date->format('Y-m-d H:i:s') !== $payTime || ($asof !== null && $payTime > $asof)) {
                $warnings['payment_time'] = '实付证据缺少有效付款时间或付款时间在未来'; continue;
            }
        }
        $fields = ['买家实际支付金额', '买家实付金额', '买家实付款', '实付金额', '实际付款金额', '实际支付金额', '支付金额'];
        foreach ($raw as $key => $value) {
            $key = preg_replace('/[\s（）()]/u', '', (string)$key);
            $key = preg_replace('/元$/u', '', $key);
            if (!in_array($key, $fields, true)) continue;
            if (trim((string)$value) === '' || trim((string)$value) === '—') continue;
            $cents = pa_cents($value);
            if ($cents === null || $cents < 0) { $warnings['payment_invalid'] = '实付字段金额无效，需要核对原始流水'; continue; }
            $success = $isEtmll ? '/交易成功|TRADE_SUCCESS|TRADE_FINISHED/' : '/交易成功|支付成功|交易完成|收款成功|已入账|TRADE_SUCCESS|TRADE_FINISHED/';
            if (!preg_match($success, $status) || preg_match('/未支付|未付款|支付失败|付款失败/', $status)) {
                $warnings['payment_status'] = '已找到实付金额，但支付成功状态尚未确认'; continue;
            }
            $paid[$cents] = $cents;
            $identity = $isEtmll && !empty($raw['__etmll_id__']) ? 'etmll:' . $raw['__etmll_id__'] : 'shop:' . $sourceShop . ':' . ($source['order_no'] ?? ($raw['订单编号'] ?? $source['id']));
            $refs[] = ['source_order_id' => (int)($source['id'] ?? 0), 'source_key' => hash('sha256',$identity), 'field' => $key, 'paid_cents' => $cents, 'status' => $status];
        }
        foreach (['退款金额', '累计退款金额', '成功退款金额'] as $key) {
            if (!isset($raw[$key]) || trim((string)$raw[$key]) === '') continue;
            $value = pa_cents($raw[$key]);
            if ($value === null || $value < 0) $warnings['refund_invalid'] = '来源退款金额无效，需要核对';
            else {
                $refund = max($refund, $value);
                if ($isEtmll && $value > 0) $warnings['etmll_refund_basis'] = 'ETMLL 有店铺退款，需核验实付与退款口径，避免二次冲减';
            }
        }
    }
    if ($shop === '' && count($shops) > 1) $warnings['shop_ambiguous'] = '同一订单号匹配到多个店铺，请确认订单店铺';
    if (count($paid) > 1) $warnings['payment_conflict'] = '同一订单存在不同实付金额，不能自动覆盖';
    if (count($referenceOrders) > 1) $warnings['transaction_ambiguous'] = '支付流水关联到多个原订单，需财务确认分摊，不能自动猜测';
    return ['paid_cents' => count($paid) === 1 ? reset($paid) : null, 'refund_cents' => $refund, 'references' => $refs, 'warnings' => $warnings, 'matched_sources' => count($sources)];
}

function pa_evaluate(array $ctx)
{
    $o = $ctx['order']; $summary = $ctx['summary']; $e = $ctx['payment'];
    $reasons = []; $exception = false; $waitSync = false; $waitFinance = false;
    $add = function ($code, $text, $kind = 'data') use (&$reasons, &$exception, &$waitSync, &$waitFinance) {
        $reasons[$code] = ['code' => $code, 'text' => $text, 'kind' => $kind];
        if ($kind === 'exception') $exception = true;
        if ($kind === 'sync') $waitSync = true;
        if ($kind === 'finance') $waitFinance = true;
    };
    $receipt = (int)round((float)$o['receipt_amount'] * 100);
    $refund = (int)round((float)$o['refund_amount'] * 100);
    $ledgerReceipt = 0; $ledgerRefund = 0; $hasEstimate = false;
    foreach ($ctx['cash'] as $cash) {
        if ($cash['review_status'] === 'pending') $add('cash_pending', '收款或退款已提交，等待财务核验，无需重复登记', 'finance');
        if ($cash['review_status'] !== 'approved') continue;
        $c = (int)round((float)$cash['amount'] * 100);
        if ($cash['movement_type'] === 'receipt') {
            $ledgerReceipt += $c;
            if (strpos((string)$cash['note'], '按售价') !== false) $hasEstimate = true;
        } else $ledgerRefund += $c;
    }
    if ($ledgerReceipt !== $receipt || $ledgerRefund !== $refund) $add('cash_ledger_mismatch', '订单收退款合计与已审核流水不一致', 'exception');
    if ($hasEstimate && ($e['paid_cents'] === null || $e['paid_cents'] !== $receipt)) $add('legacy_sale_estimate', '历史实收按售价估算，尚无独立实付证据，请财务复核', 'exception');
    foreach ($e['warnings'] as $code => $text) $add($code, $text, 'exception');
    if ($e['paid_cents'] !== null && $receipt !== 0 && $receipt !== $e['paid_cents']) $add('receipt_conflict', '已确认收款与交易实付不一致，保留原金额待核对', 'exception');
    if ($e['paid_cents'] !== null && (float)$o['contract_amount'] > 0 && $e['paid_cents'] > (int)round((float)$o['contract_amount'] * 100)) $add('payment_over_sale', '实付超过已登记售价，需核对合并付款、补款或关联错误', 'exception');
    if (!$receipt && $e['paid_cents'] !== null && (float)$o['contract_amount'] > 0 && $e['paid_cents'] !== (int)round((float)$o['contract_amount'] * 100)) $add('payment_sale_variance', '实付与售价有差异，需财务确认优惠、补贴或分期口径，不猜测差额', 'exception');
    if ($e['refund_cents'] > $refund) $add('refund_gap', '交易来源存在尚未登记的退款，先核对退款', 'exception');
    if (!empty($ctx['pending_refunds'])) $add('external_refund_pending', '有支付宝／微信等渠道退款待核验', 'exception');

    // Already approved/locked orders are never rewritten or re-snapshotted.
    $closed = in_array($o['settlement_status'], ['approved', 'locked'], true);
    if (!$closed) {
        if ((float)($ctx['later_refund'] ?? 0) > 0) $add('later_refund', '存在跨月退款，需要财务核验原月分成与后月补扣是否对应', 'exception');
        if ((int)($ctx['snapshot_count'] ?? 0) > 0) $add('existing_snapshot', '未结算订单已有分成快照，请财务核对，避免重复生成', 'exception');
        $date = (string)($o['order_date'] ?? '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > $ctx['today']) $add('order_date', '订单日期缺失、无效或晚于今天，请补齐核对');
        if (($ctx['period_status'] ?? '') === 'locked') $add('period_locked', '订单归属月份已锁定，需要财务选择调整月份', 'exception');
        if (($o['delivery_status'] ?? '') !== 'finished') $add('delivery', '尚未确认业务已完成；付款成功不代替交付确认');
        if (!empty($ctx['pending_requests'])) $add('request_pending', '交付／升级申请已提交，等待审核，无需重复提交', 'finance');
        if (!empty($ctx['catalog']['resources']) && (!$ctx['resource'] || ($ctx['resource']['domain_mode'] ?? 'pending') === 'pending')) $add('resource', '域名、空间等实际资源待技术确认');
        if (!empty($ctx['catalog']['requires_technical']) && empty($ctx['technical_count'])) $add('technical', '尚未指定参与技术');
        if (!empty($ctx['catalog']['kind_required']) && trim((string)($o['order_kind'] ?? '')) === '') $add('order_kind', '业务分成类型待确认');
        $ssl = 0; $costSignatures = [];
        foreach ($ctx['costs'] as $cost) {
            if ($cost['review_status'] === 'pending') $add('cost_pending', '成本已登记，等待财务核验；如有凭证可在结算单补充', 'finance');
            if ($cost['review_status'] === 'approved') {
                if (!empty($cost['is_custom']) && empty($cost['proof_path']) && empty($cost['reviewed_by_admin'])) $add('custom_cost_proof', '请在结算单补充自定义成本凭证，或联系财务核验', 'data');
                if (isset($cost['quantity'], $cost['unit_price']) && (int)round((float)$cost['quantity'] * (float)$cost['unit_price'] * 100) !== (int)round((float)$cost['amount'] * 100)) $add('cost_arithmetic', '成本数量 × 单价与小计不一致', 'exception');
                $signature = json_encode([$cost['category'], $cost['item_name'] ?? '', $cost['template_id'] ?? null, $cost['quantity'] ?? 1, $cost['amount'], $cost['cost_kind'] ?? 'one_time']);
                if (isset($costSignatures[$signature])) $add('cost_duplicate', '存在相同成本明细，请核对是否重复登记', 'exception');
                $costSignatures[$signature] = true;
            }
            if ($cost['review_status'] === 'approved' && $cost['category'] === 'certificate') $ssl += (int)round((float)$cost['amount'] * 100);
        }
        if ($ssl < (int)round((float)($ctx['resource']['ssl_expected_amount'] ?? 0) * 100)) $add('ssl_cost', 'SSL 报备成本与已审核凭证成本尚未对应', 'exception');
        $hasPeople = false; $subsidy = false; $allowNegative = false; $incomeDependent = false;
        foreach ($summary['groups'] as $key => $group) {
            if (!$group['people']) continue;
            $hasPeople = true;
            if (!empty($group['missing_rule'])) $add('rule_' . $key, '参与人的业务／岗位／订单类型尚未匹配有效分成规则', 'exception');
            if (abs((float)$group['weight'] - 1.0) > 0.000001) $add('weight_' . $key, '同组参与人分配权重合计必须为 100%', 'exception');
            if ((float)($group['subsidy'] ?? 0) > 0) $subsidy = true;
            foreach ($group['people'] as $person) {
                $rule = $person['rule'] ?? [];
                if (!empty($rule['allow_negative'])) $allowNegative = true;
                if ((float)($rule['rate'] ?? 0) !== 0.0 || (isset($rule['low_profit_threshold']) && $rule['low_profit_threshold'] !== '')) $incomeDependent = true;
                if (array_key_exists('review_allow_no_receipt', $rule) && !$rule['review_allow_no_receipt']) $incomeDependent = true;
                if ((float)($rule['min_contract_amount'] ?? 0) > 0 && (float)$o['contract_amount'] <= 0) $add('subsidy_sale', '补助有售价门槛，需先补齐售价，不能先按零金额结算');
            }
        }
        if (!$hasPeople) $add('participants', '请指定参与的客服或技术');
        if ($incomeDependent && (float)$summary['income'] > 0 && (float)$o['contract_amount'] <= 0) $add('sale_for_share', '比例分成须先补齐有效售价，避免成本下限和服务费误算');
        foreach ($ctx['cash'] as $cash) if ($cash['review_status']==='approved' && ($cash['movement_type']??'')==='receipt' && preg_match('/定金|分期|尾款未收|预付款/',(string)($cash['note']??'')) && $receipt<(int)round((float)$o['contract_amount']*100)) $add('partial_receipt','存在尚未收齐的定金／分期收款，需财务确认结算口径','exception');
        $missingReceipt = $receipt === 0 && $e['paid_cents'] === null;
        if (!empty($ctx['monthly_income_required'])) $incomeDependent = true;
        $noReceiptAllowance = $subsidy && !$incomeDependent;
        if ($missingReceipt && !empty($ctx['monthly_income_required'])) $add('monthly_profit_receipt', '月度利润分成仍需真实实收；单量补助独立按月核验，不会按零收入锁定分成', 'sync');
        if ($missingReceipt && !$noReceiptAllowance) $add('receipt_missing', $e['matched_sources'] ? '已匹配订单来源，但尚无明确实付字段或已核验收款；比例分成须等实收' : '尚未匹配可信收款流水；售价仅用于预估', 'sync');
        if ((float)$summary['income'] <= 0 && !$noReceiptAllowance && !($allowNegative && (float)$summary['income'] < 0)) $add('income', '没有可结算净实收，需核对收退款', $missingReceipt ? 'sync' : 'exception');
        if (!$missingReceipt && (float)$summary['profit'] < 0 && !$allowNegative && !($noReceiptAllowance && (float)$summary['income'] === 0.0)) $add('negative_profit', '贡献利润为负，需要财务核对成本及适用规则', 'exception');
        if ((float)$summary['service_fee_rate'] > 0 && (float)$o['contract_amount'] <= 0 && (float)$summary['income'] > 0) $add('sale_missing', '按售价计服务费的业务，尚缺有效售价');
        if (!$missingReceipt && $refund > max($receipt, $e['paid_cents'] ?? 0) && !$allowNegative) $add('refund_exceeds', '累计退款超过可核对收款，不能自动结算', 'exception');
    }
    $state = $exception ? 'exception' : ($closed ? 'settled' : ($waitFinance ? 'wait_finance' : ($waitSync ? 'wait_sync' : ($reasons ? 'wait_data' : 'ready'))));
    if ($state === 'wait_sync' || $state === 'wait_finance') {
        $priority = $state === 'wait_sync' ? 'sync' : 'finance';
        uasort($reasons, function ($a, $b) use ($priority) { return ($a['kind'] !== $priority) <=> ($b['kind'] !== $priority); });
    }
    return ['state' => $state, 'reasons' => array_values($reasons), 'can_apply' => !$closed && !$reasons, 'receipt_to_add_cents' => $receipt === 0 && !$reasons && $e['paid_cents'] !== null ? $e['paid_cents'] : 0, 'policy_version' => PA_POLICY_VERSION];
}

function pa_state_meta($state)
{
    $map = [
        'wait_sync' => ['等待收款资料同步', 'info'], 'wait_data' => ['待补资料', 'warning'],
        'wait_finance' => ['等待财务核验', 'info'],
        'exception' => ['异常待财务', 'danger'], 'ready' => ['核对通过·待结算', 'primary'],
        'auto_passed' => ['自动核对通过', 'success'], 'settled' => ['已结算', 'success'],
        'queued' => ['等待系统核对', 'secondary'],
    ];
    return $map[$state] ?? $map['queued'];
}
