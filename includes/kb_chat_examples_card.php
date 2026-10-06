<?php
// 合作商看板：展示与登录合作商同角色的最新“精选聊天记录”示例（已采纳的）。
// 财务查看某位合作商的看板时，按该合作商的角色显示。依赖调用方已定义 $actor，可选 $employeeId。
require_once __DIR__ . '/ProjectKnowledgeSkills.php';
try {
    $kbCardActor = $actor;
    if (($actor['type'] ?? '') === 'admin' || ($actor['role'] ?? '') === 'finance') {
        $kbCardActor = null;
        if (!empty($employeeId)) {
            $kq = db()->prepare('SELECT id,employee_id,role FROM project_users WHERE employee_id=? AND is_active=1 ORDER BY id LIMIT 1');
            $kq->execute([(int)$employeeId]);
            if ($ku = $kq->fetch()) $kbCardActor = ['type' => 'employee', 'id' => (int)$ku['id'], 'employee_id' => (int)$ku['employee_id'], 'role' => $ku['role']];
        }
    }
    $kbRoles = $kbCardActor ? (empty($actor['preview']) && ($kbCardActor['id'] ?? 0) === ($actor['id'] ?? -1) && ($actor['type'] ?? '') === 'employee' ? pks_actor_roles($kbCardActor) : pks_auto_roles($kbCardActor)) : [];
    if ($kbRoles) {
        pks_ensure();
        $kbMatch = '(' . implode(' OR ', array_fill(0, count($kbRoles), 'FIND_IN_SET(?,roles)>0')) . ')';
        $kq = db()->prepare("SELECT id,title,scenario,chat_log,content,updated_at,$kbMatch AS role_match FROM project_kb_skills WHERE deleted_at IS NULL AND status='approved' AND kind='chat' AND (roles='' OR $kbMatch) ORDER BY role_match DESC, updated_at DESC, id DESC LIMIT 3");
        $kq->execute(array_merge($kbRoles, $kbRoles));
        $kbExamples = $kq->fetchAll();
        $kbRoleText = implode('、', array_map(function ($r) { return PKS_ROLES[$r]; }, $kbRoles));
    } else {
        $kbExamples = null;
    }
} catch (Throwable $e) {
    $kbExamples = null;
}
if ($kbExamples !== null):
?>
<link rel="stylesheet" href="<?php echo pks_asset('css/knowledge_skills.css'); ?>">
<section class="kbx" style="max-width:1400px;margin:1.2rem auto 2rem;padding:0 4px" data-no-keywords aria-label="精选聊天记录">
  <div class="kbx-toolrow">
    <div><strong style="font-size:1.05rem"><i class="fas fa-quote-right" style="color:var(--k-chat)"></i> 同角色的精选聊天记录</strong>
      <span class="kb-muted" style="font-size:.84rem;margin-left:.5rem">角色：<?php echo e($kbRoleText); ?> · 最新采纳的示例</span></div>
    <div class="kbx-actions"><a class="kbx-btn ghost sm" href="<?php echo BASE_URL; ?>/project/knowledge_skills.php?kind=chat">更多精选 →</a><a class="kbx-btn sm" href="<?php echo BASE_URL; ?>/project/knowledge_skill_import.php"><i class="fas fa-magic"></i> 导入我的聊天记录</a></div>
  </div>
  <?php if (!$kbExamples): ?>
    <div class="kbx-empty" style="padding:1.6rem 1rem"><span class="ic"><i class="far fa-comments"></i></span><h2 style="font-size:1rem">还没有同角色的精选聊天记录</h2><p>把你和客户的一段好对话导入进来，采纳后大家都能学到。</p></div>
  <?php else: ?>
  <div class="kbx-grid" style="grid-template-columns:repeat(auto-fill,minmax(360px,1fr))">
    <?php foreach ($kbExamples as $ex): $kbTurns = array_slice(pks_parse_chat($ex['chat_log']), 0, 4); ?>
      <article class="kbx-card k-chat<?php echo $ex['role_match'] ? ' is-me' : ''; ?>">
        <div class="head"><span class="kbx-kind"><i class="fas fa-quote-right"></i> 精选聊天记录</span><span class="kb-muted num"><?php echo e(substr($ex['updated_at'], 0, 10)); ?></span></div>
        <h3><a href="<?php echo BASE_URL; ?>/project/knowledge_skill.php?id=<?php echo (int)$ex['id']; ?>"><?php echo e($ex['title']); ?></a></h3>
        <?php if ($ex['scenario'] !== ''): ?><p class="sc"><i class="far fa-lightbulb"></i> <?php echo e($ex['scenario']); ?></p><?php endif; ?>
        <div class="kbx-chat" style="gap:.35rem"><?php foreach ($kbTurns as $t): ?><div class="kbx-msg <?php echo e($t['speaker']); ?>"><span class="av"><?php echo $t['speaker'] === 'agent' ? '服' : '客'; ?></span><div class="bb" style="font-size:.84rem;padding:.4rem .7rem"><?php echo e(mb_strlen($t['text']) > 70 ? mb_substr($t['text'], 0, 70) . '…' : $t['text']); ?></div></div><?php endforeach; ?></div>
        <?php if ($ex['content'] !== ''): ?><p class="pv" style="-webkit-line-clamp:2"><i class="far fa-star" style="color:#d69a00"></i> <?php echo e($ex['content']); ?></p><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
