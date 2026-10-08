<?php

/**
 * 解析百分比文本：兼容 "30" / "30%" / "0.3"(比例) 三种写法，统一返回百分值（如 30.12）。
 * @param mixed $raw
 * @return float
 */
function parse_percent($raw)
{
    $rawStr = trim((string)$raw);
    if ($rawStr === '' || $rawStr === '-' || $rawStr === '--' || $rawStr === 'null') return 0.0;
    $neg = false;
    if ($rawStr[0] === '-') { $neg = true; $rawStr = ltrim($rawStr, '-'); }
    $hasPct = (mb_strpos($rawStr, '%') !== false);
    if ($hasPct) $rawStr = str_replace('%', '', $rawStr);
    $v = (float)str_replace(',', '', $rawStr);
    // 未带 % 号且值为 0~1 的纯小数，视为比例×100
    if (!$hasPct && $v > 0 && $v <= 1) $v *= 100;
    return round($neg ? -$v : $v, 2);
}

/**
 * 把导出的时长文本转成秒。兼容千牛/官方导出常见格式：
 *   "00:01:30" / "3:25"（时:分:秒 或 分:秒）
 *   "1分30秒" / "1分钟30秒" / "90秒" / "90"
 *   "1分 30秒" 等含空白/中文
 * @param mixed $raw
 * @return float 秒；无法解析返回 0
 */
function parse_duration_to_seconds($raw)
{
    $s = trim((string)$raw);
    if ($s === '' || $s === '-') return 0.0;
    // 冒号格式：HH:MM:SS 或 MM:SS
    if (strpos($s, ':') !== false) {
        $parts = array_map('intval', explode(':', $s));
        $n = count($parts);
        if ($n === 3) return (float)($parts[0]*3600 + $parts[1]*60 + $parts[2]);
        if ($n === 2) return (float)($parts[0]*60 + $parts[1]);
    }
    // 中文：X分Y秒 / X分钟Y秒 / X秒 / X时...
    if (mb_strpos($s, '秒') !== false || mb_strpos($s, '分') !== false || mb_strpos($s, '时') !== false) {
        $h = 0; $m = 0; $sec = 0;
        if (preg_match('#(\d+(?:\.\d+)?)\s*秒#u', $s, $mm)) $sec = (float)$mm[1];
        if (preg_match('#(\d+(?:\.\d+)?)\s*分#u', $s, $mm)) $m = (float)$mm[1];
        if (preg_match('#(\d+(?:\.\d+)?)\s*时#u', $s, $mm)) $h = (float)$mm[1];
        if ($h > 0 || $m > 0 || $sec > 0) return (float)($h*3600 + $m*60 + $sec);
    }
    // 纯数字 → 秒
    if (preg_match('#^\d+(\.\d+)?$#', str_replace(',', '', $s))) return (float)str_replace(',', '', $s);
    return 0.0;
}

/**
 * 逐行解析 CSV（兼容 PHP 7.4 str_getcsv/fgetcsv 对 UTF-8 多字节中文字段的解析 bug）。
 * 支持标准双引号包裹字段与 "" 转义引号；未闭合引号按普通字符处理。
 * @param string $line
 * @return array
 */
function csv_parse_line($line)
{
    $fields = [];
    $cur = '';
    $len = strlen($line);
    $inQuotes = false;
    $i = 0;
    while ($i < $len) {
        $ch = $line[$i];
        if ($inQuotes) {
            if ($ch === '"') {
                if ($i + 1 < $len && $line[$i+1] === '"') { $cur .= '"'; $i += 2; continue; }
                $inQuotes = false; $i++; continue;
            }
            $cur .= $ch; $i++; continue;
        }
        if ($ch === '"') { $inQuotes = true; $i++; continue; }
        if ($ch === ',') { $fields[] = $cur; $cur = ''; $i++; continue; }
        $cur .= $ch; $i++;
    }
    $fields[] = $cur; // 最后一段（含可能存在的空尾段）
    return $fields;
}

