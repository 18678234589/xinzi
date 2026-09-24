<?php
require_once __DIR__ . '/ProjectSettlement.php';

/**
 * 业务目录。service_fee_rate 是业务默认店铺服务费（按售价），分成规则可按组/岗位覆盖；
 * order_kinds 用于匹配分成规则（如小程序“新订单/定制/续费”）；program 表示使用成本中心的网站程序套餐。
 */
function ps_business_catalog()
{
    return [
        'AI网站定制' => ['departments' => ['定制前端','定制后端','网站定制','定制售后'], 'resources' => true, 'requires_technical' => true, 'service_fee_rate' => 0, 'order_kinds' => [], 'fields' => []],
        '网站模板' => ['departments' => ['网站模板','模板技术','网站技术','网站资料员'], 'resources' => true, 'requires_technical' => true, 'program' => true, 'service_fee_rate' => 0.03, 'order_kinds' => ['新订单','续费','加购/纯利润'], 'fields' => ['website_url' => '网站地址', 'template_name' => '模板名称 / 版本', 'deployment_note' => '部署与交付说明']],
        '环境配置' => ['departments' => ['环境配置','服务器配置'], 'resources' => false, 'requires_technical' => true, 'service_fee_rate' => 0.03, 'order_kinds' => [], 'fields' => ['service_item' => '服务内容', 'server_info' => '服务器 / 环境说明']],
        // 旧分类只用于展示历史订单；新订单按产品类型建单，客服作为参与人加入同一订单号。
        '网站客服' => ['departments' => ['网站客服'], 'resources' => false, 'fields' => ['website_url' => '网站地址', 'service_item' => '服务事项'], 'legacy' => true],
        '小程序开发' => ['departments' => ['小程序技术','小程序','标书小程序'], 'resources' => false, 'requires_technical' => true, 'service_fee_rate' => 0.03, 'order_kinds' => ['新订单','定制','续费','技术服务'], 'kind_required' => true, 'fields' => ['miniapp_name' => '小程序名称', 'make_requirement' => '制作要求', 'customer_wechat' => '客户微信']],
        '小额引流' => ['departments' => [], 'resources' => false, 'requires_technical' => false, 'service_fee_rate' => 0, 'order_kinds' => [], 'fields' => ['refund_diff' => '客户退差价金额', 'service_item' => '业务说明']],
        '小程序客服' => ['departments' => ['小程序客服'], 'resources' => false, 'fields' => ['miniapp_name' => '小程序名称', 'service_item' => '服务事项'], 'legacy' => true],
        // 设计客服：PPT (售价 − 5.5% − 设计师 40%) × 5%；图片 (售价 − 3.1%) × 5% + 0.5 元/单（同一客户当月只计一单，其余记“图片同客户”）。
        '设计' => ['departments' => ['设计客服','美工部','设计'], 'resources' => false, 'requires_technical' => false, 'service_fee_rate' => 0.031, 'order_kinds' => ['图片', '图片同客户', 'PPT'], 'kind_required' => true, 'default_kind' => '图片', 'free_shop' => true, 'fields' => ['designer_code' => '设计师', 'design_item' => '设计内容']],
        // 代写：店铺软文代写（代写客服接单，微信代写编辑“对接建群”作为协作执行）；成本 = 写手实际稿费，服务费 5.7%。
        '软文代写' => ['departments' => ['代写客服', '代写.客服', '代写.主管'], 'resources' => false, 'requires_technical' => false, 'service_fee_rate' => 0.057, 'order_kinds' => ['新订单', '合并单', '退款冲减'], 'default_kind' => '新订单', 'cost_label' => '写手稿费', 'import_cost' => true, 'free_shop' => true, 'fields' => ['writer_code' => '写手编号', 'writing_volume' => '字数']],
        // 期刊：店铺付款扣 5.7%，微信付款扣 0.35%；代付版面费单不计单量提成。
        '期刊' => ['departments' => [], 'resources' => false, 'requires_technical' => false, 'service_fee_rate' => 0.057, 'order_kinds' => ['店铺付款', '微信付款', '代付版面费'], 'kind_required' => true, 'default_kind' => '店铺付款', 'cost_label' => '杂志社 / 写手费用', 'import_cost' => true, 'free_shop' => true, 'fields' => ['journal_name' => '发表刊物名称', 'manuscript_ref' => '稿件编号 / 刊期']],
        // 网站售后部：续费（郭文娟、刘媛媛、房烁录入；每单 1 元、拍链接 0.5 元；全部续费毛利按人比例月度提成）
        '网站续费' => ['departments' => ['网站售后续费'], 'resources' => false, 'requires_technical' => false, 'service_fee_rate' => 0.03, 'order_kinds' => ['续费', '拍链接'], 'kind_required' => true, 'default_kind' => '续费', 'cost_label' => '续费成本', 'import_cost' => true, 'fields' => ['website_url' => '网站地址', 'renew_item' => '续费项目']],
        // 网站售后部：自己接单的修改 / 备案（(收入 − 成本) × 20%，备案修改另加 5 元/单）
        '网站修改' => ['departments' => ['网站售后技术', '网站售后备案'], 'resources' => false, 'requires_technical' => false, 'service_fee_rate' => 0, 'order_kinds' => ['修改', '备案', '备案修改'], 'kind_required' => true, 'default_kind' => '修改', 'cost_label' => '成本', 'import_cost' => true, 'fields' => ['website_url' => '网站地址', 'service_item' => '修改 / 备案内容']],
        // 微信代写：编辑员自接单（店铺订单每单补助 3 元），部门利润池按月分配（规则中心“部门利润池分配”）。
        '微信代写' => ['departments' => ['微信代写客服', '微信代写售后', '微信营销部经理'], 'resources' => false, 'requires_technical' => false, 'service_fee_rate' => 0, 'order_kinds' => ['店铺订单', '微信付款'], 'kind_required' => true, 'default_kind' => '店铺订单', 'cost_label' => '写手稿费', 'import_cost' => true, 'free_shop' => true, 'fields' => ['writer_code' => '写手编号', 'writing_volume' => '字数']],
    ];
}

