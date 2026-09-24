<?php
/** 每日核对轮值脑洞缺报。页面打开时也会即时补算，唯一键保证不会重复扣。 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectGovernance.php';
$count = pg_sync_idea_penalties();
echo "管理层脑洞缺报新增扣减 {$count} 笔\n";
