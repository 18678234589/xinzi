<?php
// 项目账号密码（订单内）：新增 / 删除 / 查看 / 粘贴识别。权限跟随订单：参与人、部门代录人、财务。
require_once __DIR__ . '/../includes/ProjectVault.php';

$actor = ps_require_actor();
if (!headers_sent()) { header('Cache-Control: no-store, max-age=0'); header('X-Robots-Tag: noindex'); }
$json = function ($data, $status = 200) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$ajax = !empty($_POST['ajax']);
if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { if ($ajax) $json(['error' => '页面已过期，请刷新后重试'], 403); ps_check_csrf(); }
$orderId = (int)($_POST['order_id'] ?? 0);
$order = ps_order($orderId, $actor); // 无权限时直接 403
$back = BASE_URL . '/project/order.php?id=' . $orderId . '#credentials';
$action = (string)($_POST['action'] ?? '');
try {
    if (!pv_order_enabled($order)) throw new RuntimeException('此业务不需要记录项目账号密码');
    if ($action === 'reveal') {
        $q = db()->prepare('SELECT * FROM project_order_credentials WHERE id=? AND order_id=? AND deleted_at IS NULL');
        $q->execute([(int)($_POST['id'] ?? 0), $orderId]);
        $row = $q->fetch();
        if (!$row) $json(['error' => '记录不存在'], 404);
        ps_audit('order', $orderId, (string)($_POST['purpose'] ?? '') === 'copy' ? 'credential_copy' : 'credential_reveal', $actor, ['credential_id' => (int)$row['id'], 'kind' => $row['kind']]);
        $json(['password' => pv_decrypt($row['password_enc']), 'notes' => pv_decrypt($row['notes_enc'])]);
    } elseif ($action === 'parse') {
        $parsed = pv_parse_paste((string)($_POST['text'] ?? ''), PV_ORDER_KINDS, $actor);
        // 平台信息的字段映射到项目账号：名称 → 说明，主机并入网址
        $parsed['items'] = array_map(function ($it) { return ['kind' => $it['category'], 'label' => $it['name'], 'url' => $it['url'] !== '' ? $it['url'] : $it['host'], 'account' => $it['account'], 'password' => $it['password'], 'notes' => trim(($it['url'] !== '' && $it['host'] !== '' ? '主机：' . $it['host'] . "\n" : '') . $it['notes'])]; }, $parsed['items']);
        $json($parsed);
    } elseif ($action === 'add') {
        $saved = 0;
        $rows = (array)($_POST['cred'] ?? []);
        db()->beginTransaction();
        try {
            foreach ($rows as $row) if (is_array($row) && pv_order_credential_save($orderId, $row, $actor)) $saved++;
            db()->commit();
        } catch (Throwable $e) { db()->rollBack(); throw $e; }
        if (!$saved) throw new RuntimeException('请至少填写一行的地址、账号或密码');
        $_SESSION['credential_notice'] = '已保存 ' . $saved . ' 条项目账号密码';
    } elseif ($action === 'delete') {
        $q = db()->prepare('SELECT * FROM project_order_credentials WHERE id=? AND order_id=? AND deleted_at IS NULL');
        $q->execute([(int)($_POST['id'] ?? 0), $orderId]);
        $row = $q->fetch();
        if (!$row) throw new RuntimeException('记录不存在');
        if ($actor['role'] !== 'finance' && !($row['created_by_type'] === $actor['type'] && (int)$row['created_by_id'] === (int)$actor['id'])) throw new RuntimeException('只能删除自己记录的账号密码，其他请联系财务');
        db()->prepare('UPDATE project_order_credentials SET deleted_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        ps_audit('order', $orderId, 'credential_delete', $actor, ['credential_id' => (int)$row['id'], 'kind' => $row['kind']]);
        $_SESSION['credential_notice'] = '已删除';
    } else throw new RuntimeException('操作无效');
} catch (RuntimeException $e) {
    if ($ajax) $json(['error' => $e->getMessage()], 400);
    $_SESSION['credential_error'] = $e->getMessage();
}
header('Location: ' . $back);
exit;
