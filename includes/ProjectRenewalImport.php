<?php
require_once __DIR__.'/ProjectRenewals.php';
/** Optional renewal fields must never block an otherwise valid order import. */
function pr_import_fields($head,$row)
{
    $out=['phone'=>'','resources'=>[]];
    foreach ($head as $i=>$label) {
        $label=preg_replace('/[\s（）()：:]/u','',trim((string)$label)); $value=trim((string)($row[$i]??''));
        if ($value==='' || mb_strlen($label)>40) continue;
        $type=null;
        // A combined domain/server column or long narrative is not an authoritative domain date.
        if (!preg_match('/空间|服务器|SSL/i',$label)) {
            if (preg_match('/域名.*(到期|有效期)|(到期|有效期).*域名/u',$label)) $type='domain';
            elseif (preg_match('/(小程序|认证).*(到期|有效期)|(到期|有效期).*(小程序|认证)/u',$label)) $type='miniapp_certification';
        }
        if ($type) {
            try { $date=function_exists('ps_import_date')?ps_import_date($value):null; } catch (Throwable $e) { $date=null; }
            if (!$date) { try { $date=pr_date($value); } catch (RuntimeException $e) { $date=null; } }
            if ($date) { try { $out['resources'][$type]['expires_on']=pr_date($date); } catch (RuntimeException $e) {} }
        }
        if (in_array($label,['域名','域名地址','网站域名'],true) && preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,63}$/iD',$value)) $out['resources']['domain']['resource_name']=strtolower($value);
        if (in_array($label,['小程序名称','小程序名'],true)) $out['resources']['miniapp_certification']['resource_name']=mb_substr($value,0,180);
        if (preg_match('/客户.*(手机|电话)|联系电话|客户联系方式|^手机号$|备注.*客户电话/u',$label) && !preg_match('/客服.*(手机|电话)/u',$label)) {
            preg_match_all('/(?<![0-9])1[3-9][0-9]{9}(?![0-9])/',$value,$m);
            $phones=array_values(array_unique($m[0])); if (count($phones)===1) $out['phone']=$phones[0];
        }
    }
    return $out;
}
function pr_import_apply($orderId,$fields,$actor)
{
    if (!pr_ready() || pr_scope($actor)==='none') return;
    $order=pr_order($orderId,$actor);
    if (empty($fields['resources']) && empty($fields['phone'])) return;
    $pdo=db(); $nested=$pdo->inTransaction(); if ($nested) $pdo->exec('SAVEPOINT renewal_import'); else $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id FROM project_orders WHERE id=? FOR UPDATE'); $q->execute([$orderId]);
        if (empty($fields['resources'])) $fields['resources']=[$order['project_type']==='小程序开发'?'miniapp_certification':'domain'=>[]];
        foreach ($fields['resources'] as $type=>$resource) {
            if (!isset(pr_type_labels()[$type])) continue;
            $q=$pdo->prepare('SELECT * FROM project_renewal_items WHERE order_id=? AND resource_type=? AND (resource_name=? OR seed_key=?) ORDER BY resource_name=? DESC,id LIMIT 1 FOR UPDATE');
            $q->execute([$orderId,$type,$resource['resource_name']??'',$type,$resource['resource_name']??'']); $old=$q->fetch();
            $newResource=$old && !empty($resource['resource_name']) && $old['resource_name']!=='' && $old['resource_name']!==$resource['resource_name'];
            if (!$old || $newResource) {
                $pdo->prepare('INSERT INTO project_renewal_items (order_id,resource_type,seed_key,expires_on) VALUES (?,?,?,?)')->execute([$orderId,$type,$newResource?null:$type,pr_default_expiry($order['order_date'])]);
                $q=$pdo->prepare('SELECT * FROM project_renewal_items WHERE id=? FOR UPDATE'); $q->execute([(int)$pdo->lastInsertId()]); $old=$q->fetch();
            }
            $expiry=$old['expires_on']; $source=$old['expiry_source']; $conflict=false;
            if (!empty($resource['expires_on'])) {
                if ($source==='estimated' || !$expiry) { $expiry=$resource['expires_on']; $source='imported'; }
                elseif ($expiry!==$resource['expires_on']) $conflict=true;
            }
            $name=$old['resource_name']?:($resource['resource_name']??''); $phoneHash=$old['phone_hash']; $cipher=$old['phone_cipher'];
            if (!$phoneHash && !empty($fields['phone'])) { $phone=pr_phone($fields['phone']); $cipher=pv_encrypt($phone); $phoneHash=hash_hmac('sha256',$phone,pv_key()); }
            $pdo->prepare('UPDATE project_renewal_items SET resource_name=?,expires_on=?,expiry_source=?,phone_cipher=?,phone_hash=?,revision=revision+1,updated_at=NOW() WHERE id=?')->execute([$name,$expiry,$source,$cipher,$phoneHash,$old['id']]);
            $details=['order_id'=>$orderId,'source'=>'excel','expiry_source'=>$source,'date_conflict'=>$conflict,'uploaded_expiry'=>$resource['expires_on']??null];
            $pdo->prepare('INSERT INTO project_renewal_history (item_id,action,actor_type,actor_id,old_expiry,new_expiry,details_json) VALUES (?,?,?,?,?,?,?)')->execute([$old['id'],$conflict?'import_conflict':'import',$actor['type'],$actor['id'],$old['expires_on'],$expiry,json_encode($details,JSON_UNESCAPED_UNICODE)]);
            ps_audit('renewal',$old['id'],$conflict?'import_date_conflict':'import_renewal',$actor,$details);
        }
        if ($nested) $pdo->exec('RELEASE SAVEPOINT renewal_import'); else $pdo->commit();
    } catch (Throwable $e) { if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT renewal_import'); elseif ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
