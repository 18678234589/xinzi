<?php
/** Existing-order, non-financial, fill-only import. Never calls the order creator. */
require_once __DIR__.'/ProjectIntake.php';
require_once __DIR__.'/ProjectRenewals.php';

const PBF_MAX_ROWS = 500;
function pbf_columns()
{
    return ['id'=>'订单ID','order_no'=>'订单号','business'=>'业务类型','customer'=>'客户（参考）','missing'=>'待补项（参考）',
        'phone'=>'客户手机号','wechat'=>'海外客户微信号','domain'=>'客户域名','owner'=>'域名归属','owner_note'=>'归属备注',
        'domain_expiry'=>'域名到期日','server_expiry'=>'服务器到期日','miniapp_expiry'=>'微信认证到期日','icp_expiry'=>'备案到期日','miniapp_name'=>'小程序名称'];
}
function pbf_map(array $headers)
{
    $aliases=['订单编号'=>'order_no','原订单号'=>'order_no','业务'=>'business','客户微信号'=>'wechat','微信号'=>'wechat',
        '手机号'=>'phone','客户电话'=>'phone','联系电话'=>'phone','域名'=>'domain','域名地址'=>'domain','网站域名'=>'domain',
        '域名到期日期'=>'domain_expiry','服务器到期日期'=>'server_expiry','空间到期日'=>'server_expiry',
        '小程序认证到期日'=>'miniapp_expiry','微信认证到期日期'=>'miniapp_expiry','备案到期日期'=>'icp_expiry'];
    $map=[]; $labels=array_flip(pbf_columns());
    foreach($headers as $i=>$header) {
        $h=preg_replace('/[\s：:]/u','',trim((string)$header));
        $key=$labels[$h]??$aliases[$h]??null;
        if ($key && !isset($map[$key])) $map[$key]=(int)$i;
    }
    return $map;
}
/** Blank cells do not clear existing data; field-level errors do not block the other fields. */
function pbf_parse_row(array $row,array $map)
{
    $out=['id'=>0,'order_no'=>'','business'=>'','values'=>[],'issues'=>[]];
    foreach($map as $key=>$i) {
        $v=trim((string)($row[$i]??'')); if($v==='') continue;
        try {
            if($key==='id') { if(!ctype_digit($v)) throw new RuntimeException('订单ID必须为整数'); $out['id']=(int)$v; }
            elseif($key==='order_no') { if(mb_strlen($v)>200) throw new RuntimeException('订单号过长'); $out[$key]=$v; }
            elseif($key==='business') $out[$key]=ps_business_normalize($v);
            elseif($key==='phone') $out['values'][$key]=pr_phone($v);
            elseif($key==='wechat') $out['values'][$key]=pr_wechat($v);
            elseif($key==='domain') {
                $v=strtolower(rtrim($v,'.'));
                if(!preg_match('/^(?=.{1,180}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$v)) throw new RuntimeException('域名请填 example.com，不要带 https:// 或路径');
                $out['values'][$key]=$v;
            } elseif(in_array($key,['domain_expiry','server_expiry','miniapp_expiry','icp_expiry'],true)) {
                try{$date=ps_import_date($v);}catch(Throwable $e){$date=null;}
                $out['values'][$key]=pr_date($date?:$v);
            } elseif($key==='owner') {
                if(preg_match('/^(客户自有|客户自备|自有|自备)$/u',$v)) $out['values'][$key]='customer';
                elseif(in_array($v,['我们代管','代管','公司代管','ours'],true)) $out['values'][$key]='ours';
                else throw new RuntimeException('域名归属填“我们代管”或“客户自有”');
            } elseif(in_array($key,['owner_note','miniapp_name'],true)) {
                if(mb_strlen($v)>($key==='miniapp_name'?180:500)) throw new RuntimeException('内容过长');
                $out['values'][$key]=$v;
            }
            // customer / missing and any financial columns are display-only or ignored.
        }catch(RuntimeException $e){$out['issues'][]=(pbf_columns()[$key]??$key).'：'.$e->getMessage();}
    }
    if(($out['values']['owner']??'')==='customer' && empty($out['values']['owner_note'])) {
        unset($out['values']['owner']);$out['issues'][]='客户自有域名需要归属备注；该归属未应用';
    }
    return $out;
}
function pbf_parse_sheets(array $sheets)
{
    $out=[];
    foreach($sheets as $name=>$rows) {
        $map=null;
        foreach($rows as $line=>$row) {
            if(!is_array($row) || !array_filter($row,function($v){return trim((string)$v)!=='';})) continue;
            if($map===null) {
                $candidate=pbf_map($row);
                if(isset($candidate['order_no']) || isset($candidate['id'])) $map=$candidate;
                continue;
            }
            if(ps_import_row_is_example($row)) continue;
            $r=pbf_parse_row($row,$map);
            if(!$r['values'] && !$r['issues']) continue;
            $r['source']=(string)$name.' 第 '.((int)$line+1).' 行'; $out[]=$r;
            if(count($out)>PBF_MAX_ROWS) throw new RuntimeException('每次最多补全 500 行，请拆分后再上传');
        }
    }
    if(!$out) throw new RuntimeException('没有找到可补全的信息。请保留“订单ID”或“订单号”列，并填写至少一个资料字段');
    return $out;
}
function pbf_scope($actor,&$params)
{
    return pr_order_where($actor,$params);
}
function pbf_order(array $row,$actor,$lock=false)
{
    $params=[]; $where=pbf_scope($actor,$params);
    if(!empty($row['id'])){$where.=' AND o.id=?';$params[]=(int)$row['id'];}
    elseif(!empty($row['order_no'])){$where.=' AND o.order_no=?';$params[]=$row['order_no'];}
    else throw new RuntimeException('请填写订单ID或订单号；补全入口不新建订单');
    if(!empty($row['business'])){$where.=' AND o.project_type=?';$params[]=$row['business'];}
    $q=db()->prepare('SELECT o.id,o.order_no,o.customer_name,o.project_type,o.order_date FROM project_orders o WHERE '.$where.' LIMIT 2'.($lock?' FOR UPDATE':''));
    $q->execute($params);$orders=$q->fetchAll();
    if(!$orders) throw new RuntimeException('未找到你有权限补全的订单；请先由客服录单或将你关联为技术');
    if(count($orders)!==1) throw new RuntimeException('同一订单号对应多笔项目，请使用下载模板中的订单ID或填写准确业务类型');
    $o=$orders[0];
    if(!empty($row['order_no']) && $row['order_no']!==$o['order_no']) throw new RuntimeException('订单ID与订单号不一致，请核对后再上传');
    return $o;
}
function pbf_existing($orderId,$lock=false)
{
    $q=db()->prepare('SELECT * FROM project_renewal_items WHERE order_id=? ORDER BY id'.($lock?' FOR UPDATE':''));
    $q->execute([(int)$orderId]);return $q->fetchAll();
}
/** A precise resource is mandatory if the order has several resources of the same type. */
function pbf_resource(array $items,$type,array $values)
{
    $rows=array_values(array_filter($items,function($r)use($type){return $r['resource_type']===$type;}));
    $name=$type==='domain'?($values['domain']??''):($type==='miniapp_certification'?($values['miniapp_name']??''):'');
    if($name!=='')foreach($rows as $r)if(mb_strtolower($r['resource_name'])===mb_strtolower($name))return $r;
    if(count($rows)>1)throw new RuntimeException('该订单有多个'.(pr_type_labels()[$type]??$type).'，请在线选择具体资源更正');
    return $rows[0]??null;
}
/** Pure three-way merge: blank/estimated -> fill; equal -> no-op; different -> conflict. */
function pbf_merge_value($current,$incoming,$estimated=false)
{
    if($incoming===null || $incoming==='')return 'skip';
    if((string)$current===(string)$incoming)return $estimated?'fill':'same';
    return ($current===null || $current==='' || $estimated)?'fill':'conflict';
}
function pbf_plan(array $order,array $items,array $row)
{
    $plan=['order_id'=>(int)$order['id'],'order_no'=>$order['order_no'],'business'=>$order['project_type'],'source'=>$row['source']??'',
        'changes'=>[],'same'=>[],'issues'=>$row['issues']??[]];
    $v=$row['values'];$supported=pr_types_for($order['project_type']);
    foreach($v as $key=>$value) {
        if($key==='owner_note')continue;
        $label=pbf_columns()[$key]??$key;
        try {
            if(in_array($key,['phone','wechat'],true)) {
                $active=array_values(array_filter($items,function($r){return $r['status']!=='closed';}));
                $hashes=array_values(array_unique(array_filter(array_column($active,$key.'_hash'))));
                $hash=hash_hmac('sha256',$key==='wechat'?mb_strtolower($value):$value,pv_key());
                if($hashes && (count($hashes)>1 || $hashes[0]!==$hash)) {$plan['issues'][]=$label.'与已有资料不同，未覆盖；请使用原有更正入口';continue;}
                $missing=array_filter($active,function($r)use($key){return empty($r[$key.'_hash']);});
                if(!$active || $missing) $plan['changes'][]=['key'=>$key,'value'=>$value,'label'=>$label];
                else $plan['same'][]=$label;
                continue;
            }
            $types=['domain'=>'domain','owner'=>'domain','domain_expiry'=>'domain','server_expiry'=>'server','miniapp_expiry'=>'miniapp_certification','icp_expiry'=>'icp','miniapp_name'=>'miniapp_certification'];
            $type=$types[$key]??null;if(!$type || !isset($supported[$type]))throw new RuntimeException('此业务不支持该资源');
            $item=pbf_resource($items,$type,$v);
            if($item && ($item['status']==='closed' || ($item['owner']??'ours')==='customer')) {
                if($key==='owner' && $value==='customer'){$plan['same'][]=$label;continue;}
                throw new RuntimeException('现有资源已结束维护或客户自有，请在线更正，不自动恢复维护');
            }
            if($key==='owner') {
                if($value==='ours'){$plan['same'][]=$label;continue;}
                if($item && ($item['resource_name']!=='' || $item['expiry_source']!=='estimated' || !empty($item['sms_enabled']))) throw new RuntimeException('已有代管资料，归属变更请走原有更正入口');
                $state='fill';
            }else{
                $isDate=substr($key,-7)==='_expiry';
                $current=$item?($isDate?$item['expires_on']:$item['resource_name']):null;
                $state=pbf_merge_value($current,$value,$isDate && $item && $item['expiry_source']==='estimated');
            }
            if($state==='fill')$plan['changes'][]=['key'=>$key,'type'=>$type,'item_id'=>(int)($item['id']??0),'value'=>$value,'label'=>$label];
            elseif($state==='same')$plan['same'][]=$label;
            else $plan['issues'][]=$label.'与已有资料不同，未覆盖；请使用原有更正入口';
        }catch(RuntimeException $e){$plan['issues'][]=$label.'：'.$e->getMessage();}
    }
    // A customer-owned decision should not import active expiry dates into that same resource.
    foreach($plan['changes'] as $c)if($c['key']==='owner' && $c['value']==='customer') {
        $plan['changes']=array_values(array_filter($plan['changes'],function($c){return $c['key']!=='domain_expiry';}));
        break;
    }
    return $plan;
}
function pbf_preview(array $rows,$actor)
{
    $out=[];$seen=[];
    foreach($rows as $row) {
        try{
            $o=pbf_order($row,$actor);$plan=pbf_plan($o,pbf_existing($o['id']),$row);
            $safe=[];
            foreach($plan['changes'] as $c){
                if(isset($seen[$o['id']][$c['key']]) && $seen[$o['id']][$c['key']]!==$c['value']){
                    $plan['issues'][]=$c['label'].'在同一文件的多行填写不同，本行未应用';continue;
                }
                $seen[$o['id']][$c['key']]=$c['value'];$safe[]=$c;
            }
            $plan['changes']=$safe;$out[]=$plan;
        }
        catch(RuntimeException $e){$out[]=['order_id'=>0,'order_no'=>$row['order_no'],'business'=>$row['business'],'source'=>$row['source'],'changes'=>[],'same'=>[],'issues'=>[$e->getMessage()]];}
    }
    return $out;
}
function pbf_commit_row(array $row,$actor,$batchKey,$approvedKeys=null)
{
    $pdo=db();if($pdo->inTransaction())throw new RuntimeException('补全必须使用独立事务');
    $pdo->beginTransaction();
    try{
        $order=pbf_order($row,$actor,true);$items=pbf_existing($order['id'],true);$plan=pbf_plan($order,$items,$row);
        if($approvedKeys!==null)$plan['changes']=array_values(array_filter($plan['changes'],function($c)use($approvedKeys){return in_array($c['key'],$approvedKeys,true);}));
        $changed=[];$touched=[];$oldExpiry=array_column($items,'expires_on','id');
        foreach($plan['changes'] as $change){
            $key=$change['key'];$value=$change['value'];
            if(in_array($key,['phone','wechat'],true)){
                // Do not use the generic import path: it can create a second named resource.
                $targets=array_values(array_filter($items,function($i)use($key){return $i['status']!=='closed' && empty($i[$key.'_hash']);}));
                if(!$items){$type=pr_default_type($order['project_type']);$id=pbf_insert_resource($order,$type);$items=pbf_existing($order['id'],true);$targets=$items;}
                foreach($targets as $i){
                    $hash=hash_hmac('sha256',$key==='wechat'?mb_strtolower($value):$value,pv_key());
                    $pdo->prepare("UPDATE project_renewal_items SET {$key}_cipher=?,{$key}_hash=?,revision=revision+1,updated_at=NOW() WHERE id=? AND {$key}_hash=''")->execute([pv_encrypt($value),$hash,$i['id']]);
                    $touched[$i['id']]=true;
                }
                if(!$targets){$plan['issues'][]=$change['label'].'：所有资源已结束维护，未新增提醒';continue;}
            }else{
                $type=$change['type'];$item=pbf_resource($items,$type,$row['values']);
                if(!$item){pbf_insert_resource($order,$type);$items=pbf_existing($order['id'],true);$item=pbf_resource($items,$type,$row['values']);}
                if($key==='owner'){
                    $pdo->prepare("UPDATE project_renewal_items SET owner='customer',status='closed',sms_enabled=0,note=?,revision=revision+1,updated_at=NOW() WHERE id=?")->execute([$row['values']['owner_note'],$item['id']]);
                    $pdo->prepare("UPDATE project_renewal_sms SET state='cancelled',provider_code='CUSTOMER_OWNED',updated_at=NOW() WHERE item_id=? AND state IN ('pending','failed')")->execute([$item['id']]);
                }elseif(substr($key,-7)==='_expiry'){
                    $pdo->prepare("UPDATE project_renewal_items SET expires_on=?,expiry_source='imported',revision=revision+1,updated_at=NOW() WHERE id=? AND (expires_on IS NULL OR expiry_source='estimated')")->execute([$value,$item['id']]);
                    $pdo->prepare("UPDATE project_renewal_sms SET state='cancelled',provider_code='RESOURCE_UPDATED',updated_at=NOW() WHERE item_id=? AND state IN ('pending','failed') AND expires_on<>?")->execute([$item['id'],$value]);
                }else{
                    $pdo->prepare("UPDATE project_renewal_items SET resource_name=?,revision=revision+1,updated_at=NOW() WHERE id=? AND resource_name=''")->execute([$value,$item['id']]);
                }
                $touched[$item['id']]=true;
            }
            $changed[]=$key;$items=pbf_existing($order['id'],true);
        }
        foreach(array_keys($touched) as $id){
            $pdo->prepare('INSERT INTO project_renewal_history (item_id,action,actor_type,actor_id,old_expiry,new_expiry,details_json) SELECT id,?,?,?,?,expires_on,? FROM project_renewal_items WHERE id=?')
                ->execute(['batch_fill',$actor['type'],$actor['id'],$oldExpiry[$id]??null,json_encode(['batch'=>$batchKey,'fields'=>$changed,'source'=>$row['source']??''],JSON_UNESCAPED_UNICODE),$id]);
        }
        if($changed)ps_audit('order',$order['id'],'batch_fill',$actor,['batch'=>$batchKey,'fields'=>$changed,'source'=>$row['source']??'','conflicts'=>count($plan['issues'])]);
        $pdo->commit();$plan['applied']=$changed;return $plan;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function pbf_insert_resource($order,$type)
{
    $items=pbf_existing($order['id']);$contact=['phone_cipher'=>null,'phone_hash'=>'','wechat_cipher'=>null,'wechat_hash'=>''];
    foreach(['phone','wechat'] as $key){
        $rows=array_values(array_filter($items,function($i)use($key){return $i['status']!=='closed' && !empty($i[$key.'_hash']);}));
        if($rows && count(array_unique(array_column($rows,$key.'_hash')))===1){$contact[$key.'_hash']=$rows[0][$key.'_hash'];$contact[$key.'_cipher']=$rows[0][$key.'_cipher'];}
    }
    db()->prepare('INSERT INTO project_renewal_items (order_id,resource_type,seed_key,expires_on,phone_cipher,phone_hash,wechat_cipher,wechat_hash) VALUES (?,?,?,?,?,?,?,?)')->execute([$order['id'],$type,$type,pr_default_expiry($order['order_date']),$contact['phone_cipher'],$contact['phone_hash'],$contact['wechat_cipher'],$contact['wechat_hash']]);
    return (int)db()->lastInsertId();
}
function pbf_missing_orders($actor,$month='',$keyword='',$orderId=0)
{
    $params=[];$where=pbf_scope($actor,$params);
    if($month!==''){
        if(!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$month))throw new RuntimeException('月份无效');
        $where.=' AND o.order_date>=? AND o.order_date<?';$params[]=$month.'-01';$params[]=(new DateTimeImmutable($month.'-01'))->modify('+1 month')->format('Y-m-d');
    }else{$where.=' AND (o.order_date>=? OR o.order_date IS NULL)';$params[]=(new DateTimeImmutable(pr_today()))->modify('-120 day')->format('Y-m-d');}
    if($keyword!==''){$where.=' AND (o.order_no LIKE ? OR o.customer_name LIKE ?)';$params[]='%'.mb_substr($keyword,0,100).'%';$params[]='%'.mb_substr($keyword,0,100).'%';}
    if($orderId){$where.=' AND o.id=?';$params[]=(int)$orderId;}
    $where.=" AND (
        NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND (((r.phone_hash<>'' OR COALESCE(r.wechat_hash,'')<>'') AND r.status<>'closed') OR r.owner='customer'))
        OR (o.project_type='小程序开发' AND (NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='server') OR EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='server' AND r.status<>'closed' AND (r.expires_on IS NULL OR r.expiry_source='estimated'))))
        OR (o.project_type<>'小程序开发' AND COALESCE(res.domain_mode,'pending')<>'none' AND (NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='domain') OR EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='domain' AND r.status<>'closed' AND (r.resource_name='' OR r.expires_on IS NULL OR r.expiry_source='estimated'))))
    )";
    $q=db()->prepare('SELECT o.id,o.order_no,o.customer_name,o.project_type,o.order_date,COALESCE(res.domain_mode,\'pending\') domain_mode FROM project_orders o LEFT JOIN project_order_resources res ON res.order_id=o.id WHERE '.$where.' ORDER BY o.order_date DESC,o.id DESC LIMIT 501');
    $q->execute($params);$rows=$q->fetchAll();$out=[];
    foreach($rows as $o){
        $items=pbf_existing($o['id']);$need=[];
        $contact=false;foreach($items as $i)if(($i['status']!=='closed' && pr_has_contact($i)) || ($i['owner']??'ours')==='customer')$contact=true;
        if(!$contact)$need[]='手机号或微信号';
        $types=$o['project_type']==='小程序开发'?['server']:($o['domain_mode']==='none'?[]:['domain']);
        foreach($types as $type){
            $rs=array_values(array_filter($items,function($i)use($type){return $i['resource_type']===$type;}));
            if(!$rs){$need[]=pr_type_labels()[$type].'资料';continue;}
            foreach($rs as $r){if($r['status']==='closed')continue;if($type==='domain' && $r['resource_name']==='')$need[]='域名';if(!$r['expires_on'] || $r['expiry_source']==='estimated')$need[]=pr_type_labels()[$type].'到期日';}
        }
        if($need){$o['missing']=array_values(array_unique($need));$out[]=$o;}
    }
    return $out;
}
