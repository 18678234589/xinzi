<?php
require_once __DIR__.'/../includes/ProjectJointIntake.php';
$n=0;
function ji_equal($a,$b){global $n;$n++;if($a!==$b)throw new RuntimeException('Joint intake assertion failed');}
ji_equal(ps_joint_customer_ids([], [32,33]),[]);
ji_equal(ps_joint_customer_ids(['32','33','32'],[32,33]),[32,33]);
foreach(['32',[0],[999],['1e3'],[[]]] as $bad) {
    $caught=false;try{ps_joint_customer_ids($bad,[32,33]);}catch(RuntimeException $e){$caught=true;}
    ji_equal($caught,true);
}
echo "PASS $n joint-intake assertions; no database writes.\n";
