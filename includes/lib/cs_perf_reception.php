<?php
/**
 * 设计客服绩效（店铺导出的“咨询接待分析”表）：解析、评分、排名取数。
 *
 * 每月初从店铺导出一张表（旺旺昵称、咨询/接待/有效接待人数、旺旺回复率、3分钟人工响应率、平均/首次响应时长、慢响应人数…），
 * 上传后按下面的指标逐项打 0~100 分，加权得到每人每店的综合分；多店取平均，部门内排名，前三名 850 / 800 / 750（金额规则仍是 CS_PERF_RANK_TIERS）。
 * 本月有这类上传数据时，cs_perf_rank_list 改用这里的分数（见 csr_store_scores），没有则沿用原来的四指标档位。
 *
 * 单项得分 = 在“最差值 → 最好值”之间线性插值，超出两端取 0 或 100。参数（权重、最差值、最好值）财务可在页面调整，存 cs_perf_reception_cfg。
 */

/** 评分指标：key => [标题, 单位, 默认权重, 最差值(0分), 最好值(100分), 说明] */
function csr_metric_defs()
{
    return [
        'reply_rate' => ['旺旺回复率',      '%', 25, 90,  100, '买家发来消息、客服回复了的比例'],
        'resp3_rate' => ['3分钟人工响应率', '%', 25, 95,  100, '3分钟内有人工回复的比例'],
        'avg_resp'   => ['平均响应时长',    '秒', 20, 30,  5,   '越快越好'],
        'first_resp' => ['首次响应时长',    '秒', 10, 40,  8,   '买家第一次发消息到客服第一次回复'],
        'slow_ratio' => ['慢响应占比',      '%', 10, 10,  0,   '慢响应人数 ÷ 接待人数，越低越好'],
        'valid_ratio' => ['有效接待率',     '%', 10, 40,  85,  '有效接待人数 ÷ 接待人数'],
    ];
}

