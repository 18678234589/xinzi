<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
$pdo=db();
foreach (['admins','project_users'] as $table) {
    $q=$pdo->query("SHOW COLUMNS FROM $table LIKE 'auth_version'");
    if (!$q->fetch()) $pdo->exec("ALTER TABLE $table ADD COLUMN auth_version INT NOT NULL DEFAULT 0");
}
$pdo->exec("ALTER TABLE admins MODIFY COLUMN password VARCHAR(255) NOT NULL");
foreach (explode(';',file_get_contents(__DIR__.'/20261010_password_recovery.sql')) as $sql) {
    if (trim($sql)!=='') $pdo->exec($sql);
}
// Reuse encrypted credentials only. Verification templates stay independent from renewal notifications.
$q=$pdo->query("SHOW TABLES LIKE 'project_renewal_sms_config'");
if ($q->fetchColumn()) {
    $old=$pdo->query('SELECT * FROM project_renewal_sms_config WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if ($old) $pdo->prepare("UPDATE account_recovery_config SET access_key_id=?,secret_cipher=? WHERE id=1 AND access_key_id='' AND secret_cipher=''")->execute([$old['access_key_id'],$old['secret_cipher']]);
}
echo "Password recovery schema ready; SMS is disabled until administrator configuration approval.\n";
