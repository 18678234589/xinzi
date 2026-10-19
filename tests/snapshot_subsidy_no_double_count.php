<?php
// 分成快照的 commission_amount 已含每单补助（审核时 = 分成份额 + 补助）：任何页面都不能再把 subsidy_amount 加一遍。纯静态检查 + 讲解表检查，不连数据库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__); $bad = [];
$re = '~commission_amount.{1,6}\s*\+\s*(?:\(float\)\s*)?\$\w+\[.subsidy_amount.\]~';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', $f->getPathname());
    if (substr($path, -4) !== '.php' || preg_match('~/(\.deploy|tests|storage|tools|migrations|docs)/~', $path)) continue;
    if (preg_match($re, preg_replace('/\s+/', ' ', file_get_contents($path)))) $bad[] = substr($path, strlen($root) + 1);
}
if ($bad) { fwrite(STDERR, "未通过：这些文件把 commission_amount 与 subsidy_amount 相加（补助被算了两遍）：\n  " . implode("\n  ", $bad) . "\n"); exit(1); }
echo "  [OK] 没有页面把补助重复叠加\n";
function ps_yuan($v) { return '¥' . number_format((float)$v, 2); }
function ps_pct($v) { return ($v * 100) . '%'; }
$src = file_get_contents($root . '/includes/commission_explain.php');
if (!preg_match('/function ps_explain_snapshot_steps.*?\n}\n/s', $src, $m)) { fwrite(STDERR, "未通过：找不到 ps_explain_snapshot_steps\n"); exit(1); }
eval($m[0]);
$rows = ps_explain_snapshot_steps(['calc_mode' => 'pool', 'income_amount' => 100, 'direct_cost' => 30, 'service_fee' => 0, 'rate' => 0, 'group_weight' => 1, 'calc_note' => '(…) × 0% + 每单补助 3.00', 'commission_amount' => 3.00, 'subsidy_amount' => 3.00, 'payroll_month' => '2026-09']);
$final = null; $share = null;
foreach ($rows as $r) { if (strpos($r[0], '⑧') === 0) $final = $r[2]; if (strpos($r[0], '⑥') === 0) $share = $r[2]; }
if ($final !== '¥3.00' || $share !== '¥0.00') { fwrite(STDERR, "未通过：快照 3.00（含补助 3.00）应显示 提成 ¥0.00 / 最终 ¥3.00，实际 提成 $share / 最终 $final\n"); exit(1); }
echo "  [OK] 计算过程：提成 ¥0.00 + 补助 ¥3.00 = 最终 ¥3.00\n全部通过\n";
