<?php
/** Explicit idempotent schema preparation; do not perform DDL inside an import transaction. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__.'/../includes/ProjectRenewals.php';
if (db()->inTransaction()) throw new RuntimeException('Run outside transactions');
pr_ensure_owner();
echo "Renewal contact schema ready\n";
