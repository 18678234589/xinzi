<?php
/** 规则中心的核验策略。允许无流水不等于允许猜测收入或改变核算周期。 */
function prp_cash_independent(string $scope, array $rule): bool
{
    if ($scope === 'order') return (float)($rule['rate'] ?? 0) === 0.0
        && (float)($rule['per_order_subsidy'] ?? 0) > 0
        && (!isset($rule['low_profit_threshold']) || $rule['low_profit_threshold'] === '');
    if ($scope !== 'monthly' || ($rule['rule_type'] ?? '') !== 'legacy_sheet') return false;
    $p = $rule['params'] ?? (json_decode((string)($rule['params_json'] ?? '{}'), true) ?: []);
    if (empty($p['counters']) || !is_array($p['counters'])) return false;
    foreach ($p['counters'] as $c) if (!isset($c['unit']) || !is_numeric($c['unit']) || (float)$c['unit'] < 0 || !is_finite((float)$c['unit'])) return false;
    return true;
}

function prp_validate(string $scope, array $rule, bool $allow, string $period): void
{
    if (!in_array($scope, ['order','monthly'], true) || $period !== $scope) throw new RuntimeException('核算周期须与规则算法一致；请在对应周期规则区创建规则，不能直接挪动已用规则');
    if ($allow && !prp_cash_independent($scope, $rule)) throw new RuntimeException('此算法依赖收入、利润或人工计件核验，不能开启无流水自动通过');
}

function prp_policies(): array
{
    static $rows = null;
    if ($rows !== null) return $rows;
    $rows = [];
    try {
        foreach (db()->query('SELECT * FROM project_review_rule_policies') as $r) $rows[$r['rule_scope'].'|'.$r['rule_id']] = $r;
    } catch (PDOException $e) { if ($e->getCode() !== '42S02') throw $e; }
    return $rows;
}

function prp_allow_no_receipt(string $scope, array $rule): bool
{
    if (!prp_cash_independent($scope, $rule)) return false;
    $p = prp_policies()[$scope.'|'.(int)$rule['id']] ?? null;
    // 兼容原先已上线的纯逐单补助；月度规则必须明确开启，不能仅凭业务名称推断。
    return $p ? !empty($p['allow_no_receipt']) && $p['calculation_period'] === $scope : $scope === 'order';
}

function prp_save(array $actor, string $scope, int $id, bool $allow, string $period): void
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可修改核验策略');
    if (!in_array($scope, ['order','monthly'], true)) throw new RuntimeException('规则类型无效');
    $p = db(); $p->beginTransaction();
    try {
        $table = $scope === 'order' ? 'project_commission_rules' : 'project_monthly_rules';
        $q = $p->prepare("SELECT * FROM $table WHERE id=? FOR UPDATE"); $q->execute([$id]); $r = $q->fetch();
        if (!$r) throw new RuntimeException('规则不存在');
        prp_validate($scope, $r, $allow, $period);
        $p->prepare('INSERT INTO project_review_rule_policies (rule_scope,rule_id,allow_no_receipt,calculation_period,updated_by_admin,updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE allow_no_receipt=VALUES(allow_no_receipt),calculation_period=VALUES(calculation_period),updated_by_admin=VALUES(updated_by_admin),updated_at=NOW()')
            ->execute([$scope,$id,(int)$allow,$period,$actor['id']]);
        $p->exec("UPDATE project_auto_reviews SET policy_version='rules-updated' WHERE state IN ('wait_sync','wait_data','exception','ready','queued')");
        ps_audit('review_rule_policy',$id,'save',$actor,['scope'=>$scope,'allow_no_receipt'=>$allow,'calculation_period'=>$period]);
        $p->commit();
    } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
}

function prp_col(array $raw, string $name): string
{
    if (isset($raw[$name]) && is_scalar($raw[$name])) return trim((string)$raw[$name]);
    foreach ($raw as $key => $value) if (is_scalar($value) && mb_strpos((string)$key, $name) !== false) return trim((string)$value);
    return '';
}

/** 新项目录入也可作为单量来源；仅真实参与人关联，不产生或推测收款，复用原旺旺+日期／拍链接去重算法。 */
function prp_project_quantity_rows(string $month, int $employeeId, array $legacyRows): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)) return [];
    $start=$month.'-01';$end=date('Y-m-d',strtotime($start.' +1 month'));
    $q=db()->prepare("SELECT DISTINCT o.id,o.order_no,o.shop,o.order_kind,o.order_date,o.contract_amount,o.refund_amount,
        s.payment_nickname,s.trade_status,l.raw_data AS legacy_raw,e.name AS employee_name
        FROM project_orders o JOIN project_participants p ON p.order_id=o.id
        JOIN employees e ON e.id=p.employee_id
        LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN orders l ON l.id=s.source_order_id
        WHERE p.employee_id=? AND o.project_type='网站续费' AND o.order_date>=? AND o.order_date<?");
    $q->execute([$employeeId,$start,$end]);
    $seen=[];foreach($legacyRows as $r) if(!empty($r['order_no']))$seen[trim((string)$r['order_no'])]=true;
    $rows=[];
    foreach($q->fetchAll() as $o){
        $no=trim((string)$o['order_no']);if($no===''||isset($seen[$no]))continue;
        $original=json_decode((string)($o['legacy_raw']??'{}'),true)?:[];
        if ((int)($original['__etmll_observed_refund_cents__']??0)>0)continue;
        $credit=trim((string)($original['__verified_month__']??''));if($credit!==''&&$credit!==$month)continue;
        $status=(string)($o['trade_status']??'');
        if((float)$o['refund_amount']>0||preg_match('/交易关闭|退款成功|全额退款|退款中|售后中/u',$status))continue;
        $raw=['接单客服'=>$o['employee_name'],'旺旺'=>trim((string)($o['payment_nickname']??'')),
            '日期'=>$o['order_date'],'__order_status__'=>$status,'__project_quantity_source__'=>(int)$o['id']];
        // 拍链接是订单已明确归类的业务，不靠 AI 猜测客户/流水/金额。
        if($o['order_kind']==='拍链接')$raw['拍建站']='网站链接';
        $rows[]=['id'=>-(int)$o['id'],'employee_id'=>$employeeId,'order_no'=>$no,'shop'=>$o['shop'],
            'order_date'=>$o['order_date'],'order_amount'=>(float)$o['contract_amount'],'project'=>$o['order_kind'],
            'raw_data'=>json_encode($raw,JSON_UNESCAPED_UNICODE)];
        $seen[$no]=true;
    }
    return $rows;
}

