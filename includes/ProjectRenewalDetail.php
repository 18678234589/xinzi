<?php
/** 续费工作台的订单详情：域名、联系方式、谁接单 / 谁做单，方便直接联系客户续费。只读，权限沿用续费工作台（pr_order_where）。 */
require_once __DIR__ . '/ProjectRenewals.php';

/** 一次查出本页所有订单的详情，避免每行各查一次。 */
function prd_load(array $orderIds)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if (!$ids) return [];
    $in = implode(',', $ids); // 全部是整数
    $out = [];
    foreach ($ids as $id) $out[$id] = ['domains' => [], 'items' => [], 'phone' => '', 'wechat' => '', 'people' => [], 'shop' => '', 'order_date' => '', 'nickname' => '', 'note' => ''];
    foreach (db()->query("SELECT order_id,resource_type,resource_name,expires_on,expiry_source,status,phone_cipher,wechat_cipher FROM project_renewal_items WHERE order_id IN ($in) ORDER BY id")->fetchAll() as $r) {
        $d = &$out[(int)$r['order_id']];
        if ($r['resource_type'] === 'domain' && $r['resource_name'] !== '') $d['domains'][$r['resource_name']] = true;
        $d['items'][] = $r;
        if ($d['phone'] === '' && $r['phone_cipher']) $d['phone'] = (string)pv_decrypt($r['phone_cipher']);
        if ($d['wechat'] === '' && !empty($r['wechat_cipher'])) $d['wechat'] = (string)pv_decrypt($r['wechat_cipher']);
        unset($d);
    }
    foreach (db()->query("SELECT o.id,o.shop,o.order_date,o.note,s.payment_nickname FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.id IN ($in)")->fetchAll() as $r) {
        $d = &$out[(int)$r['id']];
        $d['shop'] = (string)$r['shop']; $d['order_date'] = (string)$r['order_date']; $d['nickname'] = (string)($r['payment_nickname'] ?? ''); $d['note'] = mb_substr(trim((string)$r['note']), 0, 200);
        unset($d);
    }
    foreach (db()->query("SELECT p.order_id,p.commission_group,p.role_name,e.name,e.department FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id IN ($in) ORDER BY p.commission_group,p.id")->fetchAll() as $r) $out[(int)$r['order_id']]['people'][] = $r;
    return $out;
}

/** 列表里“资源”列下方的小字：这张订单的域名（域名行本身不重复显示）。 */
function prd_domains_line(array $d, $resourceName)
{
    $names = array_values(array_filter(array_keys($d['domains']), function ($n) use ($resourceName) { return $n !== $resourceName; }));
    return $names ? implode('、', $names) : '';
}

/** 展开行内容。$canOpen：当前账号能打开订单详情页（财务 / 订单参与人）。 */
function prd_panel(array $d, array $row, $canOpen)
{
    $labels = pr_type_labels(); $today = pr_today();
    $people = ['customer_service' => [], 'technical' => []];
    foreach ($d['people'] as $p) $people[$p['commission_group']][] = $p['name'] . ($p['role_name'] !== '' && $p['role_name'] !== '客服' && $p['role_name'] !== '技术' ? '（' . $p['role_name'] . '）' : '') . ($p['department'] !== '' ? ' · ' . $p['department'] : '');
    $contact = [];
    if ($d['phone'] !== '') $contact[] = '手机 ' . $d['phone'];
    if ($d['wechat'] !== '') $contact[] = '微信 ' . $d['wechat'];
    $res = [];
    foreach ($d['items'] as $it) {
        $left = pr_days($it['expires_on'], $today);
        $res[] = ($labels[$it['resource_type']] ?? $it['resource_type']) . '：' . ($it['resource_name'] !== '' ? $it['resource_name'] . ' · ' : '')
            . ($it['expires_on'] ? $it['expires_on'] . ($it['expiry_source'] === 'estimated' ? '（预计）' : '') . ($left !== null ? ($left < 0 ? '，已到期 ' . abs($left) . ' 天' : '，还有 ' . $left . ' 天') : '') : '到期日待补')
            . ($it['status'] !== 'active' ? '（' . ['paused' => '暂停联系', 'closed' => '已结束'][$it['status']] . '）' : '');
    }
    $rows = [
        '客户' => ($row['customer_name'] ?: '昵称待补') . ($d['nickname'] !== '' && $d['nickname'] !== $row['customer_name'] ? '　付款昵称：' . $d['nickname'] : ''),
        '联系方式' => $contact ? implode('　', $contact) : '待补手机号或微信号',
        '域名' => $d['domains'] ? implode('、', array_keys($d['domains'])) : '尚未登记域名',
        '谁接的单（客服）' => $people['customer_service'] ? implode('、', $people['customer_service']) : '未记录',
        '谁做的单（技术）' => $people['technical'] ? implode('、', $people['technical']) : '未记录',
        '订单' => $row['project_type'] . ($d['shop'] !== '' ? ' · ' . $d['shop'] : '') . ($d['order_date'] ? ' · 下单 ' . $d['order_date'] : ''),
    ];
    if ($d['note'] !== '') $rows['订单备注'] = $d['note'];
    $html = '<dl class="pr-detail-grid">';
    foreach ($rows as $k => $v) $html .= '<div><dt>' . e($k) . '</dt><dd>' . e($v) . '</dd></div>';
    $html .= '<div class="pr-detail-wide"><dt>续费资源</dt><dd>' . implode('<br>', array_map('e', $res)) . '</dd></div></dl>';
    if ($canOpen) $html .= '<a class="pr-link" href="' . BASE_URL . '/project/order.php?id=' . (int)$row['order_id'] . '">打开订单详情页 →</a>';
    return $html;
}
