<?php
// 授权某位员工在“订单审核”页批量审核指定业务（不含本人参与的订单）。
// 用法：php tools/grant_batch_review_delegate.php <员工ID> <员工姓名> <业务1,业务2,...>
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectBatchReview.php';
list(, $empId, $name, $list) = $argv + [null, 0, '', ''];
$businesses = array_values(array_filter(array_map('trim', explode(',', (string)$list))));
$pdo = db();
$q = $pdo->prepare('SELECT 1 FROM employees WHERE id=? AND name=?'); $q->execute([(int)$empId, $name]);
if (!$q->fetchColumn() || !$businesses) { fwrite(STDERR, "员工ID与姓名不匹配，或未给业务\n"); exit(1); }
$pdo->exec('CREATE TABLE IF NOT EXISTS project_batch_review_delegates (
  employee_id INT NOT NULL, business_name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id, business_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$ins = $pdo->prepare('INSERT IGNORE INTO project_batch_review_delegates (employee_id,business_name) VALUES (?,?)');
foreach ($businesses as $b) $ins->execute([(int)$empId, $b]);
$adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
ps_audit('employee', (int)$empId, 'batch_review_delegate', ['type' => 'admin', 'id' => $adminId], ['businesses' => $businesses]);
echo json_encode(['employee_id' => (int)$empId, 'businesses' => $businesses], JSON_UNESCAPED_UNICODE) . "\n";
