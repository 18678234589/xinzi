<?php
header('Cache-Control: no-store');
require_once __DIR__ . '/includes/auth.php';
if (empty($_SESSION['admin_id']) && empty($_SESSION['project_user_id'])) { header('Location: '.BASE_URL.'/login.php'); exit; }
$file='/etc/laibangwo-workbench/sso.php';
if (!is_file($file)) { http_response_code(503); exit('自媒体工作台接入尚未完成'); }
$cfg=require $file;
header('Location: '.$cfg['workbench_start'],true,302);
