<?php
/**
 * 带到期日期 / 客户联系方式的业务订单模板（xlsx）——小程序开发、网站模板、AI网站定制：订单类型 / 业务种类 / 状态为下拉选择，日期列带日期格式，订单号等长数字列为文本格式，
 * 带示例行和“填写说明”分表。不依赖 ZipArchive（自带最小 zip 写入），本机无 zip 扩展也能生成。
 * 列名与导入识别的别名一一对应（见 ps_business_import_columns）：改列名要同步改那边的别名。
 */

/** 有专用 xlsx 模板的业务。 */
function pmt_businesses()
{
    return ['小程序开发', '网站模板', 'AI网站定制', '商标', '森动备案', '微信代写'];
}

/** 列定义：[表头, 列宽, 类型 text|date|list|plain, 下拉选项, 是否必填, 说明] */
function pmt_columns($business = '小程序开发')
{
    if ($business === '商标') { require_once __DIR__ . '/ProjectTrademarkTemplate.php'; return ptt_columns(); }
    if ($business === '森动备案') return pmt_filing_columns();
    if ($business === '微信代写') return pmt_writing_columns();
    if ($business === '网站模板' || $business === 'AI网站定制') return pmt_web_columns($business);
    return [
        ['日期', 12, 'date', [], true, '下单 / 付款日期，如 2026-09-01'],
        ['店铺', 14, 'plain', [], false, '店铺名称'],
        ['付款昵称', 16, 'plain', [], false, '客户付款账号 / 旺旺'],
        ['订单编号', 24, 'text', [], true, '店铺订单号；微信付款没有订单号时留空并填“微信交易流水号”'],
        ['售价', 10, 'plain', [], true, '客户实付金额，数字'],
        ['订单类型(下拉选择)', 26, 'list', ['小程序商城新注册搭建', '定制开发（按客户需求开发）', '小程序续费（续年费）', '注册公众号、认证等其他服务'], true, '小程序商城新注册搭建＝在小程序商城新注册并搭建的订单（每单 20 元补助）；定制开发＝按客户需求定制开发；小程序续费＝续年费；注册公众号、认证等其他服务＝无补助。请用下拉选择'],
        ['业务种类(永久/年费)', 16, 'list', ['永久', '年费'], true, '永久＝一次买断、无需再续费；年费＝按年续费的业务（须填到期日期和续费联系方式）。请用下拉选择'],
        ['到期日期', 13, 'date', [], false, '年费业务必填：服务器 / 服务到期的日期；永久业务留空（也可直接写“永久”）'],
        ['续费联系方式', 20, 'text', [], false, '年费业务必填：客户手机号，或海外客户微信号；用于到期短信提醒和续费联系'],
        ['业务', 22, 'plain', [], false, '具体做了什么，如 小程序商城搭建、注册公众号、重新注册'],
        ['状态(填已完成/未完成)', 14, 'list', ['已完成', '未完成'], true, '订单进度，请用下拉选择'],
        ['客服', 10, 'plain', [], false, '客服姓名'],
        ['制作技术', 10, 'plain', [], false, '制作该小程序的技术姓名'],
        ['备注', 24, 'plain', [], false, '其他需要说明的内容'],
        ['微信交易流水号', 24, 'text', [], false, '微信付款订单没有订单编号时填这里'],
    ];
}

/**
 * 森动备案（栾鑫）：一张表同时放“备案”和“二次备案”。店铺列填收款方式（微信 / 对公 / 淘宝），业务列选备案或二次备案。
 * 备案没有订单编号（微信 / 对公付款）时留空，系统按“日期＋店铺＋付款昵称＋金额”生成内部订单号；二次备案没有订单编号 / 售价，靠“域名＋联系微信号”识别。
 * 列名与导入别名一一对应（见 ps_business_import_columns 的森动备案部分）。
 */
