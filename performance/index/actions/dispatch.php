<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'member_exclude') {
        /* split: performance/index/actions/member_exclude.php */ include (dirname(__DIR__, 2)) . '/index/actions/member_exclude.php';
    } elseif ($action === 'member_include') {
        /* split: performance/index/actions/member_include.php */ include (dirname(__DIR__, 2)) . '/index/actions/member_include.php';
    } elseif ($action === 'import') {
        /* split: performance/index/actions/import.php */ include (dirname(__DIR__, 2)) . '/index/actions/import.php';
    } elseif ($action === 'upload_delete') {
        /* split: performance/index/actions/upload_delete.php */ include (dirname(__DIR__, 2)) . '/index/actions/upload_delete.php';
    }
}