<?php
/** 本站→ETMLL：新订单及已有订单最新交易状态。结算、分配、人工金额不覆盖。 */
require_once __DIR__ . '/etmll_sync.php';
const ETMLL_PUSH_TAG = '本站同步';
const ETMLL_PUSH_COMMISSION_RATE = 0.05;
function etmll_sync_cutoff(): string { return date('Y-m-d',strtotime('-1 year')); }
function etmll_push_shop_merchants(PDO $epdo): array
{
    $map=[];
    foreach($epdo->query("SELECT shop_name,merchant_id,COUNT(*) c FROM `order` WHERE shop_name<>'' AND status IN (0,1) GROUP BY shop_name,merchant_id ORDER BY c DESC") as $r) if(!isset($map[$r['shop_name']])) $map[$r['shop_name']]=(int)$r['merchant_id'];
    return $map;
}
function etmll_push_since(): string { return (string)ps_setting_get('etmll_push_since',''); }
function etmll_push_date($value): ?string
{
    if(!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/',trim((string)$value),$m)) return null;
    $date=$m[1].' '.$m[2];$parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date);
    return $parsed && $parsed->format('Y-m-d H:i:s')===$date ? $date : null;
}
function etmll_push_local_source(array $raw): bool
{
    if(!empty($raw['__statement_uploaded_at__']) && ($raw['__financial_source__'] ?? '')==='shop_statement') return true;
    return empty($raw['__etmll_id__']) && ($raw['数据来源'] ?? '')!=='ETMLL自动同步';
}
/** State only advances; older exports cannot reopen trades. Money is not a patch field. */
function etmll_push_status_patch(array $raw,array $target): array
{
    $status=trim((string)($raw['订单状态'] ?? $raw['__order_status__'] ?? ''));
    $normalized=trim((string)($raw['__order_status__'] ?? ''));
    if(ps_shop_status_rank($normalized)>ps_shop_status_rank($status)) $status=$normalized;
    $old=trim((string)($target['raw_status'] ?? ''));
    if($status==='' || ps_shop_status_rank($status)<ps_shop_status_rank($old)) return [];
    $patch=[];
    if(ps_shop_status_rank($status)>ps_shop_status_rank($old)) $patch['raw_status']=mb_substr($status,0,50);
    $shipping=etmll_push_date($raw['发货时间'] ?? '');$oldShipping=etmll_push_date($target['shipping_time'] ?? '');
    if($shipping && (!$oldShipping || $shipping>$oldShipping)) $patch['shipping_time']=$shipping;
    $title=trim((string)($raw['商品标题'] ?? ''));
    if($title!=='' && trim((string)($target['product_title'] ?? ''))==='') $patch['product_title']=mb_substr($title,0,500);
    $payNo=trim((string)($raw['支付宝交易号'] ?? $raw['支付宝流水号'] ?? ''));
    if($payNo!=='' && mb_strlen($payNo)<=64 && trim((string)($target['alipay_no'] ?? ''))==='') $patch['alipay_no']=$payNo;
    return $patch;
}
function etmll_push_run(bool $dryRun=false,bool $backfill=false,int $limit=3000,?string $onlyShop=null): array
{
    $r=etmll_push_orders(db(),etmll_connect(),$dryRun,$backfill,$limit,$onlyShop,etmll_push_since(),etmll_sync_cutoff());
    if(!$dryRun && $r['pushed']+$r['updated']>0) {
        try { ps_audit('etmll_push',0,$backfill?'backfill':'push',['type'=>'system','id'=>0],$r); }
        catch(Throwable $e) { error_log('etmll_push 审计未写入'); }
    }
    return $r;
}
/** Injected connections allow temporary-table regressions, without touching real orders. */
function etmll_push_orders(PDO $pdo,PDO $epdo,bool $dryRun,bool $backfill,int $limit,?string $onlyShop,string $since,string $cutoff): array
{
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$cutoff) || $limit<1) throw new InvalidArgumentException('Invalid sync boundary or limit');
    $r=['dry_run'=>$dryRun,'backfill'=>$backfill,'since'=>$since,'candidates'=>0,'pushed'=>0,'updated'=>0,'would_push'=>0,'would_update'=>0,
        'skipped_existing'=>0,'skipped_unpaid'=>0,'skipped_no_shop'=>0,'skipped_deleted'=>0,'skipped_history'=>0,'skipped_refund'=>0,'skipped_source'=>0,'skipped_shop_conflict'=>0,
        'by_shop'=>[],'by_shop_updated'=>[],'amount'=>0.0];
    if(!$backfill && $since==='') { $r['note']='尚未开启本站→ETMLL 同步（etmll_push_since 未设置）';return $r; }
    $lockName='etmll_push_'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,24);
    if(!$dryRun) {
        $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);
        if((int)$lock->fetchColumn()!==1) throw new RuntimeException('已有推送在进行，请稍后再试');
    }
    $buffered=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$stream=null;
    try {
        $shops=etmll_push_shop_merchants($epdo);
        if($onlyShop!==null) $shops=array_intersect_key($shops,[$onlyShop=>true]);
        if(!$shops) return $r;
        $in=implode(',',array_map([$pdo,'quote'],array_keys($shops)));
        // creation cutoff applies only to unseen historical inserts, never to updates.
        $sql="SELECT id,shop,order_no,order_amount,order_date,raw_data,created_at FROM orders
            WHERE employee_id=0 AND order_scope='department' AND COALESCE(is_deleted,0)=0 AND order_no<>''
            AND order_date>=".$pdo->quote($cutoff)." AND shop IN ($in) ORDER BY id ASC";
        $existing=[];
        foreach($epdo->query('SELECT id,order_no,shop_name,status,raw_status,shipping_time,product_title,alipay_no FROM `order`') as $row) $existing[$row['order_no']]=$row;
        $insert=$epdo->prepare("INSERT IGNORE INTO `order` (order_no,merchant_id,total_amount,commission,proxy_amount,status,created_at,product_title,raw_status,refund_amount,confirmed_amount,buyer_paid_amount,order_create_time,order_pay_time,shop_name,shop_id,remark_tag,shipping_time,alipay_no)
            VALUES (?,?,?,?,?,0,?,?,?,0,?,?,?,?,?,'0',?,?,?)");
        $updates=[];$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$stream=$pdo->query($sql);
        foreach($stream as $row) {
            $r['candidates']++;$raw=json_decode((string)$row['raw_data'],true) ?: [];
            if(!etmll_push_local_source($raw)) { $r['skipped_source']++;continue; }
            if(isset($existing[$row['order_no']])) {
                $target=$existing[$row['order_no']];
                if(!in_array((int)$target['status'],[0,1],true)) { $r['skipped_deleted']++;continue; }
                if(trim((string)$target['shop_name'])!==$row['shop']) { $r['skipped_shop_conflict']++;continue; }
                $patch=etmll_push_status_patch($raw,$target);
                if(!$patch) { $r['skipped_existing']++;continue; }
                if($r['would_push']+$r['would_update']+$r['pushed']+$r['updated']>=$limit) { $r['truncated']=true;break; }
                if(!$dryRun) {
                    $fields=array_keys($patch);$key=implode(',',$fields);
                    if(!isset($updates[$key])) $updates[$key]=$epdo->prepare('UPDATE `order` SET '.implode(',',array_map(function($f){return '`'.$f.'`=?';},$fields)).' WHERE id=? AND shop_name=? AND status IN (0,1) AND COALESCE(raw_status,\'\')=? AND '.implode(' AND ',array_map(function($f){return '`'.$f.'` <=> ?';},$fields)));
                    $previous=array_map(function($f)use($target){return $target[$f];},$fields);
                    $updates[$key]->execute(array_merge(array_values($patch),[$target['id'],$row['shop'],(string)($target['raw_status'] ?? '')],$previous));
                    if(!$updates[$key]->rowCount()) { $r['skipped_existing']++;continue; }
                    $r['updated']++;
                } else $r['would_update']++;
                $r['by_shop_updated'][$row['shop']]=($r['by_shop_updated'][$row['shop']] ?? 0)+1;
                $existing[$row['order_no']]=array_merge($target,$patch);continue;
            }
            if(!$backfill && $row['created_at']<$since) { $r['skipped_history']++;continue; }
            $status=trim((string)($raw['__order_status__'] ?? $raw['订单状态'] ?? ''));
            if(mb_strpos($status,'等待买家付款')!==false) { $r['skipped_unpaid']++;continue; }
            if((float)$row['order_amount']<=0 || !empty($raw['__is_refund__']) || ps_shop_status_rank($status)===5) { $r['skipped_refund']++;continue; }
            if($r['would_push']+$r['would_update']+$r['pushed']+$r['updated']>=$limit) { $r['truncated']=true;break; }
            $total=round((float)($raw['__original_price__'] ?? $row['order_amount']),2);
            if($total<=0) $total=round((float)$row['order_amount'],2);
            $commission=round($total*ETMLL_PUSH_COMMISSION_RATE,4);
            $at=etmll_push_date($raw['付款时间'] ?? $raw['__trade_time__'] ?? '') ?: $row['order_date'].' 00:00:00';
            $paid=null;
            foreach(['买家实付金额','实付金额','买家实际支付金额'] as $f) if(isset($raw[$f]) && is_numeric($raw[$f])) { $paid=round((float)$raw[$f],4);break; }
            $shipping=etmll_push_date($raw['发货时间'] ?? '');$payNo=trim((string)($raw['支付宝交易号'] ?? $raw['支付宝流水号'] ?? ''));
            if($dryRun) $r['would_push']++;
            else {
                $insert->execute([$row['order_no'],$shops[$row['shop']],$total,$commission,round($total-$commission,4),$at,mb_substr(trim((string)($raw['商品标题'] ?? '')),0,500),mb_substr($status,0,50),$paid,$paid,$at,etmll_push_date($raw['付款时间'] ?? $raw['__trade_time__'] ?? ''),$row['shop'],ETMLL_PUSH_TAG,$shipping,mb_substr($payNo,0,64)]);
                if(!$insert->rowCount()) { $r['skipped_existing']++;continue; }$r['pushed']++;
            }
            $existing[$row['order_no']]=['id'=>0,'order_no'=>$row['order_no'],'shop_name'=>$row['shop'],'status'=>0,'raw_status'=>$status,'shipping_time'=>$shipping,'product_title'=>$raw['商品标题'] ?? '','alipay_no'=>$payNo];
            $r['by_shop'][$row['shop']]=($r['by_shop'][$row['shop']] ?? 0)+1;$r['amount']+=$total;
        }
        $r['amount']=round($r['amount'],2);return $r;
    } finally {
        if($stream) $stream->closeCursor();$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
        if(!$dryRun) { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]); }
    }
}
function etmll_push_status(): array
{
    $q=etmll_connect()->prepare('SELECT COUNT(*) FROM `order` WHERE remark_tag=?');$q->execute([ETMLL_PUSH_TAG]);
    $history=etmll_push_run(true,true,100000);$fresh=etmll_push_since()!=='' ? etmll_push_run(true,false,100000) : ['would_push'=>0,'would_update'=>0];
    return ['pushed_total'=>(int)$q->fetchColumn(),'since'=>etmll_push_since(),'pending_new'=>(int)$fresh['would_push'],'pending_updates'=>(int)$fresh['would_update'],
        'pending_history'=>max(0,(int)$history['would_push']-(int)$fresh['would_push']),'history_amount'=>$history['amount'],'history_by_shop'=>$history['by_shop']];
}
