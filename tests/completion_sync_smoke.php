<?php
// 按订单汇总表标记完成：解析 xlsx、对照系统、标记完成 / 未完成、撤回已审核的未完成订单。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_sandbox php -d extension=zip tests/completion_sync_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectCompletionSync.php';
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$ids = []; $emp = 0;
try {
    $check(pcs_classify('已完成') === 'fin' && pcs_classify('到账') === 'fin' && pcs_classify('未完成') === 'unf' && pcs_classify('暂不发货') === 'unf' && pcs_classify('欠尾款600元') === 'unf' && pcs_classify('交易关闭') === 'closed', '状态归类');
    $mk = function ($rows) {
        $s = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $i => $r) { $s .= '<row r="' . ($i + 1) . '">'; foreach ($r as $j => $v) $s .= '<c r="' . chr(65 + $j) . ($i + 1) . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$v) . '</t></is></c>'; $s .= '</row>'; }
        return $s . '</sheetData></worksheet>';
    };
    $nos = ['A' => "CS$tag" . '001', 'B' => "CS$tag" . '002', 'C' => "CS$tag" . '003', 'D' => "CS$tag" . '004'];
    $path = tempnam(sys_get_temp_dir(), 'csx') . '.xlsx'; $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="已完成" sheetId="1" r:id="rId1"/><sheet name="未完成" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
    $z->addFromString('xl/worksheets/sheet1.xml', $mk([['日期', '订单编号', '售价', '到账情况'], ['9.1', $nos['A'], 100, '已完成'], ['9.2', $nos['B'], 200, '已完成'], ['9.3', $nos['D'], 50, '已完成']]));
    $z->addFromString('xl/worksheets/sheet2.xml', $mk([['日期', '订单编号', '售价', '到账情况'], ['9.4', $nos['C'], 300, '未完成'], ['9.5', $nos['D'], 20, '未完成']]));
    $z->close();
    $parsed = pcs_parse($path); @unlink($path);
    $check(($parsed['map'][$nos['A']] ?? '') === 'fin' && ($parsed['map'][$nos['C']] ?? '') === 'unf' && ($parsed['map'][$nos['D']] ?? '') === 'unf' && $parsed['conflicts'] === [$nos['D']], '解析 xlsx：已完成 / 未完成，同号冲突按未完成');

    $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['cs_' . $tag, '网站定制技术', 'x']); $emp = (int)$pdo->lastInsertId();
    $order = function ($no, $ss, $ds) use ($pdo, &$ids, $emp) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','AI网站定制','','内部',100,100,'2026-09-05',?,?,'测试')")->execute([$no, $ds, $ss]);
        $id = (int)$pdo->lastInsertId(); $ids[] = $id;
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','前端',1)")->execute([$id, $emp]);
        return $id;
    };
    $a = $order($nos['A'], 'draft', 'unfinished');
    $b = $order($nos['B'], 'approved', 'finished');
    $c = $order($nos['C'], 'draft', 'finished');
    $d = $order($nos['D'], 'approved', 'finished');
    $pdo->prepare("INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,role_name,income_amount,direct_cost,service_fee,contribution_profit,rule_id,calc_mode,rate,group_weight,commission_amount,commission_exact,subsidy_amount,calc_note,payroll_month) VALUES (?,?,'technical','前端',100,0,0,100,0,'pool',0.1,1,10,10,0,'测试','2026-09')")->execute([$d, $emp]);
    $plan = pcs_plan($parsed['map']);
    $has = function ($list, $id) { foreach ($list as $o) if ((int)$o['id'] === $id) return true; return false; };
    $check($has($plan['mark_finished'], $a) && $has($plan['mark_unfinished'], $c) && $has($plan['approved_unfinished'], $d) && !$has($plan['mark_finished'], $b) && !$has($plan['approved_unfinished'], $b), '对照系统分类正确');
    $actor = ['type' => 'admin', 'id' => 1, 'role' => 'finance'];
    pcs_apply($plan, false, $actor);
    $st = function ($id) use ($pdo) { return $pdo->query("SELECT settlement_status,delivery_status FROM project_orders WHERE id=$id")->fetch(); };
    $check($st($a)['delivery_status'] === 'finished' && $st($c)['delivery_status'] === 'unfinished' && $st($d)['settlement_status'] === 'approved', '不勾撤回：只改未审核订单，已审核的分成保持');
    $plan = pcs_plan($parsed['map']);
    $res = pcs_apply($plan, true, $actor);
    $snap = (int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$d")->fetchColumn();
    $check($res['reopened'] === 1 && $snap === 0 && $st($d)['settlement_status'] === 'draft' && $st($d)['delivery_status'] === 'unfinished', '勾选撤回：已审核的未完成订单删除快照、回到未审核');
    $bk = (string)$pdo->query("SELECT details_json FROM project_audit_logs WHERE entity_type='order' AND entity_id=$d AND action='sync_completion_reopen' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $check(strpos($bk, 'snapshots_backup') !== false && strpos($bk, '10.00') !== false, '撤回前的分成快照备份在审计日志里');
    $check($st($b)['settlement_status'] === 'approved', '表里已完成且已审核的订单不受影响');
} finally {
    foreach ($ids as $id) { foreach (['project_commission_snapshots', 'project_participants'] as $t) $pdo->exec("DELETE FROM $t WHERE order_id=$id"); $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
    if ($emp) $pdo->exec("DELETE FROM employees WHERE id=$emp");
}
echo "全部通过，测试数据已清理\n";
