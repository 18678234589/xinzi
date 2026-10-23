<?php
/** Sync one welfare submission to the contribution register; reserve each verified point once. */
function pw_sync_contribution($id, $actor)
{
    $ownTransaction = !db()->inTransaction();
    if ($ownTransaction) db()->beginTransaction();
    try {
        db()->query('SELECT id FROM project_welfare_policy WHERE id=1 FOR UPDATE')->fetch();
        $q = db()->prepare('SELECT * FROM project_welfare_contributions WHERE id=? FOR UPDATE');
        $q->execute([(int)$id]);
        $c = $q->fetch();
        if (!$c) throw new RuntimeException('贡献记录不存在');
        $amount = $c['status'] === 'approved' ? (int)$c['points'] * 50 : 0;
        $state = ['pending'=>'待评判', 'approved'=>'已完成', 'rejected'=>'未采纳'][$c['status']];
        $recordId = (int)$c['governance_record_id'];
        if (!$recordId) {
            $description = $c['description'];
            if (mb_strlen($c['title']) > 120) $description = $c['title'] . "\n\n" . $description;
            if ($c['evidence_text']) $description .= "\n\n证据 / 链接 / 复现步骤：\n" . $c['evidence_text'];
            db()->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,evidence_text,created_by_employee_id,created_by_admin_id,flow_note) VALUES ('contribution',?,?,?,?,?,?,?,?)")
                ->execute([(int)$c['employee_id'],$c['occurred_on'],mb_substr($c['title'],0,120),$description,$c['evidence_text'],$c['created_by_employee_id'],$c['created_by_admin_id'],'全员福利池贡献 #' . $id]);
            $recordId = (int)db()->lastInsertId();
            db()->prepare('UPDATE project_welfare_contributions SET governance_record_id=? WHERE id=?')->execute([$recordId,(int)$id]);
        }
        $source = 'contribution:' . (int)$id;
        $q = db()->prepare('SELECT amount FROM project_welfare_ledger WHERE source_key=?');
        $q->execute([$source]);
        $reserved = $q->fetchColumn();
        if ($reserved !== false) {
            if ((float)$reserved !== (float)-$amount) throw new RuntimeException('奖励已预留，不能重复修改核验点数');
        } else {
            if ($amount > 0) {
                if (pw_balance() < $amount) throw new RuntimeException('福利池余额不足，本次核验未保存，请补足资金后重试');
                $quarter = pw_quarter($c['occurred_on']);
                $q = db()->prepare("SELECT 1 FROM project_welfare_awards WHERE award_type='quarterly' AND period_key=? LIMIT 1");
                $q->execute([$quarter]);
                if ($q->fetchColumn()) throw new RuntimeException('该季度已按旧规则生成奖励，不能重复预留');
                pw_ledger($source,'contribution_reward',-$amount,$quarter,(int)$c['employee_id'],'贡献 #' . $id . '，已核验 ' . $c['points'] . ' 点 × ¥50，待财务发放');
            }
            db()->prepare('UPDATE project_governance_records SET review_state=?,outcome_status=?,bonus_delta=?,reward_status=?,reward_paid_on=NULL,review_note=?,reviewed_at=? WHERE id=?')
                ->execute([$c['status'],$state,$c['status']==='pending'?null:$amount,$amount>0?'unpaid':null,$c['review_note'],$c['reviewed_at'],$recordId]);
            if ($amount > 0) ps_audit('welfare_contribution',(int)$id,'reserve_reward',$actor,['record_id'=>$recordId,'points'=>(int)$c['points'],'amount'=>$amount]);
        }
        if ($ownTransaction) db()->commit();
        return $recordId;
    } catch (Throwable $e) {
        if ($ownTransaction && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

/** Linked rewards are fixed by welfare verification; payment registration remains available. */
function pw_validate_linked_contribution($recordId, array $data)
{
    $q = db()->prepare('SELECT employee_id,status,points FROM project_welfare_contributions WHERE governance_record_id=?');
    $q->execute([(int)$recordId]);
    $c = $q->fetch();
    if (!$c) return;
    $amount = $c['status'] === 'pending' ? null : ($c['status'] === 'approved' ? (int)$c['points'] * 50 : 0);
    if ((int)$data['owner_employee_id'] !== (int)$c['employee_id'] || $data['review_state'] !== $c['status'] || ($data['bonus_delta'] === null) !== ($amount === null) || (float)$data['bonus_delta'] !== (float)$amount) {
        throw new RuntimeException('此记录来自全员福利池，奖金按核验点数 × 50 元生成；请在福利池核验，不能在此修改贡献人、结论或奖金');
    }
}
