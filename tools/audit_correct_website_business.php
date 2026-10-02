<?php
// 带表头或明确的无表头网站旧表布局；默认只读预演，仅更正原表已明确标注的未结算订单。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectImportClassification.php';
$fileId = (int)($argv[1] ?? 0);
$apply = in_array('--apply', $argv, true);
if ($fileId < 1) throw new RuntimeException('请指定原始上传文件 ID');
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
$file = ps_import_file_get($fileId, $actor);
$groups = [];
foreach (ps_import_file_sheets($file) as $sheet => $rows) {
    $layout = null;
    foreach ($rows as $index => $raw) {
        $header = array_map(function ($v) { return trim((string)$v); }, $raw);
        if (in_array('订单编号', $header, true) && in_array('程序名称', $header, true) && in_array('技术', $header, true) && in_array('售价', $header, true)) {
            $layout = ['no' => array_search('订单编号', $header, true), 'program' => array_search('程序名称', $header, true), 'tech' => array_search('技术', $header, true), 'price' => array_search('售价', $header, true)];
            continue;
        }
        $cols = $layout ?? ['no' => 4, 'program' => 9, 'tech' => 18, 'price' => 5];
        $no = trim((string)($raw[$cols['no']] ?? ''));
        if (!preg_match('/^[A-Za-z0-9-]{8,40}$/', $no) || !is_numeric($raw[$cols['price']] ?? null)) continue;
        if (!$layout && count($raw) < 20) continue;
        $program = trim((string)($raw[$cols['program']] ?? ''));
        $tech = trim((string)($raw[$cols['tech']] ?? ''));
        $groups[$no] = $groups[$no] ?? ['sales' => 0.0, 'program' => '', 'technical' => '', 'lines' => [], 'custom' => false, 'conflict' => false];
        if (preg_match('/^(博山定制|华梦|大连定制|网站定制)/u', $program) && strpos($tech, '刘帅') !== false) {
            $groups[$no]['custom'] = true; $groups[$no]['program'] = $program; $groups[$no]['technical'] = $tech;
        }
        if (preg_match('/^(php|jsp|森动)/iu', $program) || ($tech !== '' && strpos($tech, '刘帅') === false)) $groups[$no]['conflict'] = true;
        $groups[$no]['sales'] += (float)$raw[$cols['price']];
        $groups[$no]['lines'][] = $index + 1;
    }
}
$pdo = db();
$read = $pdo->prepare('SELECT * FROM project_orders WHERE order_no=? FOR UPDATE');
$changed = []; $skipped = [];
$pdo->beginTransaction();
try {
    foreach ($groups as $no => $original) {
        if (!$original['custom'] || $original['conflict']) continue;
        $read->execute([(string)$no]);
        $order = $read->fetch();
        if (!$order || $order['project_type'] !== '网站模板') continue;
        if (in_array($order['settlement_status'], ['approved','locked'], true) || abs((float)$order['contract_amount'] - $original['sales']) > .01) { $skipped[] = ['order_no'=>$no,'reason'=>'已结算或售价与原表合计不一致']; continue; }
        $participant = $pdo->prepare("SELECT p.*,e.name FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=? AND p.commission_group='technical'");
        $participant->execute([(int)$order['id']]);
        $people = $participant->fetchAll();
        if (count($people) !== 1 || $people[0]['name'] !== '刘帅' || $people[0]['role_name'] !== '模板技术') { $skipped[] = ['order_no'=>$no,'reason'=>'参与岗位需要人工核对']; continue; }
        $belongs = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?');
        $belongs->execute([(int)$order['id'],(int)$file['employee_id']]);
        if (!$belongs->fetchColumn()) continue;
        $changed[] = ['id'=>(int)$order['id'],'order_no'=>(string)$no,'from'=>'网站模板','to'=>'AI网站定制','role_from'=>'模板技术','role_to'=>'前端','source_file'=>$fileId,'source'=>$original];
        if (!$apply) continue;
        $pdo->prepare("UPDATE project_orders SET project_type='AI网站定制',row_version=row_version+1 WHERE id=?")->execute([(int)$order['id']]);
        $pdo->prepare("UPDATE project_participants SET role_name='前端' WHERE id=?")->execute([(int)$people[0]['id']]);
        $pdo->prepare("UPDATE project_order_details SET business_name='AI网站定制' WHERE order_id=?")->execute([(int)$order['id']]);
        ps_audit('order',(int)$order['id'],'audit_correct_business',$actor,end($changed));
    }
    if ($apply) $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
echo json_encode(['mode'=>$apply?'applied':'read_only_preview','changed_count'=>count($changed),'changed'=>$changed,'skipped'=>$skipped,'immutable_settlements_changed'=>false], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
