<?php
/** Run in a fresh CLI process. Loading these libraries does not execute pages or query a database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
$root = isset($argv[1]) ? $argv[1] : dirname(__DIR__);
foreach (['functions.php', 'ProjectIntake.php', 'ProjectMonthly.php', 'ProjectGovernance.php', 'SalaryCalculator.php'] as $entry) {
    require_once $root . '/includes/' . $entry;
}
$functions = get_defined_functions()['user'];
sort($functions);
$classes = [];
foreach (get_declared_classes() as $name) {
    $class = new ReflectionClass($name);
    if (!$class->isUserDefined()) continue;
    $methods = [];
    foreach ($class->getMethods() as $method) {
        $methods[$method->getName()] = [$method->isPublic(), $method->isProtected(), $method->isPrivate(),
            $method->isStatic(), $method->returnsReference(), $method->getNumberOfParameters(),
            $method->getNumberOfRequiredParameters()];
    }
    ksort($methods);
    $classes[$name] = $methods;
}
ksort($classes);
echo json_encode(['functions' => $functions, 'classes' => $classes], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
