<?php
// 默认密码（= 登录名）登录后被带去改密页；正常密码不受影响。起本地 PHP 服务器 + 本地隔离库，临时账号用完即删。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "PASS $label\n"; };
$tag = 'wk' . bin2hex(random_bytes(3));
$port = 18300 + random_int(0, 500);
$router = sys_get_temp_dir() . "/weak_router_$tag.php";
// 路由：预置验证码，再交给真实的 login.php / 页面
file_put_contents($router, "<?php chdir('" . addslashes(dirname(__DIR__)) . "');\n\$p=parse_url(\$_SERVER['REQUEST_URI'],PHP_URL_PATH);\nrequire_once 'includes/auth.php';\nif(\$p==='/login.php'){\$_SESSION['captcha']='ABCD';}\nreturn false;\n");
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__), $router], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, dirname(__DIR__), array_merge(getenv(), ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '13399', 'DB_NAME' => DB_NAME]));
usleep(800000);
$login = function ($user, $pass) use ($port) { // 返回 [Location, cookie jar 文件]
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $ch = curl_init("http://127.0.0.1:$port/login.php"); curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => 1]); curl_exec($ch); curl_close($ch);
    $ch = curl_init("http://127.0.0.1:$port/login.php"); curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => 1, CURLOPT_HEADER => 1, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => http_build_query(['username' => $user, 'password' => $pass, 'captcha' => 'ABCD'])]);
    $r = curl_exec($ch); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', (string)$r, $m);
    return [$m[1] ?? '', $jar];
};
$ids = [];
try {
    $pdo->prepare("INSERT INTO admins (username,password) VALUES (?,?)")->execute([$tag . 'a', md5($tag . 'a')]); $ids['a1'] = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO admins (username,password) VALUES (?,?)")->execute([$tag . 'b', md5('Str0ng-Pass-9')]); $ids['a2'] = $pdo->lastInsertId();
    $addEmp = function ($name) use ($pdo) { $pdo->prepare("INSERT INTO employees (name,department,password) VALUES (?,?,?)")->execute([$name, '商标部', 'x']); return (int)$pdo->lastInsertId(); };
    $ids['e'] = $emp = $addEmp('弱口令测试一'); $ids['e2'] = $emp2 = $addEmp('弱口令测试二');
    $ins = $pdo->prepare("INSERT INTO project_users (employee_id,username,phone,password_hash,password_changed_at,role) VALUES (?,?,?,?,NOW(),'technical')");
    $ins->execute([$emp, $tag . 's', '13800000001', password_hash($tag . 's', PASSWORD_DEFAULT)]); $ids['s1'] = $pdo->lastInsertId();
    $ins->execute([$emp2, $tag . 't', '13800000002', password_hash('Str0ng-Pass-9', PASSWORD_DEFAULT)]); $ids['s2'] = $pdo->lastInsertId();
    [$loc] = $login($tag . 'a', $tag . 'a');
    $check(strpos($loc, '/project/system.php?weak=1') !== false, '管理员默认密码登录 → 改密页');
    [$loc] = $login($tag . 'b', 'Str0ng-Pass-9');
    $check(substr($loc, -10) === '/index.php', '管理员正常密码 → 首页');
    [$loc, $jar] = $login($tag . 's', $tag . 's');
    $check(strpos($loc, '/project/profile.php?password=1&weak=1') !== false, '合作人员默认密码登录 → 改密页');
    $ch = curl_init("http://127.0.0.1:$port/project/profile.php?password=1&weak=1"); curl_setopt_array($ch, [CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => 1]); $html = curl_exec($ch); curl_close($ch);
    $check(strpos($html, '请立即修改密码') !== false, '改密页显示红色提醒');
    $ch = curl_init("http://127.0.0.1:$port/project/index.php"); curl_setopt_array($ch, [CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => 1]); $html = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $check($code === 200 && strpos($html, '密码和登录名相同') !== false && strpos($html, '</html>') !== false, '业务页正常渲染且页头持续提醒');
    [$loc2, $jar2] = $login($tag . 't', 'Str0ng-Pass-9');
    $check(strpos($loc2, 'weak') === false, '合作人员正常密码 → 不带 weak');
    $ch = curl_init("http://127.0.0.1:$port/project/profile.php"); curl_setopt_array($ch, [CURLOPT_COOKIEFILE => $jar2, CURLOPT_RETURNTRANSFER => 1]); $html2 = curl_exec($ch); curl_close($ch);
    $check(strpos($html2, '请立即修改密码') === false && strpos($html2, '密码和登录名相同') === false, '正常密码页面无提醒');
    echo "OK\n";
} finally {
    proc_terminate($proc); @unlink($router);
    if (isset($ids['a1'])) $pdo->prepare("DELETE FROM admins WHERE id IN (?,?)")->execute([$ids['a1'], $ids['a2'] ?? 0]);
    if (isset($ids['s1'])) $pdo->prepare("DELETE FROM project_users WHERE id IN (?,?)")->execute([$ids['s1'], $ids['s2'] ?? 0]);
    foreach (['e', 'e2'] as $k) if (isset($ids[$k])) $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$ids[$k]]);
}
