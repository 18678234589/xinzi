<?php
// 侧栏“客服绩效”入口：后台财务账号、设计客服主管、设计客服部门员工可见。
require_once __DIR__ . '/lib/cs_perf_reception.php';
$servicePerfActor = ps_actor();
if ($servicePerfActor && csr_can_view($servicePerfActor)) {
    echo $nav('/project/service_performance.php', 'fa-headset', '客服绩效', $_rel === 'project/service_performance.php');
}
