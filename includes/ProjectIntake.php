<?php
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectBusiness.php';

function ps_intake_templates($category = null, $business = null)
{
    $sql = 'SELECT * FROM project_cost_templates WHERE is_active=1 AND requires_proof=0';
    $params = [];
    if ($category !== null) { $sql .= ' AND category=?'; $params[] = $category; }
    if ($business !== null) { $sql .= " AND (business_scope='' OR business_scope=?)"; $params[] = ps_business_normalize($business); }
    $sql .= ' ORDER BY category,name,specification,id DESC';
    $q = db()->prepare($sql);
    $q->execute($params);
    return $q->fetchAll();
}

function ps_intake_template($id, $category)
{
    $q = db()->prepare('SELECT * FROM project_cost_templates WHERE id=? AND category=? AND is_active=1 AND requires_proof=0');
    $q->execute([(int)$id, $category]);
    $template = $q->fetch();
    if (!$template) throw new RuntimeException('所选' . (['domain' => '域名', 'program' => '程序套餐'][$category] ?? '资源') . '成本模板不可用，请刷新后重选');
    return $template;
}

/** 按“程序名称”原文（如“5年JSP展示中级版”“PHP”）匹配程序套餐；无法唯一确定时返回 null 交给人工选择。 */
function ps_intake_program_suggestion($text, $templates)
{
    $text = preg_replace('/\s+/u', '', (string)$text);
    if ($text === '') return null;
    $years = preg_match('/^(\d+)年/u', $text, $m) ? (int)$m[1] : 1;
    $name = preg_replace('/^\d+年/u', '', $text);
    $matches = [];
    foreach ($templates as $template) {
        if ($template['category'] !== 'program' || preg_replace('/\s+/u', '', $template['name']) !== $name) continue;
        $specYears = preg_match('/^(\d+)年/u', $template['specification'], $sm) ? (int)$sm[1] : 0;
        if ($specYears && $specYears !== $years) continue;
        $matches[] = $template;
    }
    if (count($matches) === 1) return $matches[0];
    // 同名同年限常见“空间+域名 / 仅空间”两档：部门表默认含域名，技术可在预览中改选。
    $withDomain = array_values(array_filter($matches, function ($t) { return mb_strpos($t['specification'], '空间+域名') !== false; }));
    return count($withDomain) === 1 ? $withDomain[0] : null;
}

/**
 * 模板成本金额：固定价 × 数量；按售价百分比的模板（如华梦外包 80%）= 售价 × 百分比 × 数量。
 * 返回 [单价, 金额, 采购价金额]。
 */
function ps_template_cost_amount($template, $contract, $quantity = 1)
{
    if (($template['price_mode'] ?? 'fixed') === 'percent') {
        if ((float)$contract <= 0) throw new RuntimeException('“' . $template['name'] . '”按售价的 ' . rtrim(rtrim((string)$template['price'], '0'), '.') . '% 计算，请先填写售价');
        $unit = round((float)$contract * (float)$template['price'] / 100, 2);
        $supplier = $template['supplier_price'] !== null ? round((float)$contract * (float)$template['supplier_price'] / 100 * (float)$quantity, 2) : null;
    } else {
        $unit = round((float)$template['price'], 2);
        $supplier = $template['supplier_price'] !== null ? round((float)$template['supplier_price'] * (float)$quantity, 2) : null;
    }
    return [$unit, round($unit * (float)$quantity, 2), $supplier];
}

/** 标准价由成本中心确定：程序套餐与按售价比例的外包成本不受 ¥500 阈值限制；财务可把模板设为“不自动审”。 */
function ps_template_cost_status($template, $amount)
{
    if ((int)$template['auto_approve'] !== 1 || !empty($template['requires_proof'])) return 'pending';
    return ((float)$amount <= 500 || $template['category'] === 'program' || ($template['price_mode'] ?? 'fixed') === 'percent') ? 'approved' : 'pending';
}

