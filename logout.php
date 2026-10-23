<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/AuthRemember.php';

auth_remember_clear();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
