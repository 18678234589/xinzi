<?php
/**
 * 订单回收站：删除订单时，订单及其关联行（参与人、成本、收款、分成快照等）完整存入回收站，保留 30 天，期内可还原；过期自动清除。
 * 还原时按原订单 id / 订单号 / 各行 id 原样写回；原订单号已被新订单占用时不能还原（先处理占用的订单）。
 */
const POT_KEEP_DAYS = 30;
/** 随订单删除、也随订单还原的表（与 project/index.php 的 $deleteOrderRows 一致），按还原时的写入顺序排列。 */
const POT_TABLES = ['project_order_sources', 'project_order_details', 'project_order_resources', 'project_participants', 'project_costs', 'project_order_items', 'project_cash_movements', 'project_commission_adjustments', 'project_commission_snapshots', 'project_order_requests'];

function pot_ensure()
{
    static $done = false;
    if ($done) return;
    // 表已存在就不再执行建表语句：DDL 会隐式提交事务，也会等元数据锁
    try { db()->query('SELECT 1 FROM project_order_trash LIMIT 0'); $done = true; return; }
    catch (PDOException $e) { if ((string)$e->getCode() !== '42S02') throw $e; }
    db()->exec("CREATE TABLE IF NOT EXISTS project_order_trash (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      order_no VARCHAR(160) NOT NULL DEFAULT '',
      project_type VARCHAR(40) NOT NULL DEFAULT '',
      order_date DATE NULL,
      contract_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
      settlement_status VARCHAR(20) NOT NULL DEFAULT '',
      deleted_by_type VARCHAR(20) NOT NULL DEFAULT '',
      deleted_by_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      deleted_by_name VARCHAR(80) NOT NULL DEFAULT '',
      deleted_at DATETIME NOT NULL,
      expires_at DATETIME NOT NULL,
      status ENUM('trashed','restored') NOT NULL DEFAULT 'trashed',
      restored_at DATETIME NULL,
      snapshot_json LONGTEXT NOT NULL,
      KEY idx_trash_status (status,expires_at),
      KEY idx_trash_order_no (order_no),
      KEY idx_trash_by (deleted_by_type,deleted_by_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function pot_actor_name($actor)
{
    if (($actor['type'] ?? '') === 'admin' || empty($actor['employee_id'])) {
        $q = db()->prepare('SELECT username FROM admins WHERE id=?'); $q->execute([(int)($actor['id'] ?? 0)]);
        return (string)($q->fetchColumn() ?: '财务');
    }
    $q = db()->prepare('SELECT name FROM employees WHERE id=?'); $q->execute([(int)$actor['employee_id']]);
    return (string)($q->fetchColumn() ?: '');
}

/** 把订单放入回收站（须在删除前、同一事务里调用）；返回回收站记录 id。 */
function pot_trash($orderId, $orderNo, $actor)
{
    pot_ensure();
    $pdo = db();
    $o = $pdo->prepare('SELECT * FROM project_orders WHERE id=?'); $o->execute([(int)$orderId]); $order = $o->fetch();
    if (!$order) throw new RuntimeException('订单不存在');
    $snapshot = ['order' => $order];
    foreach (POT_TABLES as $table) {
        $s = $pdo->prepare("SELECT * FROM $table WHERE order_id=?"); $s->execute([(int)$orderId]); $snapshot[$table] = $s->fetchAll();
    }
    // 技术对账行挂在分成快照上
    $snapshot['project_technical_reconciliations'] = [];
    try {
        $t = $pdo->prepare('SELECT t.* FROM project_technical_reconciliations t JOIN project_commission_snapshots s ON s.id=t.snapshot_id WHERE s.order_id=?');
        $t->execute([(int)$orderId]); $snapshot['project_technical_reconciliations'] = $t->fetchAll();
    } catch (Throwable $e) { /* 表可能尚未建立 */ }
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) throw new RuntimeException('放入回收站失败，已取消删除');
    $pdo->prepare('INSERT INTO project_order_trash (order_id,order_no,project_type,order_date,contract_amount,settlement_status,deleted_by_type,deleted_by_id,deleted_by_name,deleted_at,expires_at,snapshot_json) VALUES (?,?,?,?,?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL ' . POT_KEEP_DAYS . ' DAY),?)')
        ->execute([(int)$orderId, (string)$order['order_no'], (string)$order['project_type'], $order['order_date'], (float)$order['contract_amount'], (string)$order['settlement_status'], (string)($actor['type'] ?? ''), (int)($actor['id'] ?? 0), pot_actor_name($actor), $json]);
    return (int)$pdo->lastInsertId();
}

/** 清除过期（超过 30 天）的回收站记录；返回清除条数。 */
function pot_purge_expired()
{
    pot_ensure();
    $q = db()->prepare("DELETE FROM project_order_trash WHERE expires_at<NOW() OR (status='restored' AND restored_at<DATE_SUB(NOW(),INTERVAL 7 DAY))");
    $q->execute();
    return $q->rowCount();
}

/** 回收站列表：财务看全部，其他人看自己删除的；只列未还原的。 */
function pot_list($actor)
{
    pot_ensure();
    $sql = "SELECT id,order_id,order_no,project_type,order_date,contract_amount,settlement_status,deleted_by_name,deleted_at,expires_at,DATEDIFF(expires_at,NOW()) days_left FROM project_order_trash WHERE status='trashed' AND expires_at>=NOW()";
    $params = [];
    if (($actor['role'] ?? '') !== 'finance') { $sql .= ' AND deleted_by_type=? AND deleted_by_id=?'; $params = [(string)($actor['type'] ?? ''), (int)($actor['id'] ?? 0)]; }
    $q = db()->prepare($sql . ' ORDER BY deleted_at DESC,id DESC LIMIT 300');
    $q->execute($params);
    return $q->fetchAll();
}

/** 还原一条回收站记录；成功返回还原后的订单号，失败抛 RuntimeException。 */
function pot_restore($trashId, $actor)
{
    pot_ensure();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_order_trash WHERE id=? FOR UPDATE'); $q->execute([(int)$trashId]); $t = $q->fetch();
        if (!$t || $t['status'] !== 'trashed' || strtotime((string)$t['expires_at']) < time()) throw new RuntimeException('这条记录已还原或已过期');
        if (($actor['role'] ?? '') !== 'finance' && ((string)$t['deleted_by_type'] !== (string)($actor['type'] ?? '') || (int)$t['deleted_by_id'] !== (int)($actor['id'] ?? 0))) throw new RuntimeException('只能还原自己删除的订单，其他请联系财务');
        $snap = json_decode((string)$t['snapshot_json'], true);
        if (!is_array($snap) || empty($snap['order'])) throw new RuntimeException('回收站数据损坏，无法还原');
        $order = $snap['order'];
        $dup = $pdo->prepare('SELECT 1 FROM project_orders WHERE order_no=? OR id=? LIMIT 1'); $dup->execute([(string)$order['order_no'], (int)$order['id']]);
        if ($dup->fetchColumn()) throw new RuntimeException('订单号“' . $order['order_no'] . '”已被重新导入的订单占用，请先删除那张订单（或让财务处理）再还原');
        $insert = function ($table, array $row) use ($pdo) {
            $cols = array_keys($row);
            $pdo->prepare('INSERT INTO ' . $table . ' (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        };
        $insert('project_orders', $order);
        foreach (POT_TABLES as $table) foreach ((array)($snap[$table] ?? []) as $row) $insert($table, $row);
        foreach ((array)($snap['project_technical_reconciliations'] ?? []) as $row) $insert('project_technical_reconciliations', $row);
        $pdo->prepare("UPDATE project_order_trash SET status='restored',restored_at=NOW() WHERE id=?")->execute([(int)$trashId]);
        ps_audit('order', (int)$order['id'], 'restore_order', $actor, ['order_no' => $order['order_no'], 'trash_id' => (int)$trashId]);
        $pdo->commit();
        return (string)$order['order_no'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException) throw new RuntimeException('还原失败（数据与现有订单冲突），请联系财务');
        throw $e;
    }
}