function pmt_writing_columns()
{
    return [
        ['日期', 12, 'date', [], true, '接单或对接日期，如 2026-10-01'],
        ['接单方式', 14, 'list', ['自接订单', '编辑对接'], true, '本人接客户选自接订单；帮其他客服建群、对接写手选编辑对接。两者提成不同，不能只靠店铺或付款方式区分'],
        ['收款方式', 12, 'list', ['淘宝', '微信'], false, '自接订单必选淘宝或微信；编辑对接不按此列发自接单补助'],
        ['店铺', 18, 'plain', [], false, '淘宝填写实际店铺名；微信可填微信。编辑对接可以填原订单的店铺'],
        ['订单编号', 24, 'text', [], false, '淘宝填店铺订单号；编辑对接填原订单号，系统优先关联已有原单，不重复记收入；微信没有订单号可留空'],
        ['付款昵称', 20, 'text', [], false, '客户旺旺或微信昵称'],
        ['售价', 12, 'plain', [], false, '自接单填客户付款金额；编辑对接可填原单参考金额，不计为本人销售额'],
        ['实际稿费', 12, 'plain', [], false, '自接单填写稿费成本；编辑对接已有关联原单时不改动原单的成本'],
        ['状态(填已完成/未完成)', 14, 'list', ['已完成', '未完成'], true, '工作进度'],
        ['客服', 12, 'plain', [], false, '自接订单填写接单客服；个人上传不填默认本人。编辑对接不把此列当成个人自接业绩'],
        ['对接编辑', 12, 'plain', [], false, '编辑对接填写编辑姓名；个人上传可留空默认本人'],
        ['微信交易流水号', 26, 'text', [], false, '微信收款流水号；填写后可用于关联订单和收款对账'],
        ['写手编号', 14, 'text', [], false, '写手编号'],
        ['字数', 12, 'plain', [], false, '本单字数'],
        ['备注', 26, 'plain', [], false, '其他需要核对的情况'],
    ];
}

function pmt_writing_examples()
{
    $base = ['日期' => '2026-10-01', '状态(填已完成/未完成)' => '已完成'];
    return [
        $base + ['接单方式' => '自接订单', '收款方式' => '淘宝', '店铺' => '实际淘宝店名', '订单编号' => '示例-淘宝自接', '付款昵称' => '客户旺旺', '售价' => '300', '实际稿费' => '100'],
        $base + ['接单方式' => '自接订单', '收款方式' => '微信', '店铺' => '微信', '订单编号' => '示例-微信自接', '付款昵称' => '客户微信昵称', '售价' => '200', '实际稿费' => '80'],
        $base + ['接单方式' => '编辑对接', '店铺' => '原单淘宝店名', '订单编号' => '示例-编辑对接', '付款昵称' => '原单客户', '售价' => '300', '备注' => '填写原订单号；只计算对接编辑这一份'],
    ];
}