/** 旧“客服类”账户业务映射到其可建单的产品：客服与制作人员按产品共用同一订单。 */
function ps_business_account_products($name, $role)
{
    if ($role !== 'customer_service') return null;
    if ($name === '网站客服') return ['网站模板', 'AI网站定制'];
    if ($name === '小程序客服') return ['小程序开发', '小额引流'];
    return null;
}

function ps_business_fallback($department)
{
    foreach (ps_business_catalog() as $name => $definition) {
        if (in_array($department, $definition['departments'], true)) return $name;
    }
    return null;
}

function ps_business_normalize($name)
{
    $name = trim((string)$name);
    return ['AI开发定制' => 'AI网站定制', 'AI网站开发定制' => 'AI网站定制', '网站定制' => 'AI网站定制', '网站技术服务' => 'AI网站定制', '网站模板技术' => '网站模板', '服务器配置' => '环境配置', '小程序' => '小程序开发', '小程序商城' => '小程序开发', '小程序引流' => '小额引流', '代写客服' => '软文代写', '代写' => '软文代写', '软文' => '软文代写', '期刊发表' => '期刊'][$name] ?? $name;
}

function ps_is_website_order($business)
{
    return in_array($business, ['AI网站定制', '网站定制', '网站模板'], true);
}

/** 客服建单时必须指定接单技术的业务（客服与制作人员共用同一张订单）。 */
function ps_business_requires_technical($business)
{
    return !empty(ps_business_catalog()[ps_business_normalize($business)]['requires_technical']);
}

function ps_business_service_fee_rate($business)
{
    return (float)(ps_business_catalog()[ps_business_normalize($business)]['service_fee_rate'] ?? 0);
}

function ps_business_order_kinds($business)
{
    return ps_business_catalog()[ps_business_normalize($business)]['order_kinds'] ?? [];
}

function ps_actor_businesses($actor)
{
    $catalog = ps_business_catalog();
    if ($actor['role'] === 'finance') return array_values(array_filter(array_keys($catalog), function ($name) use ($catalog) { return empty($catalog[$name]['legacy']); }));
    $q = db()->prepare('SELECT business_name FROM project_user_businesses WHERE user_id=? ORDER BY is_default DESC,business_name');
    $q->execute([(int)$actor['id']]);
    $names = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$names) {
        $employee = db()->prepare('SELECT department FROM employees WHERE id=?');
        $employee->execute([(int)$actor['employee_id']]);
        $fallback = ps_business_fallback($employee->fetchColumn());
        if ($fallback) $names = [$fallback];
    }
    $allowed = [];
    foreach ($names as $name) {
        $products = ps_business_account_products($name, $actor['role']);
        if ($products) { foreach ($products as $product) $allowed[] = $product; }
        elseif (isset($catalog[$name]) && empty($catalog[$name]['legacy'])) $allowed[] = $name;
    }
    return array_values(array_unique($allowed));
}