function ps_intake_add_template_cost($orderId, $template, $actor, $origin)
{
    $contract = 0.0;
    if (($template['price_mode'] ?? 'fixed') === 'percent') {
        $q = db()->prepare('SELECT contract_amount FROM project_orders WHERE id=?');
        $q->execute([(int)$orderId]);
        $contract = (float)$q->fetchColumn();
    }
    [$price, $amount, $supplier] = ps_template_cost_amount($template, $contract);
    $status = ps_template_cost_status($template, $amount);
    $q = db()->prepare('INSERT INTO project_costs (order_id,template_id,template_version,category,item_name,quantity,unit,unit_price,amount,supplier_amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,?,?,?,?,1,?,?,?,?,?,0,?,?,?)');
    $q->execute([(int)$orderId, (int)$template['id'], (int)$template['version'], $template['category'], $template['name'] . ($template['specification'] ? ' · ' . $template['specification'] : ''), $template['unit'], $price, $amount, $supplier, $template['cost_kind'], $origin, $status, $actor['employee_id'] ?? null]);
    $costId = (int)db()->lastInsertId();
    ps_audit('cost', $costId, 'create_from_intake', $actor, ['order_id' => (int)$orderId, 'template_id' => (int)$template['id'], 'amount' => $amount, 'status' => $status, 'origin' => $origin]);
    return $costId;
}

function ps_intake_save_resources($orderId, $sourceType, $sourceLine, $domainTemplate, $serverTemplate, $sslAmount, $domainMode = null, $programTemplate = null)
{
    $q = db()->prepare('INSERT INTO project_order_resources (order_id,source_type,source_line,domain_mode,domain_template_id,server_template_id,program_template_id,ssl_expected_amount) VALUES (?,?,?,?,?,?,?,?)');
    $q->execute([(int)$orderId, $sourceType, $sourceLine, $domainMode ?? ($domainTemplate ? 'template' : 'none'), $domainTemplate['id'] ?? null, $serverTemplate['id'] ?? null, $programTemplate['id'] ?? null, $sslAmount !== null && (float)$sslAmount > 0 ? round((float)$sslAmount, 2) : null]);
}

/**
 * 客服先建档后的技术确认：程序套餐、域名、服务器三类模板成本在同一事务中各入账一次。
 * 程序套餐已含空间与域名，只选套餐时按“无需另购域名”处理。
 */
function ps_intake_confirm_resources($orderId, $mode, $domainTemplateId, $serverTemplateId, $actor, $programTemplateId = 0)
{
    $program = (int)$programTemplateId > 0 ? ps_intake_template((int)$programTemplateId, 'program') : null;
    if ($program && $mode === '') $mode = 'none';
    if (!in_array($mode, ['none','template'], true)) throw new RuntimeException('请选择域名使用方式或程序套餐');
    $domain = $mode === 'template' ? ps_intake_template((int)$domainTemplateId, 'domain') : null;
    $server = (int)$serverTemplateId > 0 ? ps_intake_template((int)$serverTemplateId, 'server') : null;
    $pending = db()->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=? FOR UPDATE');
    $pending->execute([(int)$orderId]);
    $current = $pending->fetchColumn();
    if ($current === false) {
        ps_intake_save_resources($orderId, 'manual', null, null, null, null, 'pending');
        $current = 'pending';
    }
    if ($current !== 'pending') throw new RuntimeException('资源已确认，请勿重复添加成本');
    db()->prepare('UPDATE project_order_resources SET domain_mode=?,domain_template_id=?,server_template_id=?,program_template_id=? WHERE order_id=?')
        ->execute([$mode, $domain['id'] ?? null, $server['id'] ?? null, $program['id'] ?? null, (int)$orderId]);
    if ($program) ps_intake_add_template_cost($orderId, $program, $actor, '技术确认：程序套餐');
    if ($domain) ps_intake_add_template_cost($orderId, $domain, $actor, '技术确认：域名');
    if ($server) ps_intake_add_template_cost($orderId, $server, $actor, '技术确认：服务器');
    return ['domain_template_id' => $domain['id'] ?? null, 'server_template_id' => $server['id'] ?? null, 'program_template_id' => $program['id'] ?? null];
}

