<?php

/**
 * 月度口径预置：部门核算表（奖金、排名、阶梯、主管提成、计件）+《2026.7/8 月合作商收入表》（固定服务费、全勤奖、各项补助）。
 * 人员按姓名匹配，找不到或重名的跳过并提示。
 */
function ps_monthly_presets()
{
    $all = '*';
    $rows = [
        ['name' => '模板技术超额奖金', 'rule_type' => 'threshold_bonus', 'scope_business' => '网站模板', 'scope_group' => 'technical', 'scope_role' => '模板技术', 'employee' => null, 'metric' => 'profit', 'params' => ['threshold' => 10000, 'rate' => 0.015], 'note' => '光君 / 孙妍 / 张强：毛利超过 1 万的部分 × 1.5%'],
        ['name' => '网站客服超额奖金', 'rule_type' => 'threshold_bonus', 'scope_business' => '网站模板,AI网站定制', 'scope_group' => 'customer_service', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => ['threshold' => 20000, 'rate' => 0.008], 'note' => '毛利超过 2 万的部分 × 0.8%（合接订单按整单毛利计入）'],
        ['name' => '定制客服月度第一名奖', 'rule_type' => 'ranking', 'scope_business' => 'AI网站定制', 'scope_group' => 'customer_service', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => ['awards' => [500]], 'note' => 'AI网站定制客服按当月毛利排名，第一名奖励 500 元（自 2026-10）', 'from' => '2026-10'],
        ['name' => '网站客服排名奖', 'rule_type' => 'ranking', 'scope_business' => '网站模板,AI网站定制', 'scope_group' => 'customer_service', 'scope_role' => '*', 'employee' => null, 'metric' => 'manual', 'params' => ['awards' => [500, 300, 200]], 'note' => '第一 500、第二 300、第三 200；名次按客服考核每月在规则中心填写'],
        ['name' => '定制内部前端阶梯', 'rule_type' => 'tier_rate', 'scope_business' => 'AI网站定制', 'scope_group' => 'technical', 'scope_role' => '前端', 'employee' => null, 'metric' => 'profit', 'params' => ['tiers' => [['from' => 0, 'rate' => 0.05, 'base' => '2000'], ['from' => 10000, 'rate' => 0.07, 'base' => '2000'], ['from' => 15000, 'rate' => 0.09, 'base' => '2500'], ['from' => 20000, 'rate' => 0.12, 'base' => '2500'], ['from' => 25000, 'rate' => 0.13, 'base' => '2500'], ['from' => 30000, 'rate' => 0.14, 'base' => '2500'], ['from' => 35000, 'rate' => 0.15, 'base' => '2500']]], 'note' => '刘帅：按月利润落档，全部业绩统一按该档比例'],
        ['name' => '外包前端阶梯', 'rule_type' => 'tier_rate', 'scope_business' => 'AI网站定制', 'scope_group' => 'technical', 'scope_role' => '外包前端', 'employee' => null, 'metric' => 'sales', 'params' => ['tiers' => [['from' => 0, 'rate' => 0.15, 'base' => ''], ['from' => 10000, 'rate' => 0.20, 'base' => ''], ['from' => 30000, 'rate' => 0.25, 'base' => '']]], 'note' => '李仁超 / 孙磊：按月售价 1 万以下 15%、1–3 万 20%、3 万以上 25%'],
        ['name' => '环境配置主管提成', 'rule_type' => 'dept_share', 'scope_business' => '环境配置', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '于洋', 'metric' => 'profit', 'params' => ['rate' => 0.05, 'share' => 0.5, 'base' => 'revenue', 'deduct_commissions' => true], 'note' => '于洋（网站售后部主管）：(环境配置收入 − 3% 服务费 − 部门客服/技术提成 − 员工底薪等其他费用) × 5% × 50%'],
        ['name' => '优站模板奖励', 'rule_type' => 'per_unit', 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => ['amount' => 15], 'note' => '每做一个优站模板奖励 15 元（8 月李仁超 75、李子晖 195、崔鑫栋 30）'],
        ['name' => '其他业务提成（未接入系统）', 'rule_type' => 'manual', 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => [], 'note' => '标书、续费、代写等尚未在项目系统录单的业务提成，由财务每月填写'],
        ['name' => '微信代写部门利润池', 'rule_type' => 'profit_pool', 'scope_business' => '微信代写', 'scope_group' => 'customer_service', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => ['deduction' => 6000, 'rate' => 0.10, 'members_share' => 0.68, 'fixed' => [['name' => '姚鹏', 'share' => 0.13], ['name' => '李雪', 'share' => 0.13]], 'milestone' => ['from' => 60000, 'step' => 10000, 'amount' => 100, 'cap' => 1300]], 'note' => '《微信代写提成比例汇总》：(四名编辑总利润 − 1000×6) × 10%；姚鹏、李雪各 13%，编辑 68% 按本人利润占比'],
        ['name' => '设计客服绩效固定服务费', 'rule_type' => 'perf_rank', 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => [], 'note' => '原系统客服绩效：设计客服多店绩效平均分排名，第 1/2/3 名 850/800/750，按考勤折算'],
        ['name' => '乔立宾代写单量提成', 'rule_type' => 'order_count', 'scope_business' => '软文代写,微信代写', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '乔立宾', 'metric' => 'profit', 'params' => ['amount' => 1.2], 'note' => '1.2 元/单：博山 + 博山微信（系统内已审核、稿费 > 0 的代写订单，一单多写手计 1 单）+ 临沂 / 东营 / 合伙团队等（每月填写单量）'],
        // 网站售后部《网站售后部算法》：各人按全部网站续费毛利（收入 − 成本 − 3%）的比例提成
        ['name' => '张宁 网站续费提成', 'rule_type' => 'dept_share', 'scope_business' => '网站续费', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '张宁', 'metric' => 'profit', 'params' => ['rate' => 0.022, 'share' => 1, 'base' => 'profit', 'deduct_commissions' => false], 'note' => '(网站续费收入 − 成本 − 3%) × 2.2%'],
        ['name' => '戴倩 网站续费提成', 'rule_type' => 'dept_share', 'scope_business' => '网站续费', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '戴倩', 'metric' => 'profit', 'params' => ['rate' => 0.018, 'share' => 1, 'base' => 'profit', 'deduct_commissions' => false], 'note' => '(网站续费收入 − 成本 − 3%) × 1.8%'],
        ['name' => '刘晓 网站续费提成', 'rule_type' => 'dept_share', 'scope_business' => '网站续费', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '刘晓', 'metric' => 'profit', 'params' => ['rate' => 0.018, 'share' => 1, 'base' => 'profit', 'deduct_commissions' => false], 'note' => '(网站续费收入 − 成本 − 3%) × 1.8%'],
        ['name' => '郭文娟 网站续费提成', 'rule_type' => 'dept_share', 'scope_business' => '网站续费', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '郭文娟', 'metric' => 'profit', 'params' => ['rate' => 0.019, 'share' => 1, 'base' => 'profit', 'deduct_commissions' => false], 'note' => '(网站续费收入 − 成本 − 3%) × 1.9%'],
        ['name' => '刘媛媛 网站续费提成', 'rule_type' => 'dept_share', 'scope_business' => '网站续费', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '刘媛媛', 'metric' => 'profit', 'params' => ['rate' => 0.019, 'share' => 1, 'base' => 'profit', 'deduct_commissions' => false], 'note' => '(网站续费收入 − 成本 − 3%) × 1.9%'],
        ['name' => '孙杰 网站续费提成', 'rule_type' => 'dept_share', 'scope_business' => '网站续费', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '孙杰', 'metric' => 'profit', 'params' => ['rate' => 0.01, 'share' => 1, 'base' => 'profit', 'deduct_commissions' => false], 'note' => '(网站续费收入 − 成本 − 3%) × 1%'],
        // 平面设计（阎泸琪）：按月营业额阶梯结算，另有全勤奖 200
        ['name' => '阎泸琪 营业额阶梯薪酬', 'rule_type' => 'sales_package', 'scope_business' => '平面设计', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '阎泸琪', 'metric' => 'sales', 'params' => ['tiers' => [['upto' => 3000, 'base' => 1800, 'rate' => 0, 'big' => 0, 'small' => 0], ['upto' => 6000, 'base' => 2100, 'rate' => 0.05, 'big' => 2, 'small' => 0.5], ['upto' => 9000, 'base' => 2300, 'rate' => 0.08, 'big' => 3, 'small' => 0.5], ['upto' => 12000, 'base' => 3300, 'rate' => 0.1, 'big' => 5, 'small' => 0.5], ['upto' => 15000, 'base' => 4800, 'rate' => 0.1, 'big' => 5, 'small' => 0.5]], 'big_threshold' => 50, 'returning_rate' => 0.1, 'review_min' => 10, 'review_penalty' => 100], 'note' => '平面设计：按月营业额落档，底薪按考勤折算；老客户找回 +10%；好评率每月填写，低于 10% 扣 100'],
        ['name' => '阎泸琪 全勤奖', 'rule_type' => 'attendance_bonus', 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '阎泸琪', 'metric' => 'profit', 'params' => ['amount' => 200], 'note' => '请假 <4 小时全额、≥4 小时减半、≥8 小时不发'],
        ['name' => '其他调整', 'rule_type' => 'manual', 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => [], 'note' => '上月漏记、临时奖扣等，每月填写并写明原因'],
    ];
    // 固定服务费（原基本工资，按考勤折算）；网站客服为每月不同的“补单提成”，默认 0，每月在规则中心填写。
    foreach (['光君' => 800, '张强' => 800, '孙妍' => 800, '刘帅' => 2300, '于海波' => 2300, '崔鑫栋' => 2300, '李子晖' => 2800, '纪鹏程' => 1300, '石凯新' => 2000, '刘丹丹' => 2000, '曹双双' => 800, '王宁' => 800, '王亚' => 3000, '吴宁' => 1800, '刘媛媛' => 800, '于洋' => 3800, '翟建跃' => 4800, '朱俊英' => 2300, '田悦琦' => 3300, '谢文婷' => 2800, '高晶晶' => 2300, '姚琳' => 3900, '孙曼' => 3800, '刘群' => 3500, '魏慧子' => 3800, '王芳' => 3400, '宋文娜' => 2700, '王向晖' => 800, '刘淑萍' => 800, '徐春' => 800, '乔立宾' => 5000, '韩菲菲' => 1000, '孙梦琦' => 1000, '张钰琪' => 1000, '李雪' => 1000, '姚鹏' => 1500, '刘玉霜' => 3800, '张珂' => 3300, '王红' => 3200, '周丽' => 3200, '孙承晏' => 2700, '张宁' => 800, '戴倩' => 800, '刘晓' => 800, '郭文娟' => 800, '房烁' => 800, '孙杰' => 2300, '秦婷婷' => 800, '孙荣姿' => 800, '于娜' => 800, '王慧资' => 800, '王庆美' => 800, '董旭' => 0, '宋倩倩' => 0, '苏婷' => 0, '孙湉湉' => 0] as $name => $amount) {
        $rows[] = ['name' => $name . ' 固定服务费', 'rule_type' => 'base_fee', 'scope_business' => $all, 'scope_group' => $all, 'scope_role' => $all, 'employee' => $name, 'metric' => 'profit', 'params' => ['amount' => $amount], 'note' => $amount > 0 ? '原基本工资，按考勤折算' : '网站客服补单提成，每月金额不同，请在本月试算里填写（按考勤折算）'];
    }
    foreach (['光君' => 200, '张强' => 200, '孙妍' => 200, '刘帅' => 200, '于海波' => 200, '崔鑫栋' => 200, '李子晖' => 200, '纪鹏程' => 200, '石凯新' => 200, '刘丹丹' => 200, '曹双双' => 200, '王宁' => 200, '吴宁' => 200, '刘媛媛' => 200, '于洋' => 200, '翟建跃' => 200, '朱俊英' => 200, '田悦琦' => 200, '谢文婷' => 200, '高晶晶' => 200, '姚琳' => 200, '孙曼' => 200, '刘群' => 200, '魏慧子' => 200, '王芳' => 200, '宋文娜' => 200, '王向晖' => 200, '刘淑萍' => 200, '徐春' => 200, '韩菲菲' => 200, '孙梦琦' => 200, '张钰琪' => 200, '李雪' => 200, '姚鹏' => 200, '张欣' => 200, '穆楠' => 200, '孙静怡' => 200, '刘玉霜' => 200, '张珂' => 200, '王红' => 200, '周丽' => 200, '孙承晏' => 200, '张宁' => 200, '戴倩' => 200, '刘晓' => 200, '郭文娟' => 200, '房烁' => 200, '孙杰' => 200, '秦婷婷' => 200, '孙荣姿' => 200, '于娜' => 200, '王慧资' => 200, '王庆美' => 200, '董旭' => 100, '宋倩倩' => 100, '苏婷' => 100, '孙湉湉' => 100] as $name => $amount) {
        $rows[] = ['name' => $name . ' 全勤奖', 'rule_type' => 'attendance_bonus', 'scope_business' => $all, 'scope_group' => $all, 'scope_role' => $all, 'employee' => $name, 'metric' => 'profit', 'params' => ['amount' => $amount], 'note' => '请假 <4 小时全额、≥4 小时减半、≥8 小时不发'];
    }
    // 管理（收入表“管理”页）：每月固定，不按考勤折算；代扣保险等在应发之后另行处理
    foreach (['张富全' => 8000, '王桂美' => 4900, '张光萍' => 8000, '张欣源' => 8000] as $name => $amount) {
        $rows[] = ['name' => $name . ' 固定服务费', 'rule_type' => 'base_fee', 'scope_business' => $all, 'scope_group' => $all, 'scope_role' => $all, 'employee' => $name, 'metric' => 'profit', 'params' => ['amount' => $amount, 'no_prorate' => true], 'note' => '管理岗，每月固定，不按考勤折算'];
    }
    foreach ([['孙妍', '经理补助', 100, false], ['崔鑫栋', '部门经理补助', 200, false], ['石凯新', '技术主管补助', 500, false], ['于洋', '其他补助', 2000, false], ['翟建跃', '其他补助', 900, false], ['王亚', '其他补助', 300, false], ['曹双双', '其他补助', 200, false], ['王宁', '其他补助', 200, false], ['姚琳', '其他补助', 300, false], ['孙曼', '其他补助', 400, false], ['刘群', '经理补助', 300, false], ['魏慧子', '其他补助', 200, false], ['宋文娜', '其他补助', 500, false], ['李雪', '其他补助', 300, false], ['姚鹏', '经理补助', 200, false], ['姚鹏', '其他补助', 200, false], ['姚鹏', '法人补助', 300, true], ['刘玉霜', '经理补助', 300, false], ['冯超', '经理补助', 100, false], ['刘玉霜', '其他补助', 300, false], ['刘玉霜', '法人补助', 600, true], ['孙承晏', '法人补助', 400, true], ['戴倩', '保险补助', 600, false], ['于洋', '法人补助', 500, true], ['翟建跃', '法人补助', 200, true]] as [$name, $label, $amount, $separate]) {
        $rows[] = ['name' => $name . ' ' . $label, 'rule_type' => 'fixed', 'scope_business' => $all, 'scope_group' => $all, 'scope_role' => $all, 'employee' => $name, 'metric' => 'profit', 'params' => ['amount' => $amount, 'separate' => $separate], 'note' => $separate ? '由关联公司另行支付，单列展示，不计入应结算' : '收入表“经理补助 / 其他补助”'];
    }
    return $rows;
}

