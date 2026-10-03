<?php
/** Pure renewal policy: calendar days in China; never infer expiry from order dates. */
function pr_date($value)
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
    if (!$date || $date->format('Y-m-d') !== $value || $value < '2000-01-01' || $value > '2100-12-31') throw new RuntimeException('请填写有效的到期日期');
    return $value;
}
function pr_today() { return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'); }
function pr_default_expiry($orderDate)
{
    try { $date=pr_date($orderDate); } catch (RuntimeException $e) { return null; }
    if (!$date) return null;
    $year=(int)substr($date,0,4)+1; $month=(int)substr($date,5,2); $day=(int)substr($date,8,2);
    // Feb 29 -> Feb 28, not PHP's overflowing Mar 1.
    $day=min($day,(int)(new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month)))->format('t'));
    return pr_date(sprintf('%04d-%02d-%02d',$year,$month,$day));
}
function pr_days($expiry, $today)
{
    if (!$expiry) return null;
    return (int)(new DateTimeImmutable(pr_date($today)))->diff(new DateTimeImmutable(pr_date($expiry)))->format('%r%a');
}
function pr_reminder_day($item, $today)
{
    if (($item['status'] ?? '') !== 'active' || empty($item['sms_enabled']) || empty($item['phone_hash']) || empty($item['resource_name'])) return null;
    $days = pr_days($item['expires_on'] ?? null, $today);
    return in_array($days, [10, 3, 1], true) ? $days : null;
}
function pr_phone($phone)
{
    $phone = preg_replace('/[\s\-()]+/', '', trim((string)$phone));
    $phone = preg_replace('/^(\+86|0086)/', '', $phone);
    if ($phone !== '' && !preg_match('/^1[3-9][0-9]{9}$/D', $phone)) throw new RuntimeException('请填写客户的中国大陆 11 位手机号；不是合作人员手机号');
    return $phone;
}
function pr_type_labels() { return ['domain' => '域名', 'miniapp_certification' => '小程序认证']; }
function pr_policy_scope($role, $department, $name, $businesses)
{
    if ($role === 'finance') return 'all';
    if (in_array($role, ['vault','governance'], true)) return 'none';
    if (in_array($department, ['网站售后部','售后部','综合售后部','售后退款部'], true)) return 'web';
    if ($name === '王亚' && $department === '标书小程序') return 'own';
    if (in_array($department, ['网站客服','小程序客服'], true)) return 'own';
    if (in_array($role, ['customer_service','technical'], true) && array_intersect($businesses, ['AI网站定制','网站模板','网站续费','网站修改','小程序开发'])) return 'own';
    return 'none';
}