/**
 * 归一化单个旺旺账号：兼容「店铺名:账号」或「纯账号」写法，取冒号后的账号（如 美呀美旗舰店:依蝶雅涵涵 → 依蝶雅涵涵）。
 */
function normalize_cs_wangwang($s)
{
    $s = trim((string)$s);
    if ($s === '') return '';
    if (strpos($s, ':') !== false || strpos($s, '：') !== false) {
        $parts = explode(':', str_replace('：', ':', $s));
        if (count($parts) > 1) $s = trim((string)end($parts));
    }
    return $s;
}

/**
 * 导入一份客服绩效数据文件（采集工具上传 / 千牛官网导出 / 计划任务扫描共用）。
 *
 * 自动识别列名（含千牛/官方导出：客服、接待人数、平均响应时长等），
 * 时长支持 "HH:MM:SS" / "X分X秒" / 纯秒 等格式。
 * 文件里没有日期列的月度汇总表，可传入 $defaultYear/$defaultMonth 归到指定月份。
 * 未匹配到员工的行暂存 cs_perf_pending。
 *
 * @param string $filePath 文件绝对路径
 * @param string $source   来源标识（文件名/说明）
 * @param int    $defaultYear  文件无日期时使用的年份（0=必须从文件取）
 * @param int    $defaultMonth 文件无日期时使用的月份（0=必须从文件取）
 * @param string $store    店铺名（上传时手动选择；设计客服需按店铺分别上传两家店，合并时按店铺分开存）
 * @return array ['matched'=>n,'pending'=>n,'errors'=>n,'detail'=>[每员工写入说明]]
 */