/** 财务配置的默认岗位（如外包前端、售后、定制客服）优先于表格/表单里的通用岗位名，以匹配对应分成规则。 */
function ps_employee_default_role($employeeId, $business, $group)
{
    $q = db()->prepare('SELECT role_name FROM project_employee_roles WHERE employee_id=? AND business_name=? AND commission_group=?');
    $q->execute([(int)$employeeId, ps_business_normalize($business), $group]);
    return $q->fetchColumn() ?: null;
}

function ps_intake_participants($orderId, $groups, $business = null)
{
    $insert = db()->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,?)');
    $check = db()->prepare('SELECT 1 FROM employees WHERE id=?');
    foreach ($groups as $group => $people) {
        if (!$people) continue;
        $people = array_values($people);
        $count = count($people);
        $baseWeight = intdiv(1000000, $count);
        foreach ($people as $index => $person) {
            $employeeId = (int)$person['id'];
            $check->execute([$employeeId]);
            if (!$check->fetchColumn()) throw new RuntimeException('参与人不存在，请重新选择');
            $weight = ($index === $count - 1 ? 1000000 - $baseWeight * ($count - 1) : $baseWeight) / 1000000;
            $role = $business !== null ? (ps_employee_default_role($employeeId, $business, $group) ?? (string)$person['role']) : (string)$person['role'];
            $insert->execute([(int)$orderId, $employeeId, $group, $role, $weight]);
        }
    }
}

function ps_intake_domain_suggestion($resourceNote, $templates)
{
    $resourceNote = mb_strtolower(trim((string)$resourceNote));
    if (!preg_match('/\.(com|cn|net|org|top|xyz)(?:\b|\/|：|:|，|,|\s|$)/iu', $resourceNote, $match)) return null;
    $suffix = '.' . strtolower($match[1]);
    $matches = [];
    foreach ($templates as $template) {
        $label = mb_strtolower($template['name'] . ' ' . $template['specification']);
        if (strpos($label, $suffix) === false) continue;
        if ($template['cost_kind'] !== 'annual') continue;
        if (!preg_match('/(?:1\s*年|一年|12\s*个月|12\s*月)/u', $label)) continue;
        $matches[] = $template;
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/** 订单某组（技术 / 客服）是否已有参与人。 */
function ps_import_group_taken($orderId, $group)
{
    $q = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND commission_group=? LIMIT 1');
    $q->execute([(int)$orderId, $group]);
    return (bool)$q->fetchColumn();
}

function ps_import_date($value)
{
    $value = rtrim(trim((string)$value), '.。'); // 容忍手录多打的句点，如“8.30.”
    // 紧凑写法：260901（YYMMDD）、20260901（YYYYMMDD）——须先于 Excel 序列号判断
    if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $c) && checkdate((int)$c[2], (int)$c[3], 2000 + (int)$c[1])) return sprintf('%04d-%02d-%02d', 2000 + (int)$c[1], (int)$c[2], (int)$c[3]);
    if (preg_match('/^(20\d{2})(\d{2})(\d{2})$/', $value, $c) && checkdate((int)$c[2], (int)$c[3], (int)$c[1])) return sprintf('%04d-%02d-%02d', (int)$c[1], (int)$c[2], (int)$c[3]);
    if (is_numeric($value) && (float)$value > 30000) return gmdate('Y-m-d', ((int)$value - 25569) * 86400);
    // Excel 把 8.3 / 8.7 存成浮点，读出来是 8.300000000000001：先按两位小数还原成“月.日”。
    if (preg_match('/^\d{1,2}\.\d{3,}$/', $value) && (float)$value < 13) $value = rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    // 部门表常见写法：9.05 / 8.3（省略年份）、24.7.30（两位年份）、2026.8.3、8月3日。
    if (mb_strpos($value, '月') !== false) $value = str_replace(['年', '月', '日'], ['.', '.', ''], $value);
    if (preg_match('/^(?:(\d{2}|\d{4})[.\/-])?(\d{1,2})[.\/-](\d{1,2})$/', $value, $m)) {
        $month = (int)$m[2];
        $day = (int)$m[3];
        if ($m[1] === '') {
            $year = (int)date('Y');
            // 未写年份且日期晚于一个月后的，视为上一年的“以前月份订单”。
            if (checkdate($month, $day, $year) && strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day)) > strtotime('+31 days')) $year--;
        } else {
            $year = strlen($m[1]) === 2 ? 2000 + (int)$m[1] : (int)$m[1];
        }
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
    $time = strtotime(str_replace(['年','月','日','/','.'], ['-','-','','-','-'], $value));
    return $time ? date('Y-m-d', $time) : null;
}

