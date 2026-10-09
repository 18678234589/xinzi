<?php
// 兼容旧维护命令；统一使用带完整备份、事项核对与价格校准的新入口。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../migrations/repair_trademark_pricing.php';
