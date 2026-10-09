<?php
// 上传预览“需要处理的订单”清单：缺订单号 / 对公 / 缺接单技术在卡片里补填，页面里不再重复出现同名输入框；点重新核对后补填生效。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_fix_panel_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会提交数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); $stored = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$actorOf = function ($name) use ($pdo) { $q = $pdo->prepare("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1"); $q->execute([$name]); $u = $q->fetch(); return $u ? ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']] : null; };
$tag = bin2hex(random_bytes(3));
try {
    $cao = $actorOf('曹双双');
    $sk = '状态(填已完成/未完成)'; $head = ps_business_import_headers('小程序开发');
    $row = function ($no, $date, $tech) use ($head, $sk, $tag) { $d = ['日期' => $date, '店铺' => '美呀美', '业务' => '小程序商城', '付款昵称' => 'fx' . $tag . $no, '订单编号' => $no === 'X' ? '' : "33196$tag$no", '售价' => '200', $sk => '已完成', '客服' => '曹双双', '制作技术' => $tech]; $c = []; foreach ($head as $h) $c[] = $d[$h] ?? ''; return implode(',', $c); };
    $csv = implode(',', $head) . "\n" . $row('1', '2026.9.8', '') . "\n" . $row('2', '', '石凯新') . "\n" . $row('3', '2026.9.8', '石凯新') . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'fx') . '.csv'; file_put_contents($tmp, $csv);
    $name = ps_private_store('imports', $tmp, 'fx_' . bin2hex(random_bytes(5)) . '.csv'); $stored[] = $name; @unlink($tmp);
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('小程序开发','测试.csv',?,?,'employee',?,?)")->execute([$name, strlen($csv), $cao['id'], $cao['employee_id']]);
    $fileId = (int)$pdo->lastInsertId();
    $page = function ($post) use ($cao) { $_SESSION = array_merge($_SESSION ?? [], ['project_user_id' => $cao['id'], 'project_csrf' => 'test-csrf']); $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = []; $_POST = $post + ['csrf' => 'test-csrf']; $_FILES = []; ob_start(); include __DIR__ . '/../project/import.php'; return ob_get_clean(); };
    $html = $page(['action' => 'repreview', 'business' => '小程序开发', 'file_id' => $fileId, 'all_sheets' => 1]);
    $pv = $_SESSION['project_import_preview'] ?? [];
    $check(count($pv) === 3, '3 行');
    $check(strpos($html, 'id="importFixPanel"') !== false, '页面出现“需要处理的订单”清单');
    $check(substr_count($html, 'class="fx-card"') === 2, '缺日期、缺接单技术各一张卡片（第 3 行正常，不在清单里）');
    $check(substr_count($html, 'name="fix_date[') === 1 && substr_count($html, 'name="fix_technical[') === 1, '日期和接单技术的输入框各只出现一次（不再在表格里重复）');
    $check(mb_strpos($html, '请在上方“需要处理的订单”里') !== false, '表格里改为指向上方清单的提示');
    // 补填后重新核对
    $line1 = $line2 = 0; foreach ($pv as $r) { if (empty($r['order_date'])) $line2 = (int)$r['line']; if (!empty($r['need_technical'])) $line1 = (int)$r['line']; }
    $tech = array_key_first(poj_technician_choices('小程序开发'));
    $page(['action' => 'repair_preview', 'business' => '小程序开发', 'fix_date' => [$line2 => '2026-09-08'], 'fix_technical' => [$line1 => $tech]]);
    $pv2 = $_SESSION['project_import_preview'] ?? [];
    $check(count(array_filter($pv2, function ($r) { return !empty($r['base_valid']); })) === 3, '在清单里补日期、选技术后 3 行都通过：' . implode('；', array_map(function ($r) { return $r['error'] ?? ''; }, $pv2)));
    echo "\n=== 待处理清单测试全部通过 ===\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
finally { foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php'); }
