<?php
require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';
require_once __DIR__ . '/../classes/SimpleXLSX.php';
$path = tempnam(sys_get_temp_dir(), 'filing_tpl');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); };
try {
    file_put_contents($path, pmt_xlsx('森动备案'));
    $sheets = SimpleXLSX::parseAll($path);
    $check(count($sheets) === 2, '模板必须包含订单表与填写说明');
    $rows = reset($sheets);
    $check($rows[0] === pmt_headers('森动备案'), 'Excel 表头与模板列一致');
    $shop = array_search('店铺', $rows[0], true);
    $no = array_search('订单编号', $rows[0], true);
    $check($rows[1][$shop] === '微信' && $rows[2][$shop] === '对公', '微信和对公示例可由实际上传解析器读取');
    for ($i = 1; $i <= 3; $i++) $check(strpos($rows[$i][$no], '示例-') === 0, '示例行带自动跳过标记');
    $zip = new SimpleXLSXZipReader();
    $check($zip->open($path), 'xlsx ZIP 包可读取');
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $check(strpos($xml, '微信,对公,淘宝') !== false, '店铺提供微信 / 对公 / 淘宝下拉');
    $check(strpos($xml, '备案,二次备案') !== false, '业务提供备案 / 二次备案下拉');
    $check(strpos($xml, '已完成,未完成') !== false, '状态提供下拉');
    $check(strpos($xml, 'state="frozen"') !== false, '冻结表头方便填写');
    $zip->close();
    echo "Filing XLSX parser and dropdown checks passed\n";
} finally {
    @unlink($path);
}