/**
 * 表格姓名 → 合作人员。重名时依次按“已开通项目账号 + 能做当前业务”“部门对应当前业务”唯一确定，仍无法确定才报错。
 * $employeesByName 的每人可带 has_account（1/0）与 department。
 */
function ps_import_names($value, $employeesByName, $business = null)
{
    $out = [];
    foreach (preg_split('/[,，、\/]+/u', trim((string)$value)) as $name) {
        $name = trim(preg_replace('/^(软件开发部|外包)/u', '', trim($name)));
        if ($name === '' || $name === '无') continue;
        $candidates = $employeesByName[$name] ?? [];
        if (count($candidates) > 1 && $business !== null) {
            $narrowed = array_values(array_filter($candidates, function ($emp) use ($business) { return !empty($emp['has_account']) && in_array(ps_business_normalize($business), $emp['businesses'] ?? [], true); }));
            if (count($narrowed) !== 1) $narrowed = array_values(array_filter($candidates, function ($emp) use ($business) { return ps_business_fallback($emp['department'] ?? '') === ps_business_normalize($business) || (ps_business_fallback($emp['department'] ?? '') === '网站客服' && ps_is_website_order($business)); }));
            if (count($narrowed) === 1) $candidates = $narrowed;
        }
        if (!$candidates) throw new RuntimeException('合作人员“' . $name . '”不在人员名单中，请先在“人员与考勤”里添加');
        if (count($candidates) !== 1) throw new RuntimeException('合作人员“' . $name . '”有 ' . count($candidates) . ' 位重名，请在人员管理里区分姓名（如加部门后缀）');
        $id = (int)$candidates[0]['id'];
        $out[$id] = $name;
    }
    return $out;
}

/** 导入用的姓名索引：附带部门、是否开通项目账号及账号可做的业务，供重名判断。 */
function ps_import_employee_index()
{
    $index = [];
    $rows = db()->query('SELECT e.id,e.name,e.department,u.id AS user_id,u.role FROM employees e LEFT JOIN project_users u ON u.employee_id=e.id AND u.is_active=1')->fetchAll();
    foreach ($rows as $emp) {
        $emp['has_account'] = $emp['user_id'] ? 1 : 0;
        $emp['businesses'] = $emp['user_id'] ? ps_actor_businesses(['id' => (int)$emp['user_id'], 'employee_id' => (int)$emp['id'], 'role' => $emp['role']]) : [];
        $index[$emp['name']][] = $emp;
    }
    return $index;
}

function ps_import_domain_mode($text)
{
    $text = mb_strtolower(trim((string)$text));
    if (in_array($text, ['否','无','无需','无需域名','no','n'], true)) return 'none';
    if (in_array($text, ['是','有','yes','y'], true)) return 'template';
    return '';
}

