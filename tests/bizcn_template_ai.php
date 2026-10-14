<?php
/** 离线测试：实名模板智能识别。不联网、不访问数据库、不使用真实证件；AI 与商务中国接口均为替身。 */
define('BT_TEST_UPLOADS', true);
$checks = 0;
function tt($ok, $message) { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $message); $checks++; }
function trej($fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } }
$audit = []; $bdCalls = []; $aiReady = true; $aiReply = []; $aiMessages = null; $createResult = ['contid' => 'bizfixture001'];
function ps_audit($entity, $id, $action, $actor, $data = []) { global $audit; $audit[] = [$entity, $action, $data]; }
function ps_ai_ready() { global $aiReady; return $aiReady; }
function ps_ai_chat(array $messages, $max = 1200, $config = null) { global $aiReply, $aiMessages; $aiMessages = $messages; if ($aiReply === 'boom') throw new RuntimeException('fixture ai down'); return json_encode($aiReply, JSON_UNESCAPED_UNICODE); }
function ps_ai_json($content) { return json_decode($content, true); }
function bd_call($service, $method, $params, $timeout = 18) { global $bdCalls, $createResult; $bdCalls[] = [$service, $method, $params, $timeout]; if ($method === 'queryTemplateAuthStatus') return ['auditstatus' => 'cnnicauditing']; return $createResult; }
$settings = [];
function ps_setting_get($key, $default = null) { global $settings; return $settings[$key] ?? $default; }
function ps_setting_set($key, $value, $adminId) { global $settings; $settings[$key] = $value; }
require __DIR__ . '/../includes/BizcnTemplateAi.php';

// 校验位（公开示例号码，非真实个人 / 企业）
tt(bt_id_card_valid('11010519491231002X'), 'valid id card');
tt(!bt_id_card_valid('110105194912310021'), 'bad id check digit');
tt(bt_credit_code_valid('91350100M000100Y43'), 'valid credit code');
tt(!bt_credit_code_valid('91350100M000100Y44'), 'bad credit code');

// 规则识别
$h = bt_text_hints("联系人张三 手机13812345678 邮箱 Zhang@Example.com\n证件 11010519491231002X 统一码 91350100M000100Y43");
tt($h['phone'] === '13812345678' && $h['email'] === 'zhang@example.com', 'phone and email hints');
tt($h['id_card'] === '11010519491231002X' && $h['credit_code'] === '91350100M000100Y43', 'id and credit hints');
tt(bt_text_hints('号码 110105194912310021')['id_card'] === '', 'invalid id not trusted');

// 省市
tt(bt_match_region('山东', '济南', '') === ['山东省', '济南市'], 'short names normalised');
tt(bt_match_region('', '', '山东省青岛市市南区香港中路1号') === ['山东省', '青岛市'], 'city from address');
tt(bt_match_region('广西', '南宁', '') === ['广西壮族自治区', '南宁市'], 'autonomous region');
tt(bt_match_region('上海', '', '浦东新区')[1] === '上海市', 'municipality single city');
tt(bt_match_region('不存在省', '不存在市', '') === ['', ''], 'unknown region rejected');

// 电话
tt(bt_phone('138-1234-5678') === '+86.13812345678' && bt_phone('+8613812345678') === '+86.13812345678', 'mobile normalised');
tt(bt_phone('0532-85001234') === '+86.053285001234' && bt_phone('12345') === '', 'landline keeps leading zero, junk rejected');

