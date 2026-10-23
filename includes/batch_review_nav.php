<?php
// 侧栏“订单审核申请”：财务看到待处理数；合作人员（客服 / 技术）随时可进，看自己没审核通过的订单卡在哪。
$batchReviewActor = ps_actor();
if ($batchReviewActor) {
    $batchReviewFinance = ($batchReviewActor['role'] ?? '') === 'finance';
    require_once __DIR__ . '/ProjectBatchReview.php';
    // 财务、客服 / 技术，以及被授权审核的人员 / 部门主管（含管理层账号）都能进
    if ($batchReviewFinance || (!empty($batchReviewActor['employee_id']) && (in_array($batchReviewActor['role'] ?? '', ['customer_service', 'technical'], true) || pbr_scope($batchReviewActor)))) {
        $batchReviewN = 0;
        if ($batchReviewFinance) { $batchReviewN = pbr_pending_count(); }
        echo $nav('/project/batch_review.php', 'fa-clipboard-check', '订单审核申请' . ($batchReviewN ? '（' . $batchReviewN . '）' : ''), $_rel === 'project/batch_review.php');
    }
}