/* ---------- 原始上传表格：保存、读取、权限 ---------- */

/** 保存上传的原始表格（站点目录外），返回记录 id。 */
function ps_import_file_store($file, $business, $actor)
{
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) throw new RuntimeException('文件仅支持 XLSX 或 CSV');
    $stored = ps_private_store('imports', $file['tmp_name'], date('Ym') . '_' . bin2hex(random_bytes(12)) . '.' . $ext);
    db()->prepare('INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([$business, mb_substr(basename((string)$file['name']), 0, 255), $stored, (int)$file['size'], $actor['type'], (int)$actor['id'], $actor['employee_id'] ?? null]);
    return (int)db()->lastInsertId();
}

/** 读取记录并校验权限：财务看全部，合作人员只看本人上传的。 */
function ps_import_file_get($id, $actor)
{
    $q = db()->prepare('SELECT * FROM project_import_files WHERE id=?');
    $q->execute([(int)$id]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('原始表格不存在');
    if ($actor['role'] !== 'finance' && ((int)$row['employee_id'] !== (int)($actor['employee_id'] ?? 0) || $row['uploaded_by_type'] !== $actor['type'])) throw new RuntimeException('只能查看本人上传的表格');
    $row['content'] = ps_private_read('imports', $row['stored_name']);
    if ($row['content'] === null) throw new RuntimeException('原始文件已不存在');
    return $row;
}

/** 解析全部工作表：['工作表名' => [[单元格...], ...]]；CSV 视为一张表。 */
function ps_import_file_sheets($row)
{
    // 解析需要真实文件路径（zip），临时复制到 /tmp，读完即删
    $row['path'] = tempnam(sys_get_temp_dir(), 'psx_');
    file_put_contents($row['path'], $row['content']);
    try { return ps_import_file_parse($row); } finally { @unlink($row['path']); }
}

function ps_import_file_parse($row)
{
    if (strtolower(pathinfo($row['stored_name'], PATHINFO_EXTENSION)) === 'csv') {
        $rows = [];
        $handle = fopen($row['path'], 'rb');
        while (($line = fgetcsv($handle)) !== false && count($rows) <= 5000) $rows[] = array_map(function ($v) { return mb_convert_encoding((string)$v, 'UTF-8', 'UTF-8,GBK,GB2312'); }, $line);
        fclose($handle);
        if ($rows) $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($rows[0][0] ?? ''));
        return ['CSV' => $rows];
    }
    if (class_exists('ZipArchive') && class_exists('XMLReader')) return ps_xlsx_sheets($row['path']);
    if (!class_exists('SimpleXLSX')) require_once __DIR__ . '/../classes/SimpleXLSX.php';
    // 无 zip 扩展时退回 SimpleXLSX（旧版本无 parseAll 时只读第一个工作表）
    return method_exists('SimpleXLSX', 'parseAll') ? SimpleXLSX::parseAll($row['path']) : ['工作表1' => SimpleXLSX::parse($row['path'])];
}

/**
 * 流式读取 xlsx 全部工作表（XMLReader，逐行读取）：部门表常带上百万行“有格式的空行”（整张表拉满到 1048576 行），
 * 一次性载入会耗尽内存。只保留到最后一行有内容为止，保持 Excel 行号不变；每表最多 20000 行。
 * 返回 ['工作表名' => [[单元格字符串...], ...]]。
 */
