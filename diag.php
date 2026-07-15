<?php
/**
 * 性能诊断脚本 - 用完即删
 */
header('Content-Type: text/plain; charset=utf-8');

// 先查MySQL配置文件路径
require_once __DIR__ . '/config/database.php';
$pdo = db();

echo "===== MySQL配置文件查找 =====\n\n";

// 方法1: SHOW VARIABLES
$paths = $pdo->query("SHOW VARIABLES LIKE 'datadir'")->fetch();
echo "数据目录: " . $paths['Value'] . "\n";

// 方法2: 查找配置文件路径
try {
    $defaults = $pdo->query("SHOW VARIABLES LIKE '%defaults%'")->fetchAll();
    foreach ($defaults as $row) {
        echo $row['Variable_name'] . ": " . $row['Value'] . "\n";
    }
} catch (Exception $e) {}

// 方法3: 查看basedir推算
$basedir = $pdo->query("SHOW VARIABLES LIKE 'basedir'")->fetch();
echo "安装目录: " . $basedir['Value'] . "\n";

// 方法4: 查看是否用了指定配置文件
try {
    $cnf = $pdo->query("SHOW VARIABLES LIKE 'socket'")->fetch();
    echo "Socket: " . $cnf['Value'] . "\n";
} catch (Exception $e) {}

echo "\n===== 常见配置文件位置 =====\n";
echo "Linux常见路径:\n";
echo "  /etc/my.cnf\n";
echo "  /etc/mysql/my.cnf\n";
echo "  /etc/mysql/mysql.conf.d/mysqld.cnf\n";
echo "  /etc/my.cnf.d/mysql-server.cnf\n";
echo "  基于basedir: " . rtrim($basedir['Value'], '/') . "/my.cnf\n";
echo "\nWindows常见路径:\n";
echo "  C:\\ProgramData\\MySQL\\MySQL Server 8.0\\my.ini\n";
echo "  基于basedir: " . rtrim($basedir['Value'], '/') . "\\my.ini\n";

echo "\n===== 查找命令 =====\n";
echo "在数据库服务器上执行:\n";
echo "  Linux: find / -name 'my.cnf' -o -name 'my.ini' 2>/dev/null\n";
echo "  Windows: dir /s /b C:\\my.ini C:\\my.cnf\n";
echo "  也可以用: mysqld --verbose --help 2>/dev/null | grep -A1 'Default options'\n";

echo "\n===== 继续性能诊断 =====\n\n";

$results = [];

// 1. session_start 耗时
$t = microtime(true);
session_start();
$results['session_start'] = round((microtime(true) - $t) * 1000);

// 2. 数据库连接耗时
$t = microtime(true);
require_once __DIR__ . '/config/database.php';
$results['加载database.php'] = round((microtime(true) - $t) * 1000);

$t = microtime(true);
$pdo = db();
$results['PDO连接(含持久)'] = round((microtime(true) - $t) * 1000);

// 3. 简单查询耗时
$t = microtime(true);
$pdo->query("SELECT 1");
$results['SELECT 1 查询'] = round((microtime(true) - $t) * 1000);

// 4. 实际首页查询耗时
$t = microtime(true);
$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
$results['COUNT employees'] = round((microtime(true) - $t) * 1000);

$t = microtime(true);
$pdo->query("SELECT COUNT(*) FROM orders WHERE COALESCE(is_deleted, 0) = 0")->fetchColumn();
$results['COUNT orders'] = round((microtime(true) - $t) * 1000);

$t = microtime(true);
$pdo->query("SELECT COALESCE(SUM(order_amount),0) FROM orders WHERE COALESCE(is_deleted, 0) = 0")->fetchColumn();
$results['SUM orders'] = round((microtime(true) - $t) * 1000);

// 5. 文件包含耗时
$t = microtime(true);
require_once __DIR__ . '/includes/functions.php';
$results['加载functions.php'] = round((microtime(true) - $t) * 1000);

$t = microtime(true);
require_once __DIR__ . '/includes/SalaryCalculator.php';
$results['加载SalaryCalculator.php'] = round((microtime(true) - $t) * 1000);

// 6. PHP环境信息
$results['PHP版本'] = PHP_VERSION;
$results['OPcache'] = function_exists('opcache_get_status') ? (opcache_get_status() ? '已启用' : '未启用') : '未安装';

// 7. 服务器信息
$results['服务器IP'] = $_SERVER['SERVER_ADDR'] ?? 'unknown';
$results['客户端IP'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// 8. MySQL连接信息
$vars = $pdo->query("SHOW VARIABLES LIKE 'skip_name_resolve'")->fetch();
$results['MySQL skip_name_resolve'] = $vars['Value'] ?? 'unknown';
$vars2 = $pdo->query("SHOW VARIABLES LIKE 'wait_timeout'")->fetch();
$results['MySQL wait_timeout'] = $vars2['Value'] ?? 'unknown';

// 9. 到数据库的网络延迟
$t = microtime(true);
$pdo->query("SELECT 1");
$ping = round((microtime(true) - $t) * 1000);
$results['DB往返延迟(估)'] = $ping . 'ms';

// 输出结果
echo "===== 性能诊断报告 =====\n\n";
$total = 0;
foreach ($results as $k => $v) {
    if (is_numeric($v)) {
        $flag = $v > 500 ? ' <<<< 慢!' : ($v > 100 ? ' << 偏慢' : '');
        echo sprintf("%-30s %s ms%s\n", $k, $v, $flag);
        $total += $v;
    } else {
        echo sprintf("%-30s %s\n", $k, $v);
    }
}
echo sprintf("\n%-30s %s ms\n", 'PHP后端总耗时(估)', $total);

// 10. 模拟完整首页请求
echo "\n===== 模拟首页完整加载 =====\n";
$t = microtime(true);
$emp_count = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
$order_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE COALESCE(is_deleted, 0) = 0")->fetchColumn();
$order_total = (float)$pdo->query("SELECT COALESCE(SUM(order_amount),0) FROM orders WHERE COALESCE(is_deleted, 0) = 0")->fetchColumn();
$salary_count = (int)$pdo->query("SELECT COUNT(*) FROM salaries")->fetchColumn();
$dept_stats = $pdo->query("SELECT department, COUNT(*) as cnt, SUM(base_salary) as total_salary FROM employees GROUP BY department ORDER BY department")->fetchAll();
$this_month = date('Y-m');
$month_start = $this_month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$stmt = $pdo->prepare("SELECT e.name, e.department, COUNT(o.id) as order_cnt, COALESCE(SUM(o.order_amount),0) as amount FROM employees e LEFT JOIN orders o ON o.employee_id = e.id AND o.order_date BETWEEN ? AND ? AND COALESCE(o.is_deleted, 0) = 0 GROUP BY e.id ORDER BY amount DESC LIMIT 10");
$stmt->execute([$month_start, $month_end]);
$month_orders = $stmt->fetchAll();
$fullTime = round((microtime(true) - $t) * 1000);
echo sprintf("首页全部SQL耗时: %s ms%s\n", $fullTime, $fullTime > 1000 ? ' <<<< 慢!' : '');
