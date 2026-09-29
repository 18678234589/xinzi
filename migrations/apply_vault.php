<?php
// php migrations/apply_vault.php — 建表、扩展账号角色；密钥文件不存在时生成（只在服务器执行一次，之后勿改动，否则已存密码无法解密）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
foreach (preg_split('/;\s*(?:\r?\n|$)/', file_get_contents(__DIR__ . '/20260929_vault.sql')) as $statement) {
    $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
    if ($statement !== '') db()->exec($statement);
}
$keyFile = dirname(__DIR__) . '/config/vault_key.php';
if (!is_file($keyFile)) {
    file_put_contents($keyFile, "<?php\n// 平台信息 / 项目账号密码的加密密钥。丢失或改动后已存密码将无法解密；不要提交到 git。\nreturn '" . base64_encode(random_bytes(32)) . "';\n", LOCK_EX);
    @chmod($keyFile, 0640);
    echo "已生成密钥 config/vault_key.php\n";
}
echo "vault ready\n";
