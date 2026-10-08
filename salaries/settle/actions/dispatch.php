<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        /* split: salaries/settle/actions/preview.php */ include (dirname(__DIR__, 2)) . '/settle/actions/preview.php';
    } elseif ($action === 'settle') {
        /* split: salaries/settle/actions/settle.php */ include (dirname(__DIR__, 2)) . '/settle/actions/settle.php';
    }
}