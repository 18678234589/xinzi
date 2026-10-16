require_once '/myprograms/hezuoshang/config/database.php';
require_once '/myprograms/hezuoshang/includes/ProjectIntake.php';
require_once '/myprograms/hezuoshang/includes/ProjectBusiness.php';
require_once '/myprograms/hezuoshang/includes/ProjectOrderSource.php';
require_once '/myprograms/hezuoshang/includes/intake/parse_rows.php';

function my_headerless_map($rows, $knownShops, $employeesByName)
{
    require_once __DIR__ . '/../includes/ProjectRenewalImportFields.php';
    $rows = array_slice(array_values(array_filter($rows, 'is_array')), 0, 50);
    $width = $rows ? max(array_map('count', $rows)) : 0;
    $columns = [];
    for ($i = 0; $i < $width; $i++) $columns[$i] = array_values(array_filter(array_map(function ($r) use ($i) { return trim((string)($r[$i] ?? '')); }, $rows), 'strlen'));
    $share = function ($i, $test) use ($columns) { $values = $columns[$i]; if (!$values) return 0; return count(array_filter($values, $test)) / count($values); };
    $map = [];
    $free = function ($i) use (&$map) { return !in_array($i, $map, true); };
    $pick = function ($key, $test, $after = -1) use ($columns, $share, $free, &$map) {
        foreach ($columns as $i => $values) if ($i > $after && $values && $free($i) && $share($i, $test) >= 0.6) { $map[$key] = $i; return; }
    };
    $pick('order_no', function ($v) { return preg_match('/^[A-Za-z0-9-]{12,}$/', $v) && preg_match_all('/\d/', $v) >= 10; });
    if (!isset($map['order_no'])) return null;
    $pick('order_date', function ($v) { return (preg_match('/^\d{1,4}([.\/-]\d{1,2}){1,2}\.?$/', $v) || mb_strpos($v, '月') !== false || (is_numeric($v) && $v > 40000 && $v < 60000)) && ps_import_date($v); });
    $pick('contact_note', function ($v) { return pr_import_phone_value($v, true) !== ''; });

    // 写手编号（如 M167, H288, P27, D2945：字母开头后跟 1-5 位数字）
    foreach ($columns as $i => $values) {
        if ($values && $free($i) && $share($i, function ($v) { return (bool)preg_match('/^[A-Za-z]\d{1,5}$/', trim($v)); }) >= 0.5) {
            $map['detail:writer_code'] = $i;
            break;
        }
    }

    // 金额与字数识别：在订单号后寻找纯数值列。
    // 如果有多列纯数值，必须区分“字数”（整百整千大整数，通常 >= 200）与“售价”（通常较小，几十上百元，或带小数），绝不能盲目把订单号后第一列当金额！
    $numericCols = [];
    foreach ($columns as $i => $values) {
        if ($i > $map['order_no'] && $values && $free($i) && $share($i, function ($v) {
            $n = str_replace([',', '¥', '￥', ' '], '', $v);
            return is_numeric($n) && (float)$n < 1000000;
        }) >= 0.6) {
            $numericCols[] = $i;
        }
    }
    if (count($numericCols) === 1) {
        $map['contract_amount'] = $numericCols[0];
    } elseif (count($numericCols) >= 2) {
        $colStats = [];
        foreach ($numericCols as $colIdx) {
            $nums = [];
            foreach ($columns[$colIdx] as $v) {
                $n = str_replace([',', '¥', '￥', ' '], '', $v);
                if (is_numeric($n)) $nums[] = (float)$n;
            }
            sort($nums);
            $cnt = count($nums);
            $median = $cnt ? $nums[(int)($cnt / 2)] : 0;
            $avg = $cnt ? array_sum($nums) / $cnt : 0;
            $colStats[$colIdx] = ['median' => $median, 'avg' => $avg];
        }
        $wordCol = null;
        $priceCol = null;
        for ($a = 0; $a < count($numericCols); $a++) {
            for ($b = $a + 1; $b < count($numericCols); $b++) {
                $idxA = $numericCols[$a];
                $idxB = $numericCols[$b];
                if ($colStats[$idxA]['median'] >= 300 && $colStats[$idxB]['median'] > 0 && $colStats[$idxA]['median'] >= $colStats[$idxB]['median'] * 2.5) {
                    $wordCol = $idxA;
                    $priceCol = $idxB;
                    break 2;
                } elseif ($colStats[$idxB]['median'] >= 300 && $colStats[$idxA]['median'] > 0 && $colStats[$idxB]['median'] >= $colStats[$idxA]['median'] * 2.5) {
                    $wordCol = $idxB;
                    $priceCol = $idxA;
                    break 2;
                }
            }
        }
        if ($wordCol !== null && $priceCol !== null) {
            $map['contract_amount'] = $priceCol;
            $map['detail:writing_volume'] = $wordCol;
            foreach ($numericCols as $colIdx) {
                if ($colIdx !== $wordCol && $colIdx !== $priceCol && $free($colIdx)) {
                    if ($colStats[$colIdx]['median'] <= $colStats[$priceCol]['median']) {
                        $map['direct_cost'] = $colIdx;
                        break;
                    }
                }
            }
        } else {
            $map['contract_amount'] = $numericCols[0];
        }
    }

    $pick('shop', function ($v) use ($knownShops) { foreach ($knownShops as $shop) if ($v === $shop || mb_strpos($shop, $v) !== false || mb_strpos($v, $shop) !== false) return true; return false; });
    $pick('program_name', function ($v) { return (bool)preg_match('/^(php|jsp|森动|博山定制|华梦|大连定制|网站定制)/iu', $v); });

    $isName = function ($v) use ($employeesByName) { foreach (preg_split('/[,，、\/]+/u', $v) as $n) if (!isset($employeesByName[trim($n)])) return false; return true; };
    $peopleColumns = [];
    foreach ($columns as $i => $values) if ($values && $free($i) && $share($i, $isName) >= 0.6) $peopleColumns[] = $i;
    foreach ($peopleColumns as $i) {
        $roles = [];
        foreach ($columns[$i] as $v) foreach (preg_split('/[,，、\/]+/u', $v) as $n) foreach ($employeesByName[trim($n)] ?? [] as $emp) if (!empty($emp['role'])) $roles[$emp['role']] = ($roles[$emp['role']] ?? 0) + 1;
        arsort($roles);
        $role = key($roles);
        $key = $role === 'technical' ? (isset($map['frontend']) ? 'backend' : 'frontend') : ($role === 'customer_service' && !isset($map['customer_service']) ? 'customer_service' : null);
        if ($key === null) foreach (['customer_service', 'frontend', 'backend'] as $candidate) if (!isset($map[$candidate])) { $key = $candidate; break; }
        if ($key !== null && !isset($map[$key])) $map[$key] = $i;
    }
    if (isset($map['customer_service']) && !isset($map['frontend'])) {
        $next = $map['customer_service'] + 1;
        if (!empty($columns[$next]) && $free($next) && $share($next, function ($v) { return (bool)preg_match('/^\p{Han}{2,4}$/u', $v); }) >= 0.6) $map['frontend'] = $next;
    }
    $pick('status', function ($v) { return $v !== '' && ps_import_delivery_status($v) !== null; });

    // 付款昵称：优先订单号前最近的一个未识别文字列；若无，找订单号与日期之间的文字列
    for ($i = $map['order_no'] - 1; $i >= 0; $i--) if ($columns[$i] && $free($i)) { if ($share($i, function ($v) { return !is_numeric($v); }) >= 0.6) { $map['payment_nickname'] = $i; break; } }
    if (!isset($map['payment_nickname']) && isset($map['order_date'])) {
        for ($i = $map['order_no'] + 1; $i < $map['order_date']; $i++) {
            if ($columns[$i] && $free($i) && $share($i, function ($v) { return !is_numeric($v) && !preg_match('/^[A-Za-z]\d{1,5}$/', trim($v)); }) >= 0.6) {
                $map['payment_nickname'] = $i;
                break;
            }
        }
    }

    if (!isset($map['order_date']) && !isset($map['contract_amount'])) return null;
    return $map;
}

