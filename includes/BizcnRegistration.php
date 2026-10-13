<?php
require_once __DIR__.'/BizcnRegistrationStore.php';

function br_template_pass($id)
{
    if(!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',(string)$id))throw new InvalidArgumentException('实名模板编号不正确');
    $d=bd_call('domainTemplateService','queryTemplateAuthStatus',['contid'=>$id]);
    if(($d['auditstatus'] ?? '')!=='pass')throw new RuntimeException('所选实名模板尚未审核通过，请在商务中国完成实名');
}
function br_price($domain)
{
    $d=bd_call('domainService','checkPremium',['domainname'=>$domain]);
    foreach(bd_rows($d['domains']['domain'] ?? []) as $row) {
        if(strtolower((string)($row['name'] ?? ''))!==$domain)continue;
        if(isset($row['result']) && (string)($row['result']['code'] ?? '')!=='200')throw new RuntimeException('注册商暂时无法核验此域名');
        if((string)($row['avail'] ?? '')!=='1')throw new RuntimeException('该域名暂不可注册，请更换名称');
        if(!isset($row['price']) || !is_numeric($row['price']) || (float)$row['price']<=0 || (float)$row['price']>10000000)throw new RuntimeException('未取得有效注册价格，请联系管理员');
        if(!in_array(strtolower((string)($row['premium'] ?? '')),['true','false'],true))throw new RuntimeException('高价域名状态暂未明确，请稍后再试');
        return ['price_cents'=>(int)round((float)$row['price']*100),'premium'=>strtolower($row['premium'])==='true'];
    }
    throw new RuntimeException('注册商没有返回此域名的可注册性，请稍后再试');
}
function br_public($r)
{
    $quote=json_decode($r['quote_json'],true) ?: [];
    return ['id'=>(int)$r['id'],'domain'=>$r['domainname'],'status'=>$r['status'],'order_id'=>$r['order_id'],'day'=>$r['usage_day'],'quote'=>$quote,'result'=>json_decode($r['result_json'] ?? '',true) ?: []];
}
function br_catalog_registered($r)
{
    if($r['status']!=='registered')return;
    $data=json_decode($r['result_json'],true) ?: [];$day=$r['confirmed_day'] ?: br_day(time());
    // import_id=0 denotes registration, not a fabricated spreadsheet import.
    $reg=bc_date($data['createdate'] ?? '',true);$expiry=bc_date($data['expirationdate'] ?? '',true);
    $q=db()->prepare('INSERT INTO bizcn_domain_catalog (provider_account,domainname,display_name,registrar,owner_username,regdate,expireddate,status,source_as_of,import_id,source_row) VALUES (?,?,?,?,?,?,?,?,?,0,0) ON DUPLICATE KEY UPDATE status=VALUES(status),regdate=COALESCE(VALUES(regdate),regdate),expireddate=COALESCE(VALUES(expireddate),expireddate)');
    $q->execute([$r['provider_account'],$r['domainname'],$r['domainname'],(string)($data['registrar'] ?? ''),$r['provider_account'],$reg,$expiry,'已注册（API确认）',$day]);
}
function br_dispatch($action,$input,$actor)
{
    $pdo=db();$account=bd_config()['username'];$now=time();$s=br_settings($pdo,$account);
    if($action==='registration_config') {
        return ['daily_limit'=>$s['daily_limit'],'used'=>br_usage($pdo,$account,br_day($now)),'day'=>br_day($now),'timezone'=>'Asia/Shanghai','revision'=>$s['revision'],'templates'=>$s['templates'],'ns'=>$s['ns'],'admin'=>br_admin($actor),'ready'=>count($s['templates'])>0 && count($s['ns'])===2 && $s['daily_limit']>0];
    }
    if($action==='registration_templates') {
        if(!br_admin($actor))throw new RuntimeException('只有管理员可查询和批准实名模板');
        $page=max(1,min(10000,(int)($input['page'] ?? 1)));
        $name=trim((string)($input['search'] ?? ''));$id=trim((string)($input['contid'] ?? ''));
        if(mb_strlen($name)>120 || preg_match('/[\x00-\x1f]/',$name) || ($id!=='' && !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$id)))throw new InvalidArgumentException('请输入有效的模板名称或编号');
        // Registrar currently ignores name/id filters. Search our minimal administrator-only cache instead.
        $count=$pdo->prepare('SELECT COUNT(*) FROM bizcn_registration_templates WHERE provider_account=?');$count->execute([$account]);$cached=(int)$count->fetchColumn();
        if($cached) {
            $where='provider_account=?';$params=[$account];
            if($id!==''){$where.=' AND template_id=?';$params[]=$id;}
            if($name!==''){$pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$name).'%';$where.=" AND (label LIKE ? ESCAPE '!' OR owner_label LIKE ? ESCAPE '!')";array_push($params,$pattern,$pattern);}
            $count=$pdo->prepare('SELECT COUNT(*) FROM bizcn_registration_templates WHERE '.$where);$count->execute($params);$total=(int)$count->fetchColumn();$page=max(1,min($page,max(1,(int)ceil($total/20))));
            $q=$pdo->prepare('SELECT template_id id,label,owner_label owner FROM bizcn_registration_templates WHERE '.$where.' ORDER BY template_id LIMIT 20 OFFSET '.(($page-1)*20));$q->execute($params);
            return ['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'cached'=>true,'cached_count'=>$cached];
        }
        if($name!=='' || $id!=='')throw new RuntimeException('请先点击“同步可搜索模板目录”。商务中国当前忽略筛选参数，不能把无关模板当成搜索结果');
        $args=['pageno'=>'20','currentpage'=>(string)$page];
        $d=bd_call('domainTemplateService','getTemplateList',['domaintemplateinfo'=>$args]);
        $rows=[];foreach(bd_rows($d['domaintemplatelist']['domaintemplate'] ?? []) as $row)$rows[]=['id'=>(string)($row['contid'] ?? ''),'label'=>(string)($row['templatename'] ?? ''),'owner'=>(string)(!empty($row['org_m']) ? $row['org_m'] : ($row['name_m2'] ?? '').($row['name_m1'] ?? ''))];
        return ['rows'=>$rows,'total'=>(int)($d['totalresult'] ?? 0),'page'=>$page];
    }
    if($action==='registration_sync_templates') {
        if(!br_admin($actor))throw new RuntimeException('仅管理员可同步实名模板目录');
        $page=max(1,min(1000,(int)($input['page'] ?? 1)));
        $d=bd_call('domainTemplateService','getTemplateList',['domaintemplateinfo'=>['pageno'=>'50','currentpage'=>(string)$page]]);$rows=bd_rows($d['domaintemplatelist']['domaintemplate'] ?? []);
        $sql='INSERT INTO bizcn_registration_templates (provider_account,template_id,label,owner_label,refreshed_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),owner_label=VALUES(owner_label),refreshed_at=VALUES(refreshed_at)';
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql='INSERT INTO bizcn_registration_templates (provider_account,template_id,label,owner_label,refreshed_at) VALUES (?,?,?,?,?) ON CONFLICT(provider_account,template_id) DO UPDATE SET label=excluded.label,owner_label=excluded.owner_label,refreshed_at=excluded.refreshed_at';
        $save=$pdo->prepare($sql);$pdo->beginTransaction();try{foreach($rows as $row){$id=(string)($row['contid'] ?? '');if(!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$id))throw new RuntimeException('注册商模板编号异常，暂未同步本批');$owner=!empty($row['org_m'])?$row['org_m']:($row['name_m2'] ?? '').($row['name_m1'] ?? '');$save->execute([$account,$id,mb_substr((string)($row['templatename'] ?? ''),0,120),mb_substr((string)$owner,0,200),$now]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $total=(int)($d['totalresult'] ?? 0);$size=(int)($d['pageno'] ?? 50);if($size<=0 || $size>50)$size=50;
        return ['page'=>$page,'received'=>count($rows),'total'=>$total,'done'=>count($rows)===0 || $page*$size>=$total,'completed'=>min($total,$page*$size)];
    }
    if($action==='registration_settings') {
        if(!br_admin($actor))throw new RuntimeException('只有管理员可以设置注册上限');
        $templates=json_decode((string)($input['templates'] ?? ''),true);
        if(!is_array($templates) || count($templates)>50)throw new InvalidArgumentException('请选择可使用的实名模板');
        $approved=[];foreach($templates as $t) {
            $id=(string)($t['id'] ?? '');$label=trim((string)($t['label'] ?? ''));$owner=trim((string)($t['owner'] ?? ''));
            if($label==='' || $owner==='' || mb_strlen($label)>120 || mb_strlen($owner)>200 || preg_match('/[\x00-\x1f]/',$label.$owner))throw new InvalidArgumentException('请为模板填写清晰的用途名称和实名主体');
            br_template_pass($id);$approved[$id]=['id'=>$id,'label'=>$label,'owner'=>$owner];
        }
        $ns=trim((string)($input['ns'] ?? ''))==='' ? [] : bd_ns($input['ns']);if($ns && count($ns)!==2)throw new InvalidArgumentException('注册默认 NS 需要恰好两个不同服务器');
        br_save_settings($pdo,$account,$actor,$input['daily_limit'] ?? '',array_values($approved),$ns,$input['revision'] ?? 0);
        ps_audit('domain_registration',0,'settings',$actor,['daily_limit'=>(int)$input['daily_limit'],'template_ids'=>array_keys($approved),'ns'=>$ns]);return ['message'=>'注册配置已保存，新报价将使用新配置'];
    }
    if($action==='registration_quote') {
        $domain=bd_domain($input['domain'] ?? '');$template=null;
        foreach($s['templates'] as $t)if($t['id']===($input['template_id'] ?? ''))$template=$t;
        if(!$template || count($s['ns'])!==2 || $s['daily_limit']===0)throw new RuntimeException('请管理员先批准实名模板并配置默认 NS、开启注册额度');
        if(bc_find($domain))throw new RuntimeException('该域名已在目录中，请先查看已有域名，不要重复注册');
        br_template_pass($template['id']);$price=br_price($domain);
        if($price['premium'] && !br_admin($actor))throw new RuntimeException('这是高价域名，请由管理员核对后注册');
        $quote=['domain'=>$domain,'term'=>1,'currency'=>'CNY','template'=>$template,'ns'=>$s['ns'],'revision'=>$s['revision']]+$price;
        return br_quote_save($pdo,$account,$actor,$quote,$now);
    }
    if($action==='registration_submit') {
        // Load only our own token, before any read-only or paid calls.
        $q=$pdo->prepare('SELECT * FROM bizcn_registration_requests WHERE provider_account=? AND token_hash=? AND actor_key=?');$q->execute([$account,hash('sha256',(string)($input['token'] ?? '')),br_actor_key($actor)]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r || ($input['ack'] ?? '')!=='1' || ($input['confirm'] ?? '')!==$r['domainname'])throw new RuntimeException('请核对域名、主体、价格后确认扣费');
        if($r['status']==='quoted') {
            $quote=json_decode($r['quote_json'],true);
            if((int)$r['expires_at']<$now || (int)$quote['revision']!==$s['revision'])throw new RuntimeException('报价已过期，请重新查询');
            br_template_pass($quote['template']['id']);$fresh=br_price($quote['domain']);
            if($fresh['price_cents']!==$quote['price_cents'] || $fresh['premium']!==$quote['premium'])throw new RuntimeException('注册商价格已变化，请重新查询报价后确认');
        }
        $r=br_reserve($pdo,$account,$actor,$input['token'],$input['confirm'],$input['ack'],time());
        if(br_mark_started($pdo,$r['id'],time())) {
            $quote=json_decode($r['quote_json'],true);
            try {
                $d=bd_transport('domainService','addDomainByID',['domainname'=>$quote['domain'],'term'=>1,'dns_host1'=>$quote['ns'][0],'dns_host2'=>$quote['ns'][1],'contid'=>$quote['template']['id'],'agent_orderid'=>(string)$r['id'],'price'=>number_format($quote['price_cents']/100,2,'.','')]);
                br_record_result($pdo,$r['id'],$d,time());
            }catch(Throwable $e) {error_log('Bizcn registration awaiting reconciliation: request '.(int)$r['id'].' '.get_class($e));}
            ps_audit('domain_registration',$r['id'],'submitted',$actor,['domain'=>$r['domainname'],'price_cents'=>$quote['price_cents']]);
        }
        $r=br_request($pdo,$account,$actor,$r['id']);br_catalog_registered($r);return br_public($r);
    }
    if($action==='registration_status') {
        $r=br_request($pdo,$account,$actor,$input['id'] ?? 0);
        if(in_array($r['status'],['processing','unknown'],true) && $r['order_id']!=='') {
            $d=bd_call('orderService','queryDomainOrder',['orderid'=>$r['order_id']]);br_record_result($pdo,$r['id'],$d,time());$r=br_request($pdo,$account,$actor,$r['id']);
        }
        br_catalog_registered($r);return br_public($r);
    }
    if($action==='registration_release') {
        br_release_unknown($pdo,$account,$actor,$input['id'] ?? 0,$input['note'] ?? '',$input['confirm'] ?? '');
        ps_audit('domain_registration',(int)$input['id'],'released_after_manual_verification',$actor,['domain'=>$input['confirm'],'note'=>$input['note']]);return ['message'=>'已按管理员核验结论关闭申请并释放额度'];
    }
    if($action==='registration_reconcile') {
        if(!br_admin($actor))throw new RuntimeException('仅管理员可关联注册商订单');
        $r=br_request($pdo,$account,$actor,$input['id'] ?? 0);$order=trim((string)($input['order_id'] ?? ''));
        if(!in_array($r['status'],['unknown','processing'],true) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$order))throw new InvalidArgumentException('请选择待核对申请并填写注册商订单号');
        $d=bd_call('orderService','queryDomainOrder',['orderid'=>$order]);
        if(strtolower((string)($d['domainname'] ?? ''))!==$r['domainname'] || (string)($d['orderid'] ?? '')!==$order)throw new RuntimeException('注册商订单与此域名不匹配，不会关联');
        br_record_result($pdo,$r['id'],$d,time());$r=br_request($pdo,$account,$actor,$r['id']);br_catalog_registered($r);
        ps_audit('domain_registration',$r['id'],'reconciled',$actor,['domain'=>$r['domainname'],'order_id'=>$order,'status'=>$r['status']]);return br_public($r);
    }
    if($action==='registration_history') {
        $values=[$account];$where='provider_account=?';if(!br_admin($actor)){$where.=' AND actor_key=?';$values[]=br_actor_key($actor);}
        $q=$pdo->prepare("SELECT * FROM bizcn_registration_requests WHERE $where AND status<>'quoted' ORDER BY id DESC LIMIT 50");$q->execute($values);return ['rows'=>array_map('br_public',$q->fetchAll(PDO::FETCH_ASSOC))];
    }
    throw new InvalidArgumentException('不支持此注册操作');
}
