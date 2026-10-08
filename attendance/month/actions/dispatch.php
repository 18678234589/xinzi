<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 手动添加单条
    if ($action === 'manual_add') {
        /* split: attendance/month/actions/manual_add.php */ include (dirname(__DIR__, 2)) . '/month/actions/manual_add.php';
    }
    // 单条删除
    elseif ($action === 'delete') {
        /* split: attendance/month/actions/delete.php */ include (dirname(__DIR__, 2)) . '/month/actions/delete.php';
    }
    // 批量删除
    elseif ($action === 'batch_delete') {
        /* split: attendance/month/actions/batch_delete.php */ include (dirname(__DIR__, 2)) . '/month/actions/batch_delete.php';
    }
    // 上传 Excel/CSV
    elseif ($action === 'upload') {
        /* split: attendance/month/actions/upload.php */ include (dirname(__DIR__, 2)) . '/month/actions/upload.php';
    }
}