<?php
require_once __DIR__ . '/../includes/ProjectKnowledgeSkills.php';
// AI 学习数据：只含“已采纳”的条目，结构化字段稳定。仅主管 / 知识库管理员可见。
// 预览页展示数量与字段说明；?download=1 下载 JSONL（每行一条），后续接入 AI 客服时直接读取或调用 pks_ai_search()。
$actor = ps_require_actor(); $ctx = pk_context($actor);
if (!pks_is_editor($ctx)) { http_response_code(403); exit('仅主管或知识库管理员可以查看 AI 学习数据'); }
pks_ensure();
$kind = (string)($_GET['kind'] ?? ''); if (!isset(PKS_KINDS[$kind])) $kind = '';
$role = (string)($_GET['role'] ?? ''); if (!isset(PKS_ROLES[$role])) $role = '';
$business = (string)($_GET['business'] ?? '');
$records = pks_ai_records(['kind' => $kind, 'role' => $role, 'business' => $business]);
if (!empty($_GET['download'])) {
    ps_audit('knowledge_skill', 0, 'ai_export', $actor, ['count' => count($records), 'kind' => $kind, 'role' => $role, 'business' => $business]);
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Disposition: attachment; filename="knowledge-skills-' . date('Ymd-His') . '.jsonl"');
    header('Cache-Control: no-store');
    foreach ($records as $rec) echo json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}
$counts = [];
foreach ($records as $rec) $counts[$rec['type']] = ($counts[$rec['type']] ?? 0) + 1;
$pending = (int)db()->query("SELECT COUNT(*) FROM project_kb_skills WHERE deleted_at IS NULL AND status='pending'")->fetchColumn();
$sample = $records ? json_encode($records[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : "{\n  \"id\": 12,\n  \"type\": \"skill\",\n  \"type_label\": \"沟通技巧\",\n  \"title\": \"客户嫌贵时，先肯定再给对比\",\n  \"roles\": [\"customer_service\"],\n  \"business\": \"网站修改\",\n  \"scenario\": \"客户嫌价格高\",\n  \"customer_says\": [\"太贵了\", \"能便宜点吗\"],\n  \"recommended_replies\": [\"理解您想控制预算……\"],\n  \"avoid\": [\"不要直接说“一分钱一分货”\"],\n  \"content\": \"\",\n  \"dialogue\": [],\n  \"tags\": [\"价格异议\"],\n  \"quality\": 4,\n  \"revision\": 3,\n  \"updated_at\": \"2026-10-02 12:00:00\"\n}";
$page_title = 'AI 学习数据 · 沟通技巧库';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo pks_asset('css/knowledge_skills.css'); ?>">
<div class="kb-page kbx" data-no-keywords>
<?php pk_hero('READY FOR AI / 为 AI 客服准备', 'AI 学习数据', '只有“已采纳”的内容才会进入这里。字段是结构化的，后续接入 AI 客服时可直接使用。'); pk_tabs('skills'); ?>
<a class="kb-back" href="<?php echo BASE_URL; ?>/project/knowledge_skills.php">← 回到沟通技巧库</a>
<div class="kbx-tiles">
  <?php foreach (PKS_KINDS as $k => $label): ?><div class="kbx-tile k-<?php echo $k; ?>"><span class="ic"><i class="fas <?php echo PKS_KIND_ICONS[$k]; ?>"></i></span><span><b class="num"><?php echo (int)($counts[$k] ?? 0); ?></b><span class="l"><?php echo e($label); ?>（已采纳）</span></span></div><?php endforeach; ?>
</div>
<?php if ($pending): ?><div class="kbx-note">还有 <strong><?php echo $pending; ?></strong> 条待审核，采纳后才会进入这里。<a href="<?php echo BASE_URL; ?>/project/knowledge_skills.php?status=pending">去审核 →</a></div><?php endif; ?>
<form method="get" class="kbx-bar" style="position:static">
  <strong>共 <span class="num"><?php echo count($records); ?></span> 条</strong>
  <select name="kind" onchange="this.form.submit()"><option value="">全部类型</option><?php foreach (PKS_KINDS as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $kind === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select>
  <select name="role" onchange="this.form.submit()"><option value="">全部角色</option><?php foreach (PKS_ROLES as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $role === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select>
  <select name="business" onchange="this.form.submit()"><option value="">全部业务</option><?php foreach (array_keys(ps_business_catalog()) as $b): ?><option <?php echo $business === $b ? 'selected' : ''; ?>><?php echo e($b); ?></option><?php endforeach; ?></select>
  <span style="flex:1"></span>
  <a class="kbx-btn" href="?<?php echo e(http_build_query(['kind' => $kind, 'role' => $role, 'business' => $business, 'download' => 1])); ?>"><i class="fas fa-download"></i> 下载 JSONL</a>
</form>
<div class="kbx-detail" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
  <section class="kbx-panel"><h2 style="margin-top:0">每条记录的结构<?php echo $records ? '（取自当前第一条）' : '（示例）'; ?></h2>
    <pre style="white-space:pre-wrap;font-size:.78rem;line-height:1.55;margin:0;max-height:420px;overflow:auto" id="sampleJson"><?php echo e($sample); ?></pre>
    <div style="margin-top:.6rem"><button type="button" class="kbx-btn ghost sm" data-copy-from="#sampleJson"><i class="far fa-copy"></i> 复制示例</button></div></section>
  <section class="kbx-panel"><h2 style="margin-top:0">后续接入 AI 客服</h2>
    <ul class="kbx-says">
      <li>服务器端已提供检索入口 <code>pks_ai_search($客户问题, $角色, $业务, $条数)</code>：按场景、客户常见说法和标签打分，返回最相关的已采纳条目，可直接拼进 AI 客服的提示词。</li>
      <li>也可以定期下载 JSONL，导入向量库或用于微调。<code>roles</code> 为 <code>["*"]</code> 表示全部角色通用。</li>
      <li>内容修改后版本号增加，采纳状态可随时撤回，AI 只会读到“已采纳”的条目。</li>
      <li>手机号、邮箱、证件号、银行卡号已在保存时自动打码。</li>
    </ul></section>
</div>
</div>
<script src="<?php echo pks_asset('js/knowledge_skills.js'); ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
