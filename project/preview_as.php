<?php
require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';
// 员工视角预览（仅管理员 / 财务）：选一位合作人员，按他的数据只读渲染他工作台首页上的模块。
// 不切换登录身份、不改动会话；页面里的提交类按钮全部停用，也不会写入任何操作记录。
$viewer = ps_require_actor();
if (($viewer['role'] ?? '') !== 'finance') { http_response_code(403); exit('仅管理员或财务可以预览员工视角'); }

$users = db()->query("SELECT u.id,u.username,u.role,u.employee_id,e.name,e.department,
    (SELECT GROUP_CONCAT(b.business_name ORDER BY b.is_default DESC,b.business_name SEPARATOR '、') FROM project_user_businesses b WHERE b.user_id=u.id) businesses
    FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.is_active=1 ORDER BY e.department,e.name")->fetchAll();
$selected = (int)($_GET['user'] ?? 0);
$actor = null; $who = null;
foreach ($users as $u) if ((int)$u['id'] === $selected) { $who = $u; $actor = ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role'], 'username' => $u['username'], 'preview' => true]; }
$roleLabel = ['technical' => '技术', 'customer_service' => '客服', 'governance' => '管理层', 'vault' => '平台管理'];
$page_title = '员工视角预览';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/rule_algo.css?v=' . (is_file(dirname(__DIR__) . '/assets/css/rule_algo.css') ? filemtime(dirname(__DIR__) . '/assets/css/rule_algo.css') : 1)); ?>">
<style>
.pv-bar{display:flex;flex-wrap:wrap;gap:.7rem 1rem;align-items:center;justify-content:space-between;margin:0 0 1rem;padding:.9rem 1.2rem;border:1px solid #ecdfc3;border-radius:16px;background:linear-gradient(135deg,#fffaf0,#fff);box-shadow:0 1px 2px #6b4a1510}
.pv-bar h2{margin:0;font-size:1.15rem;display:flex;align-items:center;gap:.6rem}.pv-bar h2 i{color:#c27a00}
.pv-bar p{margin:.15rem 0 0;font-size:.82rem;color:#7b6a4e}
.pv-bar select{min-width:300px;padding:.5rem .7rem;border-radius:11px;border:1px solid #d9c9a3;background:#fff;color:#2f2a1e;font:inherit}
.pv-who{display:flex;flex-wrap:wrap;gap:.4rem .8rem;align-items:center;margin:0 0 1rem;font-size:.86rem;color:#4a5a54}
.pv-tag{display:inline-block;padding:.12rem .65rem;border-radius:999px;background:#eef6f1;color:#17503e;font-size:.78rem}
.pv-ro{padding:.55rem .9rem;border-radius:12px;background:#fdf3df;color:#8a5200;font-size:.84rem;margin:0 0 1rem}
.pv-sec{margin:0 0 .4rem;font-size:.78rem;letter-spacing:.06em;color:#8a7a5c;font-weight:700}
</style>
<div class="ra-page" style="max-width:1180px">
  <form method="get" class="pv-bar">
    <div><h2><i class="fas fa-user-secret"></i> 员工视角预览</h2><p>选一位合作人员，查看他工作台首页会显示的内容。只读，不会切换你的登录身份，也不会改动任何数据。</p></div>
    <div><select name="user" onchange="this.form.submit()" aria-label="选择员工"><option value="">选择员工…</option>
      <?php $lastDept = null; foreach ($users as $u): if ($u['department'] !== $lastDept) { if ($lastDept !== null) echo '</optgroup>'; echo '<optgroup label="' . e($u['department']) . '">'; $lastDept = $u['department']; } ?>
        <option value="<?php echo (int)$u['id']; ?>" <?php echo $selected === (int)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['name'] . ' · ' . ($roleLabel[$u['role']] ?? $u['role'])); ?></option>
      <?php endforeach; if ($lastDept !== null) echo '</optgroup>'; ?></select></div>
  </form>
  <?php if (!$actor): ?>
    <div class="ra-empty"><i class="far fa-eye"></i><h3>先选择一位员工</h3><p>可以用来核对某位同事看到的分成算法、续费提醒是否正确，或排查“我这里看不到”的反馈。</p></div>
  <?php else: ?>
    <div class="pv-who"><strong style="font-size:1.05rem"><?php echo e($who['name']); ?></strong><span class="pv-tag"><?php echo e($roleLabel[$who['role']] ?? $who['role']); ?></span><span class="pv-tag"><?php echo e($who['department']); ?></span><?php echo $who['businesses'] ? '<span class="pv-tag">业务：' . e($who['businesses']) . '</span>' : '<span class="pv-tag" style="background:#fdeceb;color:#8d2b27">未分配业务</span>'; ?><span style="color:#8a9a94">账号 <?php echo e($who['username']); ?></span></div>
    <div class="pv-ro"><i class="fas fa-lock"></i> 预览模式：下面是该员工首页的内容，所有提交类按钮已停用。</div>
    <?php if ($who['role'] === 'governance'): ?><div class="pv-ro" style="background:#eef6f1;color:#17503e">这位是“管理层”账号，登录后默认进入管理层工作台；只有被分配了业务的管理层账号才有订单入口，下面显示的是他在订单入口页看到的内容。</div><?php endif; ?>
    <?php
    $previewActor = $actor;
    if ($previewActor['role'] === 'governance' && ps_governance_has_business($previewActor)) $previewActor['role'] = 'technical';
    $GLOBALS['rdw_no_audit'] = true; $raPreview = true;
    $actor = $previewActor;
    echo '<div class="pv-sec">近三天将到期 · 需要续费</div>';
    include __DIR__ . '/../includes/renewal_due_widget.php';
    echo '<div class="pv-sec" style="margin-top:1rem">我的分成算法</div>';
    include __DIR__ . '/../includes/rule_algo_card.php';
    echo '<div class="pv-sec" style="margin-top:1rem">同角色的精选聊天记录</div>';
    $employeeId = (int)$previewActor['employee_id'];
    include __DIR__ . '/../includes/kb_chat_examples_card.php';
    ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
