<?php
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../includes/ProjectSiteProjects.php';
$check = function ($ok, $name) { if (!$ok) throw new RuntimeException($name); };
$check(psp_key('  SITE-A.Example  ') === 'site-a.example', '网站标识规范化');
$check(psp_child_no('3316850666', 'site-a.example') === psp_child_no('3316850666', 'site-a.example'), '子单编号稳定');
$check(psp_child_no('3316850666', 'site-a.example') !== psp_child_no('3316850666', 'site-b.example'), '不同网站编号不同');
$members = [
    ['order_id' => 1, 'site_key' => 'site-a.example', 'contract_amount' => '350.00', 'receipt_amount' => '350.00', 'refund_amount' => '0.00'],
    ['order_id' => 2, 'site_key' => 'site-b.example', 'contract_amount' => '350.00', 'receipt_amount' => '350.00', 'refund_amount' => '0.00'],
];
psp_validate_allocation($members, '700.00');
$hash = psp_allocation_hash($members);
$members[1]['receipt_amount'] = '0.00';
$check($hash !== psp_allocation_hash($members), '收款变化使财务确认失效');
$failed = false; try { psp_validate_allocation($members, '700.00'); } catch (RuntimeException $e) { $failed = true; }
$check($failed, '未分配全部实收不允许确认');
$members[1]['receipt_amount'] = '350.00';
$failed = false; try { psp_validate_allocation($members, '350.00'); } catch (RuntimeException $e) { $failed = true; }
$check($failed, '整组金额不符不允许确认');
echo "PASS site identity and allocation rules\n";
