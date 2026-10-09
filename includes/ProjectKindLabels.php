<?php
/**
 * 订单类型的“说明性名称”：库里仍存原来的值（规则、历史订单、对账都按它匹配），页面下拉和模板里改写成不会混淆的具体名称。
 * 例：小程序开发的“新订单”＝在小程序商城新注册搭建的订单（每单有补助），显示为“小程序商城新注册搭建”。
 */
function pkl_map()
{
    return [
        '小程序开发' => [
            '新订单' => '小程序商城新注册搭建',
            '定制' => '定制开发（按客户需求开发）',
            '开发定制' => '定制开发（按客户需求开发）',
            '续费' => '小程序续费（续年费）',
            '技术服务' => '注册公众号、认证等其他服务',
        ],
        '网站模板' => [
            '新订单' => '模板新建网站',
            '续费' => '网站续费',
            '加购/纯利润' => '加购、补差价（纯利润）',
        ],
    ];
}

/** 订单类型的显示名；没有专门写法的原样返回。 */
function pkl_label($business, $kind)
{
    $business = ps_business_normalize($business);
    return pkl_map()[$business][$kind] ?? (string)$kind;
}

/** 表格 / 页面里写的显示名（或旧值）→ 库里存的订单类型值；认不出原样返回。 */
function pkl_kind_from_text($business, $text)
{
    $business = ps_business_normalize($business);
    $text = trim((string)$text);
    foreach (pkl_map()[$business] ?? [] as $kind => $label) if ($text === $label) return $kind;
    return $text;
}

/** 下拉可选项 [值 => 显示名]：同一个意思的旧写法（小程序“开发定制”）不重复列出，除非它正是当前值。 */
function pkl_choices($business, $current = '')
{
    $business = ps_business_normalize($business);
    $out = [];
    foreach (ps_business_order_kinds($business) as $kind) {
        if ($business === '小程序开发' && $kind === '开发定制' && $current !== '开发定制') continue;
        $out[$kind] = pkl_label($business, $kind);
    }
    return $out;
}

/** 全部业务的显示名映射（给页面脚本用）：[业务 => [值 => 显示名]]。 */
function pkl_js_map()
{
    $out = [];
    foreach (array_keys(pkl_map()) as $business) foreach (ps_business_order_kinds($business) as $kind) $out[$business][$kind] = pkl_label($business, $kind);
    return $out;
}
