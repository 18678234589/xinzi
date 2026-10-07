<?php
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectVault.php';
require_once __DIR__ . '/ProjectRenewalMath.php';

function pr_ready()
{
    static $ready;
    if ($ready !== null) return $ready;
    try { db()->query('SELECT id FROM project_renewal_items LIMIT 1'); return $ready = true; }
    catch (PDOException $e) { return $ready = false; }
}
function pr_scope($actor)
{
    if (($actor['role'] ?? '') === 'finance') return 'all';
    $q = db()->prepare('SELECT name,department FROM employees WHERE id=?');
    $q->execute([(int)($actor['employee_id'] ?? 0)]); $e = $q->fetch();
    return $e ? pr_policy_scope($actor['role'], $e['department'], $e['name'], ps_actor_businesses($actor)) : 'none';
}
function pr_require($actor)
{
    if (pr_scope($actor) === 'none') { http_response_code(403); exit('当前账户未分配续费工作台权限'); }
    if (!pr_ready()) { http_response_code(503); exit('续费工作台正在初始化，请稍后再试'); }
}
function pr_is_super($actor)
{
    if (($actor['type'] ?? '') !== 'admin') return false;
    $q = db()->prepare('SELECT 1 FROM project_kb_super_admins WHERE admin_id=?'); $q->execute([(int)$actor['id']]);
    return (bool)$q->fetchColumn();
}
/** Renewal-only scope: does not grant access to order finances or passwords. */
function pr_order_where($actor, &$params)
{
    $scope = pr_scope($actor);
    $web = "o.project_type IN ('AI网站定制','网站模板','网站续费','网站修改')";
    $supported = "o.project_type IN ('AI网站定制','网站模板','网站续费','网站修改','小程序开发')";
    if ($scope === 'all') return $supported;
    if ($scope === 'web') return $web;
    if ($scope === 'none') return '0=1';
    $params[] = (int)$actor['employee_id']; $params[] = (int)$actor['employee_id'];
    return $supported . ' AND (EXISTS (SELECT 1 FROM project_participants p WHERE p.order_id=o.id AND p.employee_id=?) OR EXISTS (SELECT 1 FROM project_department_uploaders u WHERE u.order_id=o.id AND u.employee_id=?))';
}
function pr_order($orderId, $actor)
{
    $params = [(int)$orderId]; $where = pr_order_where($actor, $params);
    $q = db()->prepare('SELECT o.id,o.order_no,o.customer_name,o.project_type,o.order_date FROM project_orders o WHERE o.id=? AND ' . $where);
    $q->execute($params); $row = $q->fetch();
    if (!$row) throw new RuntimeException('订单不存在或不在你的续费服务范围内');
    return $row;
}
function pr_seed()
{
    // Only actual domain/certification selections; customer phone stays unknown.
    db()->exec("INSERT IGNORE INTO project_renewal_items (order_id,resource_type,seed_key) SELECT o.id,'domain','domain' FROM project_orders o LEFT JOIN project_order_resources r ON r.order_id=o.id WHERE COALESCE(r.domain_mode,'pending')<>'none' AND o.project_type IN ('AI网站定制','网站模板','网站续费','网站修改')");
    db()->exec("INSERT IGNORE INTO project_renewal_items (order_id,resource_type,seed_key) SELECT DISTINCT o.id,'miniapp_certification','miniapp_certification' FROM project_orders o JOIN project_costs c ON c.order_id=o.id WHERE o.project_type='小程序开发' AND c.category='certification' AND c.review_status='approved' AND (c.item_name LIKE '%小程序%' OR c.item_name LIKE '%微信认证%')");
    db()->exec("INSERT IGNORE INTO project_renewal_items (order_id,resource_type,seed_key) SELECT o.id,'miniapp_certification','miniapp_certification' FROM project_orders o WHERE o.project_type='小程序开发'");
    // Estimates follow a later corrected/synced order date; verified/imported dates never move.
    db()->exec("UPDATE project_renewal_items r JOIN project_orders o ON o.id=r.order_id SET r.expires_on=DATE_ADD(o.order_date,INTERVAL 1 YEAR),r.revision=r.revision+1,r.updated_at=NOW() WHERE r.expiry_source='estimated' AND o.order_date BETWEEN '2000-01-01' AND '2099-12-31' AND NOT (r.expires_on <=> DATE_ADD(o.order_date,INTERVAL 1 YEAR))");
}
function pr_item($id, $actor)
{
    $q = db()->prepare('SELECT * FROM project_renewal_items WHERE id=?'); $q->execute([(int)$id]); $item = $q->fetch();
    if (!$item) throw new RuntimeException('续费资料不存在');
    pr_order($item['order_id'], $actor); return $item;
}
function pr_save($source, $actor)
{
    pr_require($actor);
    $id = (int)($source['id'] ?? 0); $orderId = (int)($source['order_id'] ?? 0);
    $order=pr_order($orderId, $actor);
    $type = (string)($source['resource_type'] ?? '');
    if (!isset(pr_types_for($order['project_type'])[$type])) throw new RuntimeException('请选择该订单可登记的资源类型（微信认证 / 备案 / 域名 / 服务器）');
    $name = trim((string)($source['resource_name'] ?? ''));
    // 备案 / 服务器不一定有明确名称：留空时用类型名占位，避免只为填名字而不登记到期日
    if ($name === '' && in_array($type, ['icp', 'server'], true)) $name = pr_type_labels()[$type];
    if ($name === '' || mb_strlen($name) > 180) throw new RuntimeException('请填写域名或小程序名称（最多 180 字）');
    $expiry = pr_date($source['expires_on'] ?? '');
    $expirySource=$expiry && (!empty($source['expiry_confirmed']) || $expiry!==pr_default_expiry($order['order_date'])) ? 'confirmed' : 'estimated';
    if ($expirySource==='estimated') $expiry=pr_default_expiry($order['order_date']);
    $phone = pr_phone($source['phone'] ?? '');
    $sms = !empty($source['sms_enabled']) ? 1 : 0;
    if ($sms && (!$expiry || !$phone)) throw new RuntimeException('启用短信前，请补齐实际到期日和客户手机号，并确认客户同意接收续费通知');
    $status = (string)($source['status'] ?? 'active');
    if (!in_array($status, ['active','paused','closed'], true)) throw new RuntimeException('续费状态无效');
    $note = trim((string)($source['note'] ?? ''));
    if (mb_strlen($note) > 500) throw new RuntimeException('备注最多 500 字');
    $renewed = ($source['action'] ?? '') === 'renew';
    $cipher = $phone === '' ? '' : pv_encrypt($phone);
    $hash = $phone === '' ? '' : hash_hmac('sha256', $phone, pv_key());
    $pdo = db(); $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id FROM project_orders WHERE id=? FOR UPDATE'); $q->execute([$orderId]);
        $dup=$pdo->prepare("SELECT id FROM project_renewal_items WHERE order_id=? AND resource_type=? AND resource_name=? AND id<>? AND status<>'closed' LIMIT 1"); $dup->execute([$orderId,$type,$name,$id]);
        if ($dup->fetchColumn()) throw new RuntimeException('此订单已登记相同资源，请编辑已有资料，避免重复提醒');
        $old = null;
        if ($id) {
            $q = $pdo->prepare('SELECT * FROM project_renewal_items WHERE id=? FOR UPDATE'); $q->execute([$id]); $old = $q->fetch();
            if (!$old || (int)$old['order_id'] !== $orderId || (int)$old['revision'] !== (int)($source['revision'] ?? 0)) throw new RuntimeException('资料已被同事更新，请刷新后再保存');
            if ($renewed && ($expirySource!=='confirmed' || !$expiry || !$old['expires_on'] || $expiry <= $old['expires_on'])) throw new RuntimeException('确认续费时，请核实并填写晚于原日期的新到期日');
            $pdo->prepare('UPDATE project_renewal_items SET resource_type=?,resource_name=?,expires_on=?,expiry_source=?,phone_cipher=?,phone_hash=?,sms_enabled=?,status=?,note=?,revision=revision+1,updated_at=NOW() WHERE id=?')->execute([$type,$name,$expiry,$expirySource,$cipher,$hash,$sms,$status,$note,$id]);
        } else {
            if ($renewed) throw new RuntimeException('请先登记原资源，再确认续费');
            $pdo->prepare('INSERT INTO project_renewal_items (order_id,resource_type,resource_name,expires_on,expiry_source,phone_cipher,phone_hash,sms_enabled,status,note) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$orderId,$type,$name,$expiry,$expirySource,$cipher,$hash,$sms,$status,$note]); $id = (int)$pdo->lastInsertId();
        }
        // Cancel stale pending jobs. Sent/unknown evidence must never be deleted.
        $pdo->prepare("UPDATE project_renewal_sms SET state='cancelled',provider_code='RESOURCE_UPDATED',updated_at=NOW() WHERE item_id=? AND state IN ('pending','failed') AND (expires_on<>? OR ?=0 OR ?<>'active' OR phone_hash<>?)")->execute([$id,$expiry ?: '2000-01-01',$sms,$status,$hash]);
        $details = ['type'=>$type,'name'=>$name,'status'=>$status,'expiry_source'=>$expirySource,'sms_enabled'=>$sms,'phone_changed'=>!$old || $old['phone_hash']!==$hash,'note'=>$note];
        $pdo->prepare('INSERT INTO project_renewal_history (item_id,action,actor_type,actor_id,old_expiry,new_expiry,details_json) VALUES (?,?,?,?,?,?,?)')->execute([$id,$renewed?'renew':'save',$actor['type'],(int)$actor['id'],$old['expires_on']??null,$expiry,json_encode($details,JSON_UNESCAPED_UNICODE)]);
        ps_audit('renewal',$id,$renewed?'confirmed_renewal':'save_renewal',$actor,['order_id'=>$orderId,'old_expiry'=>$old['expires_on']??null,'new_expiry'=>$expiry,'sms_enabled'=>$sms]);
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function pr_stats($actor)
{
    $params = []; $where = pr_order_where($actor,$params); $today = pr_today();
    $q = db()->prepare("SELECT COUNT(DISTINCT CASE WHEN r.status='active' AND r.expires_on<=DATE_ADD('$today',INTERVAL 30 DAY) THEN o.id END) due_orders, SUM(r.status='active' AND r.expires_on<'$today') overdue, SUM(r.status='active' AND (r.expires_on IS NULL OR r.expiry_source='estimated' OR r.resource_name='' OR r.phone_hash='')) incomplete, COUNT(DISTINCT CASE WHEN r.status='active' THEN o.id END) active_orders FROM project_renewal_items r JOIN project_orders o ON o.id=r.order_id WHERE $where");
    $q->execute($params); return $q->fetch();
}
