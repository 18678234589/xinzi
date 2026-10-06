<?php
/**
 * 平台 / 服务器信息与项目账号密码。
 * - 密码与备注用 AES-256-GCM 加密存储，密钥在 config/vault_key.php（不入库、不进 git，仅服务器与维护人员本机持有）。
 * - 平台信息栏目仅白名单可见（股东、管理、admin、服务器维护）；项目账号密码跟随订单权限（参与人、部门代录人、财务）。
 * - 每次查看 / 复制密码都记审计日志。
 * - AI 智能导入：发送前把带“密码”等标签的值替换成占位符，密码不离开服务器，识别后再还原。
 */
require_once __DIR__ . '/ProjectSettlement.php';

const PV_CATEGORIES = ['服务器', '宝塔面板', '1Panel', 'MySQL 数据库', '腾讯云', '阿里云', '雅云', '野草云', '商务中国', '新网', '域名注册商', '邮箱', '其他'];
const PV_ORDER_KINDS = ['服务器（SSH / 远程桌面）', '宝塔面板', '1Panel', '域名管理面板', '网站后台', '小程序后台', '数据库', 'FTP', '邮箱', '其他'];
// 需要记录项目账号密码的业务：技术（小程序 / 网站 / 模板 / AI 定制 / 环境配置）与网站售后
const PV_ORDER_BUSINESSES = ['小程序开发', 'AI网站定制', '网站模板', '环境配置', '网站续费', '网站修改'];

function pv_key()
{
    static $key = null;
    if ($key !== null) return $key;
    $file = dirname(__DIR__) . '/config/vault_key.php';
    $value = is_file($file) ? include $file : null;
    $raw = is_string($value) ? base64_decode($value, true) : false;
    if ($raw === false || strlen($raw) !== 32) throw new RuntimeException('尚未配置账号密码加密密钥，请联系管理员运行 migrations/apply_vault.php');
    return $key = $raw;
}

