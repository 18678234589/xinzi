<?php
// 客服绩效：店铺“咨询接待分析”表解析（跳过汇总值 / 平均值）、评分、排名 850/800/750、重传覆盖、昵称对应、权限。
// 会写入测试数据，只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/service_performance_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/lib/cs_perf_reception.php';
require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php'; // pmt_zip：本机 PHP 没有 zip 扩展，用它拼 xlsx
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); csr_ensure();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$Y = 2099; $M = 1; $store = '测试店铺A';
$head = ['旺旺昵称', '咨询人数', '接待人数', '未回复人数', '有效接待人数', '平均响应时长（秒)', '3分钟人工响应率', '旺旺回复率', '首次响应时长（秒)', '慢响应人数', '答问比', '旺旺分组'];
$data = [
    ['孙静怡', 547, 543, 0, 415, 13.78, 1.0, 1.0, 18.46, 13, 1.23, '图片'],
    ['张欣', 506, 499, 1, 408, 9.32, 0.9971, 1.0, 17.46, 14, 1.54, '图片'],
    ['穆楠', 535, 521, 2, 381, 11.45, 0.9966, 1.0, 15.47, 11, 1.41, '图片'],
    ['汇总值', 1588, 1563, 3, 1204, 11.5, 0.99, 1.0, 17, 38, 1.4, ''],
    ['平均值', 529, 521, 1, 401, 11.5, 0.99, 1.0, 17, 12, 1.4, ''],
];
$mkXlsx = function ($rows) {
    $path = tempnam(sys_get_temp_dir(), 'csr') . '.xlsx';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $r => $line) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach ($line as $c => $v) {
            $ref = chr(65 + $c) . ($r + 1);
            $sheet .= is_string($v) ? '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($v) . '</t></is></c>' : '<c r="' . $ref . '"><v>' . $v . '</v></c>';
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    file_put_contents($path, pmt_zip([
        '[Content_Types].xml' => $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $xml . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="数据信息" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ]));
    return $path;
};
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => 0];
$empId = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? AND department=?'); $q->execute([$name, CS_PERF_RANK_DEPT]); return (int)$q->fetchColumn(); };
$ids = ['孙静怡' => $empId('孙静怡'), '张欣' => $empId('张欣'), '穆楠' => $empId('穆楠')];
$spare = $empId('曲俊泽'); $spareWang = $spare ? (string)$pdo->query('SELECT wangwang FROM employees WHERE id=' . $spare)->fetchColumn() : '';
$files = [];
try {
    $check($ids['孙静怡'] && $ids['张欣'] && $ids['穆楠'] && $spare, '三位设计客服和一位备用人员在员工表里');
    $f = $mkXlsx(array_merge([$head], $data)); $files[] = $f;
    $r = csr_import($f, 'x.xlsx', $Y, $M, $store, $finance, '财务');
    $check($r['saved'] === 3 && $r['matched'] === 3 && !$r['unmatched'], '汇总值 / 平均值行被跳过，3 位客服按姓名对上员工');
    $row = $pdo->query("SELECT * FROM cs_perf_reception WHERE year=$Y AND month=$M AND nick='张欣'")->fetch();
    $check(abs((float)$row['resp3_rate'] - 0.9971) < 1e-6 && (int)$row['incoming_cnt'] === 499 && abs((float)$row['first_resp'] - 17.46) < 1e-6, '张欣的数值读对（3分钟响应率 99.71%、接待 499、首次响应 17.46）');

    $check(csr_has_data($Y, $M) && !csr_has_data($Y, $M + 1), '有数据的月份启用新评分，没数据的月份不启用');
    $rank = cs_perf_rank_list($Y, $M);
    $names = array_map(function ($x) { return $x['name']; }, array_slice($rank, 0, 3));
    $check($names === ['张欣', '穆楠', '孙静怡'], '排名：张欣 > 穆楠 > 孙静怡（' . implode('、', $names) . '）');
    $check($rank[0]['score'] > $rank[1]['score'] && $rank[1]['score'] > $rank[2]['score'], '三人得分互不相同，不会同分');
    $amt = [];
    foreach ($names as $n) { $rr = cs_perf_rank_result($ids[$n], $Y, $M); $amt[] = $rr['amount']; }
    $check($amt === [850.0, 800.0, 750.0] && array_sum($amt) / 3 == 800.0, '前三名 850 / 800 / 750，平均 800');
    $check(cs_perf_rank_result($spare, $Y, $M)['amount'] == 0.0, '没数据的设计客服排在三名之后，不发');

    // 重传同月同店 → 覆盖，不叠加
    $data2 = $data; $data2[0][7] = 0.80; $data2[0][6] = 0.80;
    $f2 = $mkXlsx(array_merge([$head], $data2)); $files[] = $f2;
    csr_import($f2, 'y.xlsx', $Y, $M, $store, $finance, '财务');
    $check((int)$pdo->query("SELECT COUNT(*) FROM cs_perf_reception WHERE year=$Y AND month=$M AND store='$store'")->fetchColumn() === 3, '重传同月同店：行数不变，覆盖旧数据');
    $check(abs((float)$pdo->query("SELECT resp3_rate FROM cs_perf_reception WHERE year=$Y AND month=$M AND nick='孙静怡'")->fetchColumn() - 0.80) < 1e-6, '重传后孙静怡的数值已更新');
    $check(cs_perf_rank_result($ids['孙静怡'], $Y, $M)['rank'] === 3, '孙静怡仍是第 3 名（排名按新数据重算）');

    // 昵称没对上 → 绑定
    $data3 = $data; $data3[] = ['路人甲', 100, 90, 0, 70, 20, 0.97, 0.99, 25, 3, 1.1, '图片'];
    $f3 = $mkXlsx(array_merge([$head], $data3)); $files[] = $f3;
    $r3 = csr_import($f3, 'z.xlsx', $Y, $M, $store, $finance, '财务');
    $check($r3['unmatched'] === ['路人甲'], '不认识的昵称被列为待对应');
    csr_bind('路人甲', $spare);
    $check((int)$pdo->query("SELECT employee_id FROM cs_perf_reception WHERE year=$Y AND month=$M AND nick='路人甲'")->fetchColumn() === $spare, '绑定后该行归到对应员工');
    $check(strpos((string)$pdo->query('SELECT wangwang FROM employees WHERE id=' . $spare)->fetchColumn(), '路人甲') !== false, '昵称记进员工旺旺账号，下个月自动对上');

    // 评分与规则参数
    $threw = false;
    try { csr_params_save(['reply_rate' => ['weight' => 10, 'bad' => 95, 'good' => 95]]); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '最差值与最好值相同的规则被拒绝');
    $s = csr_score_row(['incoming_cnt' => 100, 'valid_cnt' => 85, 'slow_cnt' => 0, 'reply_rate' => 1, 'resp3_rate' => 1, 'avg_resp' => 5, 'first_resp' => 8]);
    $check(abs($s['score'] - 100) < 1e-9, '每项都达到最好值时是 100 分');
    $s0 = csr_score_row(['incoming_cnt' => 100, 'valid_cnt' => 40, 'slow_cnt' => 20, 'reply_rate' => 0.5, 'resp3_rate' => 0.5, 'avg_resp' => 99, 'first_resp' => 99]);
    $check(abs($s0['score']) < 1e-9, '每项都不如最差值时是 0 分');

    // 权限
    $check(csr_can_view($finance) && csr_can_manage($finance), '财务：能看、能管理');
    $design = ['type' => 'employee', 'id' => 0, 'role' => 'customer_service', 'employee_id' => $ids['张欣']];
    $check(csr_can_view($design) && !csr_can_manage($design), '设计客服：能看能上传，不能管理');
    $web = (int)$pdo->query("SELECT id FROM employees WHERE department='网站客服' LIMIT 1")->fetchColumn();
    $check(!csr_can_view(['type' => 'employee', 'id' => 0, 'role' => 'customer_service', 'employee_id' => $web]), '网站客服：看不到');
    echo "全部通过\n";
} finally {
    $pdo->exec("DELETE FROM cs_perf_reception WHERE year=$Y");
    if ($spare) $pdo->prepare('UPDATE employees SET wangwang=? WHERE id=?')->execute([$spareWang, $spare]);
    $pdo->exec("DELETE FROM cs_perf_reception_cfg WHERE k='params'");
    foreach ($files as $f) @unlink($f);
}
