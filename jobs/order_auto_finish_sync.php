<?php
/**
 * 每日定时任务：将交易成功满 10 天且未审核的订单自动标记为交付完成并自动核算分成。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('仅支持命令行执行'); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';

$res = ps_auto_finish_trade_success_orders();
echo date('[Y-m-d H:i:s]') . " 自动完成执行完毕：标记完成 {$res['finished']} 单，自动核算分成 {$res['approved']} 单。\n";
if ($res['orders']) {
    foreach ($res['orders'] as $ord) {
        echo " - 订单 {$ord['order_no']} (ID: {$ord['id']})：" . ($ord['approved'] ? '已完成并核算分成' : '已标记完成（待完善前置资料）') . "\n";
    }
}
