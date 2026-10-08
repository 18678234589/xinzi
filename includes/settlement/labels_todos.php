<?php

/** 界面统一中文标签；数据库仍保存英文枚举。 */
function ps_label($type, $value)
{
    $labels = [
        'category' => ['domain' => '域名', 'server' => '服务器', 'program' => '程序套餐', 'certificate' => 'SSL证书', 'certification' => '认证', 'api' => 'API', 'plugin' => '功能插件', 'outsourcing' => '外包', 'other' => '其他'],
        'cost_kind' => ['one_time' => '一次性', 'annual' => '年度', 'monthly' => '月度'],
        'review' => ['approved' => '已通过', 'pending' => '待财务审核', 'rejected' => '已驳回/作废'],
        'settlement' => ['draft' => '录入中', 'review' => '待审核', 'approved' => '已审核', 'locked' => '已锁定'],
        'group' => ['technical' => '技术', 'customer_service' => '客服'],
        'mode' => ['pool' => '组池分摊', 'individual' => '个人独立计提'],
    ];
    return $labels[$type][$value] ?? (string)$value;
}

/**
 * 订单待办（列表与结算单共用）。$row 需包含 order 字段及 price_source、domain_mode、pending_costs、pending_cash、tech_count、cs_count。
 * 返回 [[文本, 级别(warning/info/danger)], ...]；已审核订单无待办。
 */
function ps_order_todos($row)
{
    if (in_array($row['settlement_status'] ?? '', ['approved', 'locked'], true)) return [];
    $todos = [];
    if (($row['price_source'] ?? 'missing') === 'missing') $todos[] = ['售价待补', 'warning'];
    if (($row['domain_mode'] ?? '') === 'pending' && !empty(ps_business_catalog()[ps_business_normalize($row['project_type'])]['resources'])) $todos[] = ['资源待技术确认', 'warning'];
    if (ps_business_requires_technical($row['project_type']) && (int)($row['tech_count'] ?? 1) === 0) $todos[] = ['未指定技术', 'danger'];
    if (in_array(ps_business_normalize($row['project_type'] ?? ''), ['AI网站定制', '网站定制'], true) && isset($row['backend_tech_count']) && (int)$row['backend_tech_count'] === 0 && (int)($row['tech_count'] ?? 0) > 0) {
        $todos[] = ['待指定后端', 'warning'];
    }
    if ((int)($row['pending_costs'] ?? 0) > 0) $todos[] = ['成本待审 ' . (int)$row['pending_costs'], 'info'];
    if ((int)($row['pending_cash'] ?? 0) > 0) $todos[] = ['收退款待审 ' . (int)$row['pending_cash'], 'info'];
    if ((int)($row['pending_delivery_requests'] ?? 0) > 0) $todos[] = ['交付待审', 'warning'];
    if ((int)($row['pending_upgrade_requests'] ?? 0) > 0) $todos[] = ['升级待审', 'primary'];
    if ((float)($row['receipt_amount'] ?? 0) <= 0) $todos[] = ['实收未确认', 'secondary'];
    if (($row['delivery_status'] ?? '') !== 'finished') $todos[] = ['交付未完成', 'secondary'];
    return $todos;
}

/**
 * 客户联系方式打码：手机号 158***3252；邮箱 ab***@qq.com；“微信 / VX / QQ”后面的账号保留首尾两位。
 * 财务 / 管理员始终看完整信息；技术、客服按“系统设置 › 客户联系方式权限”：默认本单参与人看完整，不在本单的人看打码。
 */
function ps_mask_contact($text)
{
    $text = (string)$text;
    if ($text === '') return $text;
    $text = preg_replace_callback('/([A-Za-z0-9._%+-]{1,2})[A-Za-z0-9._%+-]*(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', function ($m) { return $m[1] . '***' . $m[2]; }, $text);
    $text = preg_replace_callback('/(?<!\d)(1[3-9]\d)(\d{4})(\d{4})(?!\d)/', function ($m) { return $m[1] . '***' . $m[3]; }, $text);
    $text = preg_replace_callback('/((?:微信号?|wx|vx|v信|qq)\s*[:：]?\s*)(?![0-9]{3}\*)([A-Za-z0-9_-]{5,30})/iu', function ($m) { return $m[1] . ps_mask_id($m[2]); }, $text);
    return $text;
}

/** 单独的微信号 / 账号字段：保留首尾两位，中间打码；手机号按手机号规则。 */
function ps_mask_id($value)
{
    $value = trim((string)$value);
    if ($value === '' || strpos($value, '***') !== false) return $value;
    if (preg_match('/^1[3-9]\d{9}$/', $value)) return substr($value, 0, 3) . '***' . substr($value, -4);
    $length = mb_strlen($value);
    if ($length <= 4) return mb_substr($value, 0, 1) . '***';
    return mb_substr($value, 0, 2) . '***' . mb_substr($value, -2);
}

/** 按查看人与是否参与本单决定是否打码（数据库保存原文）。 */
function ps_contact_for($actor, $text, $isIdField = false, $isParticipant = true)
{
    if (ps_contact_visible($actor, $isParticipant)) return (string)$text;
    return $isIdField ? ps_mask_contact(ps_mask_id($text)) : ps_mask_contact($text);
}