$image2Rows = [
    ['3316377831159088181', '', '', 'wf1238678', 'H288', '', '2026-09-01', '1000修', '50'],
    ['3316820485157008265', '', '', 'tb5044311', 'M167', '', '2026-09-01', '600', '50'],
    ['3317005740127012974', '', '', 'dlj199063', 'H288', '', '2026-09-02', '600', '50'],
    ['3316385751212017097', '', '', 'wangkeqi', 'H288', '', '2026-09-02', '600', '50'],
    ['3316830169243081597', '', '', 'testuser', 'H288', '', '2026-09-02', '1500', '150'],
];

$map = my_headerless_map($image2Rows, ['校知网旗舰店'], ['王向晖' => [['role' => 'customer_service']]]);
echo "Headerless Map:\n";
print_r($map);
assert(isset($map['order_no']) && $map['order_no'] === 0, 'order_no should be col 0');
assert(isset($map['contract_amount']) && $map['contract_amount'] === 8, 'contract_amount should be col 8 (50, 150), NOT col 7 (word count)');
assert(isset($map['detail:writing_volume']) && $map['detail:writing_volume'] === 7, 'detail:writing_volume should be col 7 (600, 1500)');
assert(isset($map['detail:writer_code']) && $map['detail:writer_code'] === 4, 'detail:writer_code should be col 4 (H288, M167)');
assert(isset($map['payment_nickname']) && $map['payment_nickname'] === 3, 'payment_nickname should be col 3');

echo "ALL TESTS PASSED!\n";
