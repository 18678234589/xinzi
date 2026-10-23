<?php
// Isolated authorization checks; no application database or sessions are opened.
require __DIR__ . '/../includes/governance/member_auth.php';
function ps_require_actor() { return $GLOBALS['fixtureActor']; }
class GovernanceMemberLookup {
    function prepare($sql) { return $this; }
    function execute($params) {}
    function fetch() { return $GLOBALS['fixtureMember']; }
}
function db() { return new GovernanceMemberLookup; }
if (($argv[1] ?? '') === 'child') {
    $role=$argv[2];
    $fixtureActor=['type'=>$role==='admin'?'admin':'employee','role'=>$role,'id'=>1,'employee_id'=>$role==='admin'?null:3];
    $fixtureMember=in_array($role,['chair','committee'],true)?['employee_id'=>3,'governance_role'=>$role,'name'=>'Member']:null;
    $_SERVER['REQUEST_METHOD']=$argv[3];
    $result=null;
    ob_start();
    register_shutdown_function(function () use (&$result) {
        $body=ob_get_clean();
        echo json_encode(['status'=>http_response_code()?:200,'result'=>$result,'body'=>$body]);
    });
    [, $member]=pg_require_member($argv[4]==='1');
    $result=['role'=>$member['governance_role'],'can_review'=>pg_can_review($member,['owner_employee_id'=>2,'created_by_employee_id'=>2,'review_state'=>'pending'])];
    exit;
}
$cases=[
 ['admin','GET',true,200,'finance',false],
 ['finance','GET',true,200,'finance',false],
 ['admin','POST',true,403,null,null],
 ['finance','POST',true,403,null,null],
 ['customer_service','GET',true,403,null,null],
 ['admin','GET',false,403,null,null],
 ['chair','POST',true,200,'chair',false],
 ['committee','POST',true,200,'committee',true],
];
foreach ($cases as [$role,$method,$allow,$status,$memberRole,$canReview]) {
    $process=proc_open([PHP_BINARY,__FILE__,'child',$role,$method,$allow?'1':'0'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    $result=json_decode($out,true);
    if ($exit!==0 || $err!=='' || !$result || $result['status']!==$status || ($status===200 && ($result['result']['role']!==$memberRole || $result['result']['can_review']!==$canReview))) throw new RuntimeException('Authorization failed: '.$role.' '.$method.' '.$out.' '.$err);
}
echo "PASS admin/finance viewing; writes and ordinary staff denied; member review permissions preserved\n";
