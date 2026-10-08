<?php
/**
 * 跨业务分单：同一订单号由不同业务的人各录一份（如 ¥300 = 网站 ¥200 + 网站售后 ¥100）。
 * 后录入者不再被拦：系统为其建一张“分单子单”（订单号 = 原订单号~业务），各自按自己业务的规则、自己那份金额结算；
 * 收款证据（店铺流水）仍按原订单号核对，合并付款由财务分摊。
 */
require_once __DIR__ . '/ProjectSettlement.php';

function pos_ensure()
{
    static $done = false;
    if ($done) return;
    // 表已存在就不再执行建表语句：DDL 要等元数据锁，前面有长事务时会把后面所有访问这张表的请求都排队拖住（网关超时）。
    try { db()->query('SELECT 1 FROM project_order_splits LIMIT 0'); $done = true; return; }
    catch (PDOException $e) { if ((string)$e->getCode() !== '42S02') throw $e; }
    db()->exec("CREATE TABLE IF NOT EXISTS project_order_splits (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      parent_order_id BIGINT UNSIGNED NOT NULL,
      child_order_id BIGINT UNSIGNED NOT NULL,
      created_by_type VARCHAR(20) NOT NULL DEFAULT '',
      created_by_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_split_child (child_order_id),
      KEY idx_split_parent (parent_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 子单订单号：原订单号~业务名（同业务的另一位客服再加 #员工号），同一人重复上传得到同一个号，不会重复建单。 */
function pos_child_order_no($parentNo, $business, $employeeId = 0)
{
    return trim((string)$parentNo) . '~' . trim((string)$business) . ((int)$employeeId > 0 ? '#' . (int)$employeeId : '');
}

/** 订单所属的原订单（分单子单返回原单行，否则返回 null）。 */
function pos_parent_of($orderId)
{
    pos_ensure();
    $q = db()->prepare('SELECT o.id,o.order_no,o.project_type,o.contract_amount FROM project_order_splits s JOIN project_orders o ON o.id=s.parent_order_id WHERE s.child_order_id=? LIMIT 1');
    $q->execute([(int)$orderId]);
    return $q->fetch() ?: null;
}

/** 原订单下已有的分单（含各自业务与金额），用于提示与核对合计。 */
function pos_children_of($orderId)
{
    pos_ensure();
    $q = db()->prepare('SELECT o.id,o.order_no,o.project_type,o.contract_amount FROM project_order_splits s JOIN project_orders o ON o.id=s.child_order_id WHERE s.parent_order_id=? ORDER BY o.id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

function pos_link($parentId, $childId, $actor)
{
    pos_ensure();
    db()->prepare('INSERT IGNORE INTO project_order_splits (parent_order_id,child_order_id,created_by_type,created_by_id) VALUES (?,?,?,?)')
        ->execute([(int)$parentId, (int)$childId, (string)($actor['type'] ?? ''), (int)($actor['id'] ?? 0)]);
}

/** 店铺流水 / 实付证据使用的订单号：分单子单沿用原订单号。 */
function pos_evidence_order_no($order)
{
    $parent = pos_parent_of((int)($order['id'] ?? 0));
    return $parent ? $parent['order_no'] : (string)($order['order_no'] ?? '');
}

/** 分单提示文字：列出原单与已有分单，并给出含本行的合计，供上传人核对。 */
function pos_summary_text($parent, $newBusiness, $newAmount, $ownChildNo = '')
{
    $parts = [$parent['project_type'] . ' ¥' . number_format((float)$parent['contract_amount'], 2, '.', '')];
    $total = (float)$parent['contract_amount'];
    foreach (pos_children_of((int)$parent['id']) as $child) {
        if ($child['order_no'] === $ownChildNo) continue;
        $parts[] = $child['project_type'] . ' ¥' . number_format((float)$child['contract_amount'], 2, '.', '');
        $total += (float)$child['contract_amount'];
    }
    $parts[] = $newBusiness . ' ¥' . number_format((float)$newAmount, 2, '.', '') . '（本行）';
    $total += (float)$newAmount;
    return '同号订单已由其他业务 / 客服录入，本行作为分单加入：' . implode(' + ', $parts) . ' = ¥' . number_format($total, 2, '.', '') . '，请核对合计与客户实付';
}
