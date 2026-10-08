<?php
/** 同一收款订单的商品明细。明细售价只是拆分展示，绝不再次记收款。 */
function poi_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_order_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL, item_key VARCHAR(64) NOT NULL,
        item_name VARCHAR(255) NOT NULL, category VARCHAR(40) NOT NULL,
        sale_amount DECIMAL(14,2) NULL, reported_cost DECIMAL(14,2) NULL,
        technical_cost DECIMAL(14,2) NULL, template_id BIGINT UNSIGNED NULL,
        cost_id BIGINT UNSIGNED NULL, source_file_id BIGINT UNSIGNED NULL,
        source_line INT NULL, resource_hint VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_order_item(order_id,item_key), KEY idx_order(order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function poi_name($name)
{
    $name = mb_strtolower(preg_replace('/\s+/u', '', trim((string)$name)));
    return str_replace(['（','）'], ['(',')'], $name);
}

function poi_money($value)
{
    $value = preg_replace('/[¥￥,\s元]/u', '', (string)$value);
    return $value !== '' && is_numeric($value) && (float)$value >= 0 ? number_format((float)$value, 2, '.', '') : null;
}

/** 只使用明确别名，不把证书软件、泛域名证书误认作普通域名证书。 */
function poi_template($name, $templates, $resourceHint = '')
{
    $key = poi_name($name);
    $years = preg_match('/^(\d+)年/u', $key, $m) ? (int)$m[1] : 1;
    $key = preg_replace('/^\d+年/u', '', $key);
    $aliases = ['青站' => '青站(标准)', 'ssl' => '域名ssl证书', 'ssl证书' => '域名ssl证书', 'ssl证书软件' => 'https加密功能/ssl证书软件'];
    $key = $aliases[$key] ?? $key;
    $matches = [];
    foreach ($templates as $t) {
        if (!in_array($t['category'], ['program','certificate','plugin'], true) || poi_name($t['name']) !== $key) continue;
        if (preg_match('/^(\d+)年/u', $t['specification'] ?? '', $m) && (int)$m[1] !== $years) continue;
        $matches[] = $t;
    }
    if (count($matches) === 1) return $matches[0];
    $spaceOnly = preg_match('/^(仅空间|空间)$/u', trim($resourceHint));
    $preferred = array_values(array_filter($matches, function ($t) use ($spaceOnly) {
        return mb_strpos($t['specification'] ?? '', $spaceOnly ? '仅空间' : '空间+域名') !== false;
    }));
    return count($preferred) === 1 ? $preferred[0] : null;
}

/** 一行可有多个商品；没有明确分项售价时不猜价格分配。 */
function poi_from_row($name, $sale, $head, $row, $templates, $line, $resourceHint = '')
{
    if (trim((string)$name) === '') return [];
    $reported = null; $technical = null;
    foreach ($head as $i => $h) {
        $h = preg_replace('/\s+/u', '', (string)$h);
        if (in_array($h, ['成本','总成本','直接成本'], true)) $reported = poi_money($row[$i] ?? '');
        if (preg_match('/(要成本|技术成本|供应商成本)$/u', $h)) $technical = poi_money($row[$i] ?? '');
    }
    $parts = preg_split('/[+＋、\n]+/u', (string)$name, -1, PREG_SPLIT_NO_EMPTY);
    $out = []; $explicitSum = 0; $allExplicit = true;
    foreach ($parts as $part) {
        $amount = null;
        if (preg_match('/^(.*?)\s*[¥￥]?\s*(\d+(?:\.\d{1,2})?)元$/u', trim($part), $m)) { $part = trim($m[1]); $amount = poi_money($m[2]); }
        else $allExplicit = false;
        if ($part === '') continue;
        $t = poi_template($part, $templates, $resourceHint);
        $out[] = ['item_name' => mb_substr(trim($part), 0, 255), 'category' => $t['category'] ?? 'other',
            'sale_amount' => count($parts) === 1 ? poi_money($sale) : $amount,
            'reported_cost' => count($parts) === 1 ? $reported : null,
            'technical_cost' => count($parts) === 1 ? $technical : null,
            'template_id' => $t['id'] ?? null, 'source_line' => (int)$line, 'resource_hint' => mb_substr($resourceHint,0,255)];
        $explicitSum += (float)$amount;
    }
    if (count($out) > 1 && (!$allExplicit || abs($explicitSum - (float)$sale) > .001)) foreach ($out as &$item) $item['sale_amount'] = null;
    return $out;
}

function poi_keyed($items)
{
    $counts = [];
    foreach ($items as &$item) {
        // 规范模板名由 template_id 标识；未知商品保留原名；同类多份用序号，不依赖 Excel 行号。
        $base = !empty($item['template_id']) ? 'template:' . (int)$item['template_id'] : 'name:' . poi_name($item['item_name']);
        $n = $counts[$base] = ($counts[$base] ?? 0) + 1;
        $item['item_key'] = hash('sha256', $base . ':' . $n);
    }
    return $items;
}

function poi_items($orderId)
{
    poi_ensure();
    $q = db()->prepare('SELECT i.*, c.review_status cost_status FROM project_order_items i LEFT JOIN project_costs c ON c.id=i.cost_id WHERE i.order_id=? ORDER BY i.id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

/** 仅补齐草稿明细；重复上传不能再次加成本，已锁定结算不重写。调用方持有订单事务锁。 */
function poi_save($orderId, $items, $fileId, $actor)
{
    if (!$items) return;
    poi_ensure();
    $q = db()->prepare('SELECT settlement_status FROM project_orders WHERE id=? FOR UPDATE');
    $q->execute([(int)$orderId]);
    if ($q->fetchColumn() !== 'draft') return;
    foreach (poi_keyed($items) as $item) {
        $find = db()->prepare('SELECT * FROM project_order_items WHERE order_id=? AND item_key=? FOR UPDATE');
        $find->execute([$orderId,$item['item_key']]); $old = $find->fetch();
        // 新上传只填空值，不偷偷覆盖已有明细 / 已审核成本；更正走结算单的正式审核。
        if ($old) {
            db()->prepare('UPDATE project_order_items SET sale_amount=COALESCE(sale_amount,?),reported_cost=COALESCE(reported_cost,?),technical_cost=COALESCE(technical_cost,?) WHERE id=?')
                ->execute([$item['sale_amount'],$item['reported_cost'],$item['technical_cost'],$old['id']]);
            continue;
        }
        db()->prepare('INSERT INTO project_order_items (order_id,item_key,item_name,category,sale_amount,reported_cost,technical_cost,template_id,source_file_id,source_line,resource_hint) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$orderId,$item['item_key'],$item['item_name'],$item['category'],$item['sale_amount'],$item['reported_cost'],$item['technical_cost'],$item['template_id'],$fileId ?: null,$item['source_line'],$item['resource_hint']]);
        ps_audit('order', $orderId, 'item_import', $actor, ['item_name'=>$item['item_name'],'sale_amount'=>$item['sale_amount'],'file_id'=>$fileId,'line'=>$item['source_line']]);
    }
    poi_sync_costs($orderId, $actor);
}

/** 明细与成本一一对应；已有套餐成本直接关联，不再生成一笔。 */
function poi_sync_costs($orderId, $actor)
{
    $q = db()->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=?'); $q->execute([$orderId]);
    $confirmed = in_array($q->fetchColumn(), ['none','template'], true);
    $claimed = array_filter(array_column(poi_items($orderId), 'cost_id'));
    foreach (poi_items($orderId) as $item) {
        if (!$item['template_id']) continue;
        $q = db()->prepare('SELECT * FROM project_cost_templates WHERE id=? AND is_active=1'); $q->execute([$item['template_id']]); $t = $q->fetch();
        if (!$t || !empty($t['requires_proof']) || ($t['price_mode'] ?? 'fixed') !== 'fixed') continue;
        $expected = (float)$t['price'];
        // 原表成本与模板不一致，不能自动替换成一个看似正确的数。
        if ($item['reported_cost'] !== null && abs((float)$item['reported_cost']-$expected) > .001) continue;
        $costId = (int)$item['cost_id'];
        if (!$costId) {
            $q = db()->prepare("SELECT id FROM project_costs WHERE order_id=? AND template_id=? AND is_custom=0 AND amount=? AND review_status<>'rejected' ORDER BY id");
            $q->execute([$orderId,$t['id'],$expected]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $candidate) if (!in_array($candidate, $claimed)) { $costId=(int)$candidate; break; }
            if (!$costId) $costId = ps_intake_add_template_cost($orderId, $t, $actor, '商品明细：' . $item['item_name'], 1, $confirmed ? null : 'pending');
            db()->prepare('UPDATE project_order_items SET cost_id=? WHERE id=?')->execute([$costId,$item['id']]);
            $claimed[] = $costId;
        }
        if ($confirmed && ps_template_cost_status($t,$expected)==='approved') {
            $q = db()->prepare("UPDATE project_costs SET review_status='approved' WHERE id=? AND review_status='pending' AND is_custom=0 AND template_id=? AND amount=? AND reason LIKE '商品明细：%'");
            $q->execute([$costId,$t['id'],$expected]);
            if ($q->rowCount()) ps_audit('cost',$costId,'item_resource_confirmed',$actor,['order_id'=>$orderId,'amount'=>$expected]);
        }
    }
}

function poi_linked_template($orderId, $templateId)
{
    foreach (poi_items($orderId) as $item) if ((int)$item['template_id']===(int)$templateId && $item['cost_id']) return true;
    return false;
}

