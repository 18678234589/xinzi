<?php
$dir = __DIR__ . '/storage/abnormal_cache';
echo "<pre>\n=== 缓存目录状态 ===\n";
echo "路径: {$dir}\n";
echo "存在: " . (is_dir($dir) ? 'YES' : 'NO') . "\n";
if (is_dir($dir)) {
    $files = glob($dir . '/*.json');
    echo "缓存文件数: " . count($files) . "\n";
    foreach ($files as $f) {
        echo "  " . basename($f) . " (修改时间: " . date('Y-m-d H:i:s', filemtime($f)) . ", 大小: " . filesize($f) . ")\n";
    }
}
// 强制删除所有缓存
if (is_dir($dir)) {
    foreach (glob($dir . '/*.json') as $f) {
        unlink($f);
        echo "已删除: " . basename($f) . "\n";
    }
}
// 同时删除 schema 标记（强制重建）
$flag = __DIR__ . '/storage/.schema_checked';
if (file_exists($flag)) {
    unlink($flag);
    echo "已删除 .schema_checked\n";
}
echo "\n缓存已全部清除。请刷新异常订单页。\n</pre>\n";
