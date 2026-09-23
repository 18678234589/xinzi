<?php
/**
 * 系统设置：键值存储（project_settings）、客户联系方式可见权限、OpenAI 兼容 AI 接入。
 * 只依赖 db()；表未迁移时读取返回默认值，不影响其他页面。
 */

function ps_setting_get($key, $default = null, $reset = false)
{
    static $cache = null;
    if ($reset) { $cache = null; return null; }
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key,setting_value FROM project_settings')->fetchAll() as $row) $cache[$row['setting_key']] = $row['setting_value'];
        } catch (PDOException $e) {
            $cache = [];
        }
    }
    if (!array_key_exists($key, $cache)) return $default;
    $decoded = json_decode($cache[$key], true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

function ps_setting_set($key, $value, $adminId)
{
    db()->prepare('INSERT INTO project_settings (setting_key,setting_value,updated_by_admin) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by_admin=VALUES(updated_by_admin)')
        ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $adminId]);
    ps_setting_get($key, null, true);
}

/* ---------- 客户联系方式可见权限 ---------- */

function ps_contact_roles()
{
    return ['technical' => '技术', 'customer_service' => '客服'];
}

/** 每个角色：participant = 本单参与人看完整、其他人打码（默认）；masked = 始终打码。财务/管理员始终完整。 */
function ps_contact_policy()
{
    $policy = ps_setting_get('contact_visibility', []);
    $out = [];
    foreach (array_keys(ps_contact_roles()) as $role) $out[$role] = in_array($policy[$role] ?? '', ['participant', 'masked'], true) ? $policy[$role] : 'participant';
    return $out;
}

function ps_contact_visible($actor, $isParticipant)
{
    if (($actor['role'] ?? '') === 'finance') return true;
    $policy = ps_contact_policy()[$actor['role'] ?? ''] ?? 'masked';
    return $policy === 'participant' && $isParticipant;
}

/* ---------- AI 接入（OpenAI 兼容） ---------- */

function ps_ai_config()
{
    $config = ps_setting_get('ai', []);
    return [
        'enabled' => !empty($config['enabled']),
        'base_url' => (string)($config['base_url'] ?? ''),
        'api_key' => (string)($config['api_key'] ?? ''),
        'model' => (string)($config['model'] ?? ''),
    ];
}

function ps_ai_ready()
{
    $config = ps_ai_config();
    return $config['enabled'] && $config['base_url'] !== '' && $config['api_key'] !== '' && $config['model'] !== '';
}

function ps_ai_mask_key($key)
{
    $key = (string)$key;
    return strlen($key) <= 10 ? str_repeat('*', strlen($key)) : substr($key, 0, 5) . '***' . substr($key, -4);
}

/** https://host 或 https://host/v1 均可；统一补成 …/v1。 */
function ps_ai_endpoint($baseUrl, $path)
{
    $baseUrl = rtrim(trim((string)$baseUrl), '/');
    if (!preg_match('~/v\d+$~', $baseUrl)) $baseUrl .= '/v1';
    return $baseUrl . $path;
}

/**
 * 调用 OpenAI 兼容 /chat/completions，返回助手文本。失败抛 RuntimeException（不包含密钥）。
 * $config 为空时使用系统设置；测试连接时可传入未保存的配置。
 */
function ps_ai_chat(array $messages, $maxTokens = 1200, ?array $config = null)
{
    $config = $config ?? ps_ai_config();
    if ($config['base_url'] === '' || $config['api_key'] === '' || $config['model'] === '') throw new RuntimeException('AI 尚未配置完整（接口地址、密钥、模型名称）');
    if (!preg_match('~^https?://~i', $config['base_url'])) throw new RuntimeException('AI 接口地址须以 http:// 或 https:// 开头');
    if (!function_exists('curl_init')) throw new RuntimeException('服务器未启用 PHP curl 扩展');
    $ch = curl_init(ps_ai_endpoint($config['base_url'], '/chat/completions'));
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['api_key'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $config['model'], 'messages' => $messages, 'max_tokens' => (int)$maxTokens, 'temperature' => 0.1], JSON_UNESCAPED_UNICODE),
    ];
    // Windows 等未配置 CA 证书包的环境改用系统证书库（仍校验证书）。
    if (defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo') && !ini_get('openssl.cafile')) $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('无法连接 AI 服务：' . $curlError);
    $data = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($data) ? (string)($data['error']['message'] ?? $data['message'] ?? '') : '';
        throw new RuntimeException('AI 服务返回错误（HTTP ' . $status . '）' . ($message !== '' ? '：' . mb_substr($message, 0, 200) : ''));
    }
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content)) throw new RuntimeException('AI 服务返回格式不是 OpenAI 兼容格式');
    return trim($content);
}

