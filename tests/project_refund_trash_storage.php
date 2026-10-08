<?php
/** Explicit, rollback-only fixtures. Never remove or edit an existing refund. */
if (PHP_SAPI !== 'cli' || !in_array('--rollback-fixtures', $argv, true)) { fwrite(STDERR, "Use --rollback-fixtures after migration.\n"); exit(2); }
require_once __DIR__ . '/../includes/ProjectRefundImport.php';
require_once __DIR__ . '/../includes/ProjectAutoReview.php';
$p = db(); $n = 0;
function rt_check($label, $actual, $expected) { global $n; $n++; if ($actual !== $expected) throw new RuntimeException($label . ': ' . json_encode($actual)); }
function rt_denied($call) { try { $call(); } catch (RuntimeException $e) { return true; } return false; }
if (!prt_storage_available()) throw new RuntimeException('Missing recycle migration');
$finance = ['type'=>'admin','id'=>0,'role'=>'finance']; $employee = ['type'=>'employee','id'=>0,'role'=>'technical'];
$seed = $p->query('SELECT id FROM project_orders ORDER BY id LIMIT 1')->fetchColumn();
if (!$seed) throw new RuntimeException('No existing order for read-only pending count test');
$prefix = 'REFUND-TRASH-FIXTURE-' . bin2hex(random_bytes(8)); $fpr = hash('sha256', $prefix); $date = $p->query('SELECT CURDATE()')->fetchColumn();
$p->beginTransaction();
try {
    $before = pa_context((int)$seed)['pending_refunds'];
    $cashBefore = (int)$p->query('SELECT COUNT(*) FROM project_cash_movements')->fetchColumn();
    $snapBefore = (int)$p->query('SELECT COUNT(*) FROM project_commission_snapshots')->fetchColumn();
    $p->prepare("INSERT INTO project_refund_import_rows (fingerprint,order_id,order_no,refund_date,amount,payment_method,payment_reference,reason,review_status,submitted_by_type,submitted_by_id) VALUES (?,?,?, ?,3450,'支付宝',?,?,'pending','admin',0)")
        ->execute([$fpr,(int)$seed,$prefix,$date,$prefix.'-ref','rollback fixture']); $id = (int)$p->lastInsertId();
    $get = function() use($p,$id) { $q=$p->prepare('SELECT * FROM project_refund_import_rows WHERE id=?'); $q->execute([$id]); return $q->fetch(); };
    rt_check('pending can recycle',prt_can_trash($get()),true);
    rt_check('nonfinance trash denied',rt_denied(function()use($id,$employee){prt_change($id,false,'',$employee);}),true);
    rt_check('nonfinance made no change',empty($get()['deleted_at']),true);
    rt_check('active refund initially blocks auto review',pa_context((int)$seed)['pending_refunds'],$before+1);
    $r=prt_change($id,false,'误登记测试',$finance); rt_check('move changed',$r['changed'],true);
    rt_check('parent transaction retained',$p->inTransaction(),true);
    rt_check('deleted timestamp saved',empty($get()['deleted_at']),false);
    rt_check('amount retained',$get()['amount'],'3450.00');
    rt_check('status retained',$get()['review_status'],'pending');
    rt_check('reason retained',$get()['deleted_note'],'误登记测试');
    $q=$p->prepare("SELECT COUNT(*) FROM project_refund_import_rows WHERE id=?".ps_refund_live_sql());$q->execute([$id]);rt_check('automatic matcher excludes deleted',(int)$q->fetchColumn(),0);
    rt_check('auto review no deleted refund blocker',pa_context((int)$seed)['pending_refunds'],$before);
    rt_check('fingerprint cannot reimport',prt_deleted_duplicate($fpr,'支付宝',''),true);
    rt_check('refund reference cannot reimport',prt_deleted_duplicate(hash('sha256','different'),'支付宝',$prefix.'-ref'),true);
    $p->prepare("UPDATE project_refund_import_rows SET review_status='rejected' WHERE id=?")->execute([$id]);
    $input=['order_no'=>$prefix,'refund_date'=>$date,'amount'=>3450,'method'=>'支付宝','reference'=>$prefix.'-ref'];
    $preview=ps_refund_preview_row($input,$fpr,$finance);
    rt_check('preview tells finance to restore',strpos($preview['error'],'回收站')!==false,true);
    $blind=$preview;$blind['error']='';
    rt_check('blind reupload of recycled rejected refund denied',rt_denied(function()use($blind,$finance,$date){ps_refund_commit_rows([$blind],['0'],$finance,substr($date,0,7));}),true);
    rt_check('reupload cannot reset original status',$get()['review_status'],'rejected');
    rt_check('reupload cannot silently restore',empty($get()['deleted_at']),false);
    $p->prepare("UPDATE project_refund_import_rows SET review_status='pending' WHERE id=?")->execute([$id]);
    rt_check('review of deleted refund denied',rt_denied(function()use($id,$finance,$date){ps_refund_review($id,'approved',$finance,substr($date,0,7));}),true);
    rt_check('repeat move is idempotent',prt_change($id,false,'',$finance)['changed'],false);
    $q=$p->prepare("SELECT COUNT(*) FROM project_audit_logs WHERE entity_type='refund_import' AND entity_id=? AND action='trash_move'");$q->execute([$id]);rt_check('one move audit',(int)$q->fetchColumn(),1);
    rt_check('nonfinance restore denied',rt_denied(function()use($id,$employee){prt_change($id,true,'',$employee);}),true);
    rt_check('restore changes same row',prt_change($id,true,'',$finance)['changed'],true);
    rt_check('restore clears deletion',empty($get()['deleted_at']),true);
    rt_check('restore returns original status',$get()['review_status'],'pending');
    rt_check('restore resumes pending blocker',pa_context((int)$seed)['pending_refunds'],$before+1);
    rt_check('repeat restore idempotent',prt_change($id,true,'',$finance)['changed'],false);
    $p->prepare("UPDATE project_refund_import_rows SET review_status='approved' WHERE id=?")->execute([$id]);
    rt_check('approved cannot recycle',prt_can_trash($get()),false);
    rt_check('approved deletion denied',rt_denied(function()use($id,$finance){prt_change($id,false,'',$finance);}),true);
    rt_check('cash ledger unchanged',(int)$p->query('SELECT COUNT(*) FROM project_cash_movements')->fetchColumn(),$cashBefore);
    rt_check('settlement snapshots unchanged',(int)$p->query('SELECT COUNT(*) FROM project_commission_snapshots')->fetchColumn(),$snapBefore);
    echo "PASS $n refund recycle storage assertions; fixtures rolled back.\n";
} finally { if($p->inTransaction())$p->rollBack(); }
