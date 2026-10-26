<?php
/**
 * 整套测试之间共享状态的还原（只允许隔离测试库）：
 *   php tests/reset_scratch_state.php save     # 跑整套前保存：成本模板的启用状态
 *   php tests/reset_scratch_state.php restore  # 每个测试前还原：成本模板启用状态、2026-09 的结算申请、2099 年测试月份
 * 有些测试会停用成本模板、提交结算申请，导入类测试的建表又会隐式提交，失败时无法靠回滚恢复，后面的测试就会连锁误报。
 */
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
$mode = $argv[1] ?? '';
if ($mode === 'save') {
    $pdo->exec('DROP TABLE IF EXISTS _scratch_tpl_active');
    $pdo->exec('CREATE TABLE _scratch_tpl_active AS SELECT id,is_active FROM project_cost_templates');
} elseif ($mode === 'restore') {
    if ($pdo->query("SHOW TABLES LIKE '_scratch_tpl_active'")->fetchColumn()) {
        $pdo->exec('UPDATE project_cost_templates t JOIN _scratch_tpl_active s ON s.id=t.id SET t.is_active=s.is_active');
    }
    $ids = $pdo->query("SELECT id FROM project_settlement_requests WHERE payroll_month='2026-09'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) { $in = implode(',', array_map('intval', $ids)); $pdo->exec("DELETE FROM project_settlement_request_items WHERE request_id IN ($in)"); $pdo->exec("DELETE FROM project_settlement_requests WHERE id IN ($in)"); }
    $pdo->exec("DELETE FROM project_payroll_periods WHERE period LIKE '2099-%'");
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period='2026-09'");
} else { fwrite(STDERR, "用法: save | restore\n"); exit(2); }
