<?php
function ensureShopColumn()
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'shop'")->fetchAll();
        if (empty($cols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `shop` VARCHAR(100) DEFAULT '' COMMENT '店铺' AFTER `project`");
        }
    } catch (\Throwable $e) {}
}
