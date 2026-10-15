<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['attendance_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = '页面验证已失效，请刷新后重新提交';
        http_response_code(403);
        return;
    }
    try {
        attendance_require_draft(sprintf('%04d-%02d', $year, $month));
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'home_roster') {
        include __DIR__ . '/home_roster.php';
    }
    // 手动添加单条
    elseif ($action === 'manual_add') {
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
