<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectRenewalSms.php';
try {
    $send=in_array('--send',$argv,true);
    pr_seed();
    echo json_encode(pr_sms_run($send),JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) { fwrite(STDERR,"renewal_job_failed: " . get_class($e) . "\n"); exit(1); }
