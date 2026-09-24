<?php
require_once __DIR__ . '/ProjectSettlement.php';

/**
 * 《程序表记录（成本中心记录）.xlsx》标准成本。
 * 表中“A/B”：A = 上游采购价（孙姐要成本），B = 核算成本（空间 + 域名 + 商投 50），分成按 B 计。
 * 表中只给后台空间价的程序按同表规则推算：空间+域名 = 后台 + 域名 80（+ 商投 50），空间 = 后台（+ 商投 50）。
 * 仅在财务点击“导入”后写入；已存在同名同规格的启用模板不会被覆盖。
 */
function ps_preset_cost_templates()
{
    $rows = [];
    $program = function ($name, array $plans) use (&$rows) {
        foreach ($plans as $spec => $pair) {
            [$supplier, $price] = $pair;
            $rows[] = ['category' => 'program', 'business_scope' => '网站模板', 'name' => $name, 'specification' => $spec, 'unit' => '套', 'cost_kind' => 'one_time', 'price' => $price, 'supplier_price' => $supplier];
        }
    };
    $senDong = ['森动中级版' => [[80,285],[0,205],[260,620],[0,360],[440,955],[0,515]], '森动高级版' => [[80,354.5],[0,274.5],[260,759],[0,499],[440,1163.5],[0,723.5]], '森动旗舰版' => [[80,454.5],[0,374.5],[260,959],[0,699],[440,1463.5],[0,1023.5]], '森动推广版' => [[80,879.5],[0,799.5],[260,1809],[0,1549],[440,2738.5],[0,2298.5]], '森动优化版' => [[80,889],[0,809],[260,1828],[0,1568],[440,2767],[0,2327]], '森动全推版' => [[80,1129.5],[0,1049.5],[260,2309],[0,2049],[440,3488.5],[0,3048.5]], '森动强推版' => [[80,1817],[0,1737],[260,3684],[0,3424],[440,5551],[0,5111]]];
    foreach ($senDong as $name => $v) $program($name, ['1年 空间+域名' => $v[0], '1年 空间' => $v[1], '3年(二送一) 空间+域名' => $v[2], '3年(二送一) 空间' => $v[3], '5年(三送二) 空间+域名' => $v[4], '5年(三送二) 空间' => $v[5]]);
    // JSP：商城只有二送二；展示中级、高级另有二送三；展示初级只有 1 年；国际版无二送三。
    $jsp = ['JSP展示初级版' => [[170,220],[90,140]], 'JSP展示中级版' => [[260,360],[180,280],[710,860],[360,510],[800,950],[360,510]], 'JSP展示高级版' => [[380,530],[300,450],[950,1200],[600,850],[1040,1290],[600,850]], 'JSP展示至尊版' => [[830,880],[750,800],[1850,1900],[1500,1550]], 'JSP展示国际版' => [[830,880],[750,800],[1850,1900],[1500,1550]], 'JSP商城标准版' => [[860,1110],[780,1030],[1910,2360],[1560,2010]], 'JSP商城高级版' => [[1260,1310],[1180,1230],[2710,2760],[2360,2410]], 'JSP商城豪华版' => [[1460,1510],[1380,1430],[3110,3160],[2760,2810]]];
    foreach ($jsp as $name => $v) {
        $plans = ['1年 空间+域名' => $v[0], '1年 空间' => $v[1]];
        if (isset($v[2])) { $plans['4年(二送二) 空间+域名'] = $v[2]; $plans['4年(二送二) 空间'] = $v[3]; }
        if (isset($v[4])) { $plans['5年(二送三) 空间+域名'] = $v[4]; $plans['5年(二送三) 空间'] = $v[5]; }
        $program($name, $plans);
    }
    $program('JSP展示中级版', ['10年(五赠五) 空间+域名' => [1790,2090], '10年(五赠五) 空间' => [900,1200]]);
    foreach (['青站（标准）' => 66, '青站优化版' => 96, '青站营销版' => 140, '青站商城版' => 260, '青站推广版' => 600, '青站全推版' => 881] as $name => $backend) {
        $program($name, ['1年 空间+域名' => [$backend + 80, $backend + 130], '1年 空间' => [$backend, $backend + 50]]);
    }
    $program('优站', ['1年 空间+域名' => [180,230], '1年 空间' => [100,150], '2年 空间+域名' => [370,420], '2年 空间' => [200,250], '5年(三送二) 空间+域名' => [800,850], '5年(三送二) 空间' => [360,410]]);
    $program('建站兔展示推广版', ['1年 空间+域名' => [180,230], '1年 空间' => [100,150]]);
    $program('米拓银牌代理模板', ['1年 空间+域名' => [499,549]]);
    $program('DEDE', ['无空间域名（仅商投）' => [0,50]]);
    $program('PHP', ['1年 空间+域名' => [80,170], '1年 空间（不加商投）' => [0,90]]);
    $program('纯利润', ['无成本（加购/补差价）' => [0,0]]);
    foreach (['https加密功能/SSL证书软件' => 160, '短信包' => 80, '邮件群发' => 80, '物流功能（接口）' => 400, '安卓APP上架' => 300, '苹果APP上架' => 600, 'JSP商城加手机端口' => 400] as $name => $price) {
        $rows[] = ['category' => 'plugin', 'business_scope' => '网站模板', 'name' => $name, 'specification' => '1年', 'unit' => '项', 'cost_kind' => 'annual', 'price' => $price, 'supplier_price' => $price];
    }
    // 华梦（下游外包网站开发）：8 月核对表中成本均为售价的 80%（3040/3800、1600/2000、3528/4410）。
    $rows[] = ['category' => 'outsourcing', 'business_scope' => 'AI网站定制', 'name' => '华梦定制外包', 'specification' => '按售价 80%', 'unit' => '单', 'cost_kind' => 'one_time', 'price' => 80, 'supplier_price' => 80, 'price_mode' => 'percent'];
    $rows[] = ['category' => 'domain', 'business_scope' => '', 'name' => '域名 .com/.cn/.net 首年', 'specification' => '1年', 'unit' => '年', 'cost_kind' => 'annual', 'price' => 80, 'supplier_price' => 80];
    $rows[] = ['category' => 'domain', 'business_scope' => '', 'name' => '域名续费（次年起）', 'specification' => '1年', 'unit' => '年', 'cost_kind' => 'annual', 'price' => 90, 'supplier_price' => 90];
    $rows[] = ['category' => 'certificate', 'business_scope' => '', 'name' => '域名SSL证书', 'specification' => '1年', 'unit' => '年', 'cost_kind' => 'annual', 'price' => 30, 'supplier_price' => 30];
    $rows[] = ['category' => 'certificate', 'business_scope' => '', 'name' => '泛域名SSL证书', 'specification' => '1年', 'unit' => '年', 'cost_kind' => 'annual', 'price' => 250, 'supplier_price' => 250];
    return $rows;
}

