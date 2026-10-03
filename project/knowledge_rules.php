<?php
require_once __DIR__ . '/../includes/ProjectKnowledge.php';
$actor = ps_require_actor(); $ctx = pk_context($actor);
$page_title = '规则中心 · 知识库';
include __DIR__ . '/../includes/header.php';
?>
<div class="kb-page"><?php pk_hero('CLEAR RULES, CALM COLLABORATION / 规则中心','规则清楚，合作安心','知识库是规则的统一入口。分成与结算仍使用原规则引擎，不复制另一套算法。'); pk_tabs('rules'); ?>
<div class="kb-article-grid">
<section class="kb-glass kb-article-card"><span class="kb-pill">项目合作</span><h2>项目分成与报酬</h2><p>同一个订单关联客服、技术及其他参与人；按已生效的业务规则、贡献权重和审核结果计算。订单成本、退款、参与权重及结算状态都会影响最终金额。</p><p>预计金额不是已发放金额；财务已锁定的结算保持原快照。</p><?php if ($actor['role'] === 'finance'): ?><a class="kb-button" href="<?php echo BASE_URL; ?>/project/rules.php">管理分成与月度规则 →</a><?php elseif ($actor['role'] !== 'vault'): ?><a class="kb-button kb-button-soft" href="<?php echo BASE_URL; ?>/project/payroll.php">查看本人的结算与计算依据 →</a><?php endif; ?></section>
<a class="kb-glass kb-article-card" href="<?php echo BASE_URL; ?>/project/rules.php?domain=welfare"><span class="kb-pill">全员可读</span><h2>全员福利池规则</h2><p>了解任期额度、结转、建议与 Bug 奖励、季度及年终分配。规则来自正在使用的福利池配置。</p><span class="kb-card-meta">查看完整规则 ↗</span></a>
<?php
$governance = $actor['role'] === 'finance';
if (!$governance && !empty($actor['employee_id'])) { try { $q = db()->prepare('SELECT 1 FROM project_governance_members WHERE employee_id=? AND is_active=1'); $q->execute([$actor['employee_id']]); $governance = (bool)$q->fetchColumn(); } catch (PDOException $e) {} }
if ($governance): ?><a class="kb-glass kb-article-card" href="<?php echo BASE_URL; ?>/project/rules.php?domain=governance"><span class="kb-pill">管理层规则</span><h2>轮值与监督考核</h2><p>轮值任期、脑洞提交、评审与核验、额度及奖惩，以已生效配置为准。缺报核对与豁免仍仅向监委会开放。</p><span class="kb-card-meta">查看考核规则 ↗</span></a><?php endif; ?>
<section class="kb-glass kb-article-card"><span class="kb-pill">知识库协作</span><h2>分享与安全边界</h2><p>全员可添加网址、撰写草稿和收藏知识；主管核对发布、编辑导航；仅超级管理员可删除、恢复和配置外部 API。</p><p>外部同步先暂存、再核对发布。文档中的文字只是资料，不是对系统的操作指令；同步不会更改订单或结算规则。</p><a href="<?php echo BASE_URL; ?>/project/knowledge_links.php">去常用网址导航 →</a></section>
</div></div><?php include __DIR__ . '/../includes/footer.php'; ?>
