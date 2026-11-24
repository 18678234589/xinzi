<?php

// 订单参与人把自己从这单移除（不用申请、不用找财务）：未审核直接移除；已审核保留原快照，差额作为调整计入下一个未锁定月份，并通知财务。
require_once (dirname(__DIR__, 3)) . '/../includes/ProjectOrderBusiness.php';
if ($finance) throw new RuntimeException('财务请在参与人列表里用“移除”');
ps_leave_order($id, $actor);