/**
 * 《网站核算.xlsx》《8月提成核对》《小程序部门核算标准.txt》中的逐单分成口径。
 * 月度底薪、全勤、排名奖、毛利超额奖金、内部前端按月利润换档、外包前端按售价换档仍属原月度结算，这里不重复计提；
 * 定制内部前端默认 13%（8 月档位），财务可按月新增版本调整。
 */
function ps_preset_rules()
{
    $r = function ($group, $type, $role, $kind, $mode, $rate, $fee, $subsidy = 0, $min = 0, $note = '', $minCost = null, $extra = []) {
        return ['commission_group' => $group, 'project_type' => $type, 'role_name' => $role, 'order_kind' => $kind, 'calc_mode' => $mode, 'rate' => $rate, 'service_fee_rate' => $fee, 'per_order_subsidy' => $subsidy, 'min_contract_amount' => $min, 'note' => $note, 'min_cost_rate' => $minCost,
            'allow_negative' => !empty($extra['allow_negative']) ? 1 : 0, 'low_profit_threshold' => $extra['low_threshold'] ?? null, 'low_profit_subsidy' => $extra['low_subsidy'] ?? null];
    };
    $neg = ['allow_negative' => true];
    return [
        $r('technical', '网站模板', '*', '*', 'pool', 0.13, 0.03, 0, 0, '模板技术：(售价−空间域名−3%服务费)×13%'),
        $r('technical', '网站模板', '资料员', '*', 'pool', 0.10, 0.03, 0, 0, '资料员：×10%'),
        $r('customer_service', '网站模板', '*', '*', 'pool', 0.08, 0.03, 0, 0, '模板客服 8%；两名客服各 50% 即各 4%'),
        $r('customer_service', 'AI网站定制', '*', '*', 'pool', 0.10, 0.03, 0, 0, '定制客服 10%（两人合接各 5%）；博山定制成本按售价 65% 计，华梦外包按实际 80%', 0.65),
        $r('technical', 'AI网站定制', '前端', '*', 'individual', 0.13, 0.06, 0, 0, '内部前端：(售价−6%服务费−域名−SSL)×档位比例（按月利润 5%~15%，8 月档 13%）'),
        $r('technical', 'AI网站定制', '外包前端', '*', 'individual', 0.20, 0, 0, 0, '外包前端不扣服务费；按售价档 15%/20%/25%'),
        $r('technical', 'AI网站定制', '后端', '*', 'individual', 0.10, 0.06, 0, 0, '后端：(售价−6%−域名−SSL)×10%；域名/SSL 与前端各担 50%'),
        $r('technical', 'AI网站定制', '售后', '*', 'individual', 0.15, 0.06, 0, 0, '定制售后：×15%'),
        $r('technical', '环境配置', '*', '*', 'pool', 0.15, 0.03, 0, 0, '环境配置技术：(收入−成本−3%)×15%'),
        $r('customer_service', '环境配置', '*', '*', 'pool', 0.10, 0.03, 0, 0, '环境配置客服：全部环境配置订单 (收入−成本−3%)×10%（纪鹏程另按技术 15% 计本人做的单）'),
        $r('customer_service', '小程序开发', '*', '*', 'pool', 0.05, 0.03, 0, 0, '小程序客服：(金额−成本−3%)×5%'),
        $r('customer_service', '小程序开发', '*', '新订单', 'pool', 0.05, 0.03, 20, 0, '新订单 5% + 每单 20 元补助'),
        $r('customer_service', '小程序开发', '*', '续费', 'pool', 0, 0.03, 0, 0, '续费订单暂不核算提成'),
        $r('customer_service', '小程序开发', '定制客服', '定制', 'pool', 0.10, 0.03, 10, 50, '定制客服 10% + 每单 10 元，售价低于 50 元不算'),
        $r('technical', '小程序开发', '*', '*', 'pool', 0.05, 0.03, 0, 0, '小程序技术：所有订单 5%'),
        $r('technical', '小程序开发', '*', '新订单', 'pool', 0.05, 0.03, 20, 0, '新建站（v4/外卖）5% + 每单 20 元补助'),
        $r('technical', '小程序开发', '*', '定制', 'pool', 0.30, 0.03, 20, 50, '定制技术 30% + 每单 20 元，售价低于 50 元不算'),
        $r('technical', '小程序开发', '定制技术15', '定制', 'pool', 0.15, 0.03, 20, 50, '翟建跃：定制 15% + 每单 20 元，售价低于 50 元不算'),
        $r('customer_service', '小额引流', '*', '*', 'pool', 0, 0, 3, 0, '小额引流：客服每单补助 3 元'),
        // 代写部门《提成算法》：利润 = 售价 − 稿费 − 售价×5.7%；利润提成 4%，退款冲减按负数计入；单量提成利润 5 元以上 2.5 元/单、以下 1.5 元/单；同一旺旺 3 天内同一写手记为合并单不计单量。
        $r('customer_service', '软文代写', '*', '*', 'pool', 0.04, 0.057, 2.5, 0, '代写客服：利润×4% + 单量 2.5 元/单（利润 5 元以下 1.5 元）', null, ['allow_negative' => true, 'low_threshold' => 5, 'low_subsidy' => 1.5]),
        $r('customer_service', '软文代写', '*', '合并单', 'pool', 0.04, 0.057, 0, 0, '合并单（同一旺旺 3 天内同一写手）：只计利润提成，不计单量', null, $neg),
        $r('customer_service', '软文代写', '*', '退款冲减', 'pool', 0.04, 0.057, 0, 0, '退款 / 换写手冲减：按负数冲减利润提成', null, $neg),
        $r('technical', '软文代写', '*', '*', 'pool', 0, 0.057, 2.5, 0, '微信代写编辑对接建群：每单 2.5 元'),
        $r('technical', '软文代写', '*', '合并单', 'pool', 0, 0.057, 0, 0, '合并单不计对接'),
        $r('technical', '软文代写', '*', '退款冲减', 'pool', 0, 0.057, 0, 0, '冲减单不计对接'),
        // 期刊：提成 = (利润 − 单量提成) × 3% + 单量提成 50 元/单 = 利润 × 3% + 48.5 元/单；利润 = 总价 − 杂志社/写手费用 − 服务费（店铺 5.7%，微信 0.35%）。
        $r('customer_service', '期刊', '*', '*', 'pool', 0.03, 0.057, 48.5, 0, '期刊（店铺付款）：(利润 − 50)×3% + 50 元/单', null, $neg),
        $r('customer_service', '期刊', '*', '店铺付款', 'pool', 0.03, 0.057, 48.5, 0, '期刊（店铺付款）：(利润 − 50)×3% + 50 元/单', null, $neg),
        $r('customer_service', '期刊', '*', '微信付款', 'pool', 0.03, 0.0035, 48.5, 0, '期刊（微信付款，服务费 0.35%）：(利润 − 50)×3% + 50 元/单', null, $neg),
        $r('customer_service', '期刊', '*', '代付版面费', 'pool', 0.03, 0.057, 0, 0, '代付版面费：不计单量，服务费照扣（冲减利润提成）', null, $neg),
        // 设计客服《提成算法》：PPT (售价 − 售价×5.5% − 售价×40%) × 5%（设计师成本按售价 40%）；图片 (售价 − 售价×3.1%) × 5% + 单量 0.5 元。
        $r('customer_service', '设计', '*', 'PPT', 'pool', 0.05, 0.055, 0, 0, '设计 PPT：(售价 − 5.5% − 设计师 40%) × 5%', 0.40),
        $r('customer_service', '设计', '*', '图片', 'pool', 0.05, 0.031, 0.5, 0, '设计图片：(售价 − 3.1%) × 5% + 0.5 元/单'),
        $r('customer_service', '设计', '*', '图片同客户', 'pool', 0.05, 0.031, 0, 0, '同一客户当月再次下单：只计 5% 提成，不计单量'),
        // 微信代写：逐单只记毛利（售价 − 稿费）与订单补助；利润提成在月度“部门利润池分配”。
        $r('customer_service', '微信代写', '*', '店铺订单', 'pool', 0, 0, 3, 0, '微信代写店铺订单：每单补助 3 元；毛利计入部门利润池'),
        $r('customer_service', '微信代写', '*', '微信付款', 'pool', 0, 0, 0, 0, '微信付款订单：无补助；毛利计入部门利润池'),
    ];
}

