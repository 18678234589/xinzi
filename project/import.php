<?php
/* split: project/import/01_context.php */ include __DIR__ . '/import/01_context.php';
/* split: project/import/preview_controller.php */ include __DIR__ . '/import/preview_controller.php';/* split: project/import/06_followup.php */ include __DIR__ . '/import/06_followup.php';
if (PHP_SAPI === 'cli' && !empty($GLOBALS['project_import_cli'])) return;
/* split: project/import/view.php */ include __DIR__ . '/import/view.php';