<?php
// 备案原表一个文件两张工作表（备案 = 备案-提成表、备案修改 = 备案-单量表）：各归各的业务，提示继续导入另一张，不重复计单量。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_filing_two_sheets_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会提交数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }

$pdo = db();
$stored = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$run = function ($actor, $post) { $_SESSION = array_merge($_SESSION ?? [], ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf']); $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post + ['csrf' => 'test-csrf']; $GLOBALS['error'] = ''; $GLOBALS['imported'] = 0; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean(); $GLOBALS['imported'] = $imported ?? 0; $GLOBALS['error'] = $error ?? ''; };
$tag = bin2hex(random_bytes(3));
// 最小 xlsx 生成：每个工作表一个二维数组，字符串走 sharedStrings
$mkxlsx = function (array $sheets) {
    $tmp = tempnam(sys_get_temp_dir(), 'x') . '.xlsx'; $z = new ZipArchive(); $z->open($tmp, ZipArchive::CREATE);
    $strings = []; $idx = function ($s) use (&$strings) { if (!isset($strings[$s])) $strings[$s] = count($strings); return $strings[$s]; };
    $ct = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'; $n = 0;
    foreach ($sheets as $name => $rows) {
        $n++; $x = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $ri => $row) { $x .= '<row r="' . ($ri + 1) . '">'; foreach ($row as $ci => $v) $x .= '<c r="' . chr(65 + $ci) . ($ri + 1) . '" t="s"><v>' . $idx((string)$v) . '</v></c>'; $x .= '</row>'; }
        $z->addFromString("xl/worksheets/sheet$n.xml", $x . '</sheetData></worksheet>');
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . $name . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $rels .= '<Relationship Id="rId99" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
    $ss = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'; foreach (array_keys($strings) as $s) $ss .= '<si><t>' . htmlspecialchars((string)$s) . '</t></si>'; $ss .= '</sst>';
    $z->addFromString('[Content_Types].xml', $ct . '</Types>'); $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>'); $z->addFromString('xl/_rels/workbook.xml.rels', $rels); $z->addFromString('xl/sharedStrings.xml', $ss);
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->close(); return $tmp;
};
try {
    $u = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='房烁' AND u.is_active=1 LIMIT 1")->fetch();
    $check((bool)$u, '房烁账号存在');
    $actor = ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']];
    $check(!array_diff(['备案-提成', '备案-单量'], ps_actor_businesses($actor)), '房烁同时有 备案-提成 / 备案-单量');
    $n1 = "33170$tag" . '0001'; $n2 = "33170$tag" . '0002';
    $file = $mkxlsx([
        '备案' => [['日期', '店铺', '付费旺旺', '订单编号', '售价', '成本', '快递费', '建站订单', '截图', '状态', '备注'],
            ['2026.10.14', '清风易软件专营店', "w$tag", $n1, '100', '0', '0', '拍小程序链接', '', '已发货', '备案优刻云'],
            ['2026.10.15', '美呀美旗舰店', "w$tag", $n2, '100', '0', '0', '', '', '已发货', '备案景安']],
        '备案修改' => [['联系方式', '域名', '状态'], ["微信$tag-a", "ms$tag-a.com", '已完成'], ["微信$tag-b", "ms$tag-b.com", '已完成'], ["微信$tag-c", "ms$tag-c.com", '已完成']],
    ]);
    $name = ps_private_store('imports', $file, 'avoid_' . bin2hex(random_bytes(5)) . '.xlsx'); $stored[] = $name; @unlink($file);
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('备案-提成','房烁备案.xlsx',?,1000,'employee',?,?)")->execute([$name, $actor['id'], $actor['employee_id']]);
    $fileId = (int)$pdo->lastInsertId();
    $sheets = function () { return array_map(function ($s) { return !empty($s['used']); }, $_SESSION['project_import_sheets'] ?? []); };
    $run($actor, ['action' => 'repreview', 'business' => '备案-提成', 'file_id' => $fileId, 'all_sheets' => 1]);
    $check(count($_SESSION['project_import_preview']) === 2 && $sheets() === ['备案' => true, '备案修改' => false], '备案-提成：只读“备案”表（2 行），不读“备案修改”表：' . json_encode($sheets(), JSON_UNESCAPED_UNICODE));
    $check(($_SESSION['project_import_other_sheets'] ?? []) === ['备案修改' => '备案-单量'], '提示“备案修改”属于 备案-单量：' . json_encode($_SESSION['project_import_other_sheets'] ?? [], JSON_UNESCAPED_UNICODE));
    $run($actor, ['action' => 'commit', 'business' => '备案-提成', 'auto_import' => 1]);
    $check($GLOBALS['imported'] === 2, '只在“备案-提成”下点导入：提成 2 单：' . $GLOBALS['imported'] . ' ' . $GLOBALS['error']);
    $cnt = $pdo->prepare("SELECT o.project_type,COUNT(*) FROM project_orders o JOIN project_participants p ON p.order_id=o.id WHERE p.employee_id=? AND (o.order_no IN (?,?) OR o.note LIKE ?) GROUP BY o.project_type");
    $cnt->execute([$actor['employee_id'], $n1, $n2, "%微信$tag-%"]);
    $by = $cnt->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(($by['备案-提成'] ?? 0) === 2 && ($by['备案-单量'] ?? 0) === 3, '同一次导入里，“备案修改”表自动按“备案-单量”入账 3 单（没有多算）：' . json_encode($by, JSON_UNESCAPED_UNICODE));
    $check(empty($_SESSION['project_import_other_sheets']), '自动导入成功后，页面不再提示继续导入：' . json_encode($_SESSION['project_import_other_sheets'] ?? [], JSON_UNESCAPED_UNICODE));
    // 同一个文件再导入一次：不会重复建单
    $run($actor, ['action' => 'repreview', 'business' => '备案-提成', 'file_id' => $fileId, 'all_sheets' => 1]);
    $run($actor, ['action' => 'commit', 'business' => '备案-提成', 'auto_import' => 1]);
    $cnt->execute([$actor['employee_id'], $n1, $n2, "%微信$tag-%"]);
    $by2 = $cnt->fetchAll(PDO::FETCH_KEY_PAIR);
    $check($by2 === $by, '重复导入同一个文件不重复建单：' . json_encode($by2, JSON_UNESCAPED_UNICODE));
    // ===== 新版模板：同一个文件两张数据表，下拉 / 日期列；旧表头照常识别 =====
    foreach (['备案-提成', '备案-单量'] as $biz) $check(in_array($biz, pmt_businesses(), true), "$biz 有专用 xlsx 模板");
    $tplFile = sys_get_temp_dir() . '/tpl_' . $tag . '.xlsx'; file_put_contents($tplFile, pmt_xlsx('备案-提成'));
    $zip = new ZipArchive(); $zip->open($tplFile);
    preg_match_all('/<sheet name="([^"]+)"/u', $zip->getFromName('xl/workbook.xml'), $sm);
    $check($sm[1] === ['备案', '备案修改', '填写说明'], '新版模板：备案 / 备案修改 / 填写说明 三张表：' . implode('|', $sm[1]));
    $check(pmt_xlsx('备案-提成') === pmt_xlsx('备案-单量'), '两个备案业务下载的是同一个文件');
    $sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml'); $sheet2 = $zip->getFromName('xl/worksheets/sheet2.xml');
    $check(substr_count($sheet1, '<dataValidation ') >= 6 && strpos($sheet1, '拍小程序链接') !== false && strpos($sheet1, '已发货') !== false && strpos($sheet2, '已完成,未完成') !== false, '下拉：店铺 / 建站订单 / 状态 / 备注 / 域名归属，备案修改的状态');
    $zip->close(); @unlink($tplFile);
    $h1 = pmt_headers('备案-提成'); $h2 = pmt_headers('备案-单量');
    $check(array_slice($h1, 0, 11) === ['日期', '店铺', '付费旺旺', '订单编号', '售价', '成本', '快递费', '建站订单', '截图', '状态', '备注'] && $h2 === ['日期', '联系方式', '域名', '状态'], '新版表头：备案表前 11 列与旧表一致，备案修改表只多一个可选“日期”列');
    // 用新版表头填真实订单：备案修改表写了日期的按该日期记单
    $n3 = "33171$tag" . '0003'; $n4 = "33171$tag" . '0004';
    $newFile = $mkxlsx([
        '备案' => [$h1, ['2026.10.16', '美呀美旗舰店', "z$tag", $n3, '100', '0', '0', '拍小程序链接', '', '已发货', '房烁备案欧赛', '', '', ''], ['2026.10.17', '对公收款', "y$tag", $n4, '100', '0', '0', '', '', '已发货', '房烁备案景安', '', '', '']],
        '备案修改' => [$h2, ['2026-10-28', "微信$tag-d", "nv$tag-d.com", '已完成'], ['2026-10-30', "微信$tag-e", "nv$tag-e.com", '已完成'], ['', "微信$tag-f", "nv$tag-f.com", '已完成']],
    ]);
    $name2 = ps_private_store('imports', $newFile, 'avoid_' . bin2hex(random_bytes(5)) . '.xlsx'); $stored[] = $name2; @unlink($newFile);
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('备案-提成','房烁备案新版.xlsx',?,1000,'employee',?,?)")->execute([$name2, $actor['id'], $actor['employee_id']]);
    $fileId2 = (int)$pdo->lastInsertId();
    $run($actor, ['action' => 'repreview', 'business' => '备案-提成', 'file_id' => $fileId2, 'all_sheets' => 1]);
    $run($actor, ['action' => 'commit', 'business' => '备案-提成', 'auto_import' => 1]);
    $check($GLOBALS['imported'] === 2, '新版表：提成 2 单导入：' . $GLOBALS['imported'] . ' ' . $GLOBALS['error']);
    $dq = $pdo->prepare("SELECT o.order_date FROM project_orders o WHERE o.project_type='备案-单量' AND o.note LIKE ? ORDER BY o.note");
    $dates = []; foreach (['d', 'e', 'f'] as $k) { $dq->execute(["%微信$tag-$k%"]); $dates[$k] = (string)$dq->fetchColumn(); }
    $check($dates['d'] === '2026-10-28' && $dates['e'] === '2026-10-30' && $dates['f'] === '2026-10-30', '备案修改表：写了日期的按日期记单，某行没写的沿用上一行日期：' . json_encode($dates));
    // ===== 真实原表的形态：备案表 14 列（含客户手机号等）、单量表尾有“单量 / 金额”汇总行（域名列 3、状态列 15）=====
    $n5 = "33172$tag" . '0005';
    $realFile = $mkxlsx([
        '备案' => [['日期', '店铺', '付费旺旺', '订单编号', '售价', '成本', '快递费', '建站订单', '截图', '状态', '备注', '客户手机号', '海外客户微信号', '域名归属'], ['2026.10.18', '美呀美旗舰店', "r$tag", $n5, '100', '0', '0', '拍小程序链接', '', '已发货', '房烁备案欧赛']],
        '备案修改' => [['联系方式', '域名', '状态'], ["微信$tag-g", "rv$tag-g.com", '已完成'], ["微信$tag-h", "rv$tag-h.com.cn", '已完成'], ["微信$tag-i", "rv$tag-i.net", '已完成'], ['', '3', '2345']],
    ]);
    $name3 = ps_private_store('imports', $realFile, 'avoid_' . bin2hex(random_bytes(5)) . '.xlsx'); $stored[] = $name3; @unlink($realFile);
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('备案-单量','房烁备案真实形态.xlsx',?,1000,'employee',?,?)")->execute([$name3, $actor['id'], $actor['employee_id']]);
    $fileId3 = (int)$pdo->lastInsertId();
    $run($actor, ['action' => 'repreview', 'business' => '备案-单量', 'file_id' => $fileId3, 'all_sheets' => 1]);
    $check($sheets() === ['备案' => false, '备案修改' => true], '在“备案-单量”下：带订单编号 / 售价的“备案”表不读，只读“备案修改”表：' . json_encode($sheets(), JSON_UNESCAPED_UNICODE));
    $check(count($_SESSION['project_import_preview']) === 3, '表尾“单量 / 金额”汇总行不当订单：预览 ' . count($_SESSION['project_import_preview']) . ' 行（应为 3）');
    echo "\n=== 备案两张工作表测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