function ps_xlsx_sheets($path, $maxRows = 20000)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('无法打开 xlsx 文件');
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook === false) { $zip->close(); throw new RuntimeException('xlsx 缺少工作簿信息'); }
    $targets = [];
    if ($rels !== false && preg_match_all('/<Relationship\s[^>]*>/', $rels, $m)) foreach ($m[0] as $tag) {
        if (preg_match('/\sId="([^"]+)"/', $tag, $id) && preg_match('/\sTarget="([^"]+)"/', $tag, $target)) {
            $t = html_entity_decode($target[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $targets[$id[1]] = $t[0] === '/' ? ltrim($t, '/') : (strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t);
        }
    }
    $sheets = [];
    preg_match_all('/<sheet\s[^>]*>/', $workbook, $m);
    foreach ($m[0] as $i => $tag) {
        $name = preg_match('/\sname="([^"]*)"/', $tag, $n) ? html_entity_decode($n[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '工作表' . ($i + 1);
        $rid = preg_match('/\sr:id="([^"]+)"/', $tag, $r) ? $r[1] : '';
        $sheets[$name] = $targets[$rid] ?? ('xl/worksheets/sheet' . ($i + 1) . '.xml');
    }
    $shared = [];
    if ($zip->locateName('xl/sharedStrings.xml') !== false) {
        $reader = new XMLReader();
        $reader->open('zip://' . $path . '#xl/sharedStrings.xml');
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $node = new SimpleXMLElement($reader->readOuterXml());
                $text = '';
                foreach ($node->xpath('.//*[local-name()="t"]') as $t) $text .= (string)$t;
                $shared[] = $text; // 不调用 next()：外层 read() 会继续向下，调用 next() 会跳过下一个兄弟节点
            }
        }
        $reader->close();
    }
    $zip->close();
    $out = [];
    foreach ($sheets as $name => $file) {
        $rowsByNumber = [];
        $reader = new XMLReader();
        if (!@$reader->open('zip://' . $path . '#' . $file)) { $out[$name] = []; continue; }
        $count = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
            if ($reader->isEmptyElement) continue; // 有格式的空行
            $rowNumber = (int)$reader->getAttribute('r');
            $node = new SimpleXMLElement($reader->readOuterXml());
            $cells = [];
            $position = 0;
            foreach ($node->c as $c) {
                $ref = (string)$c['r'];
                $col = $position;
                if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $letters)) { $col = 0; foreach (str_split($letters[1]) as $ch) $col = $col * 26 + (ord($ch) - 64); $col--; }
                $position = $col + 1;
                $type = (string)$c['t'];
                if ($type === 's') $value = $shared[(int)$c->v] ?? '';
                elseif ($type === 'inlineStr') { $value = ''; foreach ($c->xpath('.//*[local-name()="t"]') as $t) $value .= (string)$t; }
                elseif ($type === 'b') $value = (string)$c->v === '1' ? 'TRUE' : 'FALSE';
                else $value = isset($c->v) ? (string)$c->v : '';
                if ($value !== '') $cells[$col] = $value;
            }
            if (!$cells) continue;
            $max = max(array_keys($cells));
            $row = [];
            for ($k = 0; $k <= $max; $k++) $row[] = $cells[$k] ?? '';
            $rowsByNumber[$rowNumber ?: (count($rowsByNumber) + 1)] = $row;
            if (++$count >= $maxRows) break;
        }
        $reader->close();
        // 补齐中间的空行，保持与 Excel 行号一致（第 1 行为表头）
        $rows = [];
        if ($rowsByNumber) {
            ksort($rowsByNumber);
            $last = (int)max(array_keys($rowsByNumber));
            for ($n = (int)min(array_keys($rowsByNumber)); $n <= $last; $n++) $rows[] = $rowsByNumber[$n] ?? [];
        }
        $out[$name] = $rows;
    }
    return $out;
}

function ps_import_file_mark($id, $status, $fields)
{
    $sets = ['status=?'];
    $values = [$status];
    foreach (['sheets_used', 'rows_total', 'imported_count', 'skipped_count'] as $key) if (array_key_exists($key, $fields)) { $sets[] = $key . '=?'; $values[] = $fields[$key]; }
    if ($status === 'imported') $sets[] = 'imported_at=NOW()';
    $values[] = (int)$id;
    db()->prepare('UPDATE project_import_files SET ' . implode(',', $sets) . ' WHERE id=?')->execute($values);
}
