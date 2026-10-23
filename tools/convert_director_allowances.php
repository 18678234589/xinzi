<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/ProjectPayrollSheet.php';
ps_payroll_extras_ensure(); ppf_ensure();
$apply = in_array('--apply', $argv, true);
$map = [56 => [19, '于洋', 2000], 57 => [53, '翟建跃', 900], 127 => [48, '刘玉霜', 300]];
$pdo = db(); $result = [];
$actor = ['type'=>'admin','id'=>(int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(),'role'=>'finance'];
$pdo->beginTransaction();
try {
    $periods = $pdo->query("SELECT period,status FROM project_payroll_periods WHERE period>='2026-09' ORDER BY period FOR UPDATE")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($map as $rid => $expected) {
        list($eid,$name,$default) = $expected;
        $q=$pdo->prepare('SELECT * FROM project_monthly_rules WHERE id=? FOR UPDATE');$q->execute([$rid]);$rule=$q->fetch();
        if (!$rule || (int)$rule['employee_id']!==$eid || $rule['name']!==$name.' 其他补助' || $rule['rule_type']!=='fixed' || (float)(json_decode($rule['params_json'],true)['amount']??0)!==$default*1.0) throw new RuntimeException('原补助规则已改变：'.$name);
        if ($rule['effective_to']==='2026-08') { $result[]=['name'=>$name,'already_converted'=>true]; continue; }
        if ($rule['effective_to']!==null) throw new RuntimeException('规则结束日期已改变：'.$name);
        $q=$pdo->prepare('SELECT payroll_month,value FROM project_monthly_inputs WHERE rule_id=? AND employee_id=? AND payroll_month>=?');$q->execute([$rid,$eid,'2026-09']);$inputs=$q->fetchAll(PDO::FETCH_KEY_PAIR);
        $months=array_keys($inputs);
        foreach ($periods as $month=>$status) if ($month<date('Y-m')) $months[]=$month;
        $imported=[];
        foreach (array_unique($months) as $month) {
            if (($periods[$month]??'draft')==='locked') continue;
            $amount=(float)($inputs[$month]??$default);
            $q=$pdo->prepare("SELECT COUNT(*) FROM project_payroll_manual_items WHERE employee_id=? AND payroll_month=? AND kind='other' AND label=?");$q->execute([$eid,$month,PS_DIRECTOR_LABEL]);
            if ($q->fetchColumn()) throw new RuntimeException('本月已有独立董事补助，请先核对，避免重复：'.$name.' '.$month);
            $pdo->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount,note,created_by_admin) VALUES (?,?,'other',?,?,?,?)")
                ->execute([$eid,$month,PS_DIRECTOR_LABEL,$amount,'历史导入：原工资表补助，仅本月保留，待财务核定',$actor['id']]);
            $imported[$month]=$amount;
        }
        $pdo->prepare("UPDATE project_monthly_rules SET effective_to='2026-08' WHERE id=?")->execute([$rid]);
        $pdo->prepare("UPDATE project_payroll_flags SET color='yellow',reason='董事长/董事会补助改为逐月核定，请填写并核对本月实结算' WHERE employee_id=? AND payroll_month>=? AND (color IS NULL OR color<>'red')")
            ->execute([$eid,date('Y-m')]);
        ps_audit('monthly_rule',$rid,'director_allowance_monthly_only',$actor,['before'=>$rule,'from'=>'2026-09','imported_months'=>$imported,'note'=>'保留已有历史月份金额，取消后续月份固定沿用']);
        $result[]=['name'=>$name,'imported_months'=>$imported,'fixed_rule_ends'=>'2026-08'];
    }
    if (!$pdo->inTransaction()) throw new RuntimeException('迁移事务中断');
    if ($apply) $pdo->commit(); else $pdo->rollBack();
    echo json_encode(['applied'=>$apply,'people'=>$result],JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