function import_cs_perf_file($filePath, $source = '', $defaultYear = 0, $defaultMonth = 0, $store = '')
{
    if ($source === '') $source = basename($filePath);
    $store = trim((string)$store);

    $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    $header = null;
    $lines  = [];

    if ($ext === 'xlsx' || $ext === 'xls') {
        // ===== .xlsx / .xls：用 SimpleXLSX 解析，自动定位含绩效表头的工作表/行 =====
        // 表头须含「姓名/客服」或「旺旺」之一的身份列，并在所有行中取识别列数最多者（避开标题/汇总行）。
        require_once dirname((dirname(__DIR__, 1))) . '/classes/SimpleXLSX.php';
        $idKeys = ['name','wangwang','incoming','total_sec','reply_speed','reply_count','date','year','month','net_sales','inquiry_conv','wangwang_reply','order_count'];
        $bestHeader = null;
        $bestRows   = null;
        $bestIdx    = -1;
        $bestScore  = -1;
        try {
            $sheets = SimpleXLSX::parseAll($filePath); // ['工作表名' => 行数组]
        } catch (\Throwable $e) {
            return ['matched'=>0,'pending'=>0,'errors'=>1,'detail'=>['无法解析 ' . $ext . '：' . $e->getMessage()]];
        }
        foreach ($sheets as $sRows) {
            if (!is_array($sRows)) continue;
            for ($i = 0, $n = count($sRows); $i < $n; $i++) {
                $c = detect_cs_perf_columns((array)$sRows[$i]);
                if ($c['name'] === null && $c['wangwang'] === null) continue; // 无身份列，视为标题/汇总行
                $score = 0;
                foreach ($idKeys as $k) if ($c[$k] !== null) $score++;
                if ($score > $bestScore) {
                    $bestScore  = $score;
                    $bestHeader = (array)$sRows[$i];
                    $bestRows   = $sRows;
                    $bestIdx    = $i;
                }
            }
        }
        if ($bestHeader === null) {
            return ['matched'=>0,'pending'=>0,'errors'=>1,'detail'=>[$ext . ' 中未找到客服绩效表头（客服/旺旺/净销售额/转化率等）']];
        }
        $header = $bestHeader;
        for ($j = $bestIdx + 1, $n = count($bestRows); $j < $n; $j++) {
            $row = (array)$bestRows[$j];
            $allEmpty = true;
            foreach ($row as $cell) { if (trim((string)$cell) !== '') { $allEmpty = false; break; } }
            if ($allEmpty) continue;
            $lines[] = array_values($row);
        }
        if (count($lines) < 1) {
            return ['matched'=>0,'pending'=>0,'errors'=>1,'detail'=>[$ext . ' 中未找到数据行']];
        }
    } else {
        // ===== CSV / TXT：原有逻辑（兼容 GBK）=====
        $content = @file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            return ['matched'=>0,'pending'=>0,'errors'=>1,'detail'=>['文件为空或不可读']];
        }
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'GBK');
            if ($converted !== false) $content = $converted;
        }
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if (trim($line) === '') continue;
            $lines[] = csv_parse_line(rtrim($line, "\r"));
        }
        if (count($lines) < 2) {
            return ['matched'=>0,'pending'=>0,'errors'=>1,'detail'=>['无数据行']];
        }
        $header = array_shift($lines);
    }

    $cols = detect_cs_perf_columns($header);

    ensureCsPerfSchema();
    $pdo = db();

    // 员工映射：姓名精确、旺旺账号(大小写不敏感；一人可同时登录多个账号，用逗号/空格分隔，兼容「店铺名:账号」写法)
    // 注意：PHP7.4 的 preg_split 不带 /u 会破坏 UTF-8 中文，这里用字节安全的 str_replace+explode 拆分
    $byName = [];
    $byWang = [];
    foreach (get_employees() as $e) {
        if (trim($e['name']) !== '') $byName[trim($e['name'])] = $e;
        $wws = str_replace(['，','；',';',',',' ','　',"\t","\r","\n"], ',', (string)($e['wangwang'] ?? ''));
        foreach (explode(',', $wws) as $ww) {
            $ww = normalize_cs_wangwang($ww);
            if ($ww !== '') $byWang[mb_strtolower($ww)] = $e;
        }
    }

    // 聚合：monthAgg[key] = [emp, year, month, incoming, totalSec(sum模式), replySum/replyCnt(avg模式)]
    $monthAgg = [];
    $pendingKey = []; // 未匹配：key(wangwang|name+ym) => [aggregated]
    $errors = 0;

    foreach ($lines as $row) {
        $vals = array_pad($row, count($header), '');
        $name = isset($cols['name']) && $cols['name'] !== null && isset($vals[$cols['name']]) ? trim((string)$vals[$cols['name']]) : '';
        $wang = isset($cols['wangwang']) && $cols['wangwang'] !== null && isset($vals[$cols['wangwang']]) ? trim((string)$vals[$cols['wangwang']]) : '';
        $incoming = 0;
        $totalSec = 0.0;
        $replyCount = 0;
        $replySpeed = 0.0;
        $netSales = 0.0;
        $inquiryConv = 0.0;
        $wangReply = 0.0;
        $orderCount = 0;
        if ($cols['incoming'] !== null && isset($vals[$cols['incoming']])) $incoming = (int)extract_amount($vals[$cols['incoming']]);
        if ($cols['order_count'] !== null && isset($vals[$cols['order_count']])) $orderCount = (int)extract_amount($vals[$cols['order_count']]);
        if ($cols['total_sec'] !== null && isset($vals[$cols['total_sec']])) $totalSec = (float)extract_amount($vals[$cols['total_sec']]);
        if ($cols['reply_count'] !== null && isset($vals[$cols['reply_count']])) $replyCount = (int)extract_amount($vals[$cols['reply_count']]);
        // 平均回复/响应时长：可能是 HH:MM:SS、X分X秒、纯秒，统一转成秒
        if ($cols['reply_speed'] !== null && isset($vals[$cols['reply_speed']])) $replySpeed = (float)parse_duration_to_seconds($vals[$cols['reply_speed']]);
        if ($cols['net_sales'] !== null && isset($vals[$cols['net_sales']])) $netSales = (float)extract_amount($vals[$cols['net_sales']]);
        if ($cols['inquiry_conv'] !== null && isset($vals[$cols['inquiry_conv']])) $inquiryConv = (float)parse_percent($vals[$cols['inquiry_conv']]);
        if ($cols['wangwang_reply'] !== null && isset($vals[$cols['wangwang_reply']])) $wangReply = (float)parse_percent($vals[$cols['wangwang_reply']]);

        // 日期 → 年月（文件无日期时用调用方给定的默认年月）
        $year = 0; $month = 0;
        if ($cols['date'] !== null && isset($vals[$cols['date']]) && trim((string)$vals[$cols['date']]) !== '') {
            $d = trim((string)$vals[$cols['date']]);
            if (preg_match('#(\d{4})[-/.年](\d{1,2})#', $d, $m)) { $year = (int)$m[1]; $month = (int)$m[2]; }
        }
        if ($year <= 0 && $cols['year'] !== null && isset($vals[$cols['year']])) $year = (int)extract_amount($vals[$cols['year']]);
        if ($month <= 0 && $cols['month'] !== null && isset($vals[$cols['month']])) $month = (int)extract_amount($vals[$cols['month']]);
        if ($year <= 0 && $defaultYear > 0) $year = (int)$defaultYear;
        if ($month <= 0 && $defaultMonth > 0) $month = (int)$defaultMonth;
        if ($year <= 0 || $month < 1 || $month > 12) { $errors++; continue; }

        // 匹配员工：优先旺旺账号(归一化)，其次姓名；导出表把账号填在「客服」列等情形再做兜底
        $emp = null;
        $wangKey = mb_strtolower(normalize_cs_wangwang($wang));
        if ($wangKey !== '' && isset($byWang[$wangKey])) $emp = $byWang[$wangKey];
        if ($emp === null && $name !== '' && isset($byName[$name])) $emp = $byName[$name];
        if ($emp === null && $name !== '' && isset($byWang[mb_strtolower(normalize_cs_wangwang($name))])) $emp = $byWang[mb_strtolower(normalize_cs_wangwang($name))];
        // 兜底：部分导出表「客服」列直接填员工真实姓名（如 业绩分析里的 穆楠/张欣），按姓名命中
        if ($emp === null && $wang !== '' && isset($byName[$wang])) $emp = $byName[$wang];

        if ($emp === null) {
            // 未匹配 → 暂存
            $pk = ($wang !== '' ? 'w:' . $wangKey : 'n:' . $name) . '|' . $store . "|$year-$month";
            if (!isset($pendingKey[$pk])) {
                $pendingKey[$pk] = ['wangwang'=>$wang,'name'=>$name,'store'=>$store,'year'=>$year,'month'=>$month,'incoming'=>0,'total'=>0.0,'replyCnt'=>0,'avgSum'=>0.0,'avgN'=>0,'netSales'
    =>0.0,'convSum'=>0.0,'convN'=>0,'wangSum'=>0.0,'wangN'=>0,'orderCnt'=>0];
            }
            $pendingKey[$pk]['incoming'] += $incoming;
            $pendingKey[$pk]['orderCnt'] += $orderCount;
            $pendingKey[$pk]['total'] += $totalSec;
            $pendingKey[$pk]['replyCnt'] += $replyCount;
            if ($replySpeed > 0) { $pendingKey[$pk]['avgSum'] += $replySpeed; $pendingKey[$pk]['avgN']++; }
            $pendingKey[$pk]['netSales'] += $netSales;
            if ($inquiryConv > 0) { $pendingKey[$pk]['convSum'] += $inquiryConv; $pendingKey[$pk]['convN']++; }
            if ($wangReply > 0)   { $pendingKey[$pk]['wangSum'] += $wangReply; $pendingKey[$pk]['wangN']++; }
            continue;
        }

        $ek = (int)$emp['id'] . '|' . $store . "|$year-$month";
        if (!isset($monthAgg[$ek])) {
            $monthAgg[$ek] = ['emp'=>$emp,'store'=>$store,'year'=>$year,'month'=>$month,'incoming'=>0,'total'=>0.0,'replyCnt'=>0,'avgSum'=>0.0,'avgN'=>0,'netSales'=>0.0,'convSum'=>
    0.0,'convN'=>0,'wangSum'=>0.0,'wangN'=>0,'orderCnt'=>0];
        }
        $monthAgg[$ek]['incoming'] += $incoming;
        $monthAgg[$ek]['orderCnt'] += $orderCount;
        $monthAgg[$ek]['total'] += $totalSec;
        $monthAgg[$ek]['replyCnt'] += $replyCount;
        if ($replySpeed > 0) { $monthAgg[$ek]['avgSum'] += $replySpeed; $monthAgg[$ek]['avgN']++; }
        $monthAgg[$ek]['netSales'] += $netSales;
        if ($inquiryConv > 0) { $monthAgg[$ek]['convSum'] += $inquiryConv; $monthAgg[$ek]['convN']++; }
        if ($wangReply > 0)   { $monthAgg[$ek]['wangSum'] += $wangReply; $monthAgg[$ek]['wangN']++; }
    }

    $detail = [];

    // 已匹配 → 覆盖式写入主表（保留 remark / deal_count 手动值）；按 (员工,店铺,年月) 唯一，多店铺各占一行
    $upsert = $pdo->prepare("INSERT INTO customer_service_performance
        (employee_id, store, year, month, reply_speed, incoming_count, net_sales, inquiry_conv, wangwang_reply, order_count, source_file)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        reply_speed=VALUES(reply_speed), incoming_count=VALUES(incoming_count),
        net_sales=VALUES(net_sales), inquiry_conv=VALUES(inquiry_conv), wangwang_reply=VALUES(wangwang_reply),
        order_count=VALUES(order_count), source_file=VALUES(source_file)");
    foreach ($monthAgg as $agg) {
        $incoming = $agg['incoming'];
        $orderCnt = $agg['orderCnt'];
        $replySpeed = 0.0;
        if ($agg['avgN'] > 0) {
            $replySpeed = round($agg['avgSum'] / $agg['avgN'], 1); // 优先直接用导出的平均回复/响应时长
        } elseif ($incoming > 0 && $agg['replyCnt'] > 0 && $agg['total'] > 0) {
            $replySpeed = round($agg['total'] / $agg['replyCnt'], 1); // 总回复秒 ÷ 回复条数 = 平均回复秒
        } elseif ($incoming > 0 && $agg['total'] > 0) {
            $replySpeed = round($agg['total'] / $incoming, 1); // 兼容无回复次数列的文件
        } elseif ($agg['avgN'] > 0) {
            $replySpeed = round($agg['avgSum'] / $agg['avgN'], 1); // 第三方平均回复速度列回退
        }
        $netSales   = round($agg['netSales'], 2);
        // 转化率：文件直接给出时取平均；否则用「下单人数 ÷ 询单人数」计算（当行未匹配单行已聚合）
        $inquiryConv = $agg['convN'] > 0 ? round($agg['convSum'] / $agg['convN'], 2)
                     : ($orderCnt > 0 && $incoming > 0 ? round($orderCnt / $incoming * 100, 2) : 0.0);
        $wangReply   = $agg['wangN'] > 0 ? round($agg['wangSum'] / $agg['wangN'], 2) : 0.0;
        $upsert->execute([(int)$agg['emp']['id'], $store, $agg['year'], $agg['month'], $replySpeed, $incoming, $netSales, $inquiryConv, $wangReply, $orderCnt, $source]);
        $convTxt = cs_perf_conv_derivation($inquiryConv, $orderCnt, $incoming);
        $detail[] = sprintf('%s[%s] %04d-%02d 进线%d 下单%d 回复%.1fs 销售额%.2f %s 旺旺回复率%.2f%%', $agg['emp']['name'], $store !== '' ? $store : '无店铺', $agg
    ['year'], $agg['month'], $incoming, $orderCnt, $replySpeed, $netSales, $convTxt !== null ? $convTxt : sprintf('询单转化%.2f%%', $inquiryConv), $wangReply);
    }

    // 未匹配 → 先清该 key 旧暂存再写入（避免重复上传叠加）
    $delPending = $pdo->prepare("DELETE FROM cs_perf_pending WHERE wangwang=? AND name=? AND year=? AND month=?");
    $insPending = $pdo->prepare("INSERT INTO cs_perf_pending
        (wangwang, name, year, month, incoming_count, total_reply_seconds, net_sales, inquiry_conv, wangwang_reply, order_count, source_file, raw_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $pendingCount = 0;
    foreach ($pendingKey as $pk => $p) {
        $delPending->execute([$p['wangwang'], $p['name'], $p['year'], $p['month']]);
        $netSales   = round($p['netSales'], 2);
        $inquiryConv = $p['convN'] > 0 ? round($p['convSum'] / $p['convN'], 2)
                     : ($p['orderCnt'] > 0 && $p['incoming'] > 0 ? round($p['orderCnt'] / $p['incoming'] * 100, 2) : 0.0);
        $wangReply   = $p['wangN'] > 0 ? round($p['wangSum'] / $p['wangN'], 2) : 0.0;
        $raw = json_encode(['wangwang'=>$p['wangwang'],'name'=>$p['name'],'store'=>$p['store'],'year'=>$p['year'],'month'=>$p['month'],'incoming'=>$p['incoming'],'order_count'=>$p[
    'orderCnt'],'total_reply_seconds'=>$p['total'],'net_sales'=>$netSales,'inquiry_conv'=>$inquiryConv,'wangwang_reply'=>$wangReply], JSON_UNESCAPED_UNICODE);
        $insPending->execute([$p['wangwang'], $p['name'], $p['year'], $p['month'], $p['incoming'], $p['total'], $netSales, $inquiryConv, $wangReply, $p['orderCnt'], $source, $raw])
    ;
        $pendingCount++;
        $detail[] = sprintf('未匹配暂存：%s[%s] %04d-%02d', $p['name'] !== '' ? $p['name'] : $p['wangwang'], $p['store'] !== '' ? $p['store'] : '无店铺', $p['year'], $p['month'
    ]);
    }

    // 写同步日志
    $stmt = $pdo->prepare("INSERT INTO cs_perf_sync_log (source_file, matched, pending, errors, detail) VALUES (?,?,?,?,?)");
    $stmt->execute([$source, count($monthAgg), $pendingCount, $errors, json_encode($detail, JSON_UNESCAPED_UNICODE)]);

    cs_perf_cache_reset(); // 导入改写了绩效表，清请求内缓存（同一请求内其后渲染需读到新数据）
    return ['matched'=>count($monthAgg), 'pending'=>$pendingCount, 'errors'=>$errors, 'detail'=>$detail];
}


// ===== 时区修正 =====
// 服务器 MySQL 的 time_zone 常解析为 UTC，而 PHP 为东八区(PRC)，导致 DEFAULT CURRENT_TIMESTAMP
// 写入/返回的时间比本地早 8 小时。统一将每个 MySQL 会话时区设为 +08:00，
// 使时间戳与 PHP 本地一致（TIMESTAMP 列会自动换算显示，无需改写存量数据）。
if (function_exists('db')) {
    try { db()->exec("SET time_zone = '+08:00'"); } catch (\Throwable $e) {}
}
