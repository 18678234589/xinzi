<?php

            // 已审核订单也允许提交更正；这里只记录申请并通知财务/管理员，不改任何金额。
            $tEmp = (int)($_POST['target_employee_id'] ?? 0);
            $tGroup = (string)($_POST['target_group'] ?? '');
            $shown = null; $detail = [];
            $orderRow = ps_order($id, $actor);
            if (in_array($orderRow['settlement_status'], ['approved', 'locked'], true)) {
                $sq = db()->prepare('SELECT * FROM project_commission_snapshots WHERE order_id=? AND employee_id=? AND commission_group=? ORDER BY id DESC LIMIT 1');
                $sq->execute([$id, $tEmp, $tGroup]);
                if ($snap = $sq->fetch()) { $shown = round((float)$snap['commission_amount'] + (float)$snap['subsidy_amount'], 2); $detail = ['source' => 'snapshot', 'snapshot' =>
    $snap]; }
            } else {
                $sumNow = ps_summary($orderRow, ps_costs($id), ps_participants($id));
                foreach ($sumNow['groups'][$tGroup]['people'] ?? [] as $pp) if ((int)$pp['employee_id'] === $tEmp) {
                    if ($pp['calc']) { $shown = round($pp['calc']['share'] + $pp['calc']['subsidy'], 2); }
                    $detail = ['source' => 'live', 'calc' => $pp['calc'], 'estimated' => $pp['estimated_calc'], 'receipt' => $orderRow['receipt_amount'], 'refund' => $orderRow['refund_amount'
    ], 'contract' => $orderRow['contract_amount']];
                }
            }
            ps_corr_submit($id, $actor, $tEmp, $tGroup, $_POST['reason'] ?? '', $_POST['expected_amount'] ?? '', $shown, $detail);
            header('Location: ' . BASE_URL . '/project/order.php?id=' . $id . '&corr=1'); exit;
        