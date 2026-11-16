<?php
// 侧栏“订单审核申请”：财务看到待处理数；合作人员（客服 / 技术）随时可进，看自己没审核通过的订单卡在哪。
$batchReviewActor = ps_actor();
if ($batchReviewActor) {
    $batchReviewFinance = ($batchReviewActor['role'] ?? '') === 'finance';
    require_once __DIR__ . '/ProjectBatchReview.php';
    // 财务、客服 / 技术，以及被授权审核的人员 / 部门主管（含管理层账号）都能进
    if ($batchReviewFinance || (!empty($batchReviewActor['employee_id']) && (in_array($batchReviewActor['role'] ?? '', ['customer_service', 'technical'], true) || pbr_scope($batchReviewActor)))) {
        // 财务和被授权审核的人：显示自己能审的“没通过审核”订单数（上两个整月）；财务另显示别人提交的申请数
        $batchReviewTodo = pbr_scope($batchReviewActor) ? pbr_reviewable_count($batchReviewActor) : 0;
        $batchReviewReq = $batchReviewFinance ? pbr_pending_count() : 0;
        $batchReviewLabel = $batchReviewReq && $batchReviewTodo ? '（申请 ' . $batchReviewReq . ' · 待审 ' . $batchReviewTodo . '）' : ($batchReviewReq ? '（申请 ' . $batchReviewReq . '）' : ($batchReviewTodo ? '（待审 ' . $batchReviewTodo . '）' : ''));
        echo $nav('/project/batch_review.php', 'fa-clipboard-check', '订单审核申请' . $batchReviewLabel, $_rel === 'project/batch_review.php');
    }
}
