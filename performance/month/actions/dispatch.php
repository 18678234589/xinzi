<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        /* split: performance/month/actions/save.php */ include (dirname(__DIR__, 2)) . '/month/actions/save.php';
    } elseif ($action === 'assign') {
        /* split: performance/month/actions/assign.php */ include (dirname(__DIR__, 2)) . '/month/actions/assign.php';
    } elseif ($action === 'delete_pending') {
        /* split: performance/month/actions/delete_pending.php */ include (dirname(__DIR__, 2)) . '/month/actions/delete_pending.php';
    }
}