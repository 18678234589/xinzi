<?php
// 我的在线表格：每月每业务一张；新增行（超出原有行数）、粘贴、自动保存、自动同步到系统（和上传表格同一套核对 / 导入）、
// 订单号改了撤旧订单、行清空提醒、不弹“待补全”、别人不能动。只允许本地隔离库（导入提交含建表，会隐式提交）。
// DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php -d extension=zip tests/online_sheet_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectOnlineSheets.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$throws = function (callable $f) { try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); } return null; };
$actorOf = function ($name) use ($pdo) { $q = $pdo->prepare("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1"); $q->execute([$name]); $u = $q->fetch(); return $u ? ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']] : null; };
pols_ensure(); pse_ensure(); poi_ensure(); if (function_exists("pop_ensure")) pop_ensure();
$tag = bin2hex(random_bytes(3)); $month = date('Y-m'); $ym = date('Y.n');
$song = $actorOf('宋倩倩'); $other = $actorOf('张强');
$fin = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => null];
$fileId = 0;
$cleanup = function () use ($pdo, &$fileId, $tag) {
    require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
    foreach ($pdo->query("SELECT id FROM project_orders WHERE order_no LIKE '%" . $tag . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { try { pod_delete_rows((int)$id); } catch (Throwable $e) {} }
    if ($fileId) {
        foreach (['project_import_edits', 'project_online_sheet_rows', 'project_import_followups', 'project_online_sheets'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE file_id=$fileId"); } catch (Throwable $e) {} }
        $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='import_file' AND entity_id=$fileId");
        $pdo->exec("DELETE FROM project_import_files WHERE id=$fileId");
    }
    $pdo->exec("DELETE FROM project_order_trash WHERE order_no LIKE '%" . $tag . "%'");
};
// 先清掉她本月可能留下的在线表格（上次失败 / 端到端测试遗留），保证从空表格开始
foreach ($pdo->query("SELECT file_id FROM project_online_sheets WHERE employee_id=" . (int)$song['employee_id'] . " AND month='$month'")->fetchAll(PDO::FETCH_COLUMN) as $oldFile) {
    foreach (['project_import_edits', 'project_online_sheet_rows', 'project_import_followups'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE file_id=" . (int)$oldFile); } catch (Throwable $e) {} }
    $pdo->exec('DELETE FROM project_online_sheets WHERE file_id=' . (int)$oldFile); $pdo->exec('DELETE FROM project_import_files WHERE id=' . (int)$oldFile);
}
$_SESSION = ['project_user_id' => $song['id'] ?? 0, 'project_csrf' => 'test-csrf'];
try {
    $check($song && $other, '测试账号存在（宋倩倩 / 张强）');
    $check(in_array('网站模板', pols_businesses($song), true), '网站模板在她可用的在线表格业务里');
    $check(pols_businesses($fin) === [], '财务没有在线表格');

    echo "=== 一、打开：每月每业务一张，开箱就有表头和空行 ===\n";
    $fileId = pols_open($song, '网站模板', $month);
    $check($fileId > 0 && pols_open($song, '网站模板', $month) === $fileId, '同月同业务再次打开是同一张表');
    $check($throws(function () use ($song) { pols_open($song, '网站模板', '2020-01'); }) !== null, '太早的月份不能开');
    $check($throws(function () use ($other, $month) { pols_open($other, '软文代写', $month); }) !== null, '没分配的业务不能开');
    $sheet = pse_load($fileId, 'CSV', $song);
    $head = $sheet['head']; $idx = array_flip($head);
    $check(count($sheet['rows']) === POLS_START_ROWS && in_array('订单编号', $head, true) && isset($sheet['options'][$idx['状态(填已完成/未完成)']]), '开箱 ' . count($sheet['rows']) . ' 个空行，表头是网站模板，状态列带下拉');
    $check($throws(function () use ($fileId, $other) { pse_load($fileId, 'CSV', $other); }) !== null, '别人看不到这张表');

    echo "=== 二、新增行（超过原有行数）和自动保存 ===\n";
    $no1 = "81278{$tag}00000001"; $no2 = "81278{$tag}00000002"; $no3 = "81278{$tag}00000003";
    $row = function ($no, $price, $nick, $domain) use ($idx, $ym) {
        $v = ['日期' => $ym . '.5', '编号类型' => '美呀美旗舰店', '付款账号' => $nick, '订单编号' => $no, '售价' => $price, '程序名称' => 'JSP展示中级版', '年限' => '1年', '客服' => '宋倩倩', '技术' => '张强', '状态(填已完成/未完成)' => '已完成', '续费联系方式电话' => '13800138000', '网站域名' => $domain, '域名到期日期' => '2099-01-05', '服务器到期日期' => '2099-01-05'];
        $edits = []; return $v;
    };
    $edit = function ($rowNo, array $values) use ($idx) { $e = []; foreach ($values as $h => $v) $e[] = ['row' => $rowNo, 'col' => $idx[$h], 'v' => (string)$v]; return $e; };
    $saved = pse_save($fileId, 'CSV', array_merge($edit(1, $row($no1, '300', "ol1$tag", "ol1$tag.com")), $edit(2, ['日期' => $ym . '.6', '付款账号' => "ol2$tag"])), $song);
    $check($saved === 14 + 2, '写了第 1、2 行，自动保存 ' . $saved . ' 格');
    $beyond = POLS_START_ROWS + 5;
    $saved = pse_save($fileId, 'CSV', $edit($beyond, $row($no3, '500', "ol3$tag", "ol3$tag.com")), $song, $beyond);
    $sheet = pse_load($fileId, 'CSV', $song);
    $check(count($sheet['rows']) === $beyond && $sheet['rows'][$beyond - 1][$idx['订单编号']] === $no3 && $sheet['rowNos'][$beyond - 1] === $beyond, '新增行：容量涨到 ' . $beyond . ' 行，第 ' . $beyond . ' 行的内容在');
    $check($throws(function () use ($fileId, $song, $idx) { pse_save($fileId, 'CSV', [['row' => 999, 'col' => 0, 'v' => 'x']], $song); }) !== null, '不存在的行仍被拒绝');

    echo "=== 三、同步：核对并导入，状态写回每一行，不弹待补全 ===\n";
    $r = pols_sync($fileId, $song);
    $st = $r['states'];
    $q = $pdo->prepare("SELECT o.id,o.contract_amount,o.settlement_status FROM project_orders o WHERE o.order_no LIKE ?"); $q->execute([$no1 . '%']); $o1 = $q->fetch();
    $q->execute([$no3 . '%']); $o3 = $q->fetch();
    $check($o1 && abs((float)$o1['contract_amount'] - 300) < 0.01 && $o3 && abs((float)$o3['contract_amount'] - 500) < 0.01, '第 1 行和新增的第 ' . $beyond . ' 行都导入成订单（300 / 500）：' . json_encode(array_map(function ($x) { return $x['s']; }, $st)));
    $check(($st[1]['s'] ?? '') === 'ok' && ($st[$beyond]['s'] ?? '') === 'ok' && mb_strpos($st[1]['t'], '已同步') !== false, '这两行状态是“已同步”：' . ($st[1]['t'] ?? ''));
    $check(($st[2]['s'] ?? '') === 'wait' && mb_strpos($st[2]['t'], '还没填完整') !== false, '第 2 行只写了一半：状态“还没填完整”，不报错也不建单：' . ($st[2]['t'] ?? ''));
    $bogus = $edit(3, ['日期' => $ym . '.7', '订单编号' => "81278{$tag}00000099", '售价' => '100', '状态(填已完成/未完成)' => '已完成', '客服' => '不存在的人']);
    pse_save($fileId, 'CSV', $bogus, $song); $rb = pols_sync($fileId, $song);
    $check(($rb['states'][3]['s'] ?? '') === 'bad' && mb_strpos($rb['states'][3]['t'], '需处理') !== false && $rb['bad'] >= 1, '第 3 行写了不存在的客服：状态“需处理”并说明原因：' . ($rb['states'][3]['t'] ?? ''));
    pse_save($fileId, 'CSV', $edit(3, ['日期' => '', '订单编号' => '', '售价' => '', '状态(填已完成/未完成)' => '', '客服' => '']), $song); pols_sync($fileId, $song);
    $mem = $pdo->prepare('SELECT COUNT(*) FROM project_participants WHERE order_id=? AND employee_id=?'); $mem->execute([$o1['id'], $song['employee_id']]);
    $check((int)$mem->fetchColumn() === 1, '她本人是这张订单的参与人');
    $f = (int)$pdo->query("SELECT COUNT(*) FROM project_import_followups WHERE file_id=$fileId")->fetchColumn() + (int)$pdo->query("SELECT COUNT(*) FROM project_messages WHERE category='import_fix' AND employee_id=" . (int)$song['employee_id'] . " AND created_at>=(NOW() - INTERVAL 1 MINUTE)")->fetchColumn();
    $check($f === 0, '没有“待补全”记录和站内信（问题直接写在状态列）');
    $r2 = pols_sync($fileId, $song);
    $check($r2['synced'] === 0 && $r2['bad'] === 0, '没有新改动时再同步：什么都不做');
    $rep = ps_import_result_get($fileId);
    $check(count($rep['order_ids']) === 2 && $rep['pending'] === 0, '回执累计整张表：对应 2 单');

    echo "=== 四、改售价、补全订单号：再同步更新 / 新建 ===\n";
    pse_save($fileId, 'CSV', $edit(1, ['售价' => '350']), $song);
    pse_save($fileId, 'CSV', $edit(2, $row($no2, '260', "ol2$tag", "ol2$tag.com")), $song);
    $r = pols_sync($fileId, $song);
    $q->execute([$no1 . '%']); $o1 = $q->fetch(); $q->execute([$no2 . '%']); $o2 = $q->fetch();
    $check(abs((float)$o1['contract_amount'] - 350) < 0.01 && $o2 && $r['states'][2]['s'] === 'ok', '第 1 行售价改成 350 已更新；第 2 行补全后导入新订单');
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM project_orders WHERE order_no LIKE ?'); $cnt->execute(["%$tag%"]);
    $check((int)$cnt->fetchColumn() === 3, '一共 3 张订单，没有重复建单');

    echo "=== 五、订单号写错改正：旧订单（草稿）自动撤掉，不留重复单 ===\n";
    $no1b = "81278{$tag}00000011";
    pse_save($fileId, 'CSV', $edit(1, ['订单编号' => $no1b]), $song);
    $r = pols_sync($fileId, $song);
    $q->execute([$no1 . '%']); $old = $q->fetch(); $q->execute([$no1b . '%']); $new = $q->fetch();
    $st = $r['states'][1];
    $check($new && ($st['s'] === 'ok') && (!$old || mb_strpos($st['t'], $no1) !== false), '新订单号建好；旧订单' . ($old ? '因已审核等原因保留并在状态里提醒：' : '已自动撤销：') . $st['t']);

    echo "=== 六、清空一行：订单保留，提醒去订单页删除 ===\n";
    $blank = []; foreach ($head as $h) $blank[$h] = '';
    pse_save($fileId, 'CSV', $edit($beyond, $blank), $song);
    $r = pols_sync($fileId, $song);
    $q->execute([$no3 . '%']);
    $check($q->fetch() && ($r['states'][$beyond]['s'] ?? '') === 'cleared', '第 ' . $beyond . ' 行清空后订单还在，状态提示：' . ($r['states'][$beyond]['t'] ?? ''));

    echo "=== 七、权限 ===\n";
    $check($throws(function () use ($fileId, $other) { pols_sync($fileId, $other); }) !== null, '别人不能同步我的表格');
    $check($throws(function () use ($fileId, $song) { ps_import_file_delete($fileId, $song); }) !== null, '合作人员不能删除在线表格原件');
    $check(count(pols_list($song)) >= 1 && pols_list($song)[0]['month'] === $month, '“我的表格”列表里有这张表：' . json_encode(array_map(function ($x) { return [$x['month'], $x['business_name'], $x['rows'], $x['ok']]; }, pols_list($song)), JSON_UNESCAPED_UNICODE));
    echo "=== 八、页面：原始表格页 / 工作台的入口，在线表格编辑页 ===\n";
    $_SESSION = ['project_user_id' => $song['id'], 'project_csrf' => 'test-csrf']; $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_FILES = [];
    $_SERVER['SCRIPT_NAME'] = '/project/files.php'; $_GET = [];
    ob_start(); include __DIR__ . '/../project/files.php'; $html = ob_get_clean();
    $check(strpos($html, '我的在线表格') !== false && strpos($html, 'name="online"') !== false && strpos($html, '打开在线表格') !== false && strpos($html, 'view=' . $fileId) !== false, '“我的表格”页顶部：月份 + 业务 + 打开按钮，下面列出已有的在线表格');
    $_GET = ['view' => $fileId];
    ob_start(); include __DIR__ . '/../project/files.php'; $html = ob_get_clean();
    $check(strpos($html, 'data-online="1"') !== false && strpos($html, 'se-add') !== false && strpos($html, 'se-sync') !== false && strpos($html, '系统状态') !== false && strpos($html, 'Ctrl+V') !== false, '在线表格编辑页：新增行按钮、立即同步、粘贴说明');
    $_SERVER['SCRIPT_NAME'] = '/project/dashboard.php'; $_GET = [];
    ob_start(); try { include __DIR__ . '/../project/dashboard.php'; } finally { $html = ob_get_clean(); }
    $check(strpos($html, 'id="my-online-sheets"') !== false && strpos($html, '打开在线表格') !== false, '工作台：有“我的在线表格”入口');
    $_SERVER['SCRIPT_NAME'] = '/project/index.php'; $_GET = ['month' => $month];
    ob_start(); try { include __DIR__ . '/../project/index.php'; } finally { $html = ob_get_clean(); }
    $check(strpos($html, 'online=1') !== false && strpos($html, '我的表格（' . $month . '）') !== false, '“我的项目订单”页：有“我的表格（当前月份）”按钮，直达这个月的在线表格');
    echo "\n=== 我的在线表格测试全部通过，测试数据已清理 ===\n";
} finally {
    $cleanup();
}
