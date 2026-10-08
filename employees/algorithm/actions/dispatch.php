<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        /* split: employees/algorithm/actions/save.php */ include (dirname(__DIR__, 2)) . '/algorithm/actions/save.php';
    } elseif ($action === 'reset') {
        /* split: employees/algorithm/actions/reset.php */ include (dirname(__DIR__, 2)) . '/algorithm/actions/reset.php';
    }
}