function ps_preset_template_exists($row)
{
    $q = db()->prepare('SELECT price FROM project_cost_templates WHERE category=? AND name=? AND specification=? AND is_active=1 ORDER BY version DESC LIMIT 1');
    $q->execute([$row['category'], $row['name'], $row['specification']]);
    $price = $q->fetchColumn();
    if ($price === false) return 'new';
    return abs((float)$price - (float)$row['price']) < 0.005 ? 'same' : 'differs';
}

function ps_preset_rule_exists($row)
{
    $q = db()->prepare("SELECT rate,calc_mode,service_fee_rate,per_order_subsidy,min_contract_amount,min_cost_rate,allow_negative,low_profit_threshold,low_profit_subsidy FROM project_commission_rules WHERE commission_group=? AND project_type=? AND role_name=? AND order_kind=? AND is_active=1 ORDER BY effective_from DESC,id DESC LIMIT 1");
    $q->execute([$row['commission_group'], $row['project_type'], $row['role_name'], $row['order_kind']]);
    $current = $q->fetch();
    if (!$current) return 'new';
    $same = abs((float)$current['rate'] - $row['rate']) < 0.0000005 && $current['calc_mode'] === $row['calc_mode']
        && $current['service_fee_rate'] !== null && abs((float)$current['service_fee_rate'] - $row['service_fee_rate']) < 0.0000005
        && abs((float)$current['per_order_subsidy'] - $row['per_order_subsidy']) < 0.005 && abs((float)$current['min_contract_amount'] - $row['min_contract_amount']) < 0.005
        && abs((float)$current['min_cost_rate'] - (float)($row['min_cost_rate'] ?? 0)) < 0.0000005
        && (int)$current['allow_negative'] === (int)($row['allow_negative'] ?? 0)
        && abs((float)$current['low_profit_threshold'] - (float)($row['low_profit_threshold'] ?? 0)) < 0.005 && abs((float)$current['low_profit_subsidy'] - (float)($row['low_profit_subsidy'] ?? 0)) < 0.005;
    return $same ? 'same' : 'differs';
}