function pmt_filing_columns()
{
    return [
        ['日期', 12, 'date', [], true, '备案 / 二次备案的日期，如 2026-09-01'],
        ['店铺', 12, 'list_soft', ['微信', '对公', '淘宝'], false, '收款方式：微信 / 对公 / 淘宝。选“淘宝”的备案订单记为“备案-淘宝”（另有补贴）；也可直接写淘宝店名（含“淘宝”二字）'],
        ['业务', 14, 'list', ['备案', '二次备案'], true, '备案＝首次备案；二次备案＝备案售后、迁移、改信息等杂项。请用下拉选择'],
        ['付款昵称', 20, 'plain', [], false, '客户付款的微信昵称 / 淘宝旺旺。备案必填；二次备案可不填'],
        ['订单编号', 24, 'text', [], false, '淘宝订单填店铺订单号；微信 / 对公付款没有订单号就留空（系统自动生成内部订单号）；二次备案留空'],
        ['售价', 10, 'plain', [], false, '备案必填：客户实付金额，数字。二次备案不用填'],
        ['成本', 10, 'plain', [], false, '备案选填：不填按成本中心“森动备案成本”自动补录；明确写 0 就按 0。二次备案不记成本'],
        ['状态(填已完成/未完成)', 14, 'list', ['已完成', '未完成'], true, '订单进度，请用下拉选择'],
        ['联系微信号', 18, 'text', [], false, '客户微信号（二次备案建议必填，用于识别是哪位客户；备案也建议填，列表里直接能看到）'],
        ['域名', 22, 'text', [], false, '备案的域名。二次备案必填（同月同域名同一个人的多次售后，每次各写一行）'],
        ['微信交易流水号', 24, 'text', [], false, '微信付款的交易单号，用于和收款流水对账；没有可留空'],
        ['程序', 14, 'plain', [], false, '备案用的程序，如 森动'],
        ['接入商', 14, 'plain', [], false, '备案接入商，如 百度云、阿里云'],
        ['备案状态', 12, 'plain', [], false, '如 已备案、备案中、已提交'],
        ['授权码', 16, 'text', [], false, '程序授权码（选填）'],
        ['客服', 10, 'plain', [], false, '选填；不填默认记上传人'],
        ['项目执行', 10, 'plain', [], false, '选填；不填默认记上传人'],
    ];
}

function pmt_filing_examples()
{
    $base = ['日期' => '2026-09-01', '状态(填已完成/未完成)' => '已完成'];
    return [
        $base + ['店铺' => '微信', '业务' => '备案', '付款昵称' => '客户微信昵称', '订单编号' => '示例-微信备案', '售价' => '100', '成本' => '80', '联系微信号' => 'wx_example', '域名' => 'example.com', '微信交易流水号' => '4200002012202609018888888888', '程序' => '森动', '接入商' => '百度云'],
        $base + ['店铺' => '对公', '业务' => '备案', '付款昵称' => '某某公司', '订单编号' => '示例-对公备案', '售价' => '100', '联系微信号' => 'wx_example2', '域名' => 'example.cn'],
        $base + ['业务' => '二次备案', '订单编号' => '示例-二次备案', '联系微信号' => 'wx_example', '域名' => 'example.com', '备案状态' => '已备案'],
    ];
}

