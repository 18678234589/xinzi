<?php
/**
 * 客服绩效采集上传配置
 *
 * CS_PERF_UPLOAD_TOKEN：
 *   桌面采集工具(kefu_waiter)上传 CSV 时需带同样的 token 鉴权，防止未授权写入。
 *   如需更换，修改此处并把 tools/kefu_waiter/config.ini 里的 token 改成一致。
 * CS_PERF_UPLOAD_DIR：
 *   计划任务扫描的落盘目录；工具 HTTP 上传时也写到该目录。
 */
define('CS_PERF_UPLOAD_TOKEN', 'cs_perf_W9x7Km2Q1v3tR8z');

define('CS_PERF_UPLOAD_DIR', dirname(__DIR__) . '/storage/cs_perf_uploads');
