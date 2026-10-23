<?php
// No database access: reward validation and actual template rendering only.
require __DIR__ . '/../includes/governance/ideas_reminders.php';
function reward_check($okay,$message) { if (!$okay) throw new RuntimeException($message); }
reward_check(pg_idea_reward_amount()===300 && pg_idea_reward_amount('')===300 && pg_idea_reward_amount(' 300 ')===300,'Default reward must be 300');
foreach ([200,300,400,500] as $amount) reward_check(pg_idea_reward_amount((string)$amount)===$amount,'Reward option rejected');
foreach (['100','0','-200','600','300.00','invalid',[]] as $amount) {
    $blocked=false;try { pg_idea_reward_amount($amount); } catch (RuntimeException $e) { $blocked=true; }
    reward_check($blocked,'Out-of-range reward accepted');
}
echo "PASS reward defaults, all choices and invalid values\n";
foreach (['governance_ideas.php'=>2,'governance.php'=>1] as $page=>$count) {
    $source=file_get_contents(__DIR__.'/../project/'.$page);
    preg_match_all('/<select class="form-control" name="bonus_delta">.*?<\/select>/s',$source,$matches);
    reward_check(count($matches[0])===$count,'Missing reward dropdown: '.$page);
    foreach ($matches[0] as $template) {
        ob_start();eval('?>'.$template);$html=ob_get_clean();
        preg_match_all('/<option value="(\d+)"( selected)?>/',$html,$options,PREG_SET_ORDER);
        reward_check(array_column($options,1)===['200','300','400','500'],'Wrong dropdown choices');
        $selected=array_values(array_filter($options,function ($option) { return isset($option[2]); }));
        reward_check(count($selected)===1 && $selected[0][1]==='300','Wrong selected default');
    }
}
echo "PASS review and backfill dropdowns, including the shared review page\n";
