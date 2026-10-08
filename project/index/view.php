<?php
include (dirname(__DIR__, 1)) . '/../includes/header.php';
$resourceHint = function ($t) { return trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price']); };
?>
<?php /* split: project/index/view/section_1.php */ include __DIR__ . '/view/section_1.php'; ?><?php /* split: project/index/view/section_2.php */ include __DIR__ . '/view/section_2.php'; ?><?php /* split: project/index/view/section_3.php */ include __DIR__ . '/view/section_3.php'; ?>