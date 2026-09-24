<?php
/**
 * AI 托底：系统规则处理不了的上传表格（表头对不上、状态写法没见过、订单类型没写），先查“AI 托底记录”里已存档的方案，
 * 没有再调用 AI；AI 给出的方案校验后存档（系统日志），下次同类问题直接复用，不再调用 AI。财务可在“系统设置”查看、停用或删除。
 */
require_once __DIR__ . '/ProjectSettlement.php';

/** 同类问题的签名：类别 + 业务 + 规范化后的内容。 */
function ps_ai_signature($parts)
{
    $normalized = array_map(function ($p) { return preg_replace('/\s+/u', '', mb_strtolower(trim((string)$p))); }, (array)$parts);
    return sha1(implode("\x1f", $normalized));
}

/** 查找已存档的方案（启用的）；命中时累计使用次数。 */
function ps_ai_solution_find($category, $business, $signature)
{
    try {
        $q = db()->prepare("SELECT id,solution_json FROM project_ai_solutions WHERE category=? AND business_name=? AND signature=? AND status='active' AND source<>'error'");
        $q->execute([$category, (string)$business, $signature]);
        $row = $q->fetch();
    } catch (PDOException $e) { return null; } // 表未迁移时不影响导入
    if (!$row) return null;
    db()->prepare('UPDATE project_ai_solutions SET uses=uses+1,last_used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
    $solution = json_decode($row['solution_json'], true);
    return is_array($solution) ? $solution : null;
}

function ps_ai_solution_save($category, $business, $signature, $problem, $solution, $explanation, $actor, $source = 'ai')
{
    try {
        db()->prepare('INSERT INTO project_ai_solutions (category,business_name,signature,problem,solution_json,explanation,source,created_by_type,created_by_id) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE problem=VALUES(problem),solution_json=VALUES(solution_json),explanation=VALUES(explanation),source=VALUES(source),status=\'active\',created_at=NOW()')
            ->execute([$category, (string)$business, $signature, mb_substr((string)$problem, 0, 5000), json_encode($solution, JSON_UNESCAPED_UNICODE), mb_substr((string)$explanation, 0, 1000), $source, $actor['type'] ?? '', (int)($actor['id'] ?? 0)]);
    } catch (PDOException $e) { /* 日志失败不影响导入 */ }
}

/** AI 调用失败也记一笔（source=error），方便财务排查接口问题。 */
function ps_ai_log_error($category, $business, $problem, $message, $actor)
{
    ps_ai_solution_save($category . '_error', $business, sha1($problem . microtime(true)), $problem, ['error' => $message], mb_substr($message, 0, 1000), $actor, 'error');
}

function ps_ai_labels()
{
    return ['import_columns' => '表头识别', 'import_status' => '状态识别', 'import_kind' => '订单类型建议'];
}

/**
 * 表头对不上时：已存档方案 → AI。返回 [列映射(key => 列序号), 说明]；无法识别返回 null。
 * $columns 为 ps_business_import_columns() 的 key => 别名列表；$samples 为前几行数据（供 AI 判断）。
 */
function ps_ai_import_columns($business, array $head, array $samples, array $columns, $actor, &$note = '')
{
    $signature = ps_ai_signature(array_merge([$business], $head));
    $apply = function ($mapping) use ($head, $columns) {
        $map = [];
        foreach ((array)$mapping as $key => $header) {
            if (!isset($columns[$key]) || $header === null || $header === '') continue;
            $index = is_int($header) ? $header : array_search((string)$header, $head, true);
            if ($index === false || !isset($head[$index]) || in_array($index, $map, true)) continue;
            $map[$key] = (int)$index;
        }
        return isset($map['order_no']) ? $map : null;
    };
    $saved = ps_ai_solution_find('import_columns', $business, $signature);
    if ($saved && ($map = $apply($saved['mapping'] ?? []))) { $note = '按已存档的 AI 方案识别表头（不再调用 AI）'; return $map; }
    if (!function_exists('ps_ai_ready') || !ps_ai_ready()) return null;
    $keys = [];
    foreach ($columns as $key => $aliases) $keys[$key] = implode(' / ', array_slice($aliases, 0, 3));
    $headerList = [];
    foreach ($head as $i => $h) $headerList[] = ['列' => $i + 1, '表头' => $h === '' ? '（空表头）' : $h];
    $prompt = "下面是合作人员上传的“{$business}”订单表格，系统没能按表头别名识别列。请把表格的列对应到系统字段。\n"
        . "系统字段（key：含义/常见表头）：" . json_encode($keys, JSON_UNESCAPED_UNICODE) . "\n"
        . "表格表头：" . json_encode($headerList, JSON_UNESCAPED_UNICODE) . "\n"
        . "前几行数据：" . json_encode(array_slice($samples, 0, 5), JSON_UNESCAPED_UNICODE) . "\n"
        . "要求：只输出 JSON：{\"mapping\":{\"系统字段key\":列号(从1开始)}, \"explanation\":\"一句话说明依据\"}。"
        . "order_no（订单编号）必须找到；找不到的字段不要写；一列只能对应一个字段；空表头的列看数据判断（如全是 260901 这类日期）。";
    try {
        $reply = ps_ai_json(ps_ai_chat([['role' => 'system', 'content' => '你是严谨的数据对表助手，只输出 JSON。'], ['role' => 'user', 'content' => $prompt]], 800));
        $mapping = [];
        foreach ((array)($reply['mapping'] ?? []) as $key => $col) if (is_numeric($col) && (int)$col >= 1) $mapping[$key] = (int)$col - 1;
        $map = $apply($mapping);
        if (!$map) throw new RuntimeException('AI 没有找到订单编号列');
        $byName = [];
        foreach ($map as $key => $index) $byName[$key] = $head[$index] !== '' ? $head[$index] : $index;
        ps_ai_solution_save('import_columns', $business, $signature, '表头：' . implode(' | ', $head), ['mapping' => $byName], (string)($reply['explanation'] ?? ''), $actor);
        $note = 'AI 识别了表头：' . (string)($reply['explanation'] ?? '') . '（已存档，下次同样的表头不再调用 AI）';
        return $map;
    } catch (Throwable $e) {
        ps_ai_log_error('import_columns', $business, '表头：' . implode(' | ', $head), $e->getMessage(), $actor);
        return null;
    }
}

/**
 * 一批“系统不认识的值”：先查存档，剩下的一次性交给 AI。$ask 为 AI 提问说明，$allowed 为允许的答案。
 * 返回 [原值 => 答案]；只含识别成功的。$fromAi 返回本次由 AI 新得出的原值列表。
 */
function ps_ai_resolve_values($category, $business, array $values, array $allowed, $ask, $actor, &$fromAi = [])
{
    $result = [];
    $unknown = [];
    foreach (array_unique(array_filter(array_map('strval', $values), 'strlen')) as $value) {
        $saved = ps_ai_solution_find($category, $business, ps_ai_signature([$value]));
        if ($saved && in_array($saved['answer'] ?? null, $allowed, true)) $result[$value] = $saved['answer'];
        else $unknown[] = $value;
    }
    $fromAi = [];
    if (!$unknown || !function_exists('ps_ai_ready') || !ps_ai_ready()) return $result;
    $unknown = array_slice($unknown, 0, 60);
    $prompt = $ask . "\n可选答案：" . json_encode(array_values($allowed), JSON_UNESCAPED_UNICODE) . "\n待判断的值：" . json_encode($unknown, JSON_UNESCAPED_UNICODE)
        . "\n只输出 JSON：{\"answers\":{\"原值\":\"答案\"}, \"explanation\":\"一句话说明规则\"}。拿不准的不要写。";
    try {
        $reply = ps_ai_json(ps_ai_chat([['role' => 'system', 'content' => '你是严谨的财务数据助手，只输出 JSON。'], ['role' => 'user', 'content' => $prompt]], 900));
        foreach ((array)($reply['answers'] ?? []) as $value => $answer) {
            $value = (string)$value;
            if (!in_array($value, $unknown, true) || !in_array($answer, $allowed, true)) continue;
            $result[$value] = $answer;
            $fromAi[] = $value;
            ps_ai_solution_save($category, $business, ps_ai_signature([$value]), $value, ['answer' => $answer], (string)($reply['explanation'] ?? ''), $actor);
        }
    } catch (Throwable $e) {
        ps_ai_log_error($category, $business, implode(' | ', $unknown), $e->getMessage(), $actor);
    }
    return $result;
}
