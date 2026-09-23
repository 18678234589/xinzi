<?php
require_once __DIR__ . '/ProjectSettlement.php';

function ps_business_catalog()
{
    return [
        'AI网站定制' => ['departments' => ['定制前端','定制后端','网站定制'], 'resources' => true, 'fields' => []],
        '网站模板' => ['departments' => ['网站模板','模板技术','网站技术'], 'resources' => true, 'fields' => ['website_url' => '网站地址', 'template_name' => '模板名称 / 版本', 'deployment_note' => '部署与交付说明']],
        // 旧分类只用于展示历史订单；新订单按产品类型建单，客服作为参与人加入同一订单号。
        '网站客服' => ['departments' => ['网站客服'], 'resources' => false, 'fields' => ['website_url' => '网站地址', 'service_item' => '服务事项'], 'legacy' => true],
        '小程序开发' => ['departments' => [], 'resources' => true, 'fields' => ['miniapp_name' => '小程序名称', 'miniapp_subject' => '小程序主体 / AppID']],
        '小程序客服' => ['departments' => [], 'resources' => false, 'fields' => ['miniapp_name' => '小程序名称', 'service_item' => '服务事项']],
        '设计' => ['departments' => ['设计客服','美工部'], 'resources' => false, 'fields' => ['design_item' => '设计内容', 'deliverable' => '交付文件 / 规格']],
        '代写客服' => ['departments' => ['代写客服'], 'resources' => false, 'fields' => ['writing_topic' => '文稿主题', 'writing_volume' => '篇幅 / 字数']],
        '期刊' => ['departments' => [], 'resources' => false, 'fields' => ['journal_name' => '期刊名称', 'manuscript_ref' => '稿件编号 / 刊期']],
    ];
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
    return ['AI开发定制' => 'AI网站定制', 'AI网站开发定制' => 'AI网站定制', '网站定制' => 'AI网站定制', '网站模板技术' => '网站模板'][$name] ?? $name;
}

function ps_is_website_order($business)
{
    return in_array($business, ['AI网站定制', '网站定制', '网站模板'], true);
}

function ps_actor_businesses($actor)
{
    $catalog = ps_business_catalog();
    if ($actor['role'] === 'finance') return array_values(array_filter(array_keys($catalog), function ($name) use ($catalog) { return empty($catalog[$name]['legacy']); }));
    $q = db()->prepare('SELECT business_name FROM project_user_businesses WHERE user_id=? ORDER BY is_default DESC,business_name');
    $q->execute([(int)$actor['id']]);
    $names = $q->fetchAll(PDO::FETCH_COLUMN);
    $allowed = [];
    foreach ($names as $name) {
        if ($name === '网站客服' && $actor['role'] === 'customer_service') {
            $allowed[] = '网站模板';
            $allowed[] = 'AI网站定制';
        } elseif (isset($catalog[$name]) && empty($catalog[$name]['legacy'])) $allowed[] = $name;
    }
    $names = array_values(array_unique($allowed));
    if ($names) return $names;
    $employee = db()->prepare('SELECT department FROM employees WHERE id=?');
    $employee->execute([(int)$actor['employee_id']]);
    $fallback = ps_business_fallback($employee->fetchColumn());
    if ($fallback === '网站客服' && $actor['role'] === 'customer_service') return ['网站模板', 'AI网站定制'];
    return $fallback && empty($catalog[$fallback]['legacy']) ? [$fallback] : [];
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
    return $user && in_array($business, ps_actor_businesses($user), true);
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

function ps_business_import_headers($business)
{
    $definition = ps_business_catalog()[$business] ?? null;
    if (!$definition) throw new RuntimeException('业务类型无效');
    $labels = ps_business_people_labels($business);
    $headers = ['日期','店铺','业务','付款昵称','订单编号','售价','状态(填已完成/未完成)','备注（写客户电话或者微信）','客服',$labels['frontend']];
    if ($definition['resources']) $headers = array_merge($headers, ['域名使用（写是/否）','SSL证书使用（写真实成本）']);
    $headers[] = $labels['backend'];
    if ($definition['resources']) $headers[] = '填一下域名或者空间';
    return array_merge($headers, array_values($definition['fields']));
}

function ps_business_people_labels($business)
{
    if (in_array($business, ['AI网站定制','小程序开发'], true)) return ['frontend' => '前端（技术）', 'backend' => '后端'];
    if ($business === '网站模板') return ['frontend' => '模板技术', 'backend' => '技术协作'];
    if ($business === '设计') return ['frontend' => '设计执行', 'backend' => '协作设计'];
    return ['frontend' => '项目执行', 'backend' => '协作执行'];
}
