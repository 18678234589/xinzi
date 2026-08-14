<?php
/**
 * CLI：定时扫描并自动导入 客服绩效采集文件（Windows 计划任务用）
 *
 * 用法：
 *   php scripts\cs_perf_sync.php
 *
 * 行为：
 *   - 扫描 storage/cs_perf_uploads 目录下的 .csv/.txt 文件
 *   - 逐一调用 import_cs_perf_file() 导入（列名模糊匹配、按旺旺/姓名匹配员工）
 *   - 导入成功后把文件移动到 _imported/ 子目录归档，避免重复导入
 *
 * 浏览器访问会被拒绝（仅命令行运行）。
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/cs_perf_config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("仅允许命令行运行\n");
}

$dir = CS_PERF_UPLOAD_DIR;
if (!is_dir($dir)) {
    exit("上传目录不存在: {$dir}\n");
}

$files = array_values(array_filter(scandir($dir), function ($f) use ($dir) {
    if ($f === '.' || $f === '..' || is_dir($dir . '/' . $f)) return false;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    return in_array($ext, ['csv', 'txt'], true);
}));

if (empty($files)) {
    echo "[" . date('Y-m-d H:i:s') . "] 无待导入文件\n";
    exit(0);
}

$archDir = $dir . '/_imported';
if (!is_dir($archDir)) @mkdir($archDir, 0755, true);

$totalMatched = 0;
$totalPending = 0;
$totalErrors  = 0;

foreach ($files as $f) {
    $path = $dir . '/' . $f;
    $r = import_cs_perf_file($path, 'sync:' . $f);
    $totalMatched += $r['matched'];
    $totalPending += $r['pending'];
    $totalErrors  += $r['errors'];
    printf("[%s] %s  匹配%d 未匹配%d 错误%d\n",
        date('Y-m-d H:i:s'), $f, $r['matched'], $r['pending'], $r['errors']);

    if (is_file($path) && @rename($path, $archDir . '/' . $f)) {
        echo "  已归档到 _imported/\n";
    }
}

echo "完成：匹配{$totalMatched} 未匹配{$totalPending} 错误{$totalErrors}\n";
