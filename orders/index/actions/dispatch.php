<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'manual_add') {
        /* split: orders/index/actions/manual_add.php */ include (dirname(__DIR__, 2)) . '/index/actions/manual_add.php';
    } elseif ($action === 'upload') {
        /* split: orders/index/actions/upload.php */ include (dirname(__DIR__, 2)) . '/index/actions/upload.php';
    } elseif ($action === 'delete') {
        /* split: orders/index/actions/delete.php */ include (dirname(__DIR__, 2)) . '/index/actions/delete.php';
    } elseif ($action === 'batch_delete') {
        /* split: orders/index/actions/batch_delete.php */ include (dirname(__DIR__, 2)) . '/index/actions/batch_delete.php';
    } elseif ($action === 'delete_group') {
        /* split: orders/index/actions/delete_group.php */ include (dirname(__DIR__, 2)) . '/index/actions/delete_group.php';
    } elseif ($action === 'delete_months') {
        /* split: orders/index/actions/delete_months.php */ include (dirname(__DIR__, 2)) . '/index/actions/delete_months.php';
    } elseif ($action === 'delete_project') {
        /* split: orders/index/actions/delete_project.php */ include (dirname(__DIR__, 2)) . '/index/actions/delete_project.php';
    } elseif ($action === 'verify_status') {
        /* split: orders/index/actions/verify_status.php */ include (dirname(__DIR__, 2)) . '/index/actions/verify_status.php';
    } elseif ($action === 'verify_pending') {
        /* split: orders/index/actions/verify_pending.php */ include (dirname(__DIR__, 2)) . '/index/actions/verify_pending.php';
    }
}