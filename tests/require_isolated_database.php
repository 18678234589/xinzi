<?php
/** 涉及批量停用规则 / 模板的测试只能运行在专用测试库，不能靠事务侥幸保护生产库。 */
function require_isolated_test_database($pdo)
{
    $name=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!preg_match('/(?:_test|_ci|_sandbox)$/i',$name)) {
        fwrite(STDERR,"拒绝运行：该测试会批量改写规则，只允许名称以 _test、_ci 或 _sandbox 结尾的独立测试数据库。\n");
        exit(2);
    }
}
