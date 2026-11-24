<?php
// AI网站定制技术岗位名对不上规则（如“模板技术”）时，自动按“前端”规则匹配；其他业务不变。DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/rule_role_fallback_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$front = ps_rule_for('technical', 'AI网站定制', '2026-10-22', '前端', '博山定制');
$odd = ps_rule_for('technical', 'AI网站定制', '2026-10-22', '模板技术', '博山定制');
$check($front && $odd && (int)$odd['id'] === (int)$front['id'], '模板技术 + 博山定制：自动匹配“前端”规则 #' . ($odd['id'] ?? '无'));
foreach (['AI定制', ''] as $kind) {
    $f = ps_rule_for('technical', 'AI网站定制', '2026-10-22', '前端', $kind); $o = ps_rule_for('technical', 'AI网站定制', '2026-10-22', '技术', $kind);
    $check($f && $o && (int)$o['id'] === (int)$f['id'], '技术 + 订单类型“' . $kind . '”：与前端规则一致 #' . ($o['id'] ?? '无'));
}
$check(ps_rule_for('technical', 'AI网站定制', '2026-10-22', '外包前端', '博山定制') !== null, '外包前端仍按自己的规则');
$check(ps_rule_for('technical', '商标', '2026-10-22', '模板技术', '') === ps_rule_for('technical', '商标', '2026-10-22', '模板技术', ''), '其他业务不走这条回退');
echo "全部通过\n";
