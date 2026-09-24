<?php
require_once __DIR__ . '/../includes/ProjectWelfare.php';
$actor = ps_require_actor();
$policy = pw_policy();
$page_title = '福利池规则 · 规则中心';
include __DIR__ . '/../includes/header.php';
?>
<div class="welfare-page"><section class="welfare-hero"><div><span>RULE CENTER / ALL-TEAM BENEFITS</span><h1>全员福利池规则</h1><p>把规则讲清楚，也让每一笔结转和奖励可核对。</p></div><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/welfare.php">查看福利池 →</a></section>
 <div class="welfare-layout"><section class="welfare-card"><h2>01 / 资金从哪里来</h2><p>从 <?php echo e($policy['effective_quarter']); ?> 开始。每季度轮值董事长目标 ¥<?php echo money($policy['chair_quarter_target']); ?>：履职额度 ¥<?php echo money($policy['chair_duty_portion']); ?>，创新额度最多 ¥<?php echo money((float)$policy['chair_quarter_target']-(float)$policy['chair_duty_portion']); ?>。监委会每人目标 ¥<?php echo money($policy['committee_person_target']); ?>，三人合计 ¥<?php echo money((float)$policy['committee_person_target']*3); ?>。</p><p>董事长完整轮值季度的履职额度按已生效的六天脑洞缺报扣减计算；已核验创新奖励计入创新额度，超额不从本池支付。监委会有效且已核验的监督事项每条按原规则 ¥50 计算，连同已核验额外奖惩计入个人额度，个人封顶 ¥<?php echo money($policy['committee_person_target']); ?>。</p><p>季度结束、轮值期完整、管理层事项与缺报豁免已核验后，目标额度减去已获得额度的余额一次性转入福利池。缺报扣减已包含在这笔余额中，不另作一次转入。历史季度不倒推，已结转的季度不改写。</p></section>
 <section class="welfare-card"><h2>02 / 换届与季度交叉</h2><p>轮值董事长每届任期三个月。已确认 2026-09-15 由于洋换届为栾鑫，栾鑫本届至 2026-12-14；监委会在期满前第 7 天收到站内提醒，至少两位监委投给同一候选人后自动登记下一届。季度结算按自然季度归集；若一个自然季度横跨两届，董事长履职及创新额度按各人在本季度实际轮值天数分摊，缺报和奖励按发生日期归属，绝不按两整届重复计 ¥10,000。</p></section>
 <section class="welfare-card"><h2>03 / 谁能提、谁能核</h2><p>每位合作人员可提交本人建议或 Bug；财务明确授权的部门主管可替本部门人员录入。财务负责核验证据、价值与是否重复，不得把未审核或重复内容计入奖励。审核须写依据；本人、主管和其他合作人员不能改动审核结果。</p><p>有效贡献 1 点；确已落地的改善或已修复 Bug 3 点；经证实解决重大问题 5 点。重大等级须有结果证据。财务可以退回不具体、重复、无法复现或含隐私风险的记录。</p></section>
 <section class="welfare-card"><h2>03 / 季度和年终怎么分</h2><p>季度管理层结转完成、贡献全部审核后，最多从当时可分配余额的 <?php echo number_format((float)$policy['quarterly_award_rate']*100,0); ?>% 设立当季奖励预算。按各人审核通过的点数比例计算，每人最多占这次预算的 <?php echo number_format((float)$policy['quarterly_person_cap_rate']*100,0); ?>%。不足一分舍去；未用完的预算留在福利池，不会为凑满预算而虚构奖励。</p><p>年终由财务核实合作人员资格和当年参与月份；启用规则后的各季度均结转后，系统按确认的参与月份权重自动计算并预留余额。预留时资金台账扣减一次，标记“已发放”只更新状态，不再扣一次。未确认资格不得自动发钱，未预留金额继续留在池内。</p></section>
 <section class="welfare-card"><h2>04 / 可追溯与边界</h2><p>季度结转、季度奖励、年终奖励都使用唯一来源编号和资金流水；重复运行不会重复入账。结算单显示“已预留 / 已发放”两个状态，资金计算不等于银行实际付款。规则调整须设新生效季度，已完成的季度快照不得重算。</p><p>自动核算不会凭 AI 的意见直接罚款或发奖。AI 可以帮助整理建议，最终以财务核验和有记录的轮值、评审为准。</p></section></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
