<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
function governance_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$committee = ['employee_id' => 48, 'governance_role' => 'committee'];
$chair = ['employee_id' => 19, 'governance_role' => 'chair'];
$other = ['employee_id' => 19, 'created_by_employee_id' => 19, 'owner_employee_id' => 19, 'review_state' => 'pending'];
governance_check(pg_can_review($committee, $other), 'other committee member may review');
governance_check(!pg_can_review($chair, $other), 'chair cannot review');
governance_check(!pg_can_review($committee, ['owner_employee_id' => 48, 'created_by_employee_id' => 19, 'review_state' => 'pending']), 'no self review');
governance_check(!pg_can_review($committee, ['owner_employee_id' => 19, 'created_by_employee_id' => 48, 'review_state' => 'pending']), 'no review of own submission');
governance_check(!pg_can_review($committee, ['owner_employee_id' => 19, 'created_by_employee_id' => 19, 'review_state' => 'approved']), 'approved record immutable');
governance_check(pg_validate_date('2026-09-24', true) === '2026-09-24', 'date accepted');
$invalid = false;
try { pg_validate_date('2026-02-30', true); } catch (RuntimeException $e) { $invalid = true; }
governance_check($invalid, 'impossible date rejected');
governance_check(pg_kind_label('committee') === '监委会监督', 'kind label');
echo "governance smoke OK\n";