/** 从模型回复中取出第一个 JSON 对象（兼容 ```json 代码块与前后说明文字）。 */
function ps_ai_json($content)
{
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', (string)$content);
    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end < $start) throw new RuntimeException('AI 未返回可识别的结果，请换一段更完整的文字再试');
    $data = json_decode(substr($content, $start, $end - $start + 1), true);
    if (!is_array($data)) throw new RuntimeException('AI 返回的结果无法解析，请重试');
    return $data;
}

/**
 * 从粘贴的聊天记录 / 店铺订单文字中识别录单字段，只返回通过校验的值（业务、店铺、订单类型须在系统列表中）。
 */
function ps_ai_parse_order($text, array $businesses, array $shops, array $orderKinds)
{
    $text = trim((string)$text);
    if ($text === '') throw new RuntimeException('请先粘贴订单或聊天内容');
    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000);
    $system = '你是订单录入助手。从用户粘贴的淘宝/微信订单或聊天记录中提取字段，只输出一个 JSON 对象，不要解释。字段：'
        . 'order_no(订单编号，保留原样)、order_date(YYYY-MM-DD，年份缺省用' . date('Y') . ')、shop(店铺，只能从[' . implode('、', $shops) . ']中选，否则空)、'
        . 'business(业务，只能从[' . implode('、', $businesses) . ']中选，否则空)、order_kind(订单类型，只能从[' . implode('、', $orderKinds) . ']中选，否则空)、'
        . 'payment_nickname(买家付款昵称/旺旺)、contract_amount(售价数字，不带单位)、trade_status(交易状态)、customer_name(客户或公司名)、'
        . 'contact_note(客户手机号、微信号、邮箱，原样)、program_name(网站程序/模板名称，如“5年JSP展示中级版”)、requirement(制作要求摘要，30字内)。没有的字段给空字符串。'
        . '判断业务：森动/JSP/青站/优站/PHP/米拓等现成程序或模板建站属于“网站模板”；按需求定制开发网站属于“AI网站定制”；服务器/环境搭建属于“环境配置”；小程序/公众号属于“小程序开发”。';
    $data = ps_ai_json(ps_ai_chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $text]], 1500));
    $clean = function ($key, $max) use ($data) { return mb_substr(trim((string)($data[$key] ?? '')), 0, $max); };
    $out = [
        'order_no' => preg_replace('/[^\w\-]/u', '', $clean('order_no', 100)),
        'order_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean('order_date', 10)) && strtotime($clean('order_date', 10)) ? $clean('order_date', 10) : '',
        'shop' => in_array($clean('shop', 150), $shops, true) ? $clean('shop', 150) : '',
        'business' => in_array($clean('business', 100), $businesses, true) ? $clean('business', 100) : '',
        'order_kind' => in_array($clean('order_kind', 40), $orderKinds, true) ? $clean('order_kind', 40) : '',
        'payment_nickname' => $clean('payment_nickname', 200),
        'contract_amount' => is_numeric(str_replace([',', '¥', '￥'], '', $clean('contract_amount', 20))) ? number_format((float)str_replace([',', '¥', '￥'], '', $clean('contract_amount', 20)), 2, '.', '') : '',
        'trade_status' => $clean('trade_status', 100),
        'customer_name' => $clean('customer_name', 200),
        'contact_note' => $clean('contact_note', 500),
        'program_name' => $clean('program_name', 100),
        'requirement' => $clean('requirement', 100),
    ];
    if ($out['contract_amount'] !== '' && (float)$out['contract_amount'] < 0) $out['contract_amount'] = '';
    return $out;
}
