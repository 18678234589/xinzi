<?php
/** Production legacy_module entry: no database, config files, or debug writes. */
$attendanceCalls = [];
function get_attendance($employeeId, $year, $month) {
    global $attendanceCalls;
    $attendanceCalls[] = [$employeeId, $year, $month];
    return ['absent_hours' => 4, 'work_hours' => 176];
}
require_once __DIR__ . '/../includes/SalaryCalculator.php';
$employee = ['id' => 987654321];
$fixed = SalaryCalculator::runModuleFor('base_salary', ['base_amount' => 1234.56], $employee, [], 0, '2026-08');
if ($fixed['amount'] !== 1234.56) throw new RuntimeException('Passed module config was not used');
$attendance = SalaryCalculator::runModuleFor('attendance_full', ['full_amount' => 200], $employee, [], 0, '2026-08');
if ($attendance['amount'] !== 100.0) throw new RuntimeException('Attendance context was lost');
$daily = SalaryCalculator::runModuleFor('attendance_daily', ['daily_rate' => 50], $employee, [[], [], []], 300, '2026-08');
if ($daily['amount'] !== 150.0) throw new RuntimeException('Order count context was lost');
if ($attendanceCalls !== [[987654321, 2026, 8], [987654321, 2026, 8], [987654321, 2026, 8]]) {
    throw new RuntimeException('Wrong employee or attendance month');
}
if (SalaryCalculator::runModuleFor('unknown_module', [], $employee, [], 0, 'invalid') !== null) {
    throw new RuntimeException('Unknown module must return null');
}
echo "Monthly module entry PASS (config, attendance, order count, unknown type; no writes)\n";
