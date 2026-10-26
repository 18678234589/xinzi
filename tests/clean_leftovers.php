<?php
/**
 * 测试开头清掉上次失败遗留的数据（导入提交含建表，隐式提交会让事务回滚失效）。只允许隔离测试库。
 * 用法：require_once __DIR__ . '/clean_leftovers.php'; test_clean_leftovers($pdo, ['orders' => ['VAULT-TEST-%'], 'periods2099' => true]);
 */
function test_clean_leftovers(PDO $pdo, array $what)
{
    if (!preg_match('/_(test|ci|sandbox)$/', (string)$pdo->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('只能在隔离测试库清理');
    if (!empty($what['orders'])) {
        require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
        foreach ($what['orders'] as $like) {
            $q = $pdo->prepare('SELECT id FROM project_orders WHERE order_no LIKE ?'); $q->execute([$like]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) pod_delete_rows((int)$id);
        }
    }
    if (!empty($what['periods2099'])) {
        $pdo->exec("DELETE FROM project_payroll_periods WHERE period LIKE '2099-%'");
        $pdo->exec("DELETE FROM salaries WHERE month LIKE '2099-%'");
    }
}
