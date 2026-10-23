<?php
// Run with: php -d extension=pdo_sqlite tests/welfare_contribution_rewards.php
// SQLite only; production connections and financial data are never used.
class WelfareSandbox extends PDO {
    public function prepare($sql, $options = []) { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
    public function query($sql, ...$args) { return parent::query(str_replace(' FOR UPDATE','',$sql),...$args); }
}
$pdo = new WelfareSandbox('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('CONCAT',function (...$parts) { return implode('',$parts); });
function db() { return $GLOBALS['pdo']; }
function verify($okay,$message) { if (!$okay) throw new RuntimeException($message); }
function ps_require_actor() { return $GLOBALS['evidenceActor']; }
function evidence_test_header($header) {}
function evidence_test_status($status) {}
const PS_PRIVATE_GUARD = "<?php http_response_code(404); exit; ?>\n";
require __DIR__ . '/../includes/WelfareContribution.php';
require __DIR__ . '/../includes/governance/member_auth.php';
require __DIR__ . '/../includes/governance/private_files.php';
require __DIR__ . '/../includes/settlement/private_files.php';
require __DIR__ . '/../includes/settlement/audit_db.php';
$source = file_get_contents(__DIR__ . '/../includes/ProjectWelfare.php');
eval('?>' . preg_replace('/^require_once .*;\R/m','',$source));
$pdo->exec("CREATE TABLE project_welfare_policy (id INTEGER PRIMARY KEY,effective_quarter TEXT);
INSERT INTO project_welfare_policy VALUES (1,'2026-Q3');
CREATE TABLE project_welfare_ledger (id INTEGER PRIMARY KEY,source_key TEXT UNIQUE,event_type TEXT,amount NUMERIC,quarter_key TEXT,employee_id INTEGER,note TEXT);
INSERT INTO project_welfare_ledger(source_key,amount) VALUES ('opening',1000);
CREATE TABLE project_welfare_contributions (id INTEGER PRIMARY KEY,employee_id INTEGER,created_by_employee_id INTEGER,created_by_admin_id INTEGER,title TEXT,description TEXT,evidence_text TEXT,occurred_on TEXT,status TEXT DEFAULT 'pending',points INTEGER DEFAULT 0,review_note TEXT,reviewed_at TEXT,governance_record_id INTEGER UNIQUE);
CREATE TABLE project_governance_records (id INTEGER PRIMARY KEY,record_kind TEXT,owner_employee_id INTEGER,record_date TEXT,category TEXT,description TEXT,evidence_text TEXT,created_by_employee_id INTEGER,created_by_admin_id INTEGER,flow_note TEXT,review_state TEXT DEFAULT 'pending',outcome_status TEXT,bonus_delta NUMERIC,reward_status TEXT,reward_paid_on TEXT,review_note TEXT,reviewed_at TEXT);
CREATE TABLE project_welfare_awards (award_type TEXT,period_key TEXT);
CREATE TABLE project_welfare_closures (quarter_key TEXT);
INSERT INTO project_welfare_closures VALUES ('2026-Q3');
CREATE TABLE project_audit_logs (entity_type TEXT,entity_id INTEGER,action TEXT,actor_type TEXT,actor_id INTEGER,details_json TEXT);
CREATE TABLE project_governance_evidence (id INTEGER PRIMARY KEY,record_id INTEGER,original_name TEXT,stored_name TEXT,mime_type TEXT,file_size INTEGER,uploaded_by_employee_id INTEGER,uploaded_by_admin_id INTEGER);
CREATE TABLE project_governance_members (employee_id INTEGER,governance_role TEXT,is_active INTEGER);
CREATE TABLE employees (id INTEGER,name TEXT);
INSERT INTO project_welfare_contributions(id,employee_id,created_by_employee_id,title,description,evidence_text,occurred_on,review_note) VALUES (1,39,39,'上传图片建议','提交建议时应当能上传图片','复现链接','2026-10-08','已核验');");
$actor = ['type'=>'admin','id'=>1];
$stored = null;
$tmp = null;
try {
    $record = pw_sync_contribution(1,$actor);
    verify(pw_balance()===1000.0 && db()->query('SELECT bonus_delta FROM project_governance_records')->fetchColumn()===null,'Pending submissions cannot reserve money');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $tmp = tempnam(sys_get_temp_dir(),'welfare_evidence_');
    file_put_contents($tmp,$png);
    $stored = pg_save_evidence_file($record,['type'=>'employee','id'=>1,'employee_id'=>39],$tmp,'截图.png');
    $proof = db()->query('SELECT * FROM project_governance_evidence')->fetch();
    verify((int)$proof['record_id']===$record && ps_private_read('governance',$proof['stored_name'])===$png,'Evidence must be attached to the shared contribution and retain bytes');
    file_put_contents($tmp,'<?php echo "unsafe";');
    $blocked=false;try { pg_save_evidence_file($record,$actor,$tmp,'unsafe.png'); } catch (RuntimeException $e) { $blocked=true; }
    verify($blocked && (int)db()->query('SELECT COUNT(*) FROM project_governance_evidence')->fetchColumn()===1,'Reject forged image types');
    echo "PASS submission and private evidence integrity\n";

    $evidenceSource = file_get_contents(__DIR__ . '/../project/governance_evidence.php');
    $evidenceSource = preg_replace('/^require_once .*;\R/m','',$evidenceSource);
    $evidenceSource = str_replace(['exit(', 'header(', 'http_response_code('],['throw new RuntimeException(', 'evidence_test_header(', 'evidence_test_status('],$evidenceSource);
    $_GET = ['id'=>(int)$proof['id']];
    db()->exec('UPDATE project_welfare_contributions SET created_by_employee_id=53 WHERE id=1');
    foreach ([39=>true,53=>true,61=>false] as $employee=>$allowed) {
        $GLOBALS['evidenceActor']=['type'=>'employee','employee_id'=>$employee,'id'=>$employee];
        ob_start();$denied=false;
        try { eval('?>'.$evidenceSource); } catch (RuntimeException $e) { $denied=true; }
        $body=ob_get_clean();
        verify($allowed ? (!$denied && $body===$png) : ($denied && $body===''),'Only the owner or submitter may access their welfare evidence');
    }
    echo "PASS evidence owner and submitter access; unrelated staff denied\n";

    db()->exec("UPDATE project_welfare_contributions SET status='approved',points=1,reviewed_at='2026-10-20 09:00:00' WHERE id=1");
    pw_sync_contribution(1,$actor);
    verify(pw_balance()===950.0,'One point must reserve exactly 50');
    db()->exec("UPDATE project_governance_records SET reward_status='paid',reward_paid_on='2026-10-20' WHERE id=$record");
    pw_sync_contribution(1,$actor);
    $reward=db()->query("SELECT * FROM project_governance_records WHERE id=$record")->fetch();
    verify(pw_balance()===950.0 && (float)$reward['bonus_delta']===50.0 && $reward['reward_status']==='paid' && $reward['reward_paid_on']==='2026-10-20','Repeated synchronization must not debit or reset paid rewards');
    foreach ([2=>3,3=>5] as $id=>$points) {
        db()->exec("INSERT INTO project_welfare_contributions(id,employee_id,title,description,occurred_on,status,points) VALUES ($id,61,'奖励等级','等级核验','2026-10-09','approved',$points)");
        pw_sync_contribution($id,$actor);
    }
    verify(pw_balance()===550.0,'Three and five points must reserve 150 and 250');
    echo "PASS fixed rewards, duplicate calls and paid-state preservation\n";

    db()->exec("INSERT INTO project_welfare_contributions(id,employee_id,title,description,occurred_on) VALUES (4,53,'余额不足','回滚核验','2026-10-09')");
    $count=(int)db()->query('SELECT COUNT(*) FROM project_governance_records')->fetchColumn();
    db()->beginTransaction();
    db()->exec("UPDATE project_welfare_contributions SET status='approved',points=50 WHERE id=4");
    $blocked=false;try { pw_sync_contribution(4,$actor); } catch (RuntimeException $e) { $blocked=true; }
    db()->rollBack();
    verify($blocked && pw_balance()===550.0 && db()->query('SELECT status FROM project_welfare_contributions WHERE id=4')->fetchColumn()==='pending' && (int)db()->query('SELECT COUNT(*) FROM project_governance_records')->fetchColumn()===$count,'Insufficient funds must roll back review, mirror and ledger');
    echo "PASS insufficient-funds atomic rollback\n";

    $data=['owner_employee_id'=>39,'review_state'=>'approved','bonus_delta'=>50.0];
    pw_validate_linked_contribution($record,$data);
    foreach ([['bonus_delta'=>100],['owner_employee_id'=>61],['review_state'=>'rejected']] as $change) {
        $blocked=false;try { pw_validate_linked_contribution($record,array_merge($data,$change)); } catch (RuntimeException $e) { $blocked=true; }
        verify($blocked,'Linked reward fields cannot bypass welfare verification');
    }
    echo "PASS linked reward protections\n";

    db()->exec("INSERT INTO project_welfare_contributions(id,employee_id,title,description,occurred_on,status,points) VALUES (5,61,'季度核验','季度不得重复奖励','2026-09-01','approved',1)");
    pw_sync_contribution(5,$actor);
    verify(!pw_award_quarter('2026-Q3') && pw_balance()===500.0,'Quarterly settlement cannot reward an already-reserved point again');
    echo "PASS quarterly duplicate prevention\n";
} finally {
    if (db()->inTransaction()) db()->rollBack();
    if ($stored) @unlink($stored);
    if ($tmp) @unlink($tmp);
}
