<?php
/* split: project/import/01_context.php */ include __DIR__ . '/import/01_context.php';
/* split: project/import/preview_controller.php */ include __DIR__ . '/import/preview_controller.php';/* split: project/import/06_followup.php */ include __DIR__ . '/import/06_followup.php';
if ((PHP_SAPI === 'cli' && !empty($GLOBALS['project_import_cli'])) || !empty($GLOBALS['project_import_api'])) return; // 在线表格自动同步（includes/ProjectOnlineSheets.php）只跑核对 / 导入，不输出页面
/* split: project/import/view.php */ include __DIR__ . '/import/view.php';