/** 网站类业务（网站模板 / AI网站定制）的列：域名、域名 / 服务器到期日期、续费联系方式都是一等公民。 */
function pmt_web_columns($business)
{
    $template = $business === '网站模板';
    $cols = [
        ['日期', 12, 'date', [], true, '下单 / 付款日期，如 2026-09-01'],
        ['店铺', 14, 'plain', [], false, '店铺名称'],
        ['付款昵称', 16, 'plain', [], false, '客户付款账号 / 旺旺'],
        ['订单编号', 24, 'text', [], true, $template ? '把编号粘贴在这里：左边“编号类型”选了淘宝订单编号就粘贴店铺订单号，选微信交易流水号就粘贴微信流水号，选对公转账编号就粘贴对公编码' : '店铺订单号；微信付款没有订单号时留空并填“微信交易流水号”；对公转账写“对公”，并把对公编码填在“微信交易流水号”列'],
        ['售价', 10, 'plain', [], true, '客户实付金额，数字'],
    ];
    // 网站模板：订单编号前加“编号类型”下拉（淘宝订单编号 / 微信交易流水号 / 对公转账编号）；售价后面加两列成本
    if ($template) {
        // 编号类型下拉 + 订单编号列粘贴编号：淘宝订单编号 / 微信交易流水号 / 对公转账编号（导入时按类型归到对应字段）
        array_splice($cols, 3, 0, [['编号类型', 16, 'list', ['淘宝订单编号', '微信交易流水号', '对公转账编号'], true, '先选这个订单的编号是哪一种：淘宝订单编号＝店铺订单号；微信交易流水号＝微信付款没有订单号；对公转账编号＝客户转到我们对公账户的对公编码。然后把编号粘贴到右边“订单编号”列']]);
        $cols[] = ['孙曼要成本', 12, 'plain', [], false, '孙曼要的成本（上游采购价），写利润分析时用，数字；不填不影响分成'];
        $cols[] = ['成本', 10, 'plain', [], false, '核算成本，算提成时用，数字；不填则按成本中心标准成本'];
    }
    if ($template) $cols[] = ['订单类型', 20, 'list', ['模板新建网站', '网站续费', '加购、补差价（纯利润）', '博山定制', 'AI定制', '华梦定制'], false, '模板新建网站＝用模板新建一个网站；网站续费＝续费单；加购、补差价（纯利润）＝加购、补差价等。定制单也选这里：博山定制＝正常的定制单（前端、后端分开做）；AI定制＝前后端由一个技术做；华梦定制＝大连华梦那边的单子。不填系统按描述猜，请用下拉选择'];
    if ($template) $cols[] = ['程序名称', 18, 'plain', [], true, '建站用的程序 / 套餐，如 森动中级版、jsp展示中级版；购买年数在右边“年限”列选，不用写进名称'];
    if ($template) $cols[] = ['年限', 9, 'list', array_map(function ($n) { return $n . '年'; }, range(1, 10)), false, '客户购买的年数，1-10 年下拉选择；不填按 1 年。程序名称里已写“5年xxx”的以名称为准'];
    $cols = array_merge($cols, [
        ['网站域名', 22, 'text', [], true, '客户网站域名，如 example.com。同一付款号做多个网站时，每个网站一行，各写各的域名'],
        ['域名来源', 12, 'list', ['客户提供', '我们注册'], false, '客户提供＝客户自己的域名，不计成本；我们注册＝由我们注册域名，按域名后缀自动计入注册成本。请用下拉选择'],
        ['空间来源', 14, 'list', ['我们的空间', '客户自有空间'], false, '我们的空间＝用我们的服务器 / 空间；客户自有空间＝客户用自己的空间（由客户自己维护，空间成本请财务核对）。请用下拉选择；不填按我们的空间'],
        ['域名到期日期', 14, 'date', [], false, '域名到期日；永久的可直接写“永久”'],
        ['服务器到期日期', 14, 'date', [], false, '服务器 / 空间到期日；永久的可直接写“永久”'],
        ['续费联系方式', 20, 'text', [], true, '客户手机号，或海外客户微信号；用于到期短信提醒和续费联系'],
        ['SSL证书使用', 12, 'plain', [], false, 'SSL 证书真实成本，没有填 0 或 无'],
        ['域名或空间', 22, 'plain', [], false, '域名 / 空间的补充说明'],
        ['业务', 18, 'plain', [], false, '具体做了什么，如 企业官网、商城'],
        ['状态(填已完成/未完成)', 14, 'list', ['已完成', '未完成'], true, '订单进度，请用下拉选择'],
        ['客服', 10, 'plain', [], false, '客服姓名'],
    ]);
    if ($template) $cols[] = ['模板技术', 10, 'plain', [], false, '负责建站的技术姓名'];
    else { $cols[] = ['前端（技术）', 12, 'plain', [], false, '前端技术姓名'];  $cols[] = ['后端', 10, 'plain', [], false, '后端技术姓名']; }
    $cols[] = ['备注', 24, 'plain', [], false, '其他需要说明的内容'];
    if (!$template) $cols[] = ['微信交易流水号', 24, 'text', [], false, '微信付款订单没有订单编号时填这里'];
    // 模板名称 / 版本与“程序名称”重复，新模板不再带这一列（老表里写了仍照常识别）
    if ($template) $cols[] = ['部署与交付说明', 24, 'plain', [], false, '部署、交付情况说明'];
    return $cols;
}

