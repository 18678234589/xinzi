<?php
/**
 * 雅云服务器目录：把快照里的「栏目 → 子分类 → 商品」整理成「国家 / 地区 × 品类」视图，并合并同步下来的实时价格与库存（yayun_prices）。
 * 国家与品类都只用于展示分组，由栏目 / 子分类 / 商品名关键词推断（上游没有这两个字段）；推断不到的归「其他」，不猜测。
 */
require_once __DIR__ . '/YayunCatalogData.php';

/** 国家 / 地区：key => [名称, 旗帜, 排序]。 */
function ya_countries()
{
    return [
        'cn' => ['中国大陆', '🇨🇳', 1], 'hk' => ['中国香港', '🇭🇰', 2], 'tw' => ['中国台湾', '🇹🇼', 3], 'us' => ['美国', '🇺🇸', 4], 'jp' => ['日本', '🇯🇵', 5],
        'sg' => ['新加坡', '🇸🇬', 6], 'kr' => ['韩国', '🇰🇷', 7], 'my' => ['马来西亚', '🇲🇾', 8], 'de' => ['德国', '🇩🇪', 9], 'gb' => ['英国', '🇬🇧', 10],
        'fr' => ['法国', '🇫🇷', 11], 'es' => ['西班牙', '🇪🇸', 12], 'vn' => ['越南', '🇻🇳', 13], 'ph' => ['菲律宾', '🇵🇭', 14], 'in' => ['印度', '🇮🇳', 15],
        'global' => ['全球 / 海外', '🌐', 16], 'other' => ['其他', '📦', 99],
    ];
}
/** 品类：栏目 id => 品类 key。 */
function ya_categories()
{
    return ['cloud' => ['云服务器', 1], 'lite' => ['轻量云', 2], 'dedicated' => ['物理机', 3], 'baremetal' => ['裸金属', 4], 'residential' => ['住宅 / 双 ISP', 5], 'cdn' => ['高防 CDN', 6], 'pc' => ['云电脑 / 挂机宝', 7], 'promo' => ['活动专区', 8]];
}
function ya_group_category($groupId, $childName = '', $title = '')
{
    $map = [17 => 'promo', 34 => 'cloud', 35 => 'cloud', 2 => 'cloud', 28 => 'cloud', 29 => 'cloud', 9 => 'cloud', 30 => 'cloud', 32 => 'cloud', 12 => 'cdn', 6 => 'dedicated', 8 => 'dedicated', 23 => 'pc', 25 => 'baremetal', 27 => 'residential'];
    $cat = $map[(int)$groupId] ?? 'cloud';
    // 轻量云：名称里带“轻量”的云服务器商品单独成类，便于按预算挑选。
    if ($cat === 'cloud' && preg_match('/轻量|LCS/u', $childName . ' ' . $title)) return 'lite';
    return $cat;
}
function ya_country_keywords()
{
    return ['hk' => ['香港'], 'tw' => ['台湾'], 'us' => ['美国'], 'jp' => ['日本'], 'sg' => ['新加坡'], 'kr' => ['韩国'], 'my' => ['马来西亚'], 'de' => ['德国'], 'gb' => ['英国'], 'fr' => ['法国'], 'es' => ['西班牙'], 'vn' => ['越南'], 'ph' => ['菲律宾'], 'in' => ['印度']];
}
/** 先看子分类名，再看栏目名，最后看商品名；国内栏目默认中国大陆。 */
function ya_country($groupId, $groupName, $childName, $title = '')
{
    $kw = ya_country_keywords();
    $find = function ($text) use ($kw) {
        $hits = [];
        foreach ($kw as $key => $words) foreach ($words as $w) if (mb_strpos($text, $w) !== false) $hits[$key] = true;
        return array_keys($hits);
    };
    // 子分类和栏目名各自只命中一个国家才采信；“香港/美国/日本轻量云”这种混合分类改看商品名。
    foreach ([$childName, $groupName, $title] as $text) {
        $hits = $find($text);
        if (count($hits) === 1) return $hits[0];
        if (count($hits) > 1) { $byTitle = $find($title); if (count($byTitle) === 1) return $byTitle[0]; break; }
    }
    if (in_array((int)$groupId, [34, 2, 6, 23, 17], true) && preg_match('/国内|大陆|华[东南北中]|西[南北]|东北|电信|联通|BGP|内蒙|广州|北京|深圳|宁波|襄阳|十堰/u', $childName . ' ' . $title . ' ' . $groupName)) return 'cn';
    if ((int)$groupId === 12) return mb_strpos($childName, '海外') !== false ? 'global' : 'cn';
    if (in_array((int)$groupId, [34, 2, 6], true)) return 'cn';
    return 'other';
}

