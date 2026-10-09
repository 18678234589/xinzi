<?php
require_once __DIR__ . '/etmll_push.php';
/** The button and scheduler use the same serialized two-way flow. */
function etmll_bidirectional_run(bool $dryRun=false,int $limit=3000): array
{
    $pdo=db();$lockName='etmll_both_'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,24);
    if(!$dryRun) {
        $q=$pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lockName]);
        if((int)$q->fetchColumn()!==1) throw new RuntimeException('已有双向同步正在进行，请稍后查看结果');
    }
    try {
        $push=etmll_push_run($dryRun,false,$limit);
        try { $pull=etmll_sync_run($dryRun); }
        catch(Throwable $e) { throw new RuntimeException('推送阶段已完成，拉取阶段失败；可重试，订单号会去重。原因：'.$e->getMessage(),0,$e); }
        return ['push'=>$push,'pull'=>$pull];
    } finally {
        if(!$dryRun) { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]); }
    }
}