function csr_ensure()
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS cs_perf_reception (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        year SMALLINT NOT NULL, month TINYINT NOT NULL,
        store VARCHAR(100) NOT NULL DEFAULT '',
        nick VARCHAR(100) NOT NULL,
        employee_id INT NOT NULL DEFAULT 0,
        grp VARCHAR(60) NOT NULL DEFAULT '',
        consult_cnt INT NULL, incoming_cnt INT NULL, valid_cnt INT NULL, noreply_cnt INT NULL, slow_cnt INT NULL,
        reply_rate DECIMAL(7,4) NULL, resp3_rate DECIMAL(7,4) NULL,
        avg_resp DECIMAL(10,2) NULL, first_resp DECIMAL(10,2) NULL, qa_ratio DECIMAL(8,2) NULL,
        source_file VARCHAR(255) NOT NULL DEFAULT '',
        uploaded_by_type VARCHAR(20) NOT NULL DEFAULT '', uploaded_by INT NOT NULL DEFAULT 0, uploaded_by_name VARCHAR(60) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_period_nick (year, month, store, nick), KEY idx_emp (employee_id, year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_dept_heads (department VARCHAR(100) NOT NULL, employee_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (department, employee_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT IGNORE INTO project_dept_heads (department, employee_id) SELECT ?, id FROM employees WHERE name=? LIMIT 1")->execute([CS_PERF_RANK_DEPT, CSR_HEAD_NAME]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS cs_perf_reception_cfg (k VARCHAR(40) NOT NULL PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 评分参数：默认值与财务保存的值合并。返回 key => ['weight','bad','good']。 */
function csr_params()
{
    $out = [];
    foreach (csr_metric_defs() as $k => $d) $out[$k] = ['weight' => (float)$d[2], 'bad' => (float)$d[3], 'good' => (float)$d[4]];
    try {
        $v = db()->query("SELECT v FROM cs_perf_reception_cfg WHERE k='params'")->fetchColumn();
        $saved = $v ? json_decode($v, true) : null;
        if (is_array($saved)) foreach ($out as $k => $p) if (isset($saved[$k]) && is_array($saved[$k])) {
            foreach (['weight', 'bad', 'good'] as $f) if (isset($saved[$k][$f]) && is_numeric($saved[$k][$f])) $out[$k][$f] = (float)$saved[$k][$f];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 保存评分参数（$input: key => ['weight','bad','good']，来自表单）。权重不能全为 0，最好值与最差值不能相等。 */
function csr_params_save(array $input)
{
    csr_ensure();
    $clean = []; $sum = 0.0;
    foreach (csr_metric_defs() as $k => $d) {
        $p = $input[$k] ?? [];
        $w = isset($p['weight']) && is_numeric($p['weight']) ? max(0.0, min(100.0, (float)$p['weight'])) : (float)$d[2];
        $bad = isset($p['bad']) && is_numeric($p['bad']) ? (float)$p['bad'] : (float)$d[3];
        $good = isset($p['good']) && is_numeric($p['good']) ? (float)$p['good'] : (float)$d[4];
        if ($bad == $good) throw new RuntimeException($d[0] . '的“最差值”和“最好值”不能相同');
        $clean[$k] = ['weight' => $w, 'bad' => $bad, 'good' => $good];
        $sum += $w;
    }
    if ($sum <= 0) throw new RuntimeException('至少要有一项权重大于 0');
    db()->prepare("INSERT INTO cs_perf_reception_cfg (k, v) VALUES ('params', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute([json_encode($clean, JSON_UNESCAPED_UNICODE)]);
    return $clean;
}

/** 指标原始值（百分比类用 0~100 的数；缺失为 null）。 */
function csr_metric_values(array $row)
{
    $inc = isset($row['incoming_cnt']) && $row['incoming_cnt'] !== null ? (int)$row['incoming_cnt'] : 0;
    $pct = function ($v) { return $v === null || $v === '' ? null : (float)$v * 100; };
    return [
        'reply_rate'  => $pct($row['reply_rate'] ?? null),
        'resp3_rate'  => $pct($row['resp3_rate'] ?? null),
        'avg_resp'    => ($row['avg_resp'] ?? null) === null || $row['avg_resp'] === '' ? null : (float)$row['avg_resp'],
        'first_resp'  => ($row['first_resp'] ?? null) === null || $row['first_resp'] === '' ? null : (float)$row['first_resp'],
        'slow_ratio'  => ($inc > 0 && ($row['slow_cnt'] ?? null) !== null) ? (float)$row['slow_cnt'] / $inc * 100 : null,
        'valid_ratio' => ($inc > 0 && ($row['valid_cnt'] ?? null) !== null) ? (float)$row['valid_cnt'] / $inc * 100 : null,
    ];
}

/** 一行（某人某店某月）的评分：['score'=>0~100,'parts'=>[key=>[label,unit,value,points,weight]]]；没有任何可评指标时 score=null。 */
function csr_score_row(array $row, array $params = null)
{
    $params = $params ?: csr_params();
    $values = csr_metric_values($row);
    $sum = 0.0; $wSum = 0.0; $parts = [];
    foreach (csr_metric_defs() as $k => $d) {
        $w = (float)($params[$k]['weight'] ?? 0);
        $v = $values[$k];
        if ($w <= 0 || $v === null) continue;
        $bad = (float)$params[$k]['bad']; $good = (float)$params[$k]['good'];
        $pts = max(0.0, min(1.0, ($v - $bad) / ($good - $bad))) * 100;
        $sum += $w * $pts; $wSum += $w;
        $parts[$k] = ['label' => $d[0], 'unit' => $d[1], 'value' => $v, 'points' => $pts, 'weight' => $w];
    }
    return ['score' => $wSum > 0 ? $sum / $wSum : null, 'parts' => $parts];
}

function csr_has_data($year, $month)
{
    try {
        $q = db()->prepare('SELECT 1 FROM cs_perf_reception WHERE year=? AND month=? AND employee_id>0 LIMIT 1');
        $q->execute([(int)$year, (int)$month]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) { return false; } // 表还没建（从没上传过）
}

/** 某员工某月各店的综合分：[店铺 => 0~1]，供 cs_perf_rank_list 取平均排名。 */
function csr_store_scores($employeeId, $year, $month)
{
    $out = [];
    try {
        $q = db()->prepare('SELECT * FROM cs_perf_reception WHERE employee_id=? AND year=? AND month=? ORDER BY store');
        $q->execute([(int)$employeeId, (int)$year, (int)$month]);
        $params = csr_params();
        foreach ($q->fetchAll() as $r) {
            $s = csr_score_row($r, $params);
            if ($s['score'] !== null) $out[(string)$r['store']] = $s['score'] / 100;
        }
    } catch (Throwable $e) {}
    return $out;
}

function csr_header_norm($s)
{
    return str_replace([' ', '　', "\xEF\xBB\xBF", '（', '）', "\n", "\r"], ['', '', '', '(', ')', '', ''], trim((string)$s));
}

/** 百分比单元格 → 0~1 的小数（支持 0.9971、99.71%、99.71）。 */
function csr_fraction($raw)
{
    if ($raw === null) return null;
    $s = trim(str_replace([',', '，'], '', (string)$raw));
    if ($s === '' || $s === '-' || $s === '--') return null;
    $hasPct = strpos($s, '%') !== false;
    $n = (float)str_replace('%', '', $s);
    if ($hasPct || $n > 1) $n /= 100;
    return max(0.0, min(1.0, $n));
}

function csr_number($raw)
{
    if ($raw === null) return null;
    $s = trim(str_replace([',', '，'], '', (string)$raw));
    if ($s === '' || $s === '-' || $s === '--') return null;
    return is_numeric($s) ? (float)$s : null;
}

/** 解析店铺导出表。返回 ['rows'=>[字段数组], 'error'=>'']。汇总值 / 平均值行会被跳过。 */
function csr_parse($path)
{
    require_once dirname(__DIR__, 2) . '/classes/SimpleXLSX.php';
    try { $sheets = SimpleXLSX::parseAll($path); }
    catch (Throwable $e) { return ['rows' => [], 'error' => '无法读取这个文件，请上传店铺导出的 .xlsx 表格']; }
    $aliases = [
        'nick' => ['旺旺昵称', '客服昵称', '客服账号', '旺旺账号', '客服', '昵称'], 'grp' => ['旺旺分组', '客服分组', '分组'],
        'consult' => ['咨询人数'], 'incoming' => ['接待人数'], 'valid' => ['有效接待人数'], 'noreply' => ['未回复人数'],
        'avg_resp' => ['平均响应时长'], 'first_resp' => ['首次响应时长'], 'resp3' => ['3分钟人工响应率'], 'reply' => ['旺旺回复率', '回复率'],
        'slow' => ['慢响应人数'], 'qa' => ['答问比'],
    ];
    foreach ($sheets as $rows) {
        if (!is_array($rows)) continue;
        foreach ($rows as $hi => $head) {
            if ($hi > 10) break;
            $norm = array_map('csr_header_norm', (array)$head);
            $col = [];
            foreach ($aliases as $f => $names) {
                foreach ($names as $name) {
                    foreach ($norm as $i => $h) if ($h !== '' && ($h === $name || strpos($h, $name) === 0)) { $col[$f] = $i; break 2; }
                }
            }
            if (!isset($col['nick']) || !isset($col['incoming'])) continue;
            $out = [];
            foreach (array_slice($rows, $hi + 1) as $line) {
                $line = (array)$line;
                $get = function ($f) use ($col, $line) { return isset($col[$f]) && isset($line[$col[$f]]) ? $line[$col[$f]] : null; };
                $nick = trim((string)$get('nick'));
                if ($nick === '' || in_array($nick, ['汇总值', '平均值', '合计', '总计', '汇总', '平均', '总和'], true)) continue;
                $dur = function ($f) use ($get) {
                    $v = $get($f);
                    if ($v === null || trim((string)$v) === '') return null;
                    return function_exists('parse_duration_to_seconds') ? (float)parse_duration_to_seconds($v) : csr_number($v);
                };
                $int = function ($f) use ($get) { $n = csr_number($get($f)); return $n === null ? null : (int)round($n); };
                $out[] = [
                    'nick' => $nick, 'grp' => trim((string)$get('grp')),
                    'consult_cnt' => $int('consult'), 'incoming_cnt' => $int('incoming'), 'valid_cnt' => $int('valid'), 'noreply_cnt' => $int('noreply'), 'slow_cnt' => $int('slow'),
                    'reply_rate' => csr_fraction($get('reply')), 'resp3_rate' => csr_fraction($get('resp3')),
                    'avg_resp' => $dur('avg_resp'), 'first_resp' => $dur('first_resp'), 'qa_ratio' => csr_number($get('qa')),
                ];
            }
            if ($out) return ['rows' => $out, 'error' => ''];
            return ['rows' => [], 'error' => '表里没有客服数据行'];
        }
    }
    return ['rows' => [], 'error' => '没有找到客服绩效表头（需要“旺旺昵称”和“接待人数”两列），请确认上传的是“咨询接待分析”导出表'];
}

/** 旺旺昵称 / 姓名 → 员工（employees.wangwang 可填多个，逗号或空格分隔；其次按姓名）。 */
function csr_employee_index()
{
    $byWang = []; $byName = [];
    foreach (db()->query('SELECT id, name, department, wangwang FROM employees')->fetchAll() as $e) {
        if (trim($e['name']) !== '') $byName[trim($e['name'])] = $e;
        $wws = str_replace(['，', '；', ';', ',', ' ', '　', "\t", "\r", "\n"], ',', (string)$e['wangwang']);
        foreach (explode(',', $wws) as $ww) {
            $ww = function_exists('normalize_cs_wangwang') ? normalize_cs_wangwang($ww) : trim($ww);
            if ($ww !== '') $byWang[mb_strtolower($ww)] = $e;
        }
    }
    return [$byWang, $byName];
}

/** 导入一份表：同月同店同昵称覆盖。返回 ['saved'=>n,'matched'=>n,'unmatched'=>[昵称…]]。 */
function csr_import($path, $source, $year, $month, $store, array $actor, $actorName = '')
{
    csr_ensure();
    $parsed = csr_parse($path);
    if ($parsed['error'] !== '') throw new RuntimeException($parsed['error']);
    list($byWang, $byName) = csr_employee_index();
    $store = trim((string)$store);
    $q = db()->prepare('INSERT INTO cs_perf_reception
        (year, month, store, nick, employee_id, grp, consult_cnt, incoming_cnt, valid_cnt, noreply_cnt, slow_cnt, reply_rate, resp3_rate, avg_resp, first_resp, qa_ratio, source_file, uploaded_by_type, uploaded_by, uploaded_by_name)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE employee_id=IF(VALUES(employee_id)>0, VALUES(employee_id), employee_id), grp=VALUES(grp), consult_cnt=VALUES(consult_cnt), incoming_cnt=VALUES(incoming_cnt),
        valid_cnt=VALUES(valid_cnt), noreply_cnt=VALUES(noreply_cnt), slow_cnt=VALUES(slow_cnt), reply_rate=VALUES(reply_rate), resp3_rate=VALUES(resp3_rate), avg_resp=VALUES(avg_resp),
        first_resp=VALUES(first_resp), qa_ratio=VALUES(qa_ratio), source_file=VALUES(source_file), uploaded_by_type=VALUES(uploaded_by_type), uploaded_by=VALUES(uploaded_by), uploaded_by_name=VALUES(uploaded_by_name)');
    $matched = 0; $unmatched = [];
    foreach ($parsed['rows'] as $r) {
        $emp = $byWang[mb_strtolower(function_exists('normalize_cs_wangwang') ? normalize_cs_wangwang($r['nick']) : $r['nick'])] ?? ($byName[$r['nick']] ?? null);
        if ($emp) $matched++; else $unmatched[] = $r['nick'];
        $q->execute([(int)$year, (int)$month, $store, $r['nick'], $emp ? (int)$emp['id'] : 0, $r['grp'], $r['consult_cnt'], $r['incoming_cnt'], $r['valid_cnt'], $r['noreply_cnt'], $r['slow_cnt'],
            $r['reply_rate'], $r['resp3_rate'], $r['avg_resp'], $r['first_resp'], $r['qa_ratio'], mb_substr((string)$source, 0, 255), (string)$actor['type'], (int)$actor['id'], mb_substr((string)$actorName, 0, 60)]);
    }
    if (function_exists('cs_perf_cache_reset')) cs_perf_cache_reset();
    return ['saved' => count($parsed['rows']), 'matched' => $matched, 'unmatched' => $unmatched];
}

/** 把还没对上人的昵称绑定到员工：回填已上传的行，并把昵称记到员工的旺旺账号里（下个月自动对上）。 */
function csr_bind($nick, $employeeId)
{
    csr_ensure();
    $nick = trim((string)$nick);
    $e = db()->prepare('SELECT id, wangwang FROM employees WHERE id=?');
    $e->execute([(int)$employeeId]);
    $emp = $e->fetch();
    if (!$emp || $nick === '') throw new RuntimeException('请选择要绑定的员工');
    db()->prepare('UPDATE cs_perf_reception SET employee_id=? WHERE nick=? AND employee_id=0')->execute([(int)$employeeId, $nick]);
    $have = array_filter(array_map('trim', preg_split('/[,，;；\s]+/', (string)$emp['wangwang'])));
    if (!in_array($nick, $have, true)) {
        $have[] = $nick;
        db()->prepare('UPDATE employees SET wangwang=? WHERE id=?')->execute([implode(',', $have), (int)$employeeId]);
    }
    if (function_exists('cs_perf_cache_reset')) cs_perf_cache_reset();
}

/** 删除某月某店的整份上传。返回删除行数。 */
function csr_delete_upload($year, $month, $store)
{
    csr_ensure();
    $q = db()->prepare('DELETE FROM cs_perf_reception WHERE year=? AND month=? AND store=?');
    $q->execute([(int)$year, (int)$month, (string)$store]);
    if (function_exists('cs_perf_cache_reset')) cs_perf_cache_reset();
    return $q->rowCount();
}

/** 某月全部上传行（含员工姓名），带评分。 */
function csr_month_rows($year, $month)
{
    csr_ensure();
    $q = db()->prepare('SELECT r.*, e.name AS emp_name, e.department FROM cs_perf_reception r LEFT JOIN employees e ON e.id=r.employee_id WHERE r.year=? AND r.month=? ORDER BY r.store, r.employee_id=0, r.nick');
    $q->execute([(int)$year, (int)$month]);
    $params = csr_params();
    $rows = $q->fetchAll();
    foreach ($rows as &$r) $r['scored'] = csr_score_row($r, $params);
    unset($r);
    return $rows;
}

/** 设计客服的主管：张光萍（登记在 project_dept_heads）；核算财务：刘群（后台账号 liuqun）。后台超级管理员 admin 同刘群权限。 */
const CSR_HEAD_NAME = '张光萍';
const CSR_MANAGER_ADMINS = ['admin', 'liuqun'];

/** 谁能看 / 上传：后台财务账号、设计客服的主管、设计客服部门的员工。 */
function csr_can_view($actor)
{
    if (!$actor) return false;
    if (($actor['type'] ?? '') === 'admin') return true;
    return csr_is_design_head($actor) || csr_employee_dept($actor) === CS_PERF_RANK_DEPT;
}

/** 谁能对应昵称、调整评分规则、删整份上传：核算财务刘群 / 超级管理员，以及设计客服的主管张光萍。 */
function csr_can_manage($actor)
{
    if (!$actor) return false;
    if (($actor['type'] ?? '') === 'admin') {
        try {
            $q = db()->prepare('SELECT username FROM admins WHERE id=?');
            $q->execute([(int)($actor['id'] ?? 0)]);
            return in_array((string)$q->fetchColumn(), CSR_MANAGER_ADMINS, true);
        } catch (Throwable $e) { return false; }
    }
    return csr_is_design_head($actor);
}

function csr_employee_dept($actor)
{
    if (empty($actor['employee_id'])) return '';
    try {
        $q = db()->prepare('SELECT department FROM employees WHERE id=?');
        $q->execute([(int)$actor['employee_id']]);
        return (string)$q->fetchColumn();
    } catch (Throwable $e) { return ''; }
}

/** 是不是设计客服部门的主管（project_dept_heads 里登记了“设计客服”）。 */
function csr_is_design_head($actor)
{
    if (empty($actor['employee_id'])) return false;
    try {
        $q = db()->prepare('SELECT 1 FROM project_dept_heads WHERE department=? AND employee_id=? LIMIT 1');
        $q->execute([CS_PERF_RANK_DEPT, (int)$actor['employee_id']]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) { return false; }
}
