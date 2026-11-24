<?php
/**
 * 优站 / WP 模板开发补助：每做完一个优站模板站点，开发技术补助 15 元（WP 是优站里的高级模板，50 元）；订单完成并审核后才给。
 * 以前由宋文娜月初统计优站订单发技术群、技术回填、财务再手工加。现在系统直接列出当月的优站订单，开发技术在页面里选自己
 * （或上传原来那张“优站模板填写”表），订单审核当月补助自动进入报酬表（规则见规则中心“优站模板开发补助”“WP模板开发补助”，自 2026-11 起）。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectIntake.php';

const PTMD_AMOUNT = ['youzhan' => 15, 'wp' => 50];
const PTMD_LABEL = ['youzhan' => '优站模板', 'wp' => 'WP 模板'];

function ptmd_ensure()
{
    db()->exec("CREATE TABLE IF NOT EXISTS project_template_dev (
        order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        dev_employee_id INT NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'youzhan',
        set_by VARCHAR(60) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_ptmd_dev (dev_employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ptmd_can_edit(array $actor)
{
    return ($actor['role'] ?? '') === 'finance' || ($actor['type'] ?? '') === 'admin' || (($actor['role'] ?? '') === 'technical' && (int)($actor['employee_id'] ?? 0) > 0);
}

/** 当月的优站订单（订单里有“优站”商品 / 程序成本，且不是附加行）：order_date 在本月，或分成快照记在本月。 */
function ptmd_orders($month)
{
    $from = $month . '-01'; $until = date('Y-m-d', strtotime($from . ' +1 month'));
    $q = db()->prepare("SELECT o.id,o.order_no,o.order_date,o.contract_amount,o.delivery_status,o.settlement_status,COALESCE(s.payment_nickname,'') nick,
        (SELECT GROUP_CONCAT(DISTINCT CONCAT(i.item_name,' ',COALESCE(t.specification,'')) SEPARATOR ' ') FROM project_order_items i LEFT JOIN project_cost_templates t ON t.id=i.template_id WHERE i.order_id=o.id) items,
        (SELECT GROUP_CONCAT(DISTINCT CONCAT(t2.name,' ',t2.specification) SEPARATOR ' ') FROM project_costs c JOIN project_cost_templates t2 ON t2.id=c.template_id WHERE c.order_id=o.id AND c.category='program') progs,
        (SELECT GROUP_CONCAT(DISTINCT e.name SEPARATOR '、') FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=o.id AND p.commission_group='technical') techs
        FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id
        WHERE o.project_type='网站模板' AND (
            (o.order_date>=? AND o.order_date<?) OR EXISTS (SELECT 1 FROM project_commission_snapshots n WHERE n.order_id=o.id AND n.payroll_month=?))
        ORDER BY o.order_date,o.id");
    $q->execute([$from, $until, $month]);
    $out = [];
    foreach ($q->fetchAll() as $o) {
        $text = $o['items'] . ' ' . $o['progs'];
        if (!preg_match('/优站|WP|wordpress/iu', $text) || (float)$o['contract_amount'] <= 0) continue;
        $o['kind_auto'] = preg_match('/WP|wordpress/i', $text) ? 'wp' : 'youzhan';
        $out[(int)$o['id']] = $o;
    }
    if ($out) {
        $in = implode(',', array_keys($out));
        try { foreach (db()->query("SELECT order_id,dev_employee_id,kind,set_by FROM project_template_dev WHERE order_id IN ($in)")->fetchAll() as $d) $out[(int)$d['order_id']]['dev'] = $d; } catch (PDOException $e) { /* 还没人登记过 */ }
    }
    return $out;
}

function ptmd_candidates()
{
    return db()->query("SELECT id,name,department FROM employees ORDER BY department IN ('网站定制','网站技术','定制前端','定制后端') DESC, department, id")->fetchAll();
}

/** 登记 / 修改 / 清除（$devId=0）某单的开发技术。技术人员只能登记自己，已被别人登记的要找财务改。 */
function ptmd_set($orderId, $devId, $kind, array $actor)
{
    if (!ptmd_can_edit($actor)) throw new RuntimeException('只有技术人员和财务可以登记');
    ptmd_ensure();
    $finance = ($actor['role'] ?? '') === 'finance' || ($actor['type'] ?? '') === 'admin';
    $kind = $kind === 'wp' ? 'wp' : 'youzhan';
    $q = db()->prepare('SELECT order_no,project_type,settlement_status FROM project_orders WHERE id=?'); $q->execute([(int)$orderId]); $o = $q->fetch();
    if (!$o || $o['project_type'] !== '网站模板') throw new RuntimeException('只能登记网站模板订单');
    if ($o['settlement_status'] === 'locked') throw new RuntimeException('订单已锁定，请联系财务');
    $cur = db()->prepare('SELECT dev_employee_id FROM project_template_dev WHERE order_id=?'); $cur->execute([(int)$orderId]); $curDev = (int)$cur->fetchColumn();
    $devId = (int)$devId;
    if (!$finance) {
        $me = (int)$actor['employee_id'];
        if ($devId !== 0 && $devId !== $me) throw new RuntimeException('只能登记自己；替别人登记请联系财务');
        if ($curDev && $curDev !== $me) throw new RuntimeException('这单已登记给别人，如需更改请联系财务');
    }
    $who = (string)($actor['name'] ?? $actor['username'] ?? '') ?: ('#' . (int)($actor['id'] ?? 0));
    if ($devId === 0) { db()->prepare('DELETE FROM project_template_dev WHERE order_id=?')->execute([(int)$orderId]); ps_audit('order', (int)$orderId, 'template_dev_clear', $actor, ['was' => $curDev]); return; }
    $e = db()->prepare('SELECT 1 FROM employees WHERE id=?'); $e->execute([$devId]); if (!$e->fetchColumn()) throw new RuntimeException('人员不存在');
    db()->prepare('INSERT INTO project_template_dev (order_id,dev_employee_id,kind,set_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE dev_employee_id=VALUES(dev_employee_id),kind=VALUES(kind),set_by=VALUES(set_by)')->execute([(int)$orderId, $devId, $kind, mb_substr($who, 0, 60)]);
    ps_audit('order', (int)$orderId, 'template_dev_set', $actor, ['dev' => $devId, 'kind' => $kind, 'was' => $curDev]);
}

/**
 * 上传原来的“优站模板填写”表：表头含“订单编号”和“优站模板技术 / WP模板技术”（有哪列填哪列）。同一订单号对应多张订单（一笔付款多个站）时都登记。
 * 返回 ['set' => 登记单数, 'skipped' => [原因…]]。
 */
function ptmd_import($path, array $actor)
{
    if (!(($actor['role'] ?? '') === 'finance' || ($actor['type'] ?? '') === 'admin') && ($actor['role'] ?? '') !== 'technical') throw new RuntimeException('只有技术人员和财务可以上传');
    require_once __DIR__ . '/ProjectImportFiles.php';
    ptmd_ensure();
    $names = [];
    foreach (db()->query('SELECT id,name FROM employees')->fetchAll() as $e) $names[trim($e['name'])][] = (int)$e['id'];
    $res = ['set' => 0, 'skipped' => []]; $seen = [];
    foreach (ps_xlsx_sheets($path) as $rows) {
        $noC = $yzC = $wpC = null; $head = null;
        foreach (array_slice($rows, 0, 5) as $i => $row) {
            foreach ($row as $ci => $c) {
                $c = preg_replace('/\s+/u', '', (string)$c);
                if ($c === '订单编号' || $c === '订单号') $noC = $ci;
                if ($c === '优站模板技术') $yzC = $ci;
                if ($c === 'WP模板技术' || $c === 'wp模板技术') $wpC = $ci;
            }
            if ($noC !== null && ($yzC !== null || $wpC !== null)) { $head = $i; break; }
            $noC = $yzC = $wpC = null;
        }
        if ($head === null) continue;
        foreach (array_slice($rows, $head + 1) as $row) {
            $raw = $row[$noC] ?? ''; $no = ps_order_no_canonical(is_float($raw) && $raw == floor($raw) ? sprintf('%.0f', $raw) : $raw);
            if ($no === '' || mb_strpos($no, '示例') === 0) continue;
            foreach ([['youzhan', $yzC], ['wp', $wpC]] as [$kind, $col]) {
                $nm = $col === null ? '' : trim((string)($row[$col] ?? ''));
                if ($nm === '') continue;
                if (count($names[$nm] ?? []) !== 1) { $res['skipped'][] = $no . '：“' . $nm . '”' . (empty($names[$nm]) ? '找不到这个人' : '有重名'); continue; }
                $q = db()->prepare("SELECT id FROM project_orders WHERE project_type='网站模板' AND (order_no=? OR order_no LIKE ?)"); $q->execute([$no, $no . '~%']); $ids = $q->fetchAll(PDO::FETCH_COLUMN);
                if (!$ids) { $res['skipped'][] = $no . '：系统里没有这张网站模板订单（先让客服上传订单）'; continue; }
                foreach ($ids as $id) {
                    if (isset($seen[$id])) continue; $seen[$id] = 1;
                    try { ptmd_set((int)$id, $names[$nm][0], $kind, $actor); $res['set']++; }
                    catch (RuntimeException $e) { $res['skipped'][] = $no . '：' . $e->getMessage(); }
                }
            }
        }
    }
    return $res;
}

/**
 * 月度规则用：这个月已审核（分成快照记在本月）订单里，每位开发技术的补助件数。$snapshots 为 ps_monthly_snapshots 的行。
 * 返回 [员工ID => ['youzhan' => n, 'wp' => n, 'orders' => [订单号…]]]。订单未完成 / 未审核没有快照，所以自然不算。
 */
function ptmd_counts(array $snapshots)
{
    $ids = [];
    foreach ($snapshots as $s) $ids[(int)$s['order_id']] = 1;
    if (!$ids) return [];
    try {
        $in = implode(',', array_keys($ids));
        $rows = db()->query("SELECT d.order_id,d.dev_employee_id,d.kind,o.order_no FROM project_template_dev d JOIN project_orders o ON o.id=d.order_id WHERE d.order_id IN ($in) AND o.settlement_status IN ('approved','locked') AND o.delivery_status='finished'")->fetchAll();
    } catch (PDOException $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $eid = (int)$r['dev_employee_id']; $k = $r['kind'] === 'wp' ? 'wp' : 'youzhan';
        $out[$eid] = $out[$eid] ?? ['youzhan' => 0, 'wp' => 0, 'orders' => []];
        $out[$eid][$k]++; $out[$eid]['orders'][] = $r['order_no'];
    }
    return $out;
}

/**
 * 订单 Excel 导入时登记“模板开发技术”：$td = ['yz' => 优站模板开发技术姓名, 'wp' => WP 模板开发技术姓名]。技术人员和财务上传的才登记；
 * 已登记给别人的，技术上传的不覆盖（财务上传可改）。在导入事务内调用，不建表（预览阶段已建）。失败只忽略，不影响订单导入。
 */
function ptmd_import_row($orderId, array $td, array $actor)
{
    $finance = ($actor['role'] ?? '') === 'finance' || ($actor['type'] ?? '') === 'admin';
    if (!$finance && ($actor['role'] ?? '') !== 'technical') return;
    try {
        $o = db()->prepare('SELECT project_type FROM project_orders WHERE id=?'); $o->execute([(int)$orderId]);
        if ($o->fetchColumn() !== '网站模板') return;
        $it = db()->prepare('SELECT GROUP_CONCAT(item_name SEPARATOR " ") FROM project_order_items WHERE order_id=?'); $it->execute([(int)$orderId]); $text = (string)$it->fetchColumn();
        foreach ([['yz', 'youzhan'], ['wp', 'wp']] as [$key, $kind]) {
            $nm = trim((string)($td[$key] ?? '')); if ($nm === '') continue;
            $e = db()->prepare('SELECT id FROM employees WHERE name=?'); $e->execute([$nm]); $ids = $e->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) continue;
            if ($kind === 'youzhan' && preg_match('/WP|wordpress/i', $text)) $kind = 'wp'; // 程序选的是 WordPress 模板，名字填在“优站”列也按 WP
            $cur = db()->prepare('SELECT dev_employee_id FROM project_template_dev WHERE order_id=?'); $cur->execute([(int)$orderId]); $curDev = (int)$cur->fetchColumn();
            if ($curDev && $curDev !== (int)$ids[0] && !$finance) continue;
            db()->prepare('INSERT INTO project_template_dev (order_id,dev_employee_id,kind,set_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE dev_employee_id=VALUES(dev_employee_id),kind=VALUES(kind),set_by=VALUES(set_by)')->execute([(int)$orderId, (int)$ids[0], $kind, '订单上传']);
            ps_audit('order', (int)$orderId, 'template_dev_set', $actor, ['dev' => (int)$ids[0], 'kind' => $kind, 'source' => '订单上传']);
        }
    } catch (Throwable $e) { /* 登记失败不影响订单导入，之后可在登记页补 */ }
}