function ps_business_choice($actor, $requested)
{
    $allowed = ps_actor_businesses($actor);
    if (!$allowed) return null;
    $requested = ps_business_normalize($requested);
    return in_array($requested, $allowed, true) ? $requested : $allowed[0];
}

function ps_require_business($actor, $business)
{
    if (!isset(ps_business_catalog()[$business]) || !in_array($business, ps_actor_businesses($actor), true)) {
        throw new RuntimeException('当前账户未分配此业务，请联系财务配置');
    }
    return ps_business_catalog()[$business];
}

function ps_active_employee_for_business($employeeId, $role, $business)
{
    $q = db()->prepare('SELECT id,employee_id,role FROM project_users WHERE employee_id=? AND role=? AND is_active=1 LIMIT 1');
    $q->execute([(int)$employeeId, $role]);
    $user = $q->fetch();
    return $user && in_array(ps_business_normalize($business), ps_actor_businesses($user), true);
}

function ps_business_details($business, $source)
{
    $definition = ps_business_catalog()[$business] ?? null;
    if (!$definition) throw new RuntimeException('业务类型无效');
    $details = [];
    foreach ($definition['fields'] as $key => $label) {
        $value = trim((string)($source[$key] ?? ''));
        if (mb_strlen($value) > 300) throw new RuntimeException($label . '不能超过 300 字');
        $details[$key] = $value;
    }
    return $details;
}