/** 整理成扁平商品列表：每个商品带国家 / 品类 / 分区（子分类）/ 栏目。 */
function ya_products()
{
    static $list = null;
    if ($list !== null) return $list;
    $list = [];
    foreach (ya_catalog_data()['groups'] as $g) foreach ($g['children'] as $c) foreach ($c['products'] as $p) {
        $country = ya_country($g['id'], $g['name'], $c['name'], $p['title']);
        $list[] = [
            'id' => (int)$p['id'], 'title' => (string)$p['title'], 'specs' => $p['specs'],
            'group_id' => (int)$g['id'], 'group' => (string)$g['name'], 'zone_id' => (int)$c['id'], 'zone' => (string)$c['name'],
            'country' => $country, 'category' => ya_group_category($g['id'], $c['name'], $p['title']),
        ];
    }
    return $list;
}
function ya_find_product($id)
{
    foreach (ya_products() as $p) if ($p['id'] === (int)$id) return $p;
    return null;
}

/* ---------- 价格缓存（由 jobs/yayun_sync.php 写入，页面只读） ---------- */

function ya_cycle_labels()
{
    return ['monthly' => '月付', 'quarterly' => '季付', 'semiannually' => '半年付', 'annually' => '年付', 'biennially' => '两年付', 'triennially' => '三年付', 'hour' => '按小时', 'day' => '按天', 'onetime' => '一次性'];
}
/** 从 cart/get_product_config 的 product_pricings 取出可售周期：[周期 key => 价格]，-1 表示不可用。 */
function ya_cycles_from_pricing(array $pricing)
{
    $out = [];
    foreach (array_keys(ya_cycle_labels()) as $key) {
        if (isset($pricing[$key]) && is_numeric($pricing[$key]) && (float)$pricing[$key] >= 0) $out[$key] = round((float)$pricing[$key], 2);
    }
    return $out;
}
function ya_price_map($pdo)
{
    $map = [];
    try {
        foreach ($pdo->query('SELECT pid,cycles_json,stock,stock_control,from_price,updated_at,error FROM yayun_prices')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['pid']] = ['cycles' => json_decode($r['cycles_json'], true) ?: [], 'stock' => $r['stock'] === null ? null : (int)$r['stock'], 'stock_control' => (int)$r['stock_control'], 'from_price' => $r['from_price'] === null ? null : (float)$r['from_price'], 'updated_at' => (int)$r['updated_at'], 'error' => (string)$r['error']];
        }
    } catch (PDOException $e) { if ($e->getCode() !== '42S02') throw $e; }
    return $map;
}
/** 目录视图：按国家 → 品类 → 分区组织，附价格与库存；$filters: country / category / q / only_stock。 */
function ya_catalog_view(array $prices, array $filters = [])
{
    $countries = ya_countries(); $cats = ya_categories();
    $q = mb_strtolower(trim((string)($filters['q'] ?? '')));
    $tree = []; $counts = ['country' => [], 'category' => []];
    foreach (ya_products() as $p) {
        $pr = $prices[$p['id']] ?? null;
        $haystack = mb_strtolower($p['title'] . ' ' . $p['zone'] . ' ' . $p['group'] . ' ' . implode(' ', array_column($p['specs'], 1)));
        if ($q !== '' && mb_strpos($haystack, $q) === false) continue;
        if (!empty($filters['only_stock']) && $pr && $pr['stock_control'] && $pr['stock'] !== null && $pr['stock'] <= 0) continue;
        $counts['country'][$p['country']] = ($counts['country'][$p['country']] ?? 0) + 1;
        $counts['category'][$p['category']] = ($counts['category'][$p['category']] ?? 0) + 1;
        if (!empty($filters['country']) && $filters['country'] !== $p['country']) continue;
        if (!empty($filters['category']) && $filters['category'] !== $p['category']) continue;
        $p['price'] = $pr;
        $tree[$p['country']][$p['category']][$p['zone_id']]['zone'] = $p['zone'];
        $tree[$p['country']][$p['category']][$p['zone_id']]['products'][] = $p;
    }
    uksort($tree, function ($a, $b) use ($countries) { return ($countries[$a][2] ?? 99) <=> ($countries[$b][2] ?? 99); });
    foreach ($tree as &$byCat) uksort($byCat, function ($a, $b) use ($cats) { return ($cats[$a][1] ?? 99) <=> ($cats[$b][1] ?? 99); });
    unset($byCat);
    return ['tree' => $tree, 'counts' => $counts, 'total' => array_sum($counts['country'])];
}
/** 展示用的起价：优先最低周期价，月付单价最直观。 */
function ya_display_price(array $price = null)
{
    if (!$price || !$price['cycles']) return null;
    if (isset($price['cycles']['monthly'])) return ['amount' => $price['cycles']['monthly'], 'cycle' => 'monthly', 'label' => '月付'];
    asort($price['cycles']);
    $key = key($price['cycles']);
    return ['amount' => $price['cycles'][$key], 'cycle' => $key, 'label' => ya_cycle_labels()[$key] ?? $key];
}
