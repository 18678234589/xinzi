<?php

/**
 * 全部绩效方案列表
 * @return array
 */
function get_cs_perf_schemes()
{
    $c = cs_perf_cache_get('schemes');
    if ($c['hit']) return $c['val'];
    try {
        $rows = db()->query("SELECT * FROM cs_perf_schemes ORDER BY is_default DESC, id ASC")->fetchAll();
        cs_perf_cache_set('schemes', $rows);
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 读取单个绩效方案
 */
function get_cs_perf_scheme($id)
{
    $key = 'scheme|' . (int)$id;
    $c = cs_perf_cache_get($key);
    if ($c['hit']) return $c['val'];
    try {
        $st = db()->prepare("SELECT * FROM cs_perf_schemes WHERE id=?");
        $st->execute([(int)$id]);
        $row = $st->fetch();
        $val = $row ?: null;
        cs_perf_cache_set($key, $val);
        return $val;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * 档位区间解析：把存储的 JSON / 数组转成规范化档位列表（按 from 升序）。
 * 每档：['from'=>下限, 'to'=>上限(可 null=无上限), 'rate'=>达成率%]
 */
function cs_perf_tiers_parse($json)
{
    if (is_array($json)) {
        $arr = $json;
    } elseif (is_string($json) && trim($json) !== '') {
        $arr = json_decode($json, true);
    } else {
        return [];
    }
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $t) {
        if (!is_array($t)) continue;
        $out[] = [
            'from' => (float)($t['from'] ?? 0),
            'to'   => (isset($t['to']) && $t['to'] !== '' && $t['to'] !== null) ? (float)$t['to'] : null,
            'rate' => (float)($t['rate'] ?? 0),
        ];
    }
    usort($out, function ($a, $b) { return $a['from'] <=> $b['from']; });
    return $out;
}

/**
 * 档位列表转存储 JSON（空档位行会被丢弃；下行为空表示"无上限"）。
 */
function cs_perf_tiers_json($tiers)
{
    if (is_string($tiers)) return $tiers; // 已是 JSON 直接存
    if (!is_array($tiers)) return '';
    $out = [];
    foreach ($tiers as $t) {
        if (!is_array($t)) continue;
        $from = (float)($t['from'] ?? 0);
        $to   = (isset($t['to']) && $t['to'] !== '' && $t['to'] !== null) ? (float)$t['to'] : null;
        $rate = (float)($t['rate'] ?? 0);
        if ($from <= 0 && ($to === null || $to <= 0) && $rate <= 0) continue; // 完全空行
        $out[] = ['from' => $from, 'to' => $to, 'rate' => $rate];
    }
    usort($out, function ($a, $b) { return $a['from'] <=> $b['from']; });
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : '';
}

/**
 * 档位达成率：取「区间下限 <= 实际值」的最后一个档位（连续区间下即命中档）。
 * 实际值低于首档下限 → 首档达成率；高于末档 → 末档达成率（末档通常"无上限"）。
 * 实际值落在档位间隔的空隙时取上一个档位的达成率。
 * @param array $tiers 规范化档位列表
 * @param float $value 实际值
 * @return array|null ['from','to','rate']；无档位或值<=0 返回 null
 */
function cs_perf_tier_lookup($tiers, $value)
{
    $value = (float)$value;
    if (!$tiers || $value <= 0) return null;
    usort($tiers, function ($a, $b) { return $a['from'] <=> $b['from']; });
    $hit = null;
    foreach ($tiers as $t) {
        if ($value >= (float)$t['from']) $hit = $t;
        else break;
    }
    return $hit ?: $tiers[0];
}

/**
 * 档位区间格式化（用于公式/列表展示）：≥8万 / ≤6万 / 3万~5万
 */
function cs_perf_fmt_range($tier)
{
    $f = function ($v) { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };
    $from = (float)($tier['from'] ?? 0);
    $to   = ($tier['to'] ?? null);
    if ($to === null || $to === '') return '≥' . $f($from);
    if ($from <= 0) return '≤' . $f($to);
    return $f($from) . '~' . $f($to);
}

/**
 * 保存/新增一个绩效方案。$id=0 表示新增。
 * @param array $p 键：name, w_net_sales, t_net_sales, w_inquiry_conv, t_inquiry_conv,
 *                  w_wangwang_reply, t_wangwang_reply, w_avg_response, t_avg_response,
 *                  tiers_net_sales/tiers_inquiry_conv/tiers_wangwang_reply/tiers_avg_response（数组或JSON），
 *                  floor_pct, cap_pct, is_default
 * @return bool
 */
function save_cs_perf_scheme($id, $p)
{
    try {
        $pdo  = db();
        $id   = (int)$id;
        $name = (string)($p['name'] ?? '');
        if (trim($name) === '') return false;
        $isDefault = !empty($p['is_default']) ? 1 : 0;
        $fields = [
            (float)($p['w_net_sales'] ?? 43),       (float)($p['t_net_sales'] ?? 0),
            (float)($p['w_inquiry_conv'] ?? 30),    (float)($p['t_inquiry_conv'] ?? 0),
            (float)($p['w_wangwang_reply'] ?? 17),  (float)($p['t_wangwang_reply'] ?? 0),
            (float)($p['w_avg_response'] ?? 15),    (float)($p['t_avg_response'] ?? 0),
            cs_perf_tiers_json($p['tiers_net_sales'] ?? []),
            cs_perf_tiers_json($p['tiers_inquiry_conv'] ?? []),
            cs_perf_tiers_json($p['tiers_wangwang_reply'] ?? []),
            cs_perf_tiers_json($p['tiers_avg_response'] ?? []),
            (float)($p['floor_pct'] ?? 0),          (float)($p['cap_pct'] ?? 0),
            $isDefault,
        ];
        if ($isDefault) $pdo->exec("UPDATE cs_perf_schemes SET is_default=0");
        if ($id > 0) {
            $st = $pdo->prepare("UPDATE cs_perf_schemes SET name=?, w_net_sales=?, t_net_sales=?, w_inquiry_conv=?, t_inquiry_conv=?, w_wangwang_reply=?, t_wangwang_reply=?, w_avg_response=?, t_avg_response=?, tiers_net_sales=?, tiers_inquiry_conv=?, tiers_wangwang_reply=?, tiers_avg_response=?, floor_pct=?, cap_pct=?, is_default=? WHERE id=?");
            $ok = (bool)$st->execute(array_merge([$name], $fields, [$id])); // 注意 UPDATE 需补上 name 值，否则参数比占位符少一个报错
            if ($ok) cs_perf_cache_reset();
            return $ok;
        }
        $st = $pdo->prepare("INSERT INTO cs_perf_schemes (name, w_net_sales, t_net_sales, w_inquiry_conv, t_inquiry_conv, w_wangwang_reply, t_wangwang_reply, w_avg_response, t_avg_response, tiers_net_sales, tiers_inquiry_conv, tiers_wangwang_reply, tiers_avg_response, floor_pct, cap_pct, is_default) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ok = (bool)$st->execute(array_merge([$name], $fields));
        if ($ok) cs_perf_cache_reset();
        return $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 删除绩效方案；被删方案在部门配置里回退到剩余的最小 id 方案，并把该方案设为默认。
 */
function delete_cs_perf_scheme($id)
{
    try {
        $pdo = db();
        $id  = (int)$id;
        $pdo->prepare("DELETE FROM cs_perf_schemes WHERE id=?")->execute([$id]);
        $remain = (int)$pdo->query("SELECT COUNT(*) FROM cs_perf_schemes")->fetchColumn();
        if ($remain > 0) {
            $defId = (int)$pdo->query("SELECT MIN(id) FROM cs_perf_schemes")->fetchColumn();
            if ($defId > 0) {
                $pdo->exec("UPDATE cs_perf_schemes SET is_default=0");
                $pdo->exec("UPDATE cs_perf_schemes SET is_default=1 WHERE id=$defId");
                $pdo->exec("UPDATE cs_perf_dept_config SET scheme_id=$defId WHERE scheme_id=$id");
            }
        }
        cs_perf_cache_reset();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/* ---------------- 部门基数配置 ---------------- */

/**
 * 全部部门基数配置（含方案名）
 */
function get_cs_perf_dept_configs()
{
    $c = cs_perf_cache_get('dept_configs');
    if ($c['hit']) return $c['val'];
    try {
        $rows = db()->query("SELECT c.*, s.name scheme_name FROM cs_perf_dept_config c"
            . " LEFT JOIN cs_perf_schemes s ON s.id=c.scheme_id ORDER BY c.department")->fetchAll();
        cs_perf_cache_set('dept_configs', $rows);
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 读取某部门的绩效基数配置
 */
function get_cs_perf_dept_config($dept)
{
    $key = 'dept_config|' . (string)$dept;
    $c = cs_perf_cache_get($key);
    if ($c['hit']) return $c['val'];
    try {
        $st = db()->prepare("SELECT c.*, s.name scheme_name FROM cs_perf_dept_config c"
            . " LEFT JOIN cs_perf_schemes s ON s.id=c.scheme_id WHERE c.department=?");
        $st->execute([(string)$dept]);
        $row = $st->fetch();
        $val = $row ?: null;
        cs_perf_cache_set($key, $val);
        return $val;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * 保存/新增部门绩效配置（部门 → 基数 + 方案）。$oldDept 用于重命名（不等于 $dept 时先删旧)。
 */
function save_cs_perf_dept_config($dept, $schemeId, $base, $oldDept = '')
{
    try {
        $pdo    = db();
        $dept   = trim((string)$dept);
        $oldDept = $oldDept !== '' ? $oldDept : $dept;
        if ($dept === '') return false;
        if ($oldDept !== $dept) $pdo->prepare("DELETE FROM cs_perf_dept_config WHERE department=?")->execute([$oldDept]);
        $st = $pdo->prepare("INSERT INTO cs_perf_dept_config (department, scheme_id, base) VALUES (?,?,?)"
            . " ON DUPLICATE KEY UPDATE scheme_id=VALUES(scheme_id), base=VALUES(base)");
        $ok = (bool)$st->execute([$dept, (int)$schemeId, (float)$base]);
        if ($ok) cs_perf_cache_reset();
        return $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 删除部门绩效配置
 */
function delete_cs_perf_dept_config($dept)
{
    try {
        $ok = (bool)db()->prepare("DELETE FROM cs_perf_dept_config WHERE department=?")->execute([(string)$dept]);
        if ($ok) cs_perf_cache_reset();
        return $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

/* ---------------- 绩效金额计算（共享算法） ---------------- */

// 设计客服「按排名定底薪」：部门内按「多店绩效平均→部门内前三名」排序，只取前三名发底薪。
// 第1名850元 / 第2名800元 / 第3名750元；其余为0（仅设计客服固定启用此机制）。
define('CS_PERF_RANK_DEPT', '设计客服');
define('CS_PERF_RANK_TIERS', [850, 800, 750]);

/**
 * 由绩效方案行构造四指标档位达成参数（权重+档位JSON+保底/封顶）。
 * 绩效页、薪资结算、设计客服「多店分别计算」排名共用，保证口径一致。
 */
function cs_perf_scheme_params($scheme)
{
    return [
        'w_net_sales' => (float)$scheme['w_net_sales'],       'tiers_net_sales' => cs_perf_tiers_parse($scheme['tiers_net_sales'] ?? ''),
        'w_inquiry_conv' => (float)$scheme['w_inquiry_conv'], 'tiers_inquiry_conv' => cs_perf_tiers_parse($scheme['tiers_inquiry_conv'] ?? ''),
        'w_wangwang_reply' => (float)$scheme['w_wangwang_reply'], 'tiers_wangwang_reply' => cs_perf_tiers_parse($scheme['tiers_wangwang_reply'] ?? ''),
        'w_avg_response' => (float)$scheme['w_avg_response'], 'tiers_avg_response' => cs_perf_tiers_parse($scheme['tiers_avg_response'] ?? ''),
        'floor_pct' => (float)$scheme['floor_pct'], 'cap_pct' => (float)$scheme['cap_pct'],
    ];
}
