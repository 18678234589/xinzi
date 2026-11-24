<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
// 三天脑洞等管理层奖励的“已发放”登记：仅财务 / 管理员。只改发放状态和发放日期，奖励金额与评审结论不动。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('仅财务可登记奖励发放'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Location: ' . BASE_URL . '/project/governance_ideas.php'); exit; }
ps_check_csrf();
$id = (int)($_POST['record_id'] ?? 0);
$do = (string)($_POST['do'] ?? '');
$back = BASE_URL . '/project/governance_ideas.php';
try {
    if (!in_array($do, ['paid', 'unpaid'], true)) throw new RuntimeException('操作无效');
    $paidOn = $do === 'paid' ? pg_validate_date($_POST['paid_on'] ?? '') : null;
    if ($do === 'paid' && ($paidOn === null || $paidOn > date('Y-m-d'))) $paidOn = date('Y-m-d');
    db()->beginTransaction();
    $q = db()->prepare("SELECT id,bonus_delta,review_state,reward_status,reward_paid_on FROM project_governance_records WHERE id=? AND record_kind IN ('chair','committee') FOR UPDATE");
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('记录不存在');
    if ($row['review_state'] !== 'approved' || $row['bonus_delta'] === null || (float)$row['bonus_delta'] <= 0) throw new RuntimeException('只有已评审通过、且有奖励金额的记录可以登记发放');
    if ($do === 'paid' && $row['reward_status'] === 'paid') throw new RuntimeException('这条已经标记为已发放');
    db()->prepare('UPDATE project_governance_records SET reward_status=?,reward_paid_on=? WHERE id=?')->execute([$do === 'paid' ? 'paid' : 'unpaid', $paidOn, $id]);
    ps_audit('governance_record', $id, $do === 'paid' ? 'idea_reward_paid' : 'idea_reward_unpaid', $actor, ['bonus_delta' => $row['bonus_delta'], 'paid_on' => $paidOn, 'before' => $row['reward_status']]);
    db()->commit();
    header('Location: ' . $back . '?rewarded=' . ($do === 'paid' ? 'paid' : 'unpaid') . '#idea-' . $id);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    header('Location: ' . $back . '?reward_error=' . rawurlencode($e instanceof RuntimeException ? $e->getMessage() : '保存失败，请重试') . '#idea-' . $id);
}
exit;
