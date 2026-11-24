<?php

// 更正订单的业务类型（订单被录进了错的业务）：财务可改任意业务；订单参与人可改成自己账号开通的业务（权限在 ps_change_order_business 里校验）。
// 未审核直接改；已审核保留原快照，差额作为调整计入未锁定月份（参与人自己改时计入下一个未锁定月份，并通知财务）。
require_once (dirname(__DIR__, 3)) . '/../includes/ProjectOrderBusiness.php';
$result = ps_change_order_business($id, (string)($_POST['new_business'] ?? ''), (string)($_POST['new_kind'] ?? ''), $actor, $finance ? (string)($_POST['adjust_month'] ?? '') : '');
