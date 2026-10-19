<?php
// 首页项目报酬表片段（异步加载用）。仅登录用户；财务 / 管理账号全部人员，其他合作人员只有本人。
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ProjectTerms.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // 保存合作人员结算资料（仅财务 / 管理账号）
    header('Content-Type: application/json; charset=UTF-8');
    require_once __DIR__ . '/includes/ProjectPartnerProfile.php';
    $actor = ps_actor();
    if ($actor && ($_POST['action'] ?? '') === 'request_settle_all') { // 一键申请往月全部可结算订单
        if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '页面已过期，请刷新后重试'], JSON_UNESCAPED_UNICODE); exit; }
        try {
            require_once __DIR__ . '/includes/ProjectSettlementRequest.php';
            echo json_encode(['ok' => true] + psr_submit_all($actor), JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'message' => '提交失败，请稍后再试'], JSON_UNESCAPED_UNICODE); }
        exit;
    }
    if ($actor && ($_POST['action'] ?? '') === 'request_settle') {
        if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '页面已过期，请刷新后重试'], JSON_UNESCAPED_UNICODE); exit; }
        try {
            require_once __DIR__ . '/includes/ProjectSettlementRequest.php';
            $rid = psr_submit($actor, (string)($_POST['month'] ?? ''), (array)($_POST['order_ids'] ?? []));
            echo json_encode(['ok' => true, 'id' => $rid]);
        } catch (RuntimeException $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'message' => '提交失败，请稍后再试'], JSON_UNESCAPED_UNICODE); }
        exit;
    }
    if ($actor && ($_POST['action'] ?? '') === 'save_flag') { // 财务核对标记：绿 / 黄 / 红 + 理由 + 对照金额，可通知本人
        if (!ppp_can($actor)) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE); exit; }
        if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '页面已过期，请刷新后重试'], JSON_UNESCAPED_UNICODE); exit; }
        try {
            ppf_save($actor, (int)($_POST['employee_id'] ?? 0), (string)($_POST['month'] ?? ''), (string)($_POST['color'] ?? ''), (string)($_POST['reason'] ?? ''), $_POST['ref'] ?? '', !empty($_POST['notify']));
            echo json_encode(['ok' => true]);
        } catch (RuntimeException $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'message' => '保存失败，请稍后再试'], JSON_UNESCAPED_UNICODE); }
        exit;
    }
    if ($actor && ($_POST['action'] ?? '') === 'bulk_green') { // 一键标绿：对照金额已填且差距 ≤ 5% 的人
        if (!ppp_can($actor)) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE); exit; }
        if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '页面已过期，请刷新后重试'], JSON_UNESCAPED_UNICODE); exit; }
        try {
            require_once __DIR__ . '/includes/ProjectPayrollSheet.php';
            $m = (string)($_POST['month'] ?? ''); $n = 0;
            foreach (pps_sheet($m, 0, true)['rows'] as $r) {
                if ($r['diff'] === null || abs($r['diff']) > PPF_TOLERANCE || $r['flag']['color'] === 'green') continue;
                ppf_save($actor, (int)$r['id'], $m, 'green', '与对照表差距在 5% 以内，核对无异议', (string)$r['ref'], true); $n++;
            }
            echo json_encode(['ok' => true, 'count' => $n]);
        } catch (RuntimeException $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'message' => '操作失败，请稍后再试'], JSON_UNESCAPED_UNICODE); }
        exit;
    }
    if ($actor && ($_POST['action'] ?? '') === 'request_correction') { // 本人对算得不对的订单申请更正：通知主管 + 财务
        if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '页面已过期，请刷新后重试'], JSON_UNESCAPED_UNICODE); exit; }
        try {
            require_once __DIR__ . '/includes/ProjectSettlementRequest.php';
            $res = psr_correct($actor, (string)($_POST['month'] ?? ''), (array)($_POST['order_ids'] ?? []), (string)($_POST['reason'] ?? ''));
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'message' => '提交失败，请稍后再试'], JSON_UNESCAPED_UNICODE); }
        exit;
    }
    if (!$actor || !ppp_can($actor)) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '无权限']); exit; }
    if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '页面已过期，请刷新后重试'], JSON_UNESCAPED_UNICODE); exit; }
    try {
        $act = (string)($_POST['action'] ?? 'save_profile');
        if (in_array($act, ['add_item', 'delete_item'], true)) { // 财务给某人本月加 / 扣一项（如兼职业务分成）：与结算页“手工项”同一套，锁月后不可改
            require_once __DIR__ . '/includes/ProjectPayrollExtras.php';
            $month = (string)($_POST['month'] ?? '');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new RuntimeException('月份无效');
            ps_payroll_extras_ensure();
            db()->beginTransaction();
            try {
                db()->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$month]);
                $lock = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE'); $lock->execute([$month]);
                if ($lock->fetchColumn() === 'locked') throw new RuntimeException('该月项目分成已锁定');
                ps_payroll_extras_action($act === 'add_item' ? 'add_manual_item' : 'delete_manual_item', $month, (int)($_POST['employee_id'] ?? 0), $_POST, $actor);
                db()->commit();
            } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
        } else ppp_save((int)($_POST['employee_id'] ?? 0), $_POST, $actor);
        echo json_encode(['ok' => true]);
    }
    catch (RuntimeException $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'message' => '保存失败，请稍后再试'], JSON_UNESCAPED_UNICODE); }
    exit;
}
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (isset($_GET['flag_info'])) { // 核对弹窗：系统实结算、对照金额、差距、系统给出的差异原因
    header('Content-Type: application/json; charset=UTF-8');
    require_once __DIR__ . '/includes/ProjectPayrollSheet.php';
    $a = ps_actor(); $m = (string)($_GET['pm'] ?? ''); $eid = (int)($_GET['flag_info'] ?? 0);
    if (!$a || !ppp_can($a) || $eid <= 0 || !preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', $m)) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
    $row = pps_sheet($m, $eid, true)['rows'][0] ?? ['net' => 0, 'flag' => ['color' => null, 'reason' => ''], 'ref' => null];
    $fl = ppf_all($m)[$eid] ?? null;
    echo json_encode(['ok' => true, 'net' => $row['net'] ?? 0, 'ref' => $fl && $fl['ref_net'] !== null ? (float)$fl['ref_net'] : null, 'color' => $fl['color'] ?? null, 'reason' => $fl['reason'] ?? '',
        'reasons' => ppf_reasons($eid, $m, $row, $fl['ref_net'] ?? null)], JSON_UNESCAPED_UNICODE); exit;
}
if (isset($_GET['settle_orders'])) { // 申请结算弹窗：本人某月的订单列表
    header('Content-Type: application/json; charset=UTF-8');
    require_once __DIR__ . '/includes/ProjectSettlementRequest.php';
    $a = ps_actor(); $m = (string)($_GET['pm'] ?? '');
    if (!$a || ($a['type'] ?? '') !== 'employee' || !(int)($a['employee_id'] ?? 0) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
    echo json_encode(['ok' => true, 'month' => $m, 'orders' => psr_orders((int)$a['employee_id'], $m)], JSON_UNESCAPED_UNICODE); exit;
}
$ppsFragment = true;
ob_start();
include __DIR__ . '/includes/payroll_sheet_card.php';
$html = ob_get_clean();
if ($html === '') { http_response_code(403); exit; }
echo pt_filter_html($html); // 对外用语统一替换（与整页输出一致）
