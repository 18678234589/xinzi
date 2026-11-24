<?php
// 订单审核汇总提醒：每月初给“被授权审核业务的人”（如网站人事冯超）发一条站内信，列出他能审、还没通过的订单（上两个整月），按人分组、写明卡在哪。
// 财务在侧栏“订单审核申请（待审 N）”直接看到数量，不发站内信。用法：php jobs/batch_review_digest.php [--dry]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectBatchReview.php';
$dry = in_array('--dry', $argv, true);
try {
    pbr_ensure();
    [$from, $to] = pbr_review_window();
    $delegates = [];
    foreach (db()->query('SELECT DISTINCT employee_id FROM project_batch_review_delegates')->fetchAll(PDO::FETCH_COLUMN) as $id) $delegates[] = (int)$id;
    $sent = 0;
    foreach ($delegates as $eid) {
        $scope = pbr_scope(['role' => 'delegate', 'employee_id' => $eid, 'id' => 0]);
        if (!$scope) continue;
        $people = [];
        foreach (pbr_months($from, $to) as $month) foreach (pbr_employees($month, $scope) as $emp) {
            foreach (pbr_orders((int)$emp['id'], $month, $scope) as $order) {
                $key = $emp['name'];
                $people[$key]['n'] = ($people[$key]['n'] ?? 0) + 1;
                foreach ($order['why'] as $why) $people[$key]['why'][mb_substr($why, 0, 18)] = ($people[$key]['why'][mb_substr($why, 0, 18)] ?? 0) + 1;
            }
        }
        if (!$people) continue;
        uasort($people, function ($a, $b) { return $b['n'] <=> $a['n']; });
        $total = array_sum(array_column($people, 'n'));
        $lines = [];
        foreach (array_slice($people, 0, 12, true) as $name => $info) {
            arsort($info['why']);
            $lines[] = $name . ' ' . $info['n'] . ' 单（' . implode('、', array_map(function ($w, $c) { return $w . ' ' . $c; }, array_keys(array_slice($info['why'], 0, 3, true)), array_slice($info['why'], 0, 3, true))) . '）';
        }
        $body = '你被授权审核的业务里，' . substr($from, 0, 7) . ' 起共有 ' . $total . ' 张订单还没审核通过，涉及 ' . count($people) . ' 人：' . implode('；', $lines) . '。进“订单审核申请”选人和月份即可批量审核。';
        if ($dry) { echo $eid . ': ' . $body . "\n"; continue; }
        pbr_message($eid, '有 ' . $total . ' 张订单待你审核', $body, '/project/batch_review.php', 'pbr-digest:' . date('Y-m-d') . ':' . $eid);
        $sent++;
    }
    echo json_encode(['sent' => $sent, 'delegates' => count($delegates)], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) { fwrite(STDERR, 'batch_review_digest_failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n"); exit(1); }
