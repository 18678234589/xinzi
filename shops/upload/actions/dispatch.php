<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 单条删除订单（仅允许删除归属本店铺的订单）
    if ($action === 'delete_order') {
        /* split: shops/upload/actions/delete_order.php */ include (dirname(__DIR__, 2)) . '/upload/actions/delete_order.php';
    } elseif ($action === 'batch_delete') {
        /* split: shops/upload/actions/batch_delete.php */ include (dirname(__DIR__, 2)) . '/upload/actions/batch_delete.php';
    } elseif ($action === 'delete_month') {
        /* split: shops/upload/actions/delete_month.php */ include (dirname(__DIR__, 2)) . '/upload/actions/delete_month.php';
    } elseif ($action === 'manual_add') {
        /* split: shops/upload/actions/manual_add.php */ include (dirname(__DIR__, 2)) . '/upload/actions/manual_add.php';
    } elseif ($action === 'upload') {
        /* split: shops/upload/actions/upload.php */ include (dirname(__DIR__, 2)) . '/upload/actions/upload.php';
    }
}