/** 与现用月度单量算法同口径：本人门控、非退款、计数列/旺旺日期及关键字。金额仍只由月度算法发放。 */
function prp_monthly_order_eligible(array $rule, string $name, array $rows, string $orderNo, string $shop): bool
{
    if (!prp_cash_independent('monthly', $rule) || $name === '' || $orderNo === '') return false;
    $p = $rule['params'] ?? (json_decode((string)($rule['params_json'] ?? '{}'), true) ?: []);
    $gate = trim((string)($p['gate_column'] ?? '')) ?: '接单客服';
    $owns = false;
    foreach ($rows as $r) {
        $raw = is_array($r['raw_data'] ?? null) ? $r['raw_data'] : (json_decode((string)($r['raw_data'] ?? '{}'), true) ?: []);
        if (in_array($name, array_map('trim', explode(',', prp_col($raw, $gate))), true)) { $owns = true; break; }
    }
    if (!$owns) return false;
    foreach ($rows as $r) {
        if (trim((string)($r['order_no'] ?? '')) !== $orderNo) continue;
        if ($shop !== '' && !empty($r['shop']) && $shop !== $r['shop']) continue;
        $raw = is_array($r['raw_data'] ?? null) ? $r['raw_data'] : (json_decode((string)($r['raw_data'] ?? '{}'), true) ?: []);
        if (!empty($raw['__is_refund__']) || (float)($r['order_amount'] ?? 0) < 0 || preg_match('/交易关闭|退款成功|全额退款|退款中|售后中/u', (string)($raw['__order_status__'] ?? ''))) continue;
        foreach ($p['counters'] as $c) {
            if ((float)$c['unit'] <= 0) continue;
            $column = trim((string)($c['column'] ?? ''));
            if ($column === '') { if (prp_col($raw, '旺旺') !== '' && prp_col($raw, '日期') !== '') return true; continue; }
            $value = prp_col($raw, $column);
            if ($value === '') continue;
            $keywords = array_filter(array_map('trim', explode('+', (string)($c['keywords'] ?? ''))));
            $hits = 0;
            foreach ($keywords as $kw) if (mb_strpos($value, $kw) !== false) $hits++;
            if (!$keywords || (($c['match'] ?? 'all') === 'any' ? $hits > 0 : $hits === count($keywords))) return true;
        }
    }
    return false;
}

function prp_order_context(array $order): array
{
    require_once __DIR__ . '/ProjectMonthly.php';
    static $cache = [];
    $month = substr((string)$order['order_date'], 0, 7);
    if (!isset($cache[$month])) {
        $cache[$month] = [];
        foreach (ps_monthly_rules_for($month) as $rule) {
            $rule['_allowance_rows'] = []; $rule['_employee_name'] = '';
            if (prp_allow_no_receipt('monthly', $rule) && !empty($rule['employee_id']) && function_exists('ps_legacy_sheet_orders')) {
                $q = db()->prepare('SELECT name FROM employees WHERE id=?'); $q->execute([$rule['employee_id']]);
                $rule['_employee_name'] = trim((string)$q->fetchColumn());
                $p = $rule['params'];
                $rule['_allowance_rows'] = ps_legacy_sheet_orders($month, $rule['employee_id'], (string)($p['dept'] ?? ''), trim((string)($p['backend'] ?? '')), true);
            }
            $cache[$month][] = $rule;
        }
    }
    $out = ['monthly_income_required'=>false, 'monthly_allowance_rules'=>[]];
    foreach ($cache[$month] as $rule) {
        $businesses = ps_monthly_scope_businesses($rule);
        if ($businesses !== null && !in_array(ps_business_normalize($order['project_type']), $businesses, true)) continue;
        // 部门利润分成的受益人可以不是此单参与人，仍不能把零实收快照锁成最终月度基数。
        if (in_array($rule['rule_type'], ['dept_share','profit_pool','tier_rate','threshold_bonus','sales_package','legacy_module'], true)
            || ($rule['rule_type']==='ranking' && ($rule['metric']??'manual')!=='manual')) $out['monthly_income_required'] = true;
        if (prp_allow_no_receipt('monthly', $rule) && prp_monthly_order_eligible($rule, $rule['_employee_name'], $rule['_allowance_rows'], $order['order_no'], (string)($order['shop'] ?? ''))) {
            $out['monthly_allowance_rules'][] = ['rule_id'=>(int)$rule['id'],'rule_name'=>$rule['name'],'employee_id'=>(int)$rule['employee_id'],'calculation_period'=>'monthly','state'=>'auto_verified'];
        }
    }
    return $out;
}
