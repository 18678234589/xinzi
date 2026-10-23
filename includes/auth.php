<?php
/**
 * 认证与会话管理
 */
if (session_status() === PHP_SESSION_NONE) {
    if (!empty($_COOKIE['remember_token'])) {
        @ini_set('session.gc_maxlifetime', 604800);
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        session_set_cookie_params([
            'lifetime' => 604800,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/AuthPassword.php';
require_once __DIR__ . '/AuthStaff.php';
require_once __DIR__ . '/AuthThrottle.php';
require_once __DIR__ . '/AuthRemember.php';
auth_remember_check();
auth_session_validate();

/**
 * 计算项目根目录对应的 URL 路径（支持部署在子目录或根目录）
 * 例如站点在根目录时 BASE_URL = ''，在 /caiwu 子目录时 BASE_URL = '/caiwu'
 */
if (!defined('BASE_URL')) {
    $docRoot  = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));
    $projRoot = str_replace('\\', '/', dirname(__DIR__)); // includes 的上级 = 项目根
    $baseUrl  = '';
    if ($docRoot !== '' && strpos($projRoot, $docRoot) === 0) {
        $baseUrl = substr($projRoot, strlen($docRoot));
    }
    define('BASE_URL', $baseUrl);
}

/**
 * 检查是否已登录
 */
function is_logged_in()
{
    return isset($_SESSION['admin_id']);
}

/**
 * 要求登录，否则跳转
 */
function require_login()
{
    if (!is_logged_in()) {
        // 技术/客服账户只能进入“我的项目订单”，访问后台页面时带回自己的工作台，不显示公司营业数据。
        header('Location: ' . BASE_URL . (isset($_SESSION['project_user_id']) ? '/project/index.php' : '/login.php'));
        exit;
    }
}

/**
 * 获取当前登录管理员信息
 */
function current_admin()
{
    if (!is_logged_in()) return null;
    return [
        'id'       => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'] ?? '',
    ];
}
