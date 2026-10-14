<?php
/** Initial credentials are stored as hashes, never accepted as a password fallback. */
function auth_staff_pinyin($name)
{
    $name = trim((string)$name);
    if ($name === '') throw new InvalidArgumentException('请填写合作人员姓名');
    if (preg_match('/^[a-z][a-z0-9]*$/iD', $name)) return strtolower($name);
    if (!class_exists('Transliterator')) throw new RuntimeException('当前服务器缺少拼音转换组件，请手动填写姓名拼音登录名');
    $converter = Transliterator::create('Han-Latin; Latin-ASCII; Lower()');
    $value = $converter ? $converter->transliterate($name) : false;
    $value = $value === false ? '' : preg_replace('/[\s\x27-]+/u', '', $value);
    if (!preg_match('/^[a-z][a-z0-9]{1,99}$/D', $value)) throw new InvalidArgumentException('无法自动生成姓名拼音，请手动填写登录名');
    return $value;
}

function auth_staff_unique_username($base, $employeeId, $isTaken)
{
    if (!preg_match('/^[a-z][a-z0-9]{1,99}$/D', $base)) throw new InvalidArgumentException('登录名须为小写姓名拼音');
    if (!$isTaken($base)) return $base;
    $candidate = substr($base, 0, 85) . (int)$employeeId;
    if ($isTaken($candidate)) throw new RuntimeException('姓名拼音登录名已被占用，请人工核对，不会覆盖已有账号');
    return $candidate;
}

function auth_staff_needs_password_change($account)
{
    return array_key_exists('password_changed_at', $account) && empty($account['password_changed_at']);
}

function auth_staff_destination($account)
{
    if (empty($account['phone'])) return '/project/profile.php?first=1';
    if (auth_staff_needs_password_change($account)) return '/project/profile.php?password=1';
    if ($account['role'] === 'governance') return '/project/governance_ideas.php';
    if ($account['role'] === 'vault') return '/project/vault.php';
    return '/project/index.php';
}

function auth_staff_login_seen($id)
{
    try { db()->prepare('UPDATE project_users SET last_login_at=NOW() WHERE id=? AND is_active=1')->execute([(int)$id]); }
    catch (PDOException $e) { /* Login statistics must never block a valid login. */ }
}
