<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectKnowledge.php';
require_once __DIR__ . '/../includes/dup_feedback.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db();
require_isolated_test_database($pdo);
require __DIR__ . '/../migrations/apply_management_accounts.php';
// 首次建表/默认主管同步会执行 DDL，必须在测试事务前完成。
pd_ensure();
$assert = function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$pdo->beginTransaction();
try {
    $tag = 'MANAGER-' . bin2hex(random_bytes(5));
    $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute([$tag,'商标',md5($tag)]);
    $eid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO project_users (employee_id,username,password_hash,role) VALUES (?,?,?,?)')->execute([$eid,$tag,password_hash($tag,PASSWORD_DEFAULT),'management']);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO project_user_businesses (user_id,business_name,is_default) VALUES (?,?,1)')->execute([$uid,'商标']);
    $pdo->prepare('INSERT INTO project_dept_heads (department,employee_id) VALUES (?,?)')->execute(['商标',$eid]);
    $_SESSION['project_user_id'] = $uid;
    unset($_SESSION['admin_id']);
    $actor = ps_actor();
    $assert($actor['account_role'] === 'management' && $actor['type'] === 'employee', '管理身份未保留');
    $assert($actor['role'] === 'customer_service' && $actor['role'] !== 'finance', '管理身份应兼容原录单流程，不能授予财务');
    $assert(ps_actor_businesses($actor) === ['商标'], '业务范围扩权');
    $assert(!array_diff(pk_departments($actor), ['商标','商标部']) && in_array('商标', pk_departments($actor), true), '主管部门未关联或扩权');
    $assert(ps_active_employee_for_business($eid, 'customer_service', '商标'), '管理账户无法参与已授权业务');
    $assert(!ps_active_employee_for_business($eid, 'customer_service', '小程序开发'), '未授权业务扩权');
    $assert(!ps_active_employee_for_business($eid, 'technical', '商标'), '管理身份不应成为技术');
    $assert(ps_business_account_products('网站客服', 'management') === ['网站模板','AI网站定制'], '网站业务映射错误');
    $assert(ps_business_account_products('小程序客服', 'management') === ['小程序开发','小额引流'], '小程序业务映射错误');
    $pdo->prepare('INSERT INTO project_orders (order_no,project_type,order_date) VALUES (?,?,?)')->execute([$tag.'-TM','商标','2096-01-01']);
    $tm = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO project_orders (order_no,project_type,order_date) VALUES (?,?,?)')->execute([$tag.'-OTHER','设计','2096-01-01']);
    $other = (int)$pdo->lastInsertId();
    $assert((int)ps_order($tm,$actor)['id'] === $tm, '主管无法查看本业务同事订单');
    $sql = 'SELECT COUNT(*) FROM project_orders o WHERE o.id IN ('.$tm.','.$other.') AND ('.ps_management_order_condition($actor).')';
    $assert((int)$pdo->query($sql)->fetchColumn() === 1, '部门主管订单列表扩权');
    ps_account_management_save($uid,'management',['management_scope'=>'assigned','management_title'=>'商标主管','fixed_pay_only'=>1]);
    $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute([$tag.'-CS','商标',md5($tag)]);
    $csid=(int)$pdo->lastInsertId();
    ps_intake_participants($tm,['customer_service'=>[['id'=>$eid,'role'=>'客服'],['id'=>$csid,'role'=>'客服']],'technical'=>[['id'=>$eid,'role'=>'资料专员']]],'商标');
    $participants=ps_participants($tm);
    foreach($participants as $p) $assert((float)$p['group_weight']===((int)$p['employee_id']===$eid ? 0.0 : 1.0), '固定薪酬主管稀释了实际参与人的分成权重');
    $sum=ps_summary(['id'=>$tm,'project_type'=>'商标','order_date'=>'2096-01-01','order_kind'=>'普通订单','contract_amount'=>330,'receipt_amount'=>330,'refund_amount'=>0], [['review_status'=>'approved','amount'=>270]], $participants);
    foreach($sum['groups'] as $group)foreach($group['people'] as $p)if((int)$p['employee_id']===$eid)$assert((float)$p['calc']['share']===0.0 && (float)$p['calc']['subsidy']===0.0,'固定薪酬主管误套普通客服或资料专员分成');
    ps_account_management_save($uid,'management',['management_scope'=>'company','management_title'=>'运营总监']);
    $actor = ps_actor();
    $assert(ps_management_company($actor) && $actor['management_title'] === '运营总监', '全公司管理范围未保存');
    $assert(pk_departments($actor) === ['*'], '运营总监不能管理全公司知识');
    $assert(ps_management_can_business($actor,'设计') && ps_management_can_business($actor,'小程序开发'), '运营总监不能管理其他业务');
    $assert((int)ps_order($other,$actor)['id'] === $other, '运营总监不能查看其他业务订单');
    $assert(in_array($eid,ps_management_company_employee_ids(),true), '运营总监不能接收部门审核事项');
    $assert(ps_active_employee_for_business($eid,'customer_service','小程序开发'), '全公司管理账号业务校验错误');
    $assert($actor['role'] !== 'finance', '运营总监被提升为财务');
    ps_account_management_save($uid,'management',['management_scope'=>'assigned','management_title'=>'商标主管']);
    $assert(!ps_management_can_business(ps_actor(),'设计'), '撤回全公司范围未生效');
    $pdo->prepare('UPDATE project_users SET role=?,is_active=0 WHERE id=?')->execute(['technical',$uid]);
    $assert(ps_actor() === null, '停用账户仍可登录');
    // 收入表王姿涵原标准 2800，8 月请假 1 天 = 2706.67；此处只在内存验证，不预设主管分成。
    $assert(ps_monthly_prorate(2800, ['work'=>200,'absent'=>8])[0] === 2706.67, '固定服务费请假折算与收入表不一致');
    $assert(ps_monthly_prorate(2800, ['work'=>208,'absent'=>0])[0] === 2800.0, '固定服务费满勤错误');
    $assert(ps_monthly_prorate(2800, ['work'=>0,'absent'=>240])[0] === 0.0, '无出勤固定服务费错误');
    $rules = [];
    foreach ([['base_fee','固定服务费',2800],['attendance_bonus','全勤奖',200],['fixed','董事会补助',300],['fixed','视频制作补助',300]] as $i=>$v) {
        $rules[]=['id'=>80000+$i,'name'=>$v[1],'rule_type'=>$v[0],'scope_business'=>'*','scope_group'=>'*','scope_role'=>'*','employee_id'=>$eid,'metric'=>'profit','params'=>['amount'=>$v[2]]];
    }
    $ctx=['rules'=>$rules,'snapshots'=>[],'inputs'=>[80001=>[$eid=>['value'=>200,'note'=>'全勤已批准']]],'attendance'=>[$eid=>['work'=>208,'absent'=>0]]];
    $results=ps_monthly_results('2096-01',true,$ctx);
    $assert(array_sum(array_column($results,'amount')) === 3600.0 && count($results)===4, '王姿涵四项薪酬引擎计算错误');
    $ctx['inputs']=[];
    $results=ps_monthly_results('2096-01',true,$ctx);
    $assert(array_sum(array_column($results,'amount')) === 3400.0 && strpos($results[1]['detail'],'待财务批准')!==false, '全勤奖未经批准就计入报酬');
    $ctx['attendance'][$eid]=['work'=>200,'absent'=>8];$ctx['inputs']=[80001=>[$eid=>['value'=>0,'note'=>'请假一天']]];
    $results=ps_monthly_results('2096-01',true,$ctx);
    $assert(round(array_sum(array_column($results,'amount')),2) === 3306.67, '请假一天工资与固定补助计算错误');
    echo "PASS management identity, scoped supervisor grants, account lifecycle, business mappings and base-fee proration\n";
} finally {
    unset($_SESSION['project_user_id']);
    if ($pdo->inTransaction()) $pdo->rollBack();
}
