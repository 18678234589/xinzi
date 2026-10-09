<?php
require_once __DIR__.'/ProjectRenewals.php';
/** Optional renewal fields must never block an otherwise valid order import. */
function pr_import_fields($head,$row)
{
    $out=['phone'=>'','wechat'=>'','resources'=>[]]; $permanent=false;
    foreach ($head as $i=>$label) {
        $label=preg_replace('/[\s（）()：:]/u','',trim((string)$label)); $value=trim((string)($row[$i]??''));
        if ($value==='' || mb_strlen($label)>40) continue;
        // 域名 / 服务器 / 备案 / 微信认证 的到期日列；域名与服务器合写的列、长段说明不当作权威日期（见 pr_import_label_type）
        $type=pr_import_label_type($label);
        if ($type) {
            try { $date=function_exists('ps_import_date')?ps_import_date($value):null; } catch (Throwable $e) { $date=null; }
            if (!$date) { try { $date=pr_date($value); } catch (RuntimeException $e) { $date=null; } }
            if ($date) { try { $out['resources'][$type]['expires_on']=pr_date($date); } catch (RuntimeException $e) {} }
        }
        if (in_array($label,['域名','域名地址','网站域名','客户域名'],true) && preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,63}$/iD',$value)) $out['resources']['domain']['resource_name']=strtolower($value);
        if (preg_match('/海外.*微信|^客户微信号|^微信号$/u',$label) && !preg_match('/客服/u',$label)) { try { $wx=pr_wechat($value); } catch (RuntimeException $e) { $wx=''; } if ($wx!=='') $out['wechat']=$wx; }
        if (preg_match('/^域名归属/u',$label)) {
            // “客户自有”“客户自备”（可写“客户自有：已交付源码”）→ 客户自有；“我们代管”“代管”→ 我们代管
            if (preg_match('/^(客户自有|客户自备|客户|自有|自备)/u',$value)) { $out['resources']['domain']['owner']='customer'; $note=trim(preg_replace('/^(客户自有|客户自备|客户|自有|自备)[:：\s-]*/u','',$value)); $out['resources']['domain']['owner_note']=$note!==''?$note:'上传表格标注为客户自有，需核对'; }
            elseif (preg_match('/^(我们|代管|公司|我方)/u',$value)) $out['resources']['domain']['owner']='ours';
        }
        // 小程序新模板：业务种类写“永久”→ 服务器到期日记为永久（2099-01-01）；“续费联系方式”可写手机号或微信号
        if (preg_match('/^业务种类/u',$label) && preg_match('/永久|终身|买断|一次性/u',$value)) $permanent=true;
        if (preg_match('/^续费联系方式$/u',$label)) {
            $compact=preg_replace('/[\s\-()]+/u','',$value);
            if (preg_match('/^(\+?86)?1[3-9][0-9]{9}$/D',$compact) || preg_match('/^\+?[0-9]{7,15}$/D',$compact)) { try { $out['phone']=pr_phone($value); } catch (RuntimeException $e) { /* 无效联系方式不阻断订单导入 */ } }
            else { try { $wx=pr_wechat($value); } catch (RuntimeException $e) { $wx=''; } if ($wx!=='') $out['wechat']=$wx; }
        }
        if (in_array($label,['小程序名称','小程序名'],true)) $out['resources']['miniapp_certification']['resource_name']=mb_substr($value,0,180);
        if (preg_match('/客户.*(手机|电话)|联系电话|客户联系方式|^手机号$|备注.*客户电话/u',$label) && !preg_match('/客服.*(手机|电话)/u',$label)) {
            if (preg_match('/^(客户)?(手机号?码?|电话|联系电话)$/u',$label)) {
                try { $out['phone']=pr_phone($value); } catch (RuntimeException $e) { /* 无效联系方式不阻断订单导入 */ }
                continue;
            }
            preg_match_all('/(?<![0-9])1[3-9][0-9]{9}(?![0-9])/',$value,$m);
            $phones=array_values(array_unique($m[0])); if (count($phones)===1) $out['phone']=$phones[0];
        }
    }
    if ($permanent) $out['resources']['server']['expires_on']='2099-01-01';
    return $out;
}
function pr_import_apply($orderId,$fields,$actor)
{
    if (!pr_ready() || pr_scope($actor)==='none') return;
    $order=pr_order($orderId,$actor);
    if (empty($fields['resources']) && empty($fields['phone']) && empty($fields['wechat'])) return;
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
            if ($type==='domain' && !empty($resource['owner'])) {
                try {
                    if ($resource['owner']==='customer') $pdo->prepare("UPDATE project_renewal_items SET owner='customer',status='closed',sms_enabled=0,note=? WHERE id=?")->execute([mb_substr((string)($resource['owner_note']??''),0,500),$old['id']]);
                    elseif (($old['owner']??'ours')==='customer') $pdo->prepare("UPDATE project_renewal_items SET owner='ours',status='active' WHERE id=?")->execute([$old['id']]);
                } catch (Throwable $ownerError) { /* 归属写入失败不阻断订单导入 */ }
            }
            if (!empty($fields['wechat']) && ($old['wechat_hash']??'')==='') { // 微信号只在原来为空时写入，不覆盖
                $wx=pr_wechat($fields['wechat']);
                $pdo->prepare('UPDATE project_renewal_items SET wechat_cipher=?,wechat_hash=? WHERE id=?')->execute([pv_encrypt($wx),hash_hmac('sha256',mb_strtolower($wx),pv_key()),$old['id']]);
            }
            $details=['order_id'=>$orderId,'source'=>'excel','expiry_source'=>$source,'date_conflict'=>$conflict,'uploaded_expiry'=>$resource['expires_on']??null];
            $pdo->prepare('INSERT INTO project_renewal_history (item_id,action,actor_type,actor_id,old_expiry,new_expiry,details_json) VALUES (?,?,?,?,?,?,?)')->execute([$old['id'],$conflict?'import_conflict':'import',$actor['type'],$actor['id'],$old['expires_on'],$expiry,json_encode($details,JSON_UNESCAPED_UNICODE)]);
            ps_audit('renewal',$old['id'],$conflict?'import_date_conflict':'import_renewal',$actor,$details);
        }
        if ($nested) $pdo->exec('RELEASE SAVEPOINT renewal_import'); else $pdo->commit();
    } catch (Throwable $e) { if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT renewal_import'); elseif ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