/** 只新增缺失的模板；价格不同的保留财务现价（需在成本中心手动改价）。 */
function ps_apply_preset_templates($actor)
{
    $insert = db()->prepare('INSERT INTO project_cost_templates (category,business_scope,name,specification,unit,price_mode,cost_kind,price,supplier_price,requires_proof,auto_approve,version) VALUES (?,?,?,?,?,?,?,?,?,0,1,1)');
    $added = 0;
    foreach (ps_preset_cost_templates() as $row) {
        if (ps_preset_template_exists($row) !== 'new') continue;
        $insert->execute([$row['category'], $row['business_scope'], $row['name'], $row['specification'], $row['unit'], $row['price_mode'] ?? 'fixed', $row['cost_kind'], $row['price'], $row['supplier_price']]);
        $added++;
    }
    ps_audit('template', 0, 'import_preset', $actor, ['added' => $added, 'source' => '程序表记录（成本中心记录）.xlsx']);
    return $added;
}

/** 新增缺失或参数不同的规则版本（2026-09-01 起生效）；已审核快照不受影响。 */
function ps_apply_preset_rules($actor, $effectiveFrom = '2026-09-01')
{
    $insert = db()->prepare('INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,min_cost_rate,allow_negative,low_profit_threshold,low_profit_subsidy,note,effective_from) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $added = 0;
    foreach (ps_preset_rules() as $row) {
        if (ps_preset_rule_exists($row) === 'same') continue;
        $insert->execute([$row['commission_group'], $row['project_type'], $row['role_name'], $row['order_kind'], $row['calc_mode'], $row['rate'], $row['service_fee_rate'], $row['per_order_subsidy'], $row['min_contract_amount'], $row['min_cost_rate'] ?? null, $row['allow_negative'] ?? 0, $row['low_profit_threshold'] ?? null, $row['low_profit_subsidy'] ?? null, $row['note'], $effectiveFrom]);
        $added++;
    }
    ps_audit('rule', 0, 'import_preset', $actor, ['added' => $added, 'effective_from' => $effectiveFrom]);
    return $added;
}
