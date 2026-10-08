<?php
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../includes/ProjectSettlement.php';
db()->exec(file_get_contents(__DIR__.'/20261008_review_rule_policies.sql'));
echo "Review rule policies migration completed\n";
