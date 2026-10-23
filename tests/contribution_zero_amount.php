<?php
// Exercise the page's form validation without a database connection or writes.
require_once __DIR__ . '/../includes/governance/member_auth.php';
class ContributionEmployeeCheck {
    private $id;
    public function execute($params) { $this->id = (int)$params[0]; }
    public function fetchColumn() { return in_array($this->id, [1, 2], true) ? 1 : false; }
}
class ContributionReadDatabase {
    public function prepare($sql) {
        if ($sql !== 'SELECT 1 FROM employees WHERE id=?') throw new Exception('Unexpected database access');
        return new ContributionEmployeeCheck;
    }
}
function db() { return new ContributionReadDatabase; }
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function check($condition, $message) { if (!$condition) throw new Exception($message); }
$source = file_get_contents(__DIR__ . '/../project/contributions.php');
$start = strpos($source, '$readForm = function');
$end = strpos($source, "\nif (\$_SERVER['REQUEST_METHOD']", $start);
$selfEmployeeId = 0;
$conclusions = ['pending'=>'待评判', 'approved'=>'已完成', 'rejected'=>'未采纳'];
eval(substr($source, $start, $end - $start));
$input = ['owner_employee_id'=>1, 'record_date'=>'2026-10-06', 'category'=>'Bug', 'reviewer_employee_id'=>2, 'bonus_delta'=>'0', 'conclusion'=>'rejected'];
$row = $readForm($input);
check($row['review_state'] === 'rejected' && $row['bonus_delta'] === 0.0 && $row['reward_status'] === null && $row['reward_paid_on'] === null, 'Rejected zero reward must save without payment status');
foreach (['pending', 'approved', 'rejected'] as $state) {
    foreach (['0', '0.00', ''] as $amount) {
        $row = $readForm(array_merge($input, ['conclusion'=>$state, 'bonus_delta'=>$amount]));
        check($row['bonus_delta'] === ($amount === '' ? null : 0.0), 'Zero and blank must remain distinct');
    }
}
echo "PASS zero and blank amounts across conclusions\n";
foreach ([['bonus_delta'=>'50'], ['bonus_delta'=>'-50'], ['reward_status'=>'paid'], ['reviewer_employee_id'=>1], ['reviewer_employee_id'=>0], ['bonus_delta'=>'invalid']] as $change) {
    $blocked = false;
    try { $readForm(array_merge($input, $change)); } catch (RuntimeException $ex) { $blocked = true; }
    check($blocked, 'Existing reward and reviewer validation must remain enforced');
}
$selfEmployeeId = 1;
eval(substr($source, $start, $end - $start));
$blocked = false;
try { $readForm($input); } catch (RuntimeException $ex) { $blocked = true; }
check($blocked, 'An employee cannot conclude their own contribution');
echo "PASS nonzero reward, payment and reviewer restrictions\n";
$fieldsStart = strpos($source, '$fields = function');
$fieldsEnd = strpos($source, "\n};", $fieldsStart) + 3;
$personOptions = function () { return ''; };
eval(substr($source, $fieldsStart, $fieldsEnd - $fieldsStart));
foreach (['0', '0.00', '100', '100.00'] as $amount) {
    $html = $fields(['bonus_delta'=>$amount], 'test');
    check(strpos($html, 'inputmode="decimal" value="'.$amount.'"') !== false, 'Editing must preserve zero and trailing integer zeros');
}
echo "PASS amount rendering\n";
