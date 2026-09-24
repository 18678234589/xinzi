<?php
/** 每日核对轮值脑洞缺报。页面打开时也会即时补算，唯一键保证不会重复扣。 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectGovernance.php';
require_once __DIR__ . '/../includes/ProjectWelfare.php';
$count = pg_sync_idea_penalties();
echo "管理层脑洞缺报新增扣减 {$count} 笔\n";
$notices = pg_sync_election_notices();
echo "监委会换届提醒新增 {$notices} 条\n";
// 首次生效为 2026-Q4。未完成评审、轮值或成员核对时保持待办，不提前转入。
$lastQuarter = pw_previous_quarter(date('Y-m-d'));
if ($lastQuarter >= pw_policy()['effective_quarter']) {
    try {
        if (pw_close_quarter($lastQuarter)) echo "福利池 {$lastQuarter} 季度结转完成\n";
        if (pw_award_quarter($lastQuarter)) echo "福利池 {$lastQuarter} 贡献奖励已预留\n";
    } catch (Throwable $e) {
        error_log('福利池季度待核对：' . $e->getMessage());
        echo "福利池 {$lastQuarter} 待核对：{$e->getMessage()}\n";
    }
}
$lastYear=(int)date('Y')-1;
if ($lastYear>=2026) {
    try {
        if (pw_award_year($lastYear)) echo "全员福利 {$lastYear} 年终奖励已预留\n";
    } catch (Throwable $e) {
        error_log('全员福利年终待核对：' . $e->getMessage());
        echo "全员福利 {$lastYear} 年终待核对：{$e->getMessage()}\n";
    }
}