function ps_save_business_details($orderId, $business, $details)
{
    db()->prepare('INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,?,?)')
        ->execute([(int)$orderId, $business, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

function ps_order_kind_valid($business, $kind)
{
    $kind = trim((string)$kind);
    if ($kind === '') return '';
    if (!in_array($kind, ps_business_order_kinds($business), true)) throw new RuntimeException('订单类型无效，请从列表中选择');
    return $kind;
}

/**
 * Excel/CSV 列定义：key => [表头别名...]。第一个别名用于下载模板；
 * 其余别名兼容部门现有表格（如“付款账号”“接单日期”“到账情况”“程序名称”）。
 */
function ps_business_import_columns($business)
{
    $definition = ps_business_catalog()[$business] ?? null;
    if (!$definition) throw new RuntimeException('业务类型无效');
    $labels = ps_business_people_labels($business);
    $columns = [
        'order_date' => ['日期', '接单日期', '下单日期', '付款日期'],
        'shop' => ['店铺', '店铺名称', '店铺编码'],
        'business' => ['业务'],
        'payment_nickname' => ['付款昵称', '付款账号', '付款人', '买家昵称'],
        'order_no' => ['订单编号', '订单号'],
        'contract_amount' => ['售价', '金额', '订单金额', '总价（需支付至我司的价格）', '总价', '收入'],
        'status' => ['状态(填已完成/未完成)', '状态', '到账情况', '订单情况', '是否完成', '收发货状态'],
        'contact_note' => ['备注（写客户电话或者微信）', '客户联系方式', '客户微信', '备注'],
        'customer_service' => ['客服', '客服姓名', '客服编号'],
        'frontend' => array_values(array_unique([$labels['frontend'], '前端（技术）', '前端', '技术', '制作技术', '模板技术', '建群编辑', '编辑员'])),
        'backend' => array_values(array_unique([$labels['backend'], '后端', '技术协作', '协作技术'])),
    ];
    if (ps_business_order_kinds($business)) $columns['order_kind'] = ['订单类型', '类型'];
    // 代写 / 期刊 / 微信代写：成本 = 写手稿费或杂志社费用，随订单导入；“提成”列为 0 的代写行识别为合并单；期刊“模式”列识别代付版面费。
    if (!empty($definition['import_cost'])) {
        $columns['direct_cost'] = ['实际稿费', '成本', '稿费', '杂志社费用'];
        $columns['direct_cost2'] = ['我司写手承担写作的写手费用', '写手费用'];
        $columns['unit_marker'] = ['提成'];
        $columns['pay_mode'] = ['模式'];
    }
    // 设计部总表：“序号”列写的是客服，“设计师佣金”列出现即 PPT 表，按“旺旺”识别同一客户
    if ($business === '设计') {
        $columns['customer_service'][] = '序号';
        $columns['payment_nickname'] = array_merge($columns['payment_nickname'], ['旺旺', '客户旺旺或者微信名称']);
        $columns['order_no'] = array_merge($columns['order_no'], ['编码或者订单号']);
        $columns['ppt_marker'] = ['设计师佣金'];
    }
    if (!empty($definition['program'])) $columns['program_name'] = ['程序名称', '程序套餐'];
    if ($definition['resources']) {
        $columns['domain_used'] = ['域名使用（写是/否）', '域名使用'];
        $columns['ssl_used'] = ['SSL证书使用（写真实成本）', 'SSL证书使用', 'SSL使用'];
        $columns['resource_note'] = ['填一下域名或者空间', '空间+域名网址', '域名或空间'];
    }
    foreach ($definition['fields'] as $key => $label) $columns['detail:' . $key] = [$label];
    return $columns;
}

/** 下载用表头；AI 网站定制保持原模板 14 列顺序不变。 */
function ps_business_import_headers($business)
{
    $columns = ps_business_import_columns($business);
    $order = ['order_date','shop','business','payment_nickname','order_no','contract_amount','status','contact_note','customer_service','frontend','domain_used','ssl_used','backend','resource_note','order_kind','program_name'];
    $headers = [];
    foreach ($order as $key) if (isset($columns[$key])) $headers[] = $columns[$key][0];
    foreach ($columns as $key => $aliases) if (strpos($key, 'detail:') === 0) $headers[] = $aliases[0];
    return $headers;
}

/** 表头 => 列序号映射；缺少订单编号或全部人员列时报错。 */
function ps_business_import_map($business, $head, $requirePeople = true)
{
    $map = [];
    foreach (ps_business_import_columns($business) as $key => $aliases) {
        foreach ($aliases as $alias) {
            $index = array_search($alias, $head, true);
            if ($index !== false && !in_array($index, $map, true)) { $map[$key] = $index; break; }
        }
    }
    if (!isset($map['order_no'])) throw new RuntimeException('缺少“订单编号”列；请下载当前业务模板或在表头加“订单编号”');
    // 合作人员上传自己的订单时本人自动加入，表格可以没有人员列；财务上传须有人员列
    if ($requirePeople && !isset($map['customer_service']) && !isset($map['frontend']) && !isset($map['backend'])) throw new RuntimeException('缺少客服或技术列，无法确定参与人');
    return $map;
}

/** 表格里的交付/到账状态统一为项目交付状态；无法识别时返回 null。 */
function ps_import_delivery_status($text)
{
    $text = trim((string)$text);
    if ($text === '') return 'unfinished';
    if (in_array($text, ['已完成','完成','已发货','已交','到账','已到账','交易成功','售后返','发货'], true)) return 'finished';
    // 设计总表常见“到账5”“发货15”（状态后跟补差金额）
    if (preg_match('/^(到账|已到账|发货|已发货)\d+(?:\.\d+)?$/u', $text)) return 'finished';
    foreach (['未完成','未发货','暂不发货','进行中','欠尾款','未到账','交易关闭'] as $open) if (mb_strpos($text, $open) !== false) return 'unfinished';
    return null;
}

function ps_business_people_labels($business)
{
    if ($business === 'AI网站定制') return ['frontend' => '前端（技术）', 'backend' => '后端'];
    if ($business === '小程序开发') return ['frontend' => '制作技术', 'backend' => '协作技术'];
    if ($business === '环境配置') return ['frontend' => '技术', 'backend' => '协作技术'];
    if ($business === '网站模板') return ['frontend' => '模板技术', 'backend' => '技术协作'];
    if ($business === '设计') return ['frontend' => '设计执行', 'backend' => '协作设计'];
    if ($business === '软文代写') return ['frontend' => '对接编辑', 'backend' => '协作执行'];
    return ['frontend' => '项目执行', 'backend' => '协作执行'];
}
