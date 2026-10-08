<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../includes/ProjectSettlement.php';
$pdo=db();$pdo->beginTransaction();
try {
    $pdo->exec(file_get_contents(__DIR__.'/20261008_mini_custom_cs.sql'));
    $pdo->prepare("UPDATE project_auto_reviews SET policy_version='mini-rule-updated' WHERE order_id IN (SELECT id FROM project_orders WHERE project_type='小程序开发' AND settlement_status NOT IN ('approved','locked'))")->execute();
    ps_audit('system',0,'mini_customer_rule_fix',['type'=>'system','id'=>0],['version'=>'2026-10-08','custom_rate'=>.1,'custom_subsidy'=>10,'template_subsidy'=>20,'joint_subsidy'=>'pool_weighted']);
    $pdo->commit();echo "PASS mini-program customer rules migrated.\n";
} catch(Throwable $e) { $pdo->rollBack();throw $e; }