// 合并：规则结果优先于 AI；缺项标警告；默认联系方式
$ai = ['kind' => 'company', 'template_name' => '某某科技', 'owner_name' => '济南某某科技有限公司', 'cert_type' => 'YYZZ', 'cert_no' => '91350100M000100Y4З', 'surname' => '张', 'given_name' => '三', 'province' => '山东', 'city' => '济南', 'address' => '历下区经十路1号', 'postcode' => '250000', 'phone' => '', 'email' => '', 'org_e' => 'Jinan Moumou Technology Co., Ltd.', 'surname_e' => 'Zhang', 'given_name_e' => 'San', 'province_e' => 'Shandong', 'city_e' => 'Jinan', 'address_e' => 'No.1 Jingshi Road, Lixia District'];
list($f, $w) = bt_merge_fields($ai, bt_text_hints('统一码 91350100M000100Y43'), ['phone' => '+86.13900000000', 'email' => 'ops@example.com']);
tt($f['cert_no'] === '91350100M000100Y43' && in_array('cert_no', $w, true), 'text credit code beats misread OCR and is flagged');
tt($f['province'] === '山东省' && $f['city'] === '济南市' && $f['phone'] === '+86.13900000000' && in_array('contact_default', $w, true), 'region + default contact');
list($f2, $w2) = bt_merge_fields(['kind' => 'person', 'owner_name' => '李四', 'cert_no' => '110105194912310021'], bt_text_hints(''), []);
tt($f2['kind'] === 'person' && $f2['cert_type'] === 'SFZ' && in_array('cert_no', $w2, true) && in_array('region', $w2, true), 'invalid id flagged, missing region flagged');
$ai['phone'] = '`rm -rf /` 13711112222';
list($f3) = bt_merge_fields($ai, bt_text_hints(''), []);
tt($f3['phone'] === '' || preg_match('/^\+86\.\d+$/D', $f3['phone']), 'phone from AI is always normalised');
tt(preg_match('/^[A-Za-z0-9 ,.\-#\/&()]*$/D', bt_ascii('<b>中文x</b>', 20)) === 1 && strpos(bt_ascii('<b>', 20), '<') === false, 'english field is ascii only');

// 严格校验
$good = $f; $good['cert_no'] = '91350100M000100Y43'; $good['phone'] = '13812345678'; $good['email'] = 'a@b.com'; $good['postcode'] = '250000';
$v = bt_validate_fields($good);
tt($v['phone'] === '+86.13812345678' && $v['city'] === '济南市', 'valid fields accepted');
foreach (['cert_no' => '91350100M000100Y44', 'city' => '广州市', 'postcode' => '25', 'email' => 'bad', 'phone' => '123', 'address_e' => '中文', 'surname' => '', 'template_name' => ''] as $key => $value) {
    $bad = $good; $bad[$key] = $value;
    tt(trej(function () use ($bad) { bt_validate_fields($bad); }), 'reject bad ' . $key);
}

// 图片：身份证正反面拼成一张，大小在 55K–900K 之间；坏文件被拒
function noisy($w, $h, $seed) { $im = imagecreatetruecolor($w, $h); mt_srand($seed); for ($i = 0; $i < 4000; $i++) imagefilledrectangle($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255))); return $im; }
$dir = sys_get_temp_dir() . '/bt_' . getmypid(); mkdir($dir);
imagejpeg(noisy(1800, 1100, 1), "$dir/front.jpg", 95); imagepng(noisy(1500, 950, 2), "$dir/back.png"); file_put_contents("$dir/bad.jpg", 'not an image');
$one = bt_certificate_jpeg(["$dir/front.jpg"]); $two = bt_certificate_jpeg(["$dir/front.jpg", "$dir/back.png"]);
tt(strlen($one) >= 56 * 1024 && strlen($one) <= 900 * 1024 && strlen($two) <= 900 * 1024, 'jpeg within provider limits');
$size = getimagesizefromstring($two); tt($size[2] === IMAGETYPE_JPEG && $size[1] > $size[0] * 0.9, 'front and back stitched vertically');
tt(trej(function () use ($dir) { bt_certificate_jpeg(["$dir/bad.jpg"]); }), 'non-image refused');
tt(trej(function () { bt_certificate_jpeg([]); }), 'image required');
$tiny = imagecreatetruecolor(200, 120); imagejpeg($tiny, "$dir/tiny.jpg", 90);
tt(trej(function () use ($dir) { bt_certificate_jpeg(["$dir/tiny.jpg"]); }), 'too small image refused');