function pmt_web_examples($business)
{
    $template = $business === '网站模板';
    $tech = $template ? ['模板技术' => '张强'] : ['前端（技术）' => '张强', '后端' => '李四'];
    $base = ['日期' => '2026-09-01', '店铺' => '美呀美旗舰店', '付款昵称' => 'tb12345678', '售价' => '998', '状态(填已完成/未完成)' => '已完成', '客服' => '宋倩倩'] + $tech;
    if ($template) $base += ['编号类型' => '淘宝订单编号', '孙曼要成本' => '260', '成本' => '360', '年限' => '1年'];
    $rows = [
        $base + ['订单编号' => '示例-新建站', '程序名称' => '森动中级版', '网站域名' => 'example.com', '域名来源' => '我们注册', '空间来源' => '我们的空间', '域名到期日期' => '2027-09-01', '服务器到期日期' => '2027-09-01', '续费联系方式' => '13800138000', 'SSL证书使用' => '0', '业务' => '企业官网', '备注' => '新建站：填域名、域名和服务器的到期日期、客户联系方式', '订单类型' => '模板新建网站'],
        $base + ['订单编号' => '示例-客户自备域名', '程序名称' => '森动中级版', '网站域名' => 'customer-own.com', '域名来源' => '客户提供', '空间来源' => '客户自有空间', '服务器到期日期' => '永久', '续费联系方式' => 'example_wechat', '业务' => '企业官网', '备注' => '客户自备域名在“域名来源”选“客户提供”；客户用自己的空间在“空间来源”选“客户自有空间”；永久的到期日期直接写“永久”；海外客户填微信号', '订单类型' => '模板新建网站'],
        $base + ['订单编号' => '示例-续费', '程序名称' => '森动中级版', '网站域名' => 'example.com', '域名到期日期' => '2028-09-01', '服务器到期日期' => '2028-09-01', '续费联系方式' => '13800138000', '售价' => '300', '业务' => '续费一年', '备注' => '续费单：填新的到期日期', '订单类型' => '网站续费'],
    ];
    if (!$template) foreach ($rows as &$row) unset($row['程序名称'], $row['订单类型']);
    return $rows;
}

function pmt_headers($business = '小程序开发')
{
    return array_map(function ($c) { return $c[0]; }, pmt_columns($business));
}

/** 示例行（订单编号以“示例”开头，上传时自动跳过）。 */
function pmt_example_rows($business = '小程序开发')
{
    if ($business === '商标') { require_once __DIR__ . '/ProjectTrademarkTemplate.php'; return ptt_examples(); }
    if ($business === '森动备案') return pmt_filing_examples();
    if ($business === '微信代写') return pmt_writing_examples();
    if ($business === '网站模板' || $business === 'AI网站定制') return pmt_web_examples($business);
    $base = ['日期' => '2026-09-01', '店铺' => '美呀美旗舰店', '付款昵称' => 'tb12345678', '售价' => '350', '状态(填已完成/未完成)' => '已完成', '客服' => '王宁', '制作技术' => '石凯新'];
    $k = '订单类型(下拉选择)'; $t = '业务种类(永久/年费)';
    return [
        $base + [$k => '小程序商城新注册搭建', $t => '永久', '业务' => '小程序商城搭建', '订单编号' => '示例-商城新搭建-永久', '备注' => '只有在小程序商城新注册搭建的订单才选“小程序商城新注册搭建”，每单 20 元补助；永久业务不用填到期日期'],
        $base + [$k => '小程序商城新注册搭建', $t => '年费', '到期日期' => '2027-09-01', '续费联系方式' => '13800138000', '业务' => '小程序商城搭建', '订单编号' => '示例-商城新搭建-年费', '备注' => '年费业务：填到期日期和续费联系方式'],
        $base + [$k => '注册公众号、认证等其他服务', $t => '永久', '业务' => '注册公众号', '订单编号' => '示例-其他服务', '备注' => '注册公众号、重新注册、认证等选“注册公众号、认证等其他服务”，没有补助'],
        $base + [$k => '小程序续费（续年费）', $t => '年费', '到期日期' => '2028-09-01', '续费联系方式' => 'example_wechat', '业务' => '小程序续费1年', '订单编号' => '示例-续费', '备注' => '海外客户没有手机号时，续费联系方式填微信号'],
        $base + [$k => '定制开发（按客户需求开发）', $t => '永久', '业务' => '定制开发某某功能', '订单编号' => '示例-定制', '备注' => '按客户需求定制开发选“定制开发（按客户需求开发）”'],
    ];
}

