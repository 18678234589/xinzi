<?php
/**
 * 订单删除规则（订单列表“删除 / 批量删除”共用）：
 * - 财务：任意订单（含已审核）；所在月份已核算（refund_settled_through 及以前）、已锁定的订单除外；
 * - 网站售后部：本人参与 / 代录的订单；已审核的订单只限售后部业务（备案-单量 / 备案-提成 / 网站修改 / 网站续费）——核对后发现算法错了要整批删掉重导；
 * - 客服 / 技术：本人参与、还没审核的订单（上传错表、测试单），且没有收款 / 退款登记、没有别人的分单。
 * 已审核订单删除前，完整备份订单及其分成快照、成本、收款等行到 JSON 文件，并记审计，可据此还原。
 */
const POD_DEPT_BUSINESSES = ['备案-单量', '备案-提成', '网站修改', '网站续费'];

/** 能不能删这张订单：返回空字符串表示可以，否则是不能删的原因。$row 至少含 id / order_no / project_type / order_date / settlement_status。 */
function pod_blocker(array $row, $actor)
{
    $status = (string)$row['settlement_status'];
    if ($status === 'locked') return '订单已锁定，不可删除';
    $role = (string)($actor['role'] ?? '');
    $isDept = false;
    if ($role !== 'finance' && !empty($actor['employee_id'])) {
        $q = db()->prepare('SELECT department FROM employees WHERE id=?');
        $q->execute([(int)$actor['employee_id']]);
        $isDept = $q->fetchColumn() === '网站售后部';
    }
    $pdo = db();
    if ($status === 'approved') {
        if ($role !== 'finance' && !($isDept && in_array($row['project_type'], POD_DEPT_BUSINESSES, true))) return '订单已审核生成分成，只有财务（或售后部删售后部业务订单）可删除';
        $settled = (string)ps_setting_get('refund_settled_through', '');
        if ($settled !== '' && substr((string)$row['order_date'], 0, 7) <= $settled) return '订单所在月份（' . substr((string)$row['order_date'], 0, 7) . '）已核算，不可删除';
    }
    if ($role !== 'finance') {
        $p = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? UNION SELECT 1 FROM project_department_uploaders WHERE order_id=? AND employee_id=? LIMIT 1');
        $p->execute([(int)$row['id'], (int)($actor['employee_id'] ?? 0), (int)$row['id'], (int)($actor['employee_id'] ?? 0)]);
        if (!$p->fetchColumn()) return '只能删除本人参与的订单';
        if (!$isDept) { // 普通客服 / 技术：有收款 / 退款登记或分单（别人的数据）时不能自己删
            $c = $pdo->prepare('SELECT (SELECT COUNT(*) FROM project_cash_movements WHERE order_id=?)+(SELECT COUNT(*) FROM project_order_splits WHERE parent_order_id=? OR child_order_id=?)');
            $c->execute([(int)$row['id'], (int)$row['id'], (int)$row['id']]);
            if ((int)$c->fetchColumn() > 0) return '订单已有收款 / 退款登记或分单，请联系财务删除';
        }
    }
    return '';
}

/** 删除前备份：订单及关联行（含分成快照、成本、收款），已审核订单必须成功备份才删。返回备份文件路径。 */
function pod_backup($orderId, $orderNo)
{
    $pdo = db();
    $dump = ['order_no' => $orderNo, 'order' => null, 'deleted_at' => date('c')];
    $o = $pdo->prepare('SELECT * FROM project_orders WHERE id=?'); $o->execute([(int)$orderId]); $dump['order'] = $o->fetch();
    foreach (['project_participants', 'project_costs', 'project_commission_snapshots', 'project_commission_adjustments', 'project_cash_movements', 'project_order_sources', 'project_order_details', 'project_order_resources', 'project_order_items', 'project_order_requests'] as $table) {
        try { $s = $pdo->prepare("SELECT * FROM $table WHERE order_id=?"); $s->execute([(int)$orderId]); $dump[$table] = $s->fetchAll(); } catch (Throwable $e) { $dump[$table] = 'unavailable'; }
    }
    $dir = dirname(__DIR__) . '/.deploy';
    if (!is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir();
    $file = $dir . '/deleted_orders_' . date('Ymd') . '.jsonl';
    if (file_put_contents($file, json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('删除前备份失败，已取消删除');
    return $file;
}
