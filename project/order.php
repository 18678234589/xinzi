<?php
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectIntake.php';
poi_ensure();
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
require_once __DIR__ . '/../includes/commission_explain.php';
$actor = ps_require_actor();
$id = (int)($_GET['id'] ?? 0);
/* split: project/order/context.php */ include __DIR__ . '/order/context.php';/* split: project/order/view.php */ include __DIR__ . '/order/view.php';