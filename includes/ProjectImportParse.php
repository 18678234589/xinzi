<?php

function ps_import_date($value)
{
    $value = rtrim(trim((string)$value), '.。'); // 容忍手录多打的句点，如“8.30.”
    // 紧凑写法：260901（YYMMDD）、20260901（YYYYMMDD）——须先于 Excel 序列号判断
    if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $c) && checkdate((int)$c[2], (int)$c[3], 2000 + (int)$c[1])) return sprintf('%04d-%02d-%02d', 2000 + (int)$c[1], (int)$c[2],
    (int)$c[3]);
    if (preg_match('/^(20\d{2})(\d{2})(\d{2})$/', $value, $c) && checkdate((int)$c[2], (int)$c[3], (int)$c[1])) return sprintf('%04d-%02d-%02d', (int)$c[1], (int)$c[2], (int)$c[3])
    ;
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
            $narrowed = array_values(array_filter($candidates, function ($emp) use ($business) { return !empty($emp['has_account']) && in_array(ps_business_normalize($business), $emp
    ['businesses'] ?? [], true); }));
            if (count($narrowed) !== 1) $narrowed = array_values(array_filter($candidates, function ($emp) use ($business) { return ps_business_fallback($emp['department'] ?? '') ===
    ps_business_normalize($business) || (ps_business_fallback($emp['department'] ?? '') === '网站客服' && ps_is_website_order($business)); }));
            // 仍不能确定时：只有一位开通了有效项目账号的（另一位多为离职或早期重复录入的档案），取这一位
            if (count($narrowed) !== 1) $narrowed = array_values(array_filter($candidates, function ($emp) { return !empty($emp['has_account']); }));
            if (count($narrowed) === 1) $candidates = $narrowed;
        }
        if (!$candidates) throw new RuntimeException('合作人员“' . $name . '”不在人员名单中，请先在“人员与考勤”里添加');
        if (count($candidates) !== 1) throw new RuntimeException('合作人员“' . $name . '”有 ' . count($candidates) . ' 位重名，请在人员管理里区分姓名（如加部门后缀）'
    );
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

/** 未登记的名字是否像某位合作人员（包含、被包含或只差一个字）：像的多半是写错了姓名，必须拦下提示；不像的（如“大连”）才可忽略。 */
function ps_import_name_resembles_employee($name, $employeesByName)
{
    $name = trim((string)$name);
    if (mb_strlen($name) < 2) return false;
    foreach (array_keys($employeesByName) as $known) {
        $known = (string)$known;
        if ($known === '') continue;
        if (mb_strpos($known, $name) !== false || mb_strpos($name, $known) !== false) return true;
        if (mb_strlen($known) === mb_strlen($name)) {
            $diff = 0;
            for ($i = 0, $n = mb_strlen($name); $i < $n; $i++) if (mb_substr($name, $i, 1) !== mb_substr($known, $i, 1)) $diff++;
            if ($diff <= 1) return true;
        }
    }
    return false;
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
        ['/日期无法识别|日期“.*”无法识别/u', 'date', '日期写法无法识别', '“日期”列写订单日期即可，支持 年-月-日、月.日、X月X日、8 位数字；不要写时间、星期或文字。没有日期的行可在预览里直接补填。'
    , '2026-09-01　9.1　9月1日　20260901'],
        ['/^状态“/u', 'status', '状态写法无法识别', '“状态”列写 已完成 或 未完成；也可写 到账、已发货、交易关闭。空着按未完成处理。'
    , '已完成　未完成　到账'],
        ['/缺少店铺订单号或支付流水号/u', 'order_no', '缺少订单号（又没有日期 / 金额 / 付款昵称可识别）', '“订单编号”列填店铺（淘宝等）订单号；微信 / 对公收款没有订单号的，在“微信交易流水号”列填账单里的交易单号，二选一即可。也可在预览里直接补填。'
    , '3316440471002001958　或　4200001234202609011234567890'],
        ['/订单号或售价无效/u', 'amount', '售价或订单号写法不对', '“售价”列只写数字（最多两位小数），不要带“元”、文字或写两个金额；订单号不超过 100 个字符。'
    , '350　1280.50'],
        ['/不在人员名单中/u', 'person', '姓名对不上人员名单', '“客服”“技术”列只写系统里登记的姓名，多人用“、”隔开；不要写昵称、工号、项目名或把两人名字连在一起。名单里确实没有的人请联系财务添加。'
    , '王宁　王宁、朱俊英'],
        ['/位重名/u', 'duplicate', '姓名有重名', '系统里有同名的人，无法确定是哪一位。请联系财务在人员管理里区分（如加部门后缀），再按区分后的姓名填写。'
    , '王宁（标书）'],
        ['/须指定接单技术/u', 'tech_missing', '没写接单技术', '客服上传新订单时，“技术”列须写接单技术的姓名；写了但仍提示的，是该姓名不在人员名单里（写错字或还没登记），请核对或联系财务添加。'
    , '石凯新'],
        ['/还没有开通项目账号/u', 'tech_account', '技术还没开通账号', '表格里写的技术还没有项目账号，请联系财务开通后重新上传；或确认技术姓名是否写对。'
    , ''],
        ['/SSL 真实成本无效/u', 'ssl', 'SSL 成本写法不对', '“SSL证书使用”列写真实成本数字（只写数字，不带“元”）；没用证书写 0 或 无。'
    , '0　无　68'],
        ['/表格写的业务是/u', 'business', '业务选错了', '“业务”列写的业务和当前选择的业务模板不一致：请在页面上方切换到对应业务后再上传，或把“业务”列改成具体项目描述。'
    , '小程序商城搭建（写做什么，不写别的业务名）'],
        ['/订单类型“.*”无效/u', 'kind', '订单类型写法不对', '“订单类型”列只能写：' . implode('、', $kinds) . '。不确定可留空，系统会按描述自动预选。'
    , implode('　', array_slice($kinds, 0, 3))],
        ['/缺少“订单编号”或“微信交易流水号”列|没有与“.*”表头对应的工作表|缺少客服或技术列/u', 'header', '表头对不上', '表格第 1 行须是表头，至少要有“订单编号”（或“微信交易流水号”）、“日期”、“售价”列；财务上传还要有“客服”或“技术”列。最省事：下载下方模板，把数据按列粘贴进去再上传。'
    , '日期 | 店铺 | 付款昵称 | 订单编号 | 售价 | 状态 | 客服 | 技术'],
    ];
    foreach ($guides as [$pattern, $key, $title, $how, $example]) if (preg_match($pattern, $message)) return ['key' => $key, 'title' => $title, 'how' => $how, 'example' => $example
    ];
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
        $rows[] = ['line' => (int)$row['line'], 'sheet' => (string)($row['sheet'] ?? ''), 'order_no' => (string)($row['order_no'] ?? ''), 'order_date' => (string)($row['order_date'
    ] ?? ''), 'suggested_date' => (string)($row['suggested_date'] ?? ''), 'nickname' => (string)($row['payment_nickname'] ?? ''), 'amount' => (string)($row['contract_amount'] ?? ''
    ), 'text' => mb_substr($text, 0, 60), 'need_order' => $needOrder, 'need_date' => $needDate, 'error' => (string)($row['error'] ?? '')];
    }
    return $rows;
}