function ps_monthly_apply_presets($actor, $month)
{
    $exists = db()->prepare('SELECT 1 FROM project_monthly_rules WHERE name=? AND is_active=1 LIMIT 1');
    $findEmployee = db()->prepare('SELECT id FROM employees WHERE name=?');
    $insert = db()->prepare('INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note,updated_by_admin) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $added = 0;
    $skipped = [];
    $legacyNames = ['孙妍 经理补助' => '孙妍经理补助', '崔鑫栋 部门经理补助' => '崔鑫栋部门经理补助', '石凯新 技术主管补助' => '小程序技术主管补助'];
    foreach (ps_monthly_presets() as $row) {
        if (isset($legacyNames[$row['name']])) { $exists->execute([$legacyNames[$row['name']]]); if ($exists->fetchColumn()) continue; }
        $exists->execute([$row['name']]);
        if ($exists->fetchColumn()) continue;
        $employeeId = null;
        if ($row['employee'] !== null) {
            $findEmployee->execute([$row['employee']]);
            $ids = $findEmployee->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) > 1) {
                // 重名时取已开通项目账号的那位（如两条“孙湉湉”，网站客服那条有账号）
                $withAccount = db()->prepare('SELECT e.id FROM employees e JOIN project_users u ON u.employee_id=e.id AND u.is_active=1 WHERE e.name=?');
                $withAccount->execute([$row['employee']]);
                $accountIds = $withAccount->fetchAll(PDO::FETCH_COLUMN);
                if (count($accountIds) === 1) $ids = $accountIds;
            }
            if (count($ids) !== 1) { $skipped[] = $row['name'] . '（人员“' . $row['employee'] . '”' . (count($ids) ? '重名' : '不存在') . '）'; continue; }
            $employeeId = (int)$ids[0];
        }
        if (!empty($row['params']['fixed'])) {
            // 利润池固定分成人员按姓名解析
            foreach ($row['params']['fixed'] as $i => $member) {
                $findEmployee->execute([$member['name']]);
                $memberIds = $findEmployee->fetchAll(PDO::FETCH_COLUMN);
                if (count($memberIds) !== 1) { $skipped[] = $row['name'] . '（固定分成人员“' . $member['name'] . '”' . (count($memberIds) ? '重名' : '不存在') . '）'; continue 2; }
                $row['params']['fixed'][$i]['employee_id'] = (int)$memberIds[0];
            }
        }
        $insert->execute([$row['name'], $row['rule_type'], $row['scope_business'], $row['scope_group'], $row['scope_role'], $employeeId, $row['metric'], json_encode($row['params'], JSON_UNESCAPED_UNICODE), max($month, (string)($row['from'] ?? '')), $row['note'], $actor['id']]);
        $added++;
    }
    ps_audit('monthly_rule', 0, 'import_preset', $actor, ['added' => $added, 'skipped' => $skipped]);
    return [$added, $skipped];
}
