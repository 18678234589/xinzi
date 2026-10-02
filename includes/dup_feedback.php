<?php
// 订单号重复行说明：上传表里同一订单号出现多行（系统会把售价相加合并）时，
// 通知上传人写明原因；上传人、其部门主管、财务/管理员都能在“订单号重复说明”页看到。
require_once __DIR__ . '/ProjectSettlement.php';

// 网站和小程序业务的专属财务（管理员账号）：只有他们能看到重复订单号说明及其提醒。
const PD_FINANCE_USERNAMES = ['songwenna', 'weihuizi'];

function pd_is_dedicated_finance(array $actor)
{
    return ($actor['type'] ?? '') === 'admin' && in_array(pd_admin_username($actor), PD_FINANCE_USERNAMES, true);
}

function pd_admin_username(array $actor)
{
    static $cache = [];
    $id = (int)($actor['id'] ?? 0);
    if (!isset($cache[$id])) {
        $q = db()->prepare('SELECT username FROM admins WHERE id=?');
        $q->execute([$id]);
        $cache[$id] = (string)$q->fetchColumn();
    }
    return $cache[$id];
}

function pd_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_dup_feedback (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      order_no VARCHAR(100) NOT NULL,
      employee_id INT NOT NULL,
      lines_text VARCHAR(120) NOT NULL DEFAULT '',
      amount DECIMAL(14,2) NULL,
      status ENUM('asking','answered','closed') NOT NULL DEFAULT 'asking',
      reason TEXT NULL,
      answered_at DATETIME NULL,
      closed_by VARCHAR(80) NULL,
      closed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_dup_order_emp (order_id, employee_id),
      KEY idx_dup_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS project_dept_heads (
      department VARCHAR(100) NOT NULL,
      employee_id INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (department, employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 网站售后部主管：于洋；网站和小程序业务各部门主管：张光萍。其他部门主管由财务在此表补充。
    db()->exec("INSERT IGNORE INTO project_dept_heads (department, employee_id) SELECT '网站售后部', id FROM employees WHERE name='于洋' AND department='网站售后部'");
    db()->exec("INSERT IGNORE INTO project_dept_heads (department, employee_id) SELECT d.department, e.id FROM employees e JOIN (SELECT DISTINCT department FROM employees WHERE department IN ('网站定制','网站售后部','网站客服','网站技术','网站资料员','网站人事','定制前端','定制后端','标书小程序')) d WHERE e.name='张光萍'");
    $done = true;
}

function pd_message($employeeId, $title, $body, $link, $key)
{
    try {
        db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$employeeId, 'dup_feedback', mb_substr($title, 0, 160), $body, $link, $key]);
    } catch (Throwable $e) {
        // 站内信表异常不影响导入
    }
}

/** 上传人所在部门的主管（不含本人）。 */
function pd_department_heads($employeeId)
{
    pd_ensure();
    $q = db()->prepare('SELECT h.employee_id FROM project_dept_heads h JOIN employees e ON e.department=h.department WHERE e.id=? AND h.employee_id<>?');
    $q->execute([(int)$employeeId, (int)$employeeId]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/** 导入合并了同号多行时调用：登记并通知上传人写明原因。 */
function pd_ask($orderId, $orderNo, $employeeId, array $lines, $amount)
{
    pd_ensure();
    $lineText = implode('、', $lines);
    $ins = db()->prepare('INSERT IGNORE INTO project_dup_feedback (order_id,order_no,employee_id,lines_text,amount) VALUES (?,?,?,?,?)');
    $ins->execute([(int)$orderId, (string)$orderNo, (int)$employeeId, $lineText, $amount === '' ? null : $amount]);
    if (!$ins->rowCount()) return;
    $id = (int)db()->lastInsertId();
    pd_message($employeeId, '订单号 ' . $orderNo . ' 在表里出现了多行，请说明原因',
        '你上传的表格中，订单号 ' . $orderNo . ' 出现在第 ' . $lineText . " 行，系统已把这几行的售价合并相加（合计 ¥" . number_format((float)$amount, 2) . "）。\n请点“去处理”写明原因（例如：确为两笔订单 / 重复录入需改回）。你的说明会同步给财务、管理员和你的部门主管。",
        '/project/dup_feedback.php?id=' . $id, 'dup_ask:' . $id);
}

function pd_can_view(array $actor, array $row)
{
    if (($actor['type'] ?? '') === 'admin') return pd_is_dedicated_finance($actor);
    $eid = (int)($actor['employee_id'] ?? 0);
    return $eid === (int)$row['employee_id'] || in_array($eid, pd_department_heads($row['employee_id']), true);
}

function pd_answer($id, array $actor, $reason)
{
    pd_ensure();
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 5) throw new RuntimeException('请写清原因（至少 5 个字）');
    $q = db()->prepare('SELECT f.*, e.name FROM project_dup_feedback f JOIN employees e ON e.id=f.employee_id WHERE f.id=?');
    $q->execute([(int)$id]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('记录不存在');
    if ((int)$row['employee_id'] !== (int)($actor['employee_id'] ?? 0)) throw new RuntimeException('只有上传人本人可以填写原因');
    db()->prepare("UPDATE project_dup_feedback SET reason=?, status='answered', answered_at=NOW() WHERE id=? AND status<>'closed'")->execute([$reason, (int)$id]);
    ps_audit('order', (int)$row['order_id'], 'dup_feedback_answer', $actor, ['reason' => $reason]);
    foreach (pd_department_heads($row['employee_id']) as $headId) {
        pd_message($headId, $row['name'] . ' 说明了订单号 ' . $row['order_no'] . ' 的重复行原因', '原因：' . $reason, '/project/dup_feedback.php?id=' . (int)$id, 'dup_ans_head:' . (int)$id . ':' . $headId);
    }
}

function pd_close($id, array $actor)
{
    if (!pd_is_dedicated_finance($actor)) throw new RuntimeException('仅专属财务可确认');
    pd_ensure();
    db()->prepare("UPDATE project_dup_feedback SET status='closed', closed_by=?, closed_at=NOW() WHERE id=? AND status='answered'")
        ->execute([pd_admin_username($actor), (int)$id]);
}

/** 财务/管理员待看：已说明未确认的条数。 */
function pd_answered_count()
{
    try {
        pd_ensure();
        return (int)db()->query("SELECT COUNT(*) FROM project_dup_feedback WHERE status='answered'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