function pv_encrypt($plain)
{
    $plain = (string)$plain;
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', pv_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('加密失败');
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function pv_decrypt($stored)
{
    $stored = (string)$stored;
    if ($stored === '') return '';
    if (strpos($stored, 'v1:') !== 0) return '';
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', pv_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

/** 可见名单：admin 账号用户名 + 合作人员 employee_id；可在栏目内由 admin 调整。 */
function pv_access_list()
{
    $saved = ps_setting_get('vault_access', null);
    if (is_array($saved) && isset($saved['admins'], $saved['employees'])) return ['admins' => array_values((array)$saved['admins']), 'employees' => array_map('intval', (array)$saved['employees'])];
    $ids = [];
    $q = db()->prepare('SELECT id FROM employees WHERE name=? ORDER BY id');
    foreach (['张富全', '王桂美', '张光萍', '张欣源', '孙杰', '于洋', '栾鑫'] as $name) { $q->execute([$name]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[] = (int)$id; }
    return ['admins' => ['admin', 'wangguimei', 'zhangfuquan'], 'employees' => $ids];
}

function pv_admin_username($adminId)
{
    $q = db()->prepare('SELECT username FROM admins WHERE id=?');
    $q->execute([(int)$adminId]);
    return (string)$q->fetchColumn();
}

function pv_can_access($actor)
{
    if (!$actor) return false;
    $list = pv_access_list();
    if ($actor['type'] === 'admin') return in_array(pv_admin_username($actor['id']), $list['admins'], true);
    return in_array((int)$actor['employee_id'], $list['employees'], true);
}

/** 只有 admin 账号可调整可见名单。 */
function pv_can_manage_access($actor)
{
    return $actor && $actor['type'] === 'admin' && pv_admin_username($actor['id']) === 'admin';
}

function pv_require_access()
{
    $actor = ps_require_actor();
    if (!pv_can_access($actor)) { http_response_code(403); exit('此栏目仅股东、管理层、管理员与服务器维护人员可见'); }
    return $actor;
}

function pv_actor_name($type, $id)
{
    static $cache = [];
    $key = $type . ':' . $id;
    if (isset($cache[$key])) return $cache[$key];
    if ($type === 'admin') return $cache[$key] = pv_admin_username($id);
    $q = db()->prepare('SELECT e.name FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.id=?');
    $q->execute([(int)$id]);
    return $cache[$key] = (string)($q->fetchColumn() ?: '');
}

/** 按网址 / 名称关键字推断平台类别。 */
function pv_guess_category($text)
{
    $t = mb_strtolower((string)$text);
    $rules = ['宝塔面板' => ['宝塔', 'bt.cn', ':8888', 'btpanel'], '1Panel' => ['1panel'], 'MySQL 数据库' => ['mysql', ':3306', 'phpmyadmin', '数据库'], '腾讯云' => ['腾讯云', 'cloud.tencent', 'qcloud', 'tencentcloud'], '阿里云' => ['阿里云', 'aliyun', 'alibabacloud'], '雅云' => ['雅云', 'yayun', 'yaoyun'], '野草云' => ['野草云', 'yecaoyun'], '商务中国' => ['商务中国', 'bizcn'], '新网' => ['新网', 'xinnet'], '邮箱' => ['邮箱', 'mail.', 'exmail'], '服务器' => ['服务器', 'ssh', 'root@', ':22', '远程桌面', 'rdp']];
    foreach ($rules as $category => $words) foreach ($words as $word) if (mb_strpos($t, $word) !== false) return $category;
    return '';
}

/** 带“密码 / password / 密钥”等标签的值换成占位符，返回 [替换后文本, 占位符 => 原值]。 */
function pv_mask_secrets($text)
{
    $secrets = [];
    $masked = preg_replace_callback('/((?:root\s*)?(?:密码|口令|密碼|登录密码|登陆密码|数据库密码|面板密码|pwd|passwd|password|pass|secret\s*key|secretkey|access\s*key\s*secret|secret|密钥))(\s*[:：=]\s*|\s*(?:为|是)\s*|\s+)([^\s,，;；]+)/iu', function ($m) use (&$secrets) {
        $token = '⟦P' . (count($secrets) + 1) . '⟧';
        $secrets[$token] = $m[3];
        return $m[1] . $m[2] . $token;
    }, (string)$text);
    return [$masked, $secrets];
}

function pv_unmask($value, $secrets)
{
    return strtr((string)$value, $secrets);
}

/** 本地规则识别（AI 未配置或失败时托底）：按空行分块，逐块取网址、IP、账号、密码。 */
function pv_parse_local($text)
{
    $items = [];
    foreach (preg_split('/\n\s*\n/u', str_replace("\r", '', (string)$text)) as $block) {
        $block = trim($block);
        if ($block === '') continue;
        $item = ['category' => '', 'name' => '', 'url' => '', 'host' => '', 'account' => '', 'password' => '', 'notes' => ''];
        $rest = preg_replace('/\s+/u', ' ', $block);
        $take = function ($pattern) use (&$rest) { if (!preg_match($pattern, $rest, $m)) return ''; $rest = str_replace($m[0], ' ', $rest); return trim(end($m)); };
        $item['url'] = $take('~https?://[^\s，,;；]+~i');
        if ($item['url'] === '' && preg_match('~https?://[^\s，,;；]+~i', $block, $m)) $item['url'] = $m[0];
        $item['host'] = $take('/(?:(?:主机|服务器|IP|地址)\s*[:：=]?\s*)?\b(\d{1,3}(?:\.\d{1,3}){3}(?::\d{2,5})?)\b/iu');
        if ($item['host'] === '' && $item['url'] !== '' && preg_match('~^https?://(\d{1,3}(?:\.\d{1,3}){3}(?::\d{2,5})?)~', $item['url'], $m)) $item['host'] = $m[1];
        $label = '(?:账号|帐号|用户名|登录名|用户|user(?:name)?|account|login)';
        $sep = '(?:\s*[:：=]\s*|\s*(?:为|是)\s*|\s+)';
        $item['password'] = $take('/(?<![\p{Han}A-Za-z])(?:root\s*)?(?:密码|口令|登录密码|pwd|passwd|password|pass)' . $sep . '([^\s,，;；]+)/iu');
        $item['account'] = $take('/(?<![\p{Han}A-Za-z])' . $label . $sep . '(?!https?:)([^\s,，;；]+)/iu');
        // 没写“账号”标签但紧挨着密码前的单词（如 root password=…）
        if ($item['account'] === '' && $item['password'] !== '' && preg_match('/(?:^|\s)([A-Za-z0-9_.@-]{2,40})\s+(?:密码|password|pwd|pass)/iu', preg_replace('/\s+/u', ' ', $block), $m) && !preg_match('/^(mysql|redis|ftp|ssh)$/i', $m[1])) { $item['account'] = $m[1]; $rest = preg_replace('/(?<!\S)' . preg_quote($m[1], '/') . '(?!\S)/u', ' ', $rest, 1); }
        $rest = trim(preg_replace('/\s+/u', ' ', $rest));
        // 名称：剩余文字的前几个词；其余作为备注（如安全入口、到期时间）
        $words = $rest === '' ? [] : explode(' ', $rest);
        $nameWords = [];
        foreach ($words as $i => $word) { if ($i >= 3 || preg_match('/^(安全入口|到期|有效期|端口|数据库|备注|手机|邮箱|绑定)/u', $word)) break; $nameWords[] = $word; }
        $item['name'] = mb_substr(implode(' ', $nameWords), 0, 60);
        $item['notes'] = mb_substr(trim(implode(' ', array_slice($words, count($nameWords)))), 0, 500);
        $item['category'] = pv_guess_category($block) ?: '其他';
        if ($item['name'] === '') $item['name'] = $item['category'];
        if ($item['url'] !== '' || $item['host'] !== '' || $item['account'] !== '' || $item['password'] !== '') $items[] = $item;
    }
    return $items;
}

/**
 * 粘贴文字 → 结构化条目。$categories 为允许的类别（平台信息或项目账号类型）。
 * 返回 ['items' => [...], 'source' => 'ai'|'local', 'note' => 说明]。
 */
function pv_parse_paste($text, array $categories, $actor)
{
    $text = trim((string)$text);
    if ($text === '') throw new RuntimeException('请先粘贴要识别的文字');
    if (mb_strlen($text) > 12000) throw new RuntimeException('文字太长，请分几次粘贴（每次不超过 12000 字）');
    [$masked, $secrets] = pv_mask_secrets($text);
    $items = null; $note = '';
    if (ps_ai_ready()) {
        try {
            $prompt = "下面是一段记录各类平台登录信息的文字（服务器、面板、数据库、云平台、域名注册商等），其中 ⟦P数字⟧ 是已隐藏的密码占位符，必须原样保留在对应字段里，不要改写或猜测。\n"
                . "请识别出每一个独立的登录条目，输出 JSON：{\"items\":[{\"category\":\"\",\"name\":\"\",\"url\":\"\",\"host\":\"\",\"account\":\"\",\"password\":\"\",\"notes\":\"\"}]}\n"
                . "category 只能从这些里选：" . implode('、', $categories) . "；name 写简短名称（如“腾讯云主账号”“官网服务器”）；url 为登录网址；host 为 IP / 域名与端口；account 为登录账号；password 为密码（通常就是占位符）；notes 放其它有用信息（如到期时间、安全入口、数据库名、端口、手机号），没有就留空。没有的字段留空字符串，不要编造。只输出 JSON。\n\n"
                . $masked;
            $messages = [['role' => 'system', 'content' => '你是严谨的信息整理助手，只输出 JSON。'], ['role' => 'user', 'content' => $prompt]];
            // 网络偶发（DNS / 连接超时）时自动重试一次
            // 推理型模型的思考也占 max_tokens：给足额度，避免内容多时正文被挤空
            try { $reply = ps_ai_chat($messages, 10000); } catch (RuntimeException $e) { if (mb_strpos($e->getMessage(), '无法连接') === false) throw $e; $reply = ps_ai_chat($messages, 10000); }
            $data = ps_ai_json($reply);
            $items = is_array($data['items'] ?? null) ? $data['items'] : null;
            if ($items === null) throw new RuntimeException('AI 未返回条目');
        } catch (RuntimeException $e) {
            $note = 'AI 识别失败（' . mb_substr($e->getMessage(), 0, 60) . '），已改用规则识别，请逐条核对';
            $items = null;
        }
    } else $note = 'AI 未启用，已按规则识别，请逐条核对';
    $source = $items === null ? 'local' : 'ai';
    if ($items === null) $items = pv_parse_local($masked);
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $row = [];
        foreach (['category', 'name', 'url', 'host', 'account', 'password', 'notes'] as $field) $row[$field] = mb_substr(trim(pv_unmask((string)($item[$field] ?? ''), $secrets)), 0, $field === 'notes' ? 2000 : 500);
        if (!in_array($row['category'], $categories, true)) { $guess = pv_guess_category($row['name'] . ' ' . $row['url'] . ' ' . $row['host']); $row['category'] = in_array($guess, $categories, true) ? $guess : (in_array('其他', $categories, true) ? '其他' : $categories[0]); }
        if ($row['url'] === '' && $row['host'] === '' && $row['account'] === '' && $row['password'] === '') continue;
        $out[] = $row;
    }
    // 模型漏掉的占位符（密码没被放进任何条目）提示核对
    $used = implode("\n", array_column($out, 'password')) . implode("\n", array_column($out, 'notes'));
    $missing = array_filter(array_values($secrets), function ($s) use ($used) { return mb_strpos($used, $s) === false; });
    if ($missing) $note = trim($note . '；有 ' . count($missing) . ' 个密码没对应到条目，请对照原文补填', '；');
    ps_audit('vault', 0, 'ai_parse', $actor, ['source' => $source, 'items' => count($out), 'masked' => count($secrets)]);
    return ['items' => $out, 'source' => $source, 'note' => $note];
}

function pv_items($search = '', $category = '')
{
    $sql = 'SELECT * FROM project_vault_items WHERE deleted_at IS NULL';
    $params = [];
    if ($category !== '') { $sql .= ' AND category=?'; $params[] = $category; }
    if ($search !== '') { $sql .= ' AND (name LIKE ? OR url LIKE ? OR host LIKE ? OR account LIKE ?)'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like, $like); }
    $q = db()->prepare($sql . ' ORDER BY FIELD(category,' . implode(',', array_fill(0, count(PV_CATEGORIES), '?')) . '), name, id');
    $q->execute(array_merge($params, PV_CATEGORIES));
    return $q->fetchAll();
}

function pv_item_save($data, $actor, $id = 0)
{
    $category = in_array($data['category'] ?? '', PV_CATEGORIES, true) ? $data['category'] : '其他';
    $name = mb_substr(trim((string)($data['name'] ?? '')), 0, 120);
    $url = mb_substr(trim((string)($data['url'] ?? '')), 0, 500);
    $host = mb_substr(trim((string)($data['host'] ?? '')), 0, 200);
    $account = mb_substr(trim((string)($data['account'] ?? '')), 0, 200);
    if ($name === '') $name = $url !== '' ? (parse_url($url, PHP_URL_HOST) ?: $url) : ($host !== '' ? $host : $category);
    if ($url === '' && $host === '' && $account === '') throw new RuntimeException('至少填写登录网址、主机或账号中的一项');
    if ($id) {
        $fields = 'category=?,name=?,url=?,host=?,account=?,updated_by_type=?,updated_by_id=?';
        $params = [$category, $name, $url, $host, $account, $actor['type'], $actor['id']];
        // 编辑时密码 / 备注留空表示不修改
        if (array_key_exists('password', $data) && (string)$data['password'] !== '') { $fields .= ',password_enc=?'; $params[] = pv_encrypt($data['password']); }
        if (array_key_exists('notes', $data)) { $fields .= ',notes_enc=?'; $params[] = pv_encrypt(trim((string)$data['notes'])); }
        $params[] = (int)$id;
        db()->prepare("UPDATE project_vault_items SET $fields WHERE id=? AND deleted_at IS NULL")->execute($params);
        ps_audit('vault', (int)$id, 'update', $actor, ['name' => $name, 'category' => $category, 'password_changed' => (string)($data['password'] ?? '') !== '']);
        return (int)$id;
    }
    db()->prepare('INSERT INTO project_vault_items (category,name,url,host,account,password_enc,notes_enc,created_by_type,created_by_id,updated_by_type,updated_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$category, $name, $url, $host, $account, pv_encrypt($data['password'] ?? ''), pv_encrypt(trim((string)($data['notes'] ?? ''))), $actor['type'], $actor['id'], $actor['type'], $actor['id']]);
    $newId = (int)db()->lastInsertId();
    ps_audit('vault', $newId, 'create', $actor, ['name' => $name, 'category' => $category, 'via' => $data['via'] ?? 'manual']);
    return $newId;
}

/** 项目账号密码：订单是否适用、当前人能否查看（跟随订单权限）。 */
function pv_order_enabled($order)
{
    return in_array(ps_business_normalize($order['project_type'] ?? ''), PV_ORDER_BUSINESSES, true);
}

function pv_order_credentials($orderId)
{
    $q = db()->prepare('SELECT * FROM project_order_credentials WHERE order_id=? AND deleted_at IS NULL ORDER BY id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

/**
 * 订单列表搜索：按项目账号的类型/说明/地址/账号/备注匹配，返回 [order_id => 命中说明]。
 * 密码不参与搜索；备注是密文，需在 PHP 里解密后匹配。
 */
function pv_search_order_hits($keyword)
{
    $keyword = trim((string)$keyword);
    if ($keyword === '') return [];
    $rows = db()->query('SELECT order_id, kind, label, url, account, notes_enc FROM project_order_credentials WHERE deleted_at IS NULL')->fetchAll();
    $hits = [];
    foreach ($rows as $row) {
        $oid = (int)$row['order_id'];
        if (isset($hits[$oid])) continue;
        $fields = ['类型' => $row['kind'], '说明' => $row['label'], '地址/IP' => $row['url'], '账号' => $row['account']];
        $found = '';
        foreach ($fields as $name => $value) {
            if ($value !== '' && mb_stripos((string)$value, $keyword) !== false) { $found = $name . '：' . $value; break; }
        }
        if ($found === '' && $row['notes_enc'] !== '' && mb_stripos(pv_decrypt($row['notes_enc']), $keyword) !== false) $found = '备注';
        if ($found !== '') $hits[$oid] = $found;
    }
    return $hits;
}

function pv_order_credential_save($orderId, $data, $actor)
{
    $kind = in_array($data['kind'] ?? '', PV_ORDER_KINDS, true) ? $data['kind'] : '其他';
    $url = mb_substr(trim((string)($data['url'] ?? '')), 0, 500);
    $account = mb_substr(trim((string)($data['account'] ?? '')), 0, 200);
    $label = mb_substr(trim((string)($data['label'] ?? '')), 0, 120);
    if ($url === '' && $account === '' && trim((string)($data['password'] ?? '')) === '') return 0;
    db()->prepare('INSERT INTO project_order_credentials (order_id,kind,label,url,account,password_enc,notes_enc,created_by_type,created_by_id) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$orderId, $kind, $label, $url, $account, pv_encrypt($data['password'] ?? ''), pv_encrypt(trim((string)($data['notes'] ?? ''))), $actor['type'], $actor['id']]);
    $id = (int)db()->lastInsertId();
    ps_audit('order', (int)$orderId, 'credential_add', $actor, ['credential_id' => $id, 'kind' => $kind]);
    return $id;
}
