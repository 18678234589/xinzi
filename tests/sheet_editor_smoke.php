<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
setlocale(LC_CTYPE, 'C'); // Windows 默认 GBK locale 会误读 UTF-8 CSV。
require_once __DIR__ . '/../includes/ProjectSheetEdit.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db();
require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1') throw new RuntimeException('只允许在本地隔离库运行');
pse_ensure();
$check = function ($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "[OK] $message\n"; };
$actor = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null];
$csv = '"日期","店铺","订单编号","售价","付款昵称","备注","任意附加字段"' . "\n" . '"2026-09-01","原店铺","0012345678901234567890","100","原昵称","原备注","原内容"' . "\n";
$tmp = tempnam(sys_get_temp_dir(), 'sheet_');
file_put_contents($tmp, $csv);
$stored = null;
$pdo->beginTransaction();
try {
    $id = ps_import_file_store(['name' => '在线编辑测试.csv', 'tmp_name' => $tmp, 'size' => strlen($csv)], '网站模板', $actor);
    $file = pse_file($id, $actor); $stored = $file['stored_name'];
    $sheet = (string)array_keys(ps_import_file_sheets($file))[0];
    $loaded = pse_load($id, $sheet, $actor);
    $check(count(array_filter($loaded['editable'])) === count($loaded['head']), '订单号、日期和任意附加列全部可编辑');
    $long = str_repeat('完整备注', 90);
    $edits = [['row' => 1, 'col' => 0, 'v' => '2026-10-08'], ['row' => 1, 'col' => 2, 'v' => '0098765432109876543210'], ['row' => 1, 'col' => 5, 'v' => $long], ['row' => 1, 'col' => 6, 'v' => '新附加内容']];
    $check(pse_save($id, $sheet, $edits, $actor) === 4, '全部字段保存成功');
    $loaded = pse_load($id, $sheet, $actor);
    $check($loaded['rows'][0][2] === $edits[1]['v'], '长订单号和前导零保持原样');
    $check($loaded['rows'][0][5] === $long, '超过旧 200 字限制的备注不截断');
    $edited = pse_import_sheets($file, $actor);
    $check($edited[$sheet][1][0] === '2026-10-08' && $edited[$sheet][1][6] === '新附加内容', '导入解析读取在线修改后的全部字段');
    $originalSheets = ps_import_file_sheets($file);
    $check($originalSheets[$sheet][1][2] === '0012345678901234567890', '原始上传附件不改变：' . json_encode($originalSheets[$sheet][1][2]));
    $check(count($edited[$sheet][0]) === count($loaded['head']), '虚拟补充列带入导入表头');
    $revision = pse_revision($id);
    pse_save($id, $sheet, [['row' => 1, 'col' => 6, 'v' => '再次修改']], $actor);
    $check($revision !== pse_revision($id), '核对后的编辑会使旧预览版本失效');
    $revision = pse_revision($id);
    try { pse_save($id, $sheet, [['row' => 1, 'col' => 6, 'v' => '不应保存'], ['row' => 9000, 'col' => 0, 'v' => '无效']], $actor); throw new LogicException('未拒绝无效行'); }
    catch (RuntimeException $e) { $check(pse_revision($id) === $revision, '无效批次整体拒绝，不发生部分保存'); }
    try { pse_save($id, $sheet, array_fill(0, 501, $edits[0]), $actor); throw new LogicException('未拒绝超量批次'); }
    catch (RuntimeException $e) { $check(pse_revision($id) === $revision, '超过 500 格明确拒绝，不静默丢失'); }
    pse_save($id, $sheet, [['row' => 1, 'col' => 0, 'v' => '2026-09-01']], $actor);
    $check(!isset(pse_overlay($id, $sheet)[1][0]), '恢复原值会撤销该格草稿');
    $check(!pse_can_edit($file, ['type' => 'employee', 'id' => 999999, 'employee_id' => 999999, 'role' => 'technical']), '其他用户不能编辑文件');
    echo "sheet_editor_smoke PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($stored) ps_private_delete('imports', $stored);
    @unlink($tmp);
}
