<?php
require_once __DIR__ . '/settle/helpers/calcFullAttendanceBonus.php';
require_once __DIR__ . '/settle/helpers/calcProratedBaseSalary.php';
require_once __DIR__ . '/settle/helpers/applyProratedBaseSalary.php';
require_once __DIR__ . '/settle/helpers/loadEmployeeOrdersWithDept.php';
/* split: salaries/settle/context.php */ include __DIR__ . '/settle/context.php';/* split: salaries/settle/view.php */ include __DIR__ . '/settle/view.php';