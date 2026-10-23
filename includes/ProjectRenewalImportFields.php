<?php
require_once __DIR__ . '/ProjectRenewalMath.php';
/** 仅在已确认的联系方式列中识别号码；备注中的唯一手机号、国际前缀和 Excel 科学计数法都可保留。 */
function pr_import_phone_value($value, $inferred = false)
{
    $value=trim((string)$value);
    if (preg_match('/^[0-9]+(?:\.[0-9]+)?[eE]\+?10$/D',$value)) $value=sprintf('%.0f',(float)$value);
    $compact=preg_replace('/[\s\-()（）]+/u','',$value);
    if (preg_match('/^861[3-9][0-9]{9}$/D',$compact)) $compact=substr($compact,2);
    try { return pr_phone($compact,$inferred && strpos($compact,'+')!==0); } catch (RuntimeException $e) { /* 再识别带标注的号码 */ }
    preg_match_all('/(?<![0-9])(?:\+?86|0086)?(1[3-9][0-9]{9})(?![0-9])/',$value,$matches);
    $phones=array_values(array_unique($matches[1]));
    return count($phones)===1?$phones[0]:'';
}
/** Optional renewal fields must never block an otherwise valid order import. */
function pr_import_fields($head,$row,$columnMap=[])
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
        if (preg_match('/海外.*微信|^客户微信号?$|^微信号?$/u',$label) && !preg_match('/客服/u',$label)) { try { $wx=pr_wechat($value); } catch (RuntimeException $e) { $wx=''; } if ($wx!=='') $out['wechat']=$wx; }
        if (preg_match('/^域名归属/u',$label)) {
            // “客户自有”“客户自备”（可写“客户自有：已交付源码”）→ 客户自有；“我们代管”“代管”→ 我们代管
            if (preg_match('/^(客户自有|客户自备|客户|自有|自备)/u',$value)) { $out['resources']['domain']['owner']='customer'; $note=trim(preg_replace('/^(客户自有|客户自备|客户|自有|自备)[:：\s-]*/u','',$value)); $out['resources']['domain']['owner_note']=$note!==''?$note:'上传表格标注为客户自有，需核对'; }
            elseif (preg_match('/^(我们|代管|公司|我方)/u',$value)) $out['resources']['domain']['owner']='ours';
        }
        // 新模板已去掉重复的“域名归属”列：域名来源写“客户提供 / 客户自备 / 客户自有”＝客户自己的域名，等同原来的“域名归属＝客户自有”（不续费）；已有“域名归属”列时以它为准
        if (preg_match('/^域名(来源|使用)$/u',$label) && preg_match('/^(客户提供|客户自备|客户自有|客户的)/u',$value) && !isset($out['resources']['domain']['owner'])) { $out['resources']['domain']['owner']='customer'; $out['resources']['domain']['owner_note']='域名来源为客户提供，上传表格标注，需核对'; }
        // 小程序新模板：业务种类写“永久”→ 服务器到期日记为永久（2099-01-01）；“续费联系方式”可写手机号或微信号
        if (preg_match('/^业务种类/u',$label) && preg_match('/永久|终身|买断|一次性/u',$value)) $permanent=true;
        if (preg_match('/^续费联系方式(电话|手机|手机号)$/u',$label)) { $phone=pr_import_phone_value($value); if ($phone!=='') $out['phone']=$phone; continue; }
        if (preg_match('/^续费联系方式微信(号)?$/u',$label)) { try { $wx=pr_wechat($value); } catch (RuntimeException $e) { $wx=''; } if ($wx!=='') $out['wechat']=$wx; continue; }
        if (preg_match('/^(续费|客户)?联系方式$/u',$label)) {
            $compact=preg_replace('/[\s\-()]+/u','',$value);
            if (pr_import_phone_value($value)!=='') { $out['phone']=pr_import_phone_value($value); }
            else { try { $wx=pr_wechat($value); } catch (RuntimeException $e) { $wx=''; } if ($wx!=='') $out['wechat']=$wx; }
        }
        if (in_array($label,['小程序名称','小程序名'],true)) $out['resources']['miniapp_certification']['resource_name']=mb_substr($value,0,180);
        if (preg_match('/客户.*(手机|电话)|联系电话|客户联系方式|^手机号码?$|备注.*客户电话/u',$label) && !preg_match('/客服.*(手机|电话)/u',$label)) {
            if (preg_match('/^(客户)?(手机号?码?|电话|联系电话)$/u',$label)) {
                $out['phone']=pr_import_phone_value($value);
                continue;
            }
            preg_match_all('/(?<![0-9])1[3-9][0-9]{9}(?![0-9])/',$value,$m);
            $phones=array_values(array_unique($m[0])); if (count($phones)===1) $out['phone']=$phones[0];
        }
    }
    // 无表头旧表已由内容识别出客户电话列；不能再依赖空表头，也不能把付款昵称、账号或任意备注当成微信号。
    if ($out['phone']==='' && isset($columnMap['contact_note'])) {
        $i=(int)$columnMap['contact_note'];
        if (trim((string)($head[$i]??''))==='') {
            $value=trim((string)($row[$i]??''));
            $out['phone']=pr_import_phone_value($value,true);
            if ($out['phone']==='' && preg_match('/^(?:微信|wechat|wx)\s*[:：]\s*(.+)$/iu',$value,$wx)) { try { $out['wechat']=pr_wechat($wx[1]); } catch (RuntimeException $e) {} }
        }
    }
    if ($permanent) $out['resources']['server']['expires_on']='2099-01-01';
    return $out;
}

/** 合并同单附加行时保留后续行的续费资料；已有值和明确的到期日不被空格覆盖。 */
function pr_import_merge_fields($first,$next)
{
    $out=$first;
    foreach (['phone','wechat'] as $key) if (empty($out[$key]) && !empty($next[$key])) $out[$key]=$next[$key];
    foreach (($next['resources']??[]) as $type=>$fields) {
        foreach ($fields as $key=>$value) if ((!isset($out['resources'][$type][$key]) || $out['resources'][$type][$key]==='') && $value!=='') $out['resources'][$type][$key]=$value;
    }
    return $out;
}
