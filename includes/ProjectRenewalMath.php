<?php
/** Pure renewal policy: calendar days in China; never infer expiry from order dates. */
function pr_date($value)
{
    $value = trim((string)$value);
    if ($value === '') return null;
    // 很多程序是永久的：到期日写“永久”一律记为 2099-01-01（系统约定的永久日期，永远不会触发到期提醒）
    if (preg_match('/^(永久|永久有效|终身|无期限|长期|买断)$/u', preg_replace('/\s+/u', '', $value))) return '2099-01-01';
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
function pr_phone($phone, $strictMobile = false)
{
    $phone = preg_replace('/[\s\-()]+/', '', trim((string)$phone));
    $phone = preg_replace('/^(\+86|0086)/', '', $phone);
    if ($phone === '') return '';
    if (preg_match('/^1[3-9][0-9]{9}$/D', $phone)) return $phone;
    if ($strictMobile) throw new RuntimeException('请填写客户的中国大陆 11 位手机号；不是合作人员手机号');
    if (!preg_match('/^\+?[0-9]{7,15}$/D', $phone)) throw new RuntimeException('手机号请填写 7～15 位数字；海外客户微信号请填在微信号栏');
    return $phone;
}
function pr_type_labels() { return ['miniapp_certification' => '微信认证', 'icp' => '备案', 'domain' => '域名', 'server' => '服务器']; }
/** 各业务可登记的资源类型：小程序有微信认证 / 备案 / 域名 / 服务器；网站类没有微信认证。 */
function pr_types_for($projectType)
{
    $all = pr_type_labels();
    if ($projectType === '小程序开发') return $all;
    unset($all['miniapp_certification']);
    return $all;
}
/** 海外客户微信号：2～60 位字母 / 数字 / 下划线 / 短横线 / 点 / @。 */
function pr_wechat($wechat)
{
    $w = preg_replace('/\s+/u', '', trim((string)$wechat));
    if ($w === '') return '';
    if (!preg_match('/^[\p{L}0-9_\-.@]{2,60}$/u', $w)) throw new RuntimeException('微信号格式不正确（2～60 位字母、数字或 _ - . @）');
    return $w;
}
/** 联系方式可二选一，也可同时填写；微信号不等于短信手机号。 */
function pr_has_contact(array $item)
{
    return !empty($item['phone_hash']) || !empty($item['wechat_hash']);
}
function pr_contact_missing_sql($alias = 'r')
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/iD', $alias)) throw new InvalidArgumentException('Invalid SQL alias');
    return "($alias.phone_hash='' AND COALESCE($alias.wechat_hash,'')='')";
}
function pr_owner_labels() { return ['ours' => '我们代管', 'customer' => '客户自有（不续费）']; }
function pr_default_type($projectType) { return $projectType === '小程序开发' ? 'miniapp_certification' : 'domain'; }
/** 表头里的到期日列 → 资源类型。域名和服务器合写、或写的是长段说明的列不当作权威到期日。 */
function pr_import_label_type($label)
{
    if (preg_match('/^(到期日期|到期日|服务到期日?)$/u', $label)) return 'server'; // 小程序新模板的“到期日期”：服务器 / 服务到期
    if (!preg_match('/到期|有效期/u', $label)) return null;
    $domain = preg_match('/域名/u', $label); $server = preg_match('/服务器|空间|主机/u', $label);
    if ($domain && $server) return null;
    if (preg_match('/SSL|证书/iu', $label)) return null;
    if ($domain) return 'domain';
    if ($server) return 'server';
    if (preg_match('/备案|ICP/iu', $label)) return 'icp';
    if (preg_match('/小程序|认证/u', $label)) return 'miniapp_certification';
    return null;
}
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