function pmt_col_letter($index)
{
    $s = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
    return $s;
}

function pmt_xml($value)
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Excel 日期序列号（1900 体系）。 */
function pmt_serial($ymd)
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
    $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
    return (int)$base->diff($d)->format('%a');
}

/** 最小 zip 写入（store，无压缩）。 */
function pmt_zip(array $files)
{
    $out = ''; $central = ''; $offset = 0; $count = 0;
    $time = (12 << 11); $date = ((2026 - 1980) << 9) | (10 << 5) | 9;
    foreach ($files as $name => $data) {
        $crc = crc32($data) & 0xFFFFFFFF; $size = strlen($data); $nameLen = strlen($name);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $time, $date, $crc, $size, $size, $nameLen, 0) . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $time, $date, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
        $out .= $local; $offset += strlen($local); $count++;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
}

function pmt_cell($ref, $value, $style, $number = false)
{
    if ($value === '' || $value === null) return '<c r="' . $ref . '" s="' . $style . '"/>';
    if ($number) return '<c r="' . $ref . '" s="' . $style . '"><v>' . pmt_xml($value) . '</v></c>';
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . pmt_xml($value) . '</t></is></c>';
}

/** 生成 xlsx 二进制内容。 */
function pmt_xlsx($business = '小程序开发')
{
    $columns = pmt_columns($business);
    $last = pmt_col_letter(count($columns) - 1);
    $maxRow = 1000;
    // 样式：0 默认；1 必填表头；2 选填表头；3 日期；4 文本；5 示例文本；6 示例日期；7 说明正文（换行）；8 说明标题
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/></numFmts>'
        . '<fonts count="4"><font><sz val="11"/><name val="微软雅黑"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="微软雅黑"/></font><font><b/><sz val="11"/><color rgb="FF1B4332"/><name val="微软雅黑"/></font><font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="微软雅黑"/></font></fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2D6A4F"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD8F3DC"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFBFC8C2"/></left><right style="thin"><color rgb="FFBFC8C2"/></right><top style="thin"><color rgb="FFBFC8C2"/></top><bottom style="thin"><color rgb="FFBFC8C2"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="49" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="49" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="49" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
        . '<xf numFmtId="164" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
        . '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="49" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment vertical="center"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    // 订单表
    $cols = '';
    foreach ($columns as $i => [$name, $width, $type]) {
        $style = $type === 'date' ? 3 : ($type === 'text' ? 4 : 0);
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" style="' . $style . '" customWidth="1"/>';
    }
    $rows = '<row r="1" ht="42" customHeight="1">';
    foreach ($columns as $i => $c) $rows .= pmt_cell(pmt_col_letter($i) . '1', $c[0], $c[4] ? 1 : 2);
    $rows .= '</row>';
    foreach (pmt_example_rows($business) as $n => $example) {
        $r = $n + 2;
        $rows .= '<row r="' . $r . '">';
        foreach ($columns as $i => $c) {
            $value = $example[$c[0]] ?? '';
            $ref = pmt_col_letter($i) . $r;
            if ($c[2] === 'date' && $value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $rows .= pmt_cell($ref, pmt_serial($value), 6, true);
            else $rows .= pmt_cell($ref, $value, 5);
        }
        $rows .= '</row>';
    }
    $validations = '';
    $count = 0;
    foreach ($columns as $i => $c) {
        $range = pmt_col_letter($i) . '2:' . pmt_col_letter($i) . $maxRow;
        if ($c[2] === 'list_soft') {
            // 软下拉：有下拉选项方便选，但允许手输其它内容（如淘宝店名），不弹错误
            $validations .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="0" sqref="' . $range . '"><formula1>"' . pmt_xml(implode(',', $c[3])) . '"</formula1></dataValidation>';
            $count++;
        } elseif ($c[2] === 'list') {
            $validations .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" errorTitle="请从下拉里选择" error="请点单元格右侧的小箭头，从列表里选择：' . pmt_xml(implode(' / ', $c[3])) . '" promptTitle="' . pmt_xml(explode('(', $c[0])[0]) . '" prompt="请从下拉列表选择" sqref="' . $range . '"><formula1>"' . pmt_xml(implode(',', $c[3])) . '"</formula1></dataValidation>';
            $count++;
        } elseif ($c[2] === 'integer') {
            $validations .= '<dataValidation type="whole" operator="between" allowBlank="1" showInputMessage="1" showErrorMessage="1" errorTitle="计价数量" error="请填写范围内的整数，不能按小数件计费" promptTitle="整数数量" prompt="' . pmt_xml($c[5]) . '" sqref="' . $range . '"><formula1>' . (int)$c[3][0] . '</formula1><formula2>' . (int)$c[3][1] . '</formula2></dataValidation>';
            $count++;
        } elseif ($c[2] === 'money') {
            $validations .= '<dataValidation type="decimal" operator="greaterThanOrEqual" allowBlank="1" showErrorMessage="1" errorTitle="金额" error="请填写非负金额，不要填写文字、负数或单件单价" sqref="' . $range . '"><formula1>0</formula1></dataValidation>';
            $count++;
        } elseif ($c[2] === 'date' && mb_strpos($c[5], '永久') !== false) {
            // 到期日期既可填日期也可写“永久”：自定义校验放行日期、“永久”和空白
            $first = pmt_col_letter($i) . '2';
            $validations .= '<dataValidation type="custom" allowBlank="1" showErrorMessage="1" errorTitle="到期日期" error="请填写日期（如 2027-09-01），永久的写“永久”" sqref="' . $range . '"><formula1>OR(' . $first . '="",' . $first . '="永久",ISNUMBER(' . $first . '))</formula1></dataValidation>';
            $count++;
        } elseif ($c[2] === 'date') {
            $validations .= '<dataValidation type="date" operator="greaterThan" allowBlank="1" showErrorMessage="1" errorTitle="日期格式" error="请填写日期，如 2026-09-01" sqref="' . $range . '"><formula1>36526</formula1></dataValidation>';
            $count++;
        }
    }
    $pane = $business === '商标' ? 'bottomRight' : 'bottomLeft';
    $firstInput = ($business === '商标' ? 'D' : 'A') . (count(pmt_example_rows($business)) + 2);
    $freeze = $business === '商标' ? 'xSplit="3" ySplit="1" topLeftCell="D2"' : 'ySplit="1" topLeftCell="A2"';
    $sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="A1:' . $last . (count(pmt_example_rows($business)) + 1) . '"/>'
        . '<sheetViews><sheetView workbookViewId="0" tabSelected="1"><pane ' . $freeze . ' activePane="' . $pane . '" state="frozen"/><selection pane="' . $pane . '" activeCell="' . $firstInput . '" sqref="' . $firstInput . '"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/><cols>' . $cols . '</cols><sheetData>' . $rows . '</sheetData>'
        . ($count ? '<dataValidations count="' . $count . '">' . $validations . '</dataValidations>' : '')
        . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';

    // 填写说明
    $help = [['列名', '是否必填', '怎么填']];
    foreach ($columns as $c) $help[] = [$c[0], $c[4] ? '必填' : '选填', $c[5]];
    $help[] = ['', '', ''];
    $exampleCount = count(pmt_example_rows($business));
    $help[] = ['示例行', '', '表格前 ' . $exampleCount . ' 行灰色字是示例，订单编号以“示例”开头，上传时自动跳过，可删可留；从第 ' . ($exampleCount + 2) . ' 行起填写真实订单。'];
    if ($business === '商标') $help = array_merge($help, ptt_notes());
    elseif ($business === '微信代写') {
        $help[] = ['自接与对接必须分开', '', '自接订单才计入本人微信代写部门利润分成；淘宝自接另外有店铺单量补助。编辑对接只按规则中心的对接编辑单量规则计算，不再拿本单的自接利润分成或淘宝自接补助。'];
        $help[] = ['同一张表混合填写', '', '每行分别选择接单方式和收款方式；同一客服可以同时有淘宝自接、微信自接、编辑对接。不要按人员或整张表统一分类。'];
        $help[] = ['关联原单', '', '编辑对接填写原订单号；已有关联原单时只加入编辑参与，不修改原接单客服、收入和成本。重复上传不会重复建单或重复加入。'];
    }
    elseif ($business === '森动备案') {
        $help[] = ['怎么区分两种单', '', '“业务”选备案：填日期、店铺（微信 / 对公 / 淘宝）、付款昵称、售价；没有订单编号就留空，系统自动生成。“业务”选二次备案：只需填日期、联系微信号、域名、状态。'];
        $help[] = ['成本', '', '首次备案不填成本时，系统按成本中心“森动备案成本”自动补录；表里写了就以表为准（写 0 即按 0）。二次备案不记成本。'];
        $help[] = ['重复上传', '', '同一张表改完可以整张重传，已有订单会按最新内容更新，不会重复建单。'];
    }
    else $help[] = ['续费提醒', '', '填了到期日期和续费联系方式后，系统会在到期前 10 / 3 / 1 天自动短信提醒客户；到期日期写“永久”的不提醒。'];
    $helpRows = '';
    foreach ($help as $n => $line) {
        $r = $n + 1;
        $helpHeight = $business === '商标' ? max(24, 6 + 18 * max(ceil(mb_strwidth((string)$line[0], 'UTF-8') / 32), ceil(mb_strwidth((string)$line[2], 'UTF-8') / 85))) : ($n === 0 ? 24 : 0);
        $helpRows .= '<row r="' . $r . '"' . ($helpHeight ? ' ht="' . $helpHeight . '" customHeight="1"' : '') . '>';
        foreach ($line as $i => $text) $helpRows .= pmt_cell(pmt_col_letter($i) . $r, $text, $n === 0 ? 8 : ($text === '' && $i < 2 ? 0 : 7));
        $helpRows .= '</row>';
    }
    $sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="18"/>'
        . '<cols><col min="1" max="1" width="34" customWidth="1"/><col min="2" max="2" width="10" customWidth="1"/><col min="3" max="3" width="90" customWidth="1"/></cols>'
        . '<sheetData>' . $helpRows . '</sheetData><pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';

    $ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $ns . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . $ns . '"><bookViews><workbookView activeTab="0"/></bookViews><sheets><sheet name="订单" sheetId="1" r:id="rId1"/><sheet name="填写说明" sheetId="2" r:id="rId2"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $ns . '/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $ns . '/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="' . $ns . '/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet1,
        'xl/worksheets/sheet2.xml' => $sheet2,
    ];
    return pmt_zip($files);
}

/** “业务种类”写法归一：永久 / 年费 / ''（空）；认不出返回 null。 */
function pmt_normalize_term($value)
{
    $v = preg_replace('/\s+/u', '', trim((string)$value));
    if ($v === '') return '';
    if (preg_match('/永久|终身|买断|一次性|长期/u', $v)) return '永久';
    if (preg_match('/年费|续费|按年|包年|年付|一年|1年|有期/u', $v)) return '年费';
    return null;
}

/** 到期日期单元格写的是“永久”一类文字。 */
function pmt_is_permanent_text($value)
{
    return (bool)preg_match('/^(永久|永久有效|终身|无期限|长期|买断)$/u', preg_replace('/\s+/u', '', trim((string)$value)));
}
