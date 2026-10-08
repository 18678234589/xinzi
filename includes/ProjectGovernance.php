<?php
require_once __DIR__ . '/ProjectSettlement.php';
// 脑洞轮值按中国业务日期结算，避免 CLI / Web 的系统默认时区不同造成跨日误扣。
date_default_timezone_set('Asia/Shanghai');
/* split: includes/governance/member_auth.php */ require_once __DIR__ . '/governance/member_auth.php';
/* split: includes/governance/ideas_reminders.php */ require_once __DIR__ . '/governance/ideas_reminders.php';
/* split: includes/governance/oversight.php */ require_once __DIR__ . '/governance/oversight.php';
/* split: includes/governance/chair_elections.php */ require_once __DIR__ . '/governance/chair_elections.php';
/* split: includes/governance/private_files.php */ require_once __DIR__ . '/governance/private_files.php';
