<?php
/**
 * 一笔销售、多个付款订单号：客户限额、优惠券叠减、分次付款等原因，一笔生意在店铺里拍成了几个订单号（如 250 + 300 = 550）。
 * 系统里仍是 **一张订单**（单量算 1 单）：第一个号是主订单号，其余记在 project_order_payments（关联付款订单号）。
 * 上传表格时订单编号格里写多个号（换行 / 逗号 / 顿号 / 分号 / 空格分隔），售价写合计；之后用任一个号重传都对应同一张订单。
 */

function pop_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_order_payments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        payment_no VARCHAR(100) NOT NULL,
        amount DECIMAL(14,2) NULL,
        note VARCHAR(200) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_payment_no (payment_no), KEY idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * 订单编号格里写了几个号？返回 [主号, [其余号...]]；只有一个号（或不是“多个订单号”的写法）时 [原值, []]。
 * 每一段都必须长得像订单号（≥8 位字母数字，可带前缀汉字），避免把“对公 xxx”“订单号：xxx 备注”这类写法误拆。
 */
function pop_split_numbers($raw)
{
    $text = trim((string)$raw);
    if ($text === '') return [$text, []];
    $tokens = preg_split('/[\r\n\t,，、;；\/+＋\s]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (count($tokens) < 2) return [$text, []];
    $seen = []; $nos = [];
    foreach ($tokens as $t) {
        if (!preg_match('/^[\p{Han}]{0,4}[A-Za-z0-9_-]{8,}$/u', $t) || !preg_match('/\d{6}/', $t) || preg_match('/^(示例|样例)/u', $t)) return [$text, []];
        $key = function_exists('ps_order_no_canonical') ? ps_order_no_canonical($t) : $t;
        if (isset($seen[$key])) continue;
        $seen[$key] = true; $nos[] = $key;
    }
    if (count($nos) < 2) return [$nos ? $nos[0] : $text, []];
    return [array_shift($nos), $nos];
}

/** 该号码是不是某张订单的关联付款订单号：返回 ['order_id','order_no','payment_no'] 或 null。 */
function pop_find($no)
{
    pop_ensure();
    $q = db()->prepare('SELECT p.order_id,o.order_no,p.payment_no FROM project_order_payments p JOIN project_orders o ON o.id=p.order_id WHERE p.payment_no=? LIMIT 1');
    $q->execute([trim((string)$no)]);
    return $q->fetch() ?: null;
}

/** 本次上传里已出现、还没落库的“其余号 → 主号”：同一张表后面的行（如加购 SSL）只写其中一个号，也对到同一张订单。pop_run_map() 不带参数＝清空。 */
function pop_run_map($no = null, $primary = null)
{
    static $map = [];
    if ($no === null) { $map = []; return null; }
    if ($primary !== null) $map[$no] = $primary;
    return $map[$no] ?? null;
}

/** 订单号解析：库里没有这个订单号、但它是某订单的关联付款订单号（或本次上传里前面行的其余号）时，换成那张订单的主订单号；否则原样返回。 */
function pop_resolve($no)
{
    $no = trim((string)$no);
    if ($no === '') return $no;
    $q = db()->prepare('SELECT 1 FROM project_orders WHERE order_no=? LIMIT 1'); $q->execute([$no]);
    if ($q->fetchColumn()) return $no;
    $hit = pop_find($no);
    return $hit ? $hit['order_no'] : (pop_run_map($no) ?? $no);
}

function pop_list($orderId)
{
    pop_ensure();
    $q = db()->prepare('SELECT * FROM project_order_payments WHERE order_id=? ORDER BY id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

/**
 * 导入预览用：检查这一行的“其余号”能不能并入主订单。返回 [可并入的号, 问题说明]。
 * 问题：该号已是另一张独立订单（上传前已经拍成两单录进来了）/ 已是别的订单的关联号。
 */
function pop_check_extras($primaryNo, array $extras)
{
    pop_ensure();
    $ok = []; $problems = [];
    $own = db()->prepare('SELECT id FROM project_orders WHERE order_no=? LIMIT 1'); $own->execute([$primaryNo]);
    $primaryId = (int)$own->fetchColumn();
    $exists = db()->prepare('SELECT id FROM project_orders WHERE order_no=? LIMIT 1');
    foreach ($extras as $no) {
        $exists->execute([$no]); $otherOrder = (int)$exists->fetchColumn();
        if ($otherOrder > 0 && $otherOrder !== $primaryId) { $problems[] = '订单号 ' . $no . ' 已经是另一张独立订单（#' . $otherOrder . '），请财务在订单页把它并入主订单后再上传'; continue; }
        $hit = pop_find($no);
        if ($hit && (int)$hit['order_id'] !== $primaryId) { $problems[] = '订单号 ' . $no . ' 已关联到订单 ' . $hit['order_no']; continue; }
        $ok[] = $no;
    }
    return [$ok, $problems];
}

/** 给订单加一个关联付款订单号（已有则忽略）。返回是否新增。调用方负责权限 / 事务。 */
function pop_add($orderId, $no, $amount, array $actor, $note = '')
{
    pop_ensure();
    $no = trim((string)$no);
    if ($no === '' || mb_strlen($no) > 100) throw new RuntimeException('请填写 100 字以内的订单号');
    $q = db()->prepare('SELECT id,order_no FROM project_orders WHERE order_no=? LIMIT 1'); $q->execute([$no]); $o = $q->fetch();
    if ($o && (int)$o['id'] === (int)$orderId) throw new RuntimeException('这就是本单的订单号');
    if ($o) throw new RuntimeException('订单号 ' . $no . ' 已经是另一张独立订单，请用“并入另一张订单”把它合并进来');
    $hit = pop_find($no);
    if ($hit) {
        if ((int)$hit['order_id'] === (int)$orderId) return false;
        throw new RuntimeException('订单号 ' . $no . ' 已关联到订单 ' . $hit['order_no']);
    }
    db()->prepare('INSERT INTO project_order_payments (order_id,payment_no,amount,note) VALUES (?,?,?,?)')->execute([(int)$orderId, $no, $amount === null || $amount === '' ? null : round((float)$amount, 2), mb_substr($note, 0, 200)]);
    ps_audit('order', (int)$orderId, 'add_payment_no', $actor, ['payment_no' => $no, 'amount' => $amount]);
    return true;
}

/**
 * 把另一张独立订单（上传时被拍成了两单）并入本单：它的订单号变成本单的关联付款订单号，单量只算 1 单。
 * 只有财务能操作；两张订单都必须未审核，且被并入的那张没有收款 / 退款 / 分单记录。可选把它的售价加到本单。
 * 被并入的订单放进回收站（30 天内可还原）。
 */
function pop_merge($orderId, $otherNo, $addAmount, array $actor)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('只有财务可以合并订单');
    require_once __DIR__ . '/ProjectOrderDelete.php';
    pop_ensure();
    $otherNo = trim((string)$otherNo);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $a = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE'); $a->execute([(int)$orderId]); $main = $a->fetch();
        $b = $pdo->prepare('SELECT * FROM project_orders WHERE order_no=? FOR UPDATE'); $b->execute([$otherNo]); $other = $b->fetch();
        if (!$main) throw new RuntimeException('订单不存在');
        if (!$other) throw new RuntimeException('没有找到订单号 ' . $otherNo . ' 对应的订单');
        if ((int)$other['id'] === (int)$main['id']) throw new RuntimeException('不能把订单并入自己');
        foreach ([$main, $other] as $o) if (!in_array($o['settlement_status'], ['draft', 'review'], true)) throw new RuntimeException('订单 ' . $o['order_no'] . ' 已审核，不能合并；请先反审核或走调整流程');
        $c = $pdo->prepare('SELECT (SELECT COUNT(*) FROM project_cash_movements WHERE order_id=?)+(SELECT COUNT(*) FROM project_order_splits WHERE parent_order_id=? OR child_order_id=?)+(SELECT COUNT(*) FROM project_refund_import_rows WHERE order_id=?)');
        $c->execute([(int)$other['id'], (int)$other['id'], (int)$other['id'], (int)$other['id']]);
        if ((int)$c->fetchColumn() > 0) throw new RuntimeException('订单 ' . $otherNo . ' 已有收款 / 退款 / 分单记录，不能直接合并，请先处理这些记录');
        $amount = (float)$other['contract_amount'];
        $backup = pod_backup((int)$other['id'], $otherNo, $actor);
        pod_delete_rows((int)$other['id']);
        $pdo->prepare('DELETE FROM project_order_payments WHERE order_id=?')->execute([(int)$other['id']]);
        $pdo->prepare('INSERT INTO project_order_payments (order_id,payment_no,amount,note) VALUES (?,?,?,?)')->execute([(int)$main['id'], $otherNo, $amount > 0 ? $amount : null, '由独立订单并入']);
        if ($addAmount && $amount > 0) $pdo->prepare('UPDATE project_orders SET contract_amount=contract_amount+?,row_version=row_version+1 WHERE id=?')->execute([$amount, (int)$main['id']]);
        else $pdo->prepare('UPDATE project_orders SET row_version=row_version+1 WHERE id=?')->execute([(int)$main['id']]);
        ps_audit('order', (int)$main['id'], 'merge_payment_order', $actor, ['merged_order_no' => $otherNo, 'amount' => $amount, 'added_to_contract' => (bool)$addAmount, 'backup' => $backup]);
        $pdo->commit();
        return ['merged' => $otherNo, 'amount' => $amount, 'backup' => $backup];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
