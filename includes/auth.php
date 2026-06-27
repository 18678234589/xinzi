<?php
/**
 * 认证与会话管理
 */
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

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
        header('Location: ' . BASE_URL . '/login.php');
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