// 动作：AI 识别（AI 正常、AI 故障、AI 未启用）
$files = ['images' => ['tmp_name' => ["$dir/front.jpg"], 'error' => [UPLOAD_ERR_OK]]];
$aiReply = ['kind' => 'company', 'owner_name' => '济南某某科技有限公司', 'cert_no' => '91350100M000100Y43', 'province' => '山东省', 'city' => '济南市', 'address' => '历下区经十路1号', 'surname' => '张', 'given_name' => '三', 'postcode' => '250000', 'org_e' => 'A Co', 'surname_e' => 'Zhang', 'given_name_e' => 'San', 'province_e' => 'Shandong', 'city_e' => 'Jinan', 'address_e' => 'No.1 Road'];
$actor = ['type' => 'employee', 'id' => 7];
$r = bt_action_ai(['text' => '手机 13812345678', '_files' => $files], $actor);
tt($r['fields']['phone'] === '+86.13812345678' && $r['note'] === '' && $r['images'] === 1, 'ai action fills fields');
tt(is_array($aiMessages[1]['content']) && $aiMessages[1]['content'][1]['type'] === 'image_url', 'image sent to ai as data url');
tt(strpos(json_encode($audit), '91350100M000100Y43') === false && strpos(json_encode($audit), '13812345678') === false, 'audit holds no identifiers');
$aiReply = 'boom'; $r = bt_action_ai(['text' => '手机 13812345678 邮箱 a@b.com'], $actor);
tt($r['note'] !== '' && $r['fields']['phone'] === '+86.13812345678', 'ai failure falls back to rules');
$aiReady = false; $r = bt_action_ai(['text' => '统一码 91350100M000100Y43'], $actor);
tt($r['fields']['cert_no'] === '91350100M000100Y43' && $r['note'] !== '', 'ai disabled falls back to rules');
tt(trej(function () use ($actor) { bt_action_ai(['text' => ''], $actor); }), 'empty request refused');

// 动作：提交
$bdCalls = []; $in = ['fields' => json_encode($good, JSON_UNESCAPED_UNICODE), 'ack' => '1', '_files' => $files];
tt(trej(function () use ($in, $actor) { $in['ack'] = '0'; bt_action_create($in, $actor); }) && !$bdCalls, 'ack required before any provider call');
tt(trej(function () use ($good, $actor, $files) { bt_action_create(['fields' => json_encode($good), 'ack' => '1'], $actor); }) && !$bdCalls, 'image required before any provider call');
$bad = $good; $bad['cert_no'] = '91350100M000100Y44';
tt(trej(function () use ($bad, $actor, $files) { bt_action_create(['fields' => json_encode($bad), 'ack' => '1', '_files' => $files], $actor); }) && !$bdCalls, 'invalid number blocked before provider call');
$r = bt_action_create($in, $actor);
tt($r['contid'] === 'bizfixture001' && count($bdCalls) === 1 && $bdCalls[0][1] === 'addTemplate' && $bdCalls[0][3] === 60, 'one provider call');
$args = $bdCalls[0][2]['domaintemplateinfo'];
tt(array_keys($args) === ['templatename', 'templatetype', 'companytype', 'org_e', 'name_e2', 'name_e1', 'country_e', 'province_e', 'city_e', 'address_e', 'postcode', 'telephone', 'fax', 'email', 'org_m', 'name_m2', 'name_m1', 'province_m', 'city_m', 'address_m', 'file_1', 'file_1_name', 'file_1_code', 'cert_type'], 'WSDL element order');
tt($args['templatetype'] === '1' && $args['companytype'] === 'PU' && $args['cert_type'] === 'YYZZ' && $args['file_1_code'] === '91350100M000100Y43' && $args['telephone'] === '+86.13812345678', 'provider arguments');
tt(substr(base64_decode($args['file_1']), 0, 2) === "\xFF\xD8", 'certificate sent as jpeg');
tt(strpos(json_encode($audit), '91350100M000100Y43') === false, 'create audit holds no identifiers');
$createResult = []; tt(trej(function () use ($in, $actor) { bt_action_create($in, $actor); }), 'missing template id reported');
$st = bt_action_status(['contid' => 'bizfixture001']); tt($st['status'] === 'cnnicauditing' && $st['label'] === '审核中', 'status label');
tt(trej(function () { bt_action_status(['contid' => 'x y']); }), 'bad template id refused');

// 管理员默认联系方式
tt(trej(function () use ($actor) { bt_action_contact(['phone' => '13800000000'], $actor); }), 'staff cannot set default contact');
bt_action_contact(['phone' => '13800000000', 'email' => 'Ops@Example.com'], ['type' => 'admin', 'id' => 1]);
tt(bt_contact() === ['phone' => '+86.13800000000', 'email' => 'ops@example.com'], 'admin default contact saved');
tt(trej(function () { bt_action_contact(['phone' => 'x'], ['type' => 'admin', 'id' => 1]); }), 'bad contact refused');

array_map('unlink', glob("$dir/*")); rmdir($dir);
echo json_encode(['passed' => $checks, 'failed' => 0, 'real_provider_calls' => 0], JSON_UNESCAPED_UNICODE) . "\n";
