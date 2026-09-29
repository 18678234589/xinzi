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

/**
 * 商标订单的资料专员、提交专员分别上传同一单：技术组按岗位区分，同岗位已有人时不再追加。
 * 返回可加入的岗位名；本人已在单上或同岗位已有人时返回 null。
 */
function ps_trademark_technical_role_open($orderId, $employeeId, $fallbackRole)
{
    $role = ps_employee_default_role($employeeId, '商标', 'technical') ?? (string)$fallbackRole;
    $q = db()->prepare("SELECT employee_id,role_name FROM project_participants WHERE order_id=? AND commission_group='technical'");
    $q->execute([(int)$orderId]);
    foreach ($q->fetchAll() as $p) if ((int)$p['employee_id'] === (int)$employeeId || $p['role_name'] === $role) return null;
    return $role;
}

/** 追加一名商标技术参与人，并把技术组权重重新均分（审核要求组内合计 100%；商标技术按件计，权重不影响金额）。调用方负责事务。 */
function ps_trademark_add_technical($orderId, $employeeId, $role)
{
    db()->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical',?,0)")->execute([(int)$orderId, (int)$employeeId, $role]);
    $q = db()->prepare("SELECT id FROM project_participants WHERE order_id=? AND commission_group='technical' ORDER BY id");
    $q->execute([(int)$orderId]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    $base = intdiv(1000000, count($ids));
    $update = db()->prepare('UPDATE project_participants SET group_weight=? WHERE id=?');
    foreach ($ids as $i => $id) $update->execute([($i === count($ids) - 1 ? 1000000 - $base * (count($ids) - 1) : $base) / 1000000, (int)$id]);
}

/**
 * 商标部原表整理：日期与店铺互换（“美呀美 | 46236”）、网报加急空一格使件数落到“设计”列、网报加急列写“8.10发货”、
 * 日期带“晚 / 上午”等字样时按内容归位；合计 / 底薪 / 提成等汇总行返回 null，不当作订单。
 */
function ps_trademark_fix_row($row, $map)
{
    $get = function ($key) use (&$row, $map) { return isset($map[$key]) ? trim((string)($row[$map[$key]] ?? '')) : ''; };
    if (isset($map['order_date']) && preg_match('/^(\d{4}[.\/-]\d{1,2}[.\/-]\d{1,2})\s*(?:早上|上午|中午|下午|晚上|早|晚)$/u', $get('order_date'), $m)) $row[$map['order_date']] = $m[1];
    $orderNo = $get('order_no');
    // 订单号可带前缀（如“致2026081303”）：含 6 位以上连续数字即视为订单行
    if (!preg_match('/^[A-Za-z0-9_-]{8,}$/', $orderNo) && !preg_match('/\d{6,}/', $orderNo) && $get('payment_reference') === '') {
        if ($orderNo !== '' && !ps_import_date($get('order_date')) && !ps_import_date($get('shop'))) return null;
        if (preg_match('/合计|总计|底薪|全勤|提成|单价|出勤|请假/u', implode(' ', array_map('strval', $row)))) return null;
    }
    if (isset($map['order_date'], $map['shop']) && !ps_import_date($get('order_date')) && $get('shop') !== '' && ps_import_date($get('shop'))) {
        [$row[$map['order_date']], $row[$map['shop']]] = [$row[$map['shop']], $row[$map['order_date']]];
    }
    if (isset($map['detail:trademark_count'])) {
        $count = '';
        $texts = [];
        $shipNotes = [];
        foreach (['detail:service_type', 'detail:trademark_count', 'trademark_extra'] as $key) {
            $value = $get($key);
            if ($value === '') continue;
            if (is_numeric($value)) { if ($count === '' && $key !== 'detail:service_type') $count = $value; continue; }
            if (preg_match('/^\d{1,2}[.\/月]\d{1,2}日?\s*(?:已)?发货$/u', $value)) $shipNotes[] = $value; else $texts[] = $value;
        }
        $row[$map['detail:trademark_count']] = $count;
        if (isset($map['detail:service_type'])) $row[$map['detail:service_type']] = implode(' ', $texts ?: $shipNotes);
        if (isset($map['trademark_extra'])) $row[$map['trademark_extra']] = '';
    }
    return $row;
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
    $names = [];
    foreach (preg_split('/[,，、\/]+/u', trim((string)$value)) as $name) {
        $name = trim(preg_replace('/^(软件开发部|外包)/u', '', trim($name)));
        // 两个姓名连写没加分隔（如“朱俊英王宁”）：能完整拆成名单里的姓名时按多人处理
        $parts = !isset($employeesByName[$name]) ? ps_import_split_joined_names($name, $employeesByName) : null;
        foreach ($parts ?: [$name] as $part) $names[] = $part;
    }
    foreach ($names as $name) {
        if ($name === '' || $name === '无') continue;
        $candidates = $employeesByName[$name] ?? [];
        if (count($candidates) > 1 && $business !== null) {
            $narrowed = array_values(array_filter($candidates, function ($emp) use ($business) { return !empty($emp['has_account']) && in_array(ps_business_normalize($business), $emp['businesses'] ?? [], true); }));
            if (count($narrowed) !== 1) $narrowed = array_values(array_filter($candidates, function ($emp) use ($business) { return ps_business_fallback($emp['department'] ?? '') === ps_business_normalize($business) || (ps_business_fallback($emp['department'] ?? '') === '网站客服' && ps_is_website_order($business)); }));
            // 仍不能确定时：只有一位开通了有效项目账号的（另一位多为离职或早期重复录入的档案），取这一位
            if (count($narrowed) !== 1) $narrowed = array_values(array_filter($candidates, function ($emp) { return !empty($emp['has_account']); }));
            if (count($narrowed) === 1) $candidates = $narrowed;
        }
        if (!$candidates) throw new RuntimeException('合作人员“' . $name . '”不在人员名单中，请先在“人员与考勤”里添加');
        if (count($candidates) !== 1) throw new RuntimeException('合作人员“' . $name . '”有 ' . count($candidates) . ' 位重名，请在人员管理里区分姓名（如加部门后缀）');
        $id = (int)$candidates[0]['id'];
        $out[$id] = $name;
    }
    return $out;
}

/** 连写姓名拆分：整段能恰好拆成 2 个以上名单内姓名时返回拆分结果，否则 null。 */
function ps_import_split_joined_names($text, $employeesByName)
{
    $length = mb_strlen($text);
    if ($length < 4 || $length > 16 || !preg_match('/^\p{Han}+$/u', $text)) return null;
    $walk = function ($offset) use (&$walk, $text, $length, $employeesByName) {
        if ($offset === $length) return [];
        for ($size = min(4, $length - $offset); $size >= 2; $size--) {
            $part = mb_substr($text, $offset, $size);
            if (!isset($employeesByName[$part])) continue;
            $rest = $walk($offset + $size);
            if ($rest !== null) return array_merge([$part], $rest);
        }
        return null;
    };
    $parts = $walk(0);
    return $parts && count($parts) >= 2 ? $parts : null;
}

/**
 * 宽松解析人员列（用于制作技术 / 协作技术）：不在人员名单中的写法（常见是把项目名称填进了技术列）不拦截整行，
 * 放进 $unknown 由调用方提示并忽略；重名等真正需要区分的人员问题仍抛出。
 */
function ps_import_names_lenient($value, $employeesByName, $business, &$unknown)
{
    $out = [];
    foreach (preg_split('/[,，、\/]+/u', trim((string)$value)) as $token) {
        if (trim($token) === '') continue;
        try {
            $out += ps_import_names($token, $employeesByName, $business);
        } catch (RuntimeException $e) {
            if (mb_strpos($e->getMessage(), '不在人员名单中') === false) throw $e;
            $unknown[] = trim($token);
        }
    }
    return $out;
}

/**
 * 业务说明字段自动补全：表格没填的，用前面已有的信息补上，避免重复填写——
 * 名称 / 内容类字段取“业务”列描述（或技术列里误填的项目名），客户微信取备注或付款昵称。只补空字段。
 */
function ps_import_autofill_details($details, $businessText, $contactNote, $paymentNickname, $fallbackName = '')
{
    $description = trim((string)$businessText) !== '' ? trim((string)$businessText) : trim((string)$fallbackName);
    foreach (['miniapp_name', 'service_item', 'make_requirement', 'design_item', 'renew_item'] as $key) {
        if (array_key_exists($key, $details) && trim((string)$details[$key]) === '' && $description !== '') $details[$key] = mb_substr($description, 0, 300);
    }
    if (array_key_exists('customer_wechat', $details) && trim((string)$details['customer_wechat']) === '') {
        $contact = trim((string)$contactNote) !== '' ? trim((string)$contactNote) : trim((string)$paymentNickname);
        if ($contact !== '') $details['customer_wechat'] = mb_substr($contact, 0, 300);
    }
    return $details;
}

/**
 * 因填写问题导致无法识别时的“怎么改”指引：返回 ['key','title','how','example']；非填写问题（需财务核对等）返回 null。
 * 用于预览页弹窗与红色行下的提示，让上传人一眼看懂该怎么填。
 */
function ps_import_fix_guide($message, $business = '')
{
    $message = (string)$message;
    $kinds = $business ? ps_business_order_kinds($business) : [];
    $guides = [
        ['/日期无法识别|日期“.*”无法识别/u', 'date', '日期写法无法识别', '“日期”列写订单日期即可，支持 年-月-日、月.日、X月X日、8 位数字；不要写时间、星期或文字。没有日期的行可在预览里直接补填。', '2026-09-01　9.1　9月1日　20260901'],
        ['/^状态“/u', 'status', '状态写法无法识别', '“状态”列写 已完成 或 未完成；也可写 到账、已发货、交易关闭。空着按未完成处理。', '已完成　未完成　到账'],
        ['/缺少店铺订单号或支付流水号/u', 'order_no', '缺少订单号', '“订单编号”列填店铺（淘宝等）订单号；微信 / 对公收款没有订单号的，在“微信交易流水号”列填账单里的交易单号，二选一即可。也可在预览里直接补填。', '3316440471002001958　或　4200001234202609011234567890'],
        ['/订单号或售价无效/u', 'amount', '售价或订单号写法不对', '“售价”列只写数字（最多两位小数），不要带“元”、文字或写两个金额；订单号不超过 100 个字符。', '350　1280.50'],
        ['/不在人员名单中/u', 'person', '姓名对不上人员名单', '“客服”“技术”列只写系统里登记的姓名，多人用“、”隔开；不要写昵称、工号、项目名或把两人名字连在一起。名单里确实没有的人请联系财务添加。', '王宁　王宁、朱俊英'],
        ['/位重名/u', 'duplicate', '姓名有重名', '系统里有同名的人，无法确定是哪一位。请联系财务在人员管理里区分（如加部门后缀），再按区分后的姓名填写。', '王宁（标书）'],
        ['/须指定接单技术/u', 'tech_missing', '没写接单技术', '客服上传新订单时，“技术”列须写接单技术的姓名；写了但仍提示的，是该姓名不在人员名单里（写错字或还没登记），请核对或联系财务添加。', '石凯新'],
        ['/还没有开通项目账号/u', 'tech_account', '技术还没开通账号', '表格里写的技术还没有项目账号，请联系财务开通后重新上传；或确认技术姓名是否写对。', ''],
        ['/SSL 真实成本无效/u', 'ssl', 'SSL 成本写法不对', '“SSL证书使用”列写真实成本数字（只写数字，不带“元”）；没用证书写 0 或 无。', '0　无　68'],
        ['/表格写的业务是/u', 'business', '业务选错了', '“业务”列写的业务和当前选择的业务模板不一致：请在页面上方切换到对应业务后再上传，或把“业务”列改成具体项目描述。', '小程序商城搭建（写做什么，不写别的业务名）'],
        ['/订单类型“.*”无效/u', 'kind', '订单类型写法不对', '“订单类型”列只能写：' . implode('、', $kinds) . '。不确定可留空，系统会按描述自动预选。', implode('　', array_slice($kinds, 0, 3))],
        ['/缺少“订单编号”或“微信交易流水号”列|没有与“.*”表头对应的工作表|缺少客服或技术列/u', 'header', '表头对不上', '表格第 1 行须是表头，至少要有“订单编号”（或“微信交易流水号”）、“日期”、“售价”列；财务上传还要有“客服”或“技术”列。最省事：下载下方模板，把数据按列粘贴进去再上传。', '日期 | 店铺 | 付款昵称 | 订单编号 | 售价 | 状态 | 客服 | 技术'],
    ];
    foreach ($guides as [$pattern, $key, $title, $how, $example]) if (preg_match($pattern, $message)) return ['key' => $key, 'title' => $title, 'how' => $how, 'example' => $example];
    return null;
}

/**
 * 导入后仍需上传人补全的行：真实订单（有金额）但缺订单号或日期。$keepAll 时（弹窗补填后）凡仍未通过的行都保留，附上原因。
 * 返回弹窗展示用的精简行。
 */
function ps_import_followup_rows($preview, $keepAll = false)
{
    $rows = [];
    foreach ($preview as $row) {
        if (!empty($row['base_valid']) || in_array($row['status'] ?? '', ['已导入过', '他人订单'], true)) continue;
        $needOrder = empty($row['order_no']);
        $needDate = empty($row['order_date']);
        if (!$keepAll && !$needOrder && !$needDate) continue;
        $text = trim((string)($row['business_text'] ?? ''));
        if ($text === '') foreach ((array)($row['details'] ?? []) as $value) if (trim((string)$value) !== '') { $text = trim((string)$value); break; }
        $rows[] = ['line' => (int)$row['line'], 'sheet' => (string)($row['sheet'] ?? ''), 'order_no' => (string)($row['order_no'] ?? ''), 'order_date' => (string)($row['order_date'] ?? ''), 'suggested_date' => (string)($row['suggested_date'] ?? ''), 'nickname' => (string)($row['payment_nickname'] ?? ''), 'amount' => (string)($row['contract_amount'] ?? ''), 'text' => mb_substr($text, 0, 60), 'need_order' => $needOrder, 'need_date' => $needDate, 'error' => (string)($row['error'] ?? '')];
    }
    return $rows;
}

/** 保存 / 关闭待补全记录；新建时发站内信。只对合作人员本人上传的表格。表未迁移时静默跳过。 */
function ps_import_followup_save($fileId, $actor, $business, $scope, $sheets, $rows)
{
    if (($actor['type'] ?? '') !== 'employee' || !$fileId) return null;
    try {
        if (!$rows) { db()->prepare("UPDATE project_import_followups SET status='done' WHERE file_id=?")->execute([(int)$fileId]); return null; }
        $existing = db()->prepare('SELECT id,status FROM project_import_followups WHERE file_id=?');
        $existing->execute([(int)$fileId]);
        $before = $existing->fetch();
        db()->prepare("INSERT INTO project_import_followups (file_id,user_id,employee_id,business_name,scope,sheets,rows_json,status) VALUES (?,?,?,?,?,?,?,'open') ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),employee_id=VALUES(employee_id),business_name=VALUES(business_name),scope=VALUES(scope),sheets=VALUES(sheets),rows_json=VALUES(rows_json),status='open'")
            ->execute([(int)$fileId, (int)$actor['id'], (int)$actor['employee_id'], $business, $scope, mb_substr(implode('、', (array)$sheets), 0, 500), json_encode(array_values($rows), JSON_UNESCAPED_UNICODE)]);
        $existing->execute([(int)$fileId]);
        $id = (int)$existing->fetchColumn();
        if (!$before || $before['status'] !== 'open') {
            $name = db()->prepare('SELECT original_name FROM project_import_files WHERE id=?');
            $name->execute([(int)$fileId]);
            db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')->execute([(int)$actor['employee_id'], 'import_fix', mb_substr('《' . $name->fetchColumn() . '》有 ' . count($rows) . ' 行缺订单号或日期，请补全', 0, 160), '其余正确的订单已导入。缺订单号或日期的行没有入账，打开任意页面会弹出补填窗口，填好后点“提交并导入”即可；不是订单的行勾选“不是订单”即可。', '/project/import.php?followup=' . $id, 'import-fix:' . $id . ':' . date('YmdHis')]);
        }
        return $id;
    } catch (PDOException $e) {
        return null;
    }
}

/** 本人名下的一条待补全记录（含解码后的行）。 */
function ps_import_followup_get($id, $actor)
{
    $q = db()->prepare("SELECT * FROM project_import_followups WHERE id=? AND user_id=? AND status='open'");
    $q->execute([(int)$id, (int)$actor['id']]);
    $row = $q->fetch();
    if (!$row || ($actor['type'] ?? '') !== 'employee') return null;
    $row['rows'] = json_decode($row['rows_json'], true) ?: [];
    return $row;
}

/** 模板示例行：每列给一个正确写法；订单号以“示例”开头，上传时自动跳过，忘删也不会入账。 */
function ps_business_import_example_row($business, $headers)
{
    $columns = ps_business_import_columns($business);
    $kinds = ps_business_order_kinds($business);
    $samples = ['order_date' => '2026-09-01', 'shop' => '美呀美旗舰店', 'business' => '写具体做什么，如 小程序商城搭建', 'payment_nickname' => 'tb12345678', 'payment_reference' => '', 'order_no' => '示例-3316440471002001958（本行可删，上传时自动跳过）', 'contract_amount' => '350', 'status' => '已完成', 'contact_note' => '13800000000', 'customer_service' => '王宁', 'frontend' => '石凯新', 'backend' => '', 'order_kind' => $kinds[0] ?? '', 'program_name' => '森动中级版', 'domain_used' => '否', 'ssl_used' => '0', 'resource_note' => 'www.example.com', 'direct_cost' => '120', 'direct_cost2' => '0'];
    $row = [];
    foreach ($headers as $label) {
        $value = '';
        foreach ($columns as $key => $aliases) if (in_array($label, $aliases, true)) { $value = $samples[$key] ?? (strpos($key, 'detail:') === 0 ? '按实际填写' : ''); break; }
        $row[] = $value;
    }
    return $row;
}

/** 模板示例行（任一单元格以“示例”开头）：导入时跳过。 */
function ps_import_row_is_example($row)
{
    foreach ((array)$row as $cell) if (mb_strpos(trim((string)$cell), '示例') === 0) return true;
    return false;
}

/** 首行像数据而不是表头（含订单号样式的长数字串）：用于识别没有表头、直接从第 1 行写订单的表格。 */
function ps_import_row_is_data($row)
{
    foreach ((array)$row as $cell) if (preg_match('/^[A-Za-z0-9-]{12,}$/', trim((string)$cell)) && preg_match_all('/\d/', (string)$cell) >= 10) return true;
    return false;
}

/**
 * 没有表头的表格：按各列内容识别列含义（订单号 / 日期 / 店铺 / 售价 / 手机 / 客服 / 技术 / 付款昵称 / 状态）。
 * 识别不出订单号列，或日期、售价都识别不出时返回 null。
 */
function ps_import_headerless_map($rows, $knownShops, $employeesByName)
{
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
    $pick('contact_note', function ($v) { return (bool)preg_match('/^1[3-9]\d{9}$/', $v); });
    $pick('contract_amount', function ($v) { return is_numeric(str_replace([',', '¥', '￥'], '', $v)) && (float)str_replace([',', '¥', '￥'], '', $v) < 1000000; }, $map['order_no']);
    $pick('shop', function ($v) use ($knownShops) { foreach ($knownShops as $shop) if ($v === $shop || mb_strpos($shop, $v) !== false || mb_strpos($v, $shop) !== false) return true; return false; });
    // 人员列：姓名都在人员名单中；按账号角色区分客服列与技术列，角色不明时先客服后技术
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
    // 客服列后紧跟一列中文短名、但不在名单里（如负责传资料的同事）：当作技术列，导入时按“非合作人员”提示后忽略
    if (isset($map['customer_service']) && !isset($map['frontend'])) {
        $next = $map['customer_service'] + 1;
        if (!empty($columns[$next]) && $free($next) && $share($next, function ($v) { return (bool)preg_match('/^\p{Han}{2,4}$/u', $v); }) >= 0.6) $map['frontend'] = $next;
    }
    $pick('status', function ($v) { return $v !== '' && ps_import_delivery_status($v) !== null; });
    // 付款昵称：订单号前最近的一个未识别的文字列（淘宝表常见顺序：日期、店铺、付款账号、订单编号）
    for ($i = $map['order_no'] - 1; $i >= 0; $i--) if ($columns[$i] && $free($i)) { if ($share($i, function ($v) { return !is_numeric($v); }) >= 0.6) $map['payment_nickname'] = $i; break; }
    if (!isset($map['order_date']) && !isset($map['contract_amount'])) return null;
    return $map;
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

function ps_import_kind_preference($employeeId, $business, $layoutSignature)
{
    if (!$employeeId || !$layoutSignature) return '';
    try {
        $q = db()->prepare('SELECT order_kind FROM project_import_kind_preferences WHERE employee_id=? AND business_name=? AND layout_signature IN (?, ?) ORDER BY (source=?) DESC, (layout_signature=?) DESC LIMIT 1');
        $q->execute([(int)$employeeId, $business, $layoutSignature, '*', 'finance', '*']);
        $kind = (string)$q->fetchColumn();
        return in_array($kind, ps_business_order_kinds($business), true) ? $kind : '';
    } catch (PDOException $e) { return ''; }
}

function ps_import_kind_preference_save($employeeId, $business, $layoutSignature, $kind)
{
    if (!$employeeId || !$layoutSignature || !in_array($kind, ps_business_order_kinds($business), true)) return;
    db()->prepare("INSERT INTO project_import_kind_preferences (employee_id,business_name,layout_signature,order_kind) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE order_kind=VALUES(order_kind),confirmed_count=confirmed_count+1,updated_at=NOW()")
        ->execute([(int)$employeeId, $business, $layoutSignature, $kind]);
}

/** 表头加工作表名作为版式指纹，不把上传时所选业务写进指纹。 */
function ps_import_business_signature($head, $sheetName)
{
    return hash('sha256', json_encode([mb_strtolower(trim((string)$sheetName)), array_map(function ($value) { return mb_strtolower(trim((string)$value)); }, $head)], JSON_UNESCAPED_UNICODE));
}

/** 在当前账号获准的业务中判定整份表的归属。只让明确证据自动覆盖默认业务。 */
function ps_import_business_detect($fileRow, $allowedBusinesses, $selectedBusiness, $employeeId = 0)
{
    if (count($allowedBusinesses) <= 1) return ['business' => $selectedBusiness, 'reason' => '账户唯一业务'];
    $explicit = []; $orderNos = []; $signatures = []; $scores = array_fill_keys($allowedBusinesses, 0);
    foreach (array_slice(ps_import_file_sheets($fileRow), 0, 5, true) as $sheetName => $rows) {
        if (count($rows) < 2) continue;
        $head = array_map(function ($value) { return trim((string)$value); }, array_shift($rows));
        $signatures[] = ps_import_business_signature($head, $sheetName);
        foreach (['订单编号','订单号','订单','淘宝订单号','编码或者订单号'] as $alias) {
            $index = array_search($alias, $head, true);
            if ($index !== false) { foreach (array_slice($rows, 0, 20) as $row) { $no = trim((string)($row[$index] ?? '')); if ($no !== '') $orderNos[$no] = true; } break; }
        }
        foreach (['业务类型','项目类型','业务'] as $alias) {
            $index = array_search($alias, $head, true);
            if ($index !== false) { foreach (array_slice($rows, 0, 30) as $row) { $business = ps_business_normalize($row[$index] ?? ''); if (in_array($business, $allowedBusinesses, true)) $explicit[$business] = true; } break; }
        }
        foreach ($allowedBusinesses as $business) {
            try { $map = ps_business_import_map($business, $head, false); } catch (RuntimeException $e) { continue; }
            $scores[$business] += 1;
            if (mb_strpos(mb_strtolower((string)$sheetName), mb_strtolower($business)) !== false) $scores[$business] += 10;
            foreach ($map as $key => $index) {
                if (strpos($key, 'detail:') === 0 || in_array($key, ['program_name','domain_used','ssl_used','resource_note','ppt_marker'], true)) $scores[$business] += 3;
                elseif (in_array($key, ['direct_cost2','pay_mode'], true)) $scores[$business] += 2;
            }
        }
    }
    if (count($explicit) === 1) return ['business' => array_key_first($explicit), 'reason' => '表格业务列'];
    if ($orderNos) {
        $numbers = array_slice(array_keys($orderNos), 0, 30);
        $q = db()->prepare('SELECT DISTINCT project_type FROM project_orders WHERE order_no IN (' . implode(',', array_fill(0, count($numbers), '?')) . ')');
        $q->execute($numbers);
        $known = array_values(array_unique(array_filter(array_map('ps_business_normalize', $q->fetchAll(PDO::FETCH_COLUMN)), function ($name) use ($allowedBusinesses) { return in_array($name, $allowedBusinesses, true); })));
        if (count($known) === 1) return ['business' => $known[0], 'reason' => '已有关联订单号'];
    }
    if ($employeeId && $signatures) {
        try {
            $q = db()->prepare('SELECT DISTINCT business_name FROM project_import_business_preferences WHERE employee_id=? AND layout_signature IN (' . implode(',', array_fill(0, count($signatures), '?')) . ')');
            $q->execute(array_merge([(int)$employeeId], $signatures));
            $saved = array_values(array_filter($q->fetchAll(PDO::FETCH_COLUMN), function ($name) use ($allowedBusinesses) { return in_array($name, $allowedBusinesses, true); }));
            if (count($saved) === 1) return ['business' => $saved[0], 'reason' => '本人同版式历史导入'];
        } catch (PDOException $e) { /* 升级数据库前仍可按表头判断。 */ }
    }
    arsort($scores);
    $ranked = array_keys($scores);
    if ($ranked && $scores[$ranked[0]] >= 4 && $scores[$ranked[0]] >= ($scores[$ranked[1]] ?? 0) + 3) return ['business' => $ranked[0], 'reason' => '表头与工作表特征'];
    return ['business' => $selectedBusiness, 'reason' => '未找到唯一业务特征，沿用账户默认业务'];
}

/** 成功导入后才记忆归属；预览/AI 猜测不学习，避免错误自我强化。 */
function ps_import_business_preference_save($employeeId, $signature, $business)
{
    if (!$employeeId || !$signature || !isset(ps_business_catalog()[$business])) return;
    try {
        db()->prepare('INSERT INTO project_import_business_preferences (employee_id,layout_signature,business_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE business_name=VALUES(business_name),confirmed_count=confirmed_count+1,updated_at=NOW()')->execute([(int)$employeeId, $signature, $business]);
    } catch (PDOException $e) { /* 尚未执行迁移时不阻止订单导入。 */ }
}

/* ---------- 原始上传表格：保存、读取、权限 ---------- */

/** 保存上传的原始表格（站点目录外），返回记录 id。 */
function ps_import_file_store($file, $business, $actor, $parsedFile = null)
{
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv', 'xls'], true)) throw new RuntimeException('文件仅支持 XLSX、XLS 或 CSV');
    if ($ext === 'xls' && (!$parsedFile || ($parsedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || strtolower(pathinfo((string)$parsedFile['name'], PATHINFO_EXTENSION)) !== 'xlsx' || (int)($parsedFile['size'] ?? 0) > 25 * 1024 * 1024)) throw new RuntimeException('旧版 XLS 转换失败或文件过大，请使用新版浏览器重试或另存为 XLSX');
    $stored = ps_private_store('imports', $file['tmp_name'], date('Ym') . '_' . bin2hex(random_bytes(12)) . '.' . $ext);
    $parsed = $ext === 'xls' ? ps_private_store('imports', $parsedFile['tmp_name'], date('Ym') . '_' . bin2hex(random_bytes(12)) . '.xlsx') : null;
    db()->prepare('INSERT INTO project_import_files (business_name,original_name,stored_name,parse_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$business, ps_import_original_name($file['name']), $stored, $parsed, (int)$file['size'], $actor['type'], (int)$actor['id'], $actor['employee_id'] ?? null]);
    return (int)db()->lastInsertId();
}

/** 不依赖服务器 locale 的 basename()，保留中文文件名并去掉浏览器可能携带的 Windows 路径。 */
function ps_import_original_name($name)
{
    $name = str_replace('\\', '/', (string)$name);
    $position = strrpos($name, '/');
    return mb_substr($position === false ? $name : substr($name, $position + 1), 0, 255);
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
    if (!empty($row['parse_name'])) {
        $row['parse_content'] = ps_private_read('imports', $row['parse_name']);
        if ($row['parse_content'] === null) throw new RuntimeException('转换后的解析文件已不存在');
    }
    return $row;
}

/** 删除原始表格记录与私有文件：权限同查看（财务可删任何，合作人员只删本人上传的）；已导入的订单不受影响。 */
function ps_import_file_delete($id, $actor)
{
    $q = db()->prepare('SELECT * FROM project_import_files WHERE id=?');
    $q->execute([(int)$id]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('原始表格不存在');
    if ($actor['role'] !== 'finance' && ((int)$row['employee_id'] !== (int)($actor['employee_id'] ?? 0) || $row['uploaded_by_type'] !== $actor['type'])) throw new RuntimeException('只能删除本人上传的表格');
    $pdo = db();
    $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_import_file_delete');
    else $pdo->beginTransaction();
    try {
        ps_audit('import_file', (int)$row['id'], 'delete', $actor, ['original_name' => $row['original_name'], 'business_name' => $row['business_name'], 'status' => $row['status'], 'imported_count' => (int)$row['imported_count']]);
        $pdo->prepare('DELETE FROM project_import_files WHERE id=?')->execute([(int)$row['id']]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_import_file_delete');
        else $pdo->commit();
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_import_file_delete');
        else $pdo->rollBack();
        throw $e;
    }
    ps_private_delete('imports', $row['stored_name']);
    if (!empty($row['parse_name'])) ps_private_delete('imports', $row['parse_name']);
}

/** 解析全部工作表：['工作表名' => [[单元格...], ...]]；CSV 视为一张表。 */
function ps_import_file_sheets($row)
{
    // 解析需要真实文件路径（zip），临时复制到 /tmp，读完即删
    $row['path'] = tempnam(sys_get_temp_dir(), 'psx_');
    if (!empty($row['parse_name'])) $row['stored_name'] = $row['parse_name'];
    file_put_contents($row['path'], $row['parse_content'] ?? $row['content']);
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
