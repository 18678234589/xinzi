<?php
// 侧栏“客服绩效”入口：财务、设计客服、部门主管、管理层可见。
require_once __DIR__ . '/lib/cs_perf_reception.php';
$servicePerfActor = ps_actor();
if ($servicePerfActor && csr_can_view($servicePerfActor)) {
    echo $nav('/project/service_performance.php', 'fa-headset', '客服绩效', $_rel === 'project/service_performance.php');
}
