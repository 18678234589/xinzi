<?php
/** Trusted registrar exports are inventory snapshots, not live ownership authorization. */
function bc_date($value, $allowEmpty = true, $date1904 = false)
{
    $value = trim((string)$value);
    if ($value === '' && $allowEmpty) return null;
    if (is_numeric($value) && (float)$value >= 20000 && (float)$value <= 100000) $value = gmdate('Y-m-d', strtotime($date1904 ? '1904-01-01 UTC' : '1899-12-30 UTC') + (int)floor((float)$value) * 86400);
    $value = str_replace('/', '-', $value);
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/D', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new InvalidArgumentException('日期格式不正确');
    return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
}

function bc_normalize_sheets($sheets, $date1904 = false)
{
    $records = []; $duplicates = 0;
    foreach ($sheets as $sheet => $rows) {
        if (!$rows) continue;
        $headers = array_map(function ($v) { return trim((string)$v); }, $rows[0]);
        $map = array_flip($headers);
        foreach (['域名','注册商','注册时间','过期时间','状态','用户名'] as $name) if (!isset($map[$name])) throw new InvalidArgumentException('工作表 ' . $sheet . ' 缺少列：' . $name);
        foreach (array_slice($rows, 1) as $offset => $row) {
            if (!array_filter($row, function ($v) { return trim((string)$v) !== ''; })) continue;
            $get = function ($name) use ($row, $map) { return trim((string)($row[$map[$name]] ?? '')); };
            try {
                $display=$get('域名');$canonical=$display;
                // Bizcn exports IDNs as punycode(中文域名), not as a bare domain.
                if(preg_match('/^([^\s()（）]+)\s*[(（]([^()（）]+)[)）]$/uD',$display,$idn))$canonical=$idn[1];
                $domain = bd_domain($canonical);
                $item = ['domainname'=>$domain, 'display_name'=>$display, 'provider_domain_id'=>isset($map['check']) ? $get('check') : '', 'registrar'=>$get('注册商'), 'owner_username'=>$get('用户名'), 'regdate'=>bc_date($get('注册时间'),true,$date1904), 'expireddate'=>bc_date($get('过期时间'),true,$date1904), 'status'=>$get('状态')];
                foreach (['display_name'=>500,'provider_domain_id'=>40,'registrar'=>100,'owner_username'=>100,'status'=>200] as $key=>$limit) if (mb_strlen($item[$key]) > $limit || preg_match('/[\x00-\x1f]/', $item[$key])) throw new InvalidArgumentException('字段过长或含无效字符');
                if ($item['regdate'] && $item['expireddate'] && $item['expireddate'] < $item['regdate']) throw new InvalidArgumentException('到期时间早于注册时间');
                if (isset($records[$domain])) {
                    if (array_intersect_key($records[$domain], $item) !== $item) throw new InvalidArgumentException('同一域名有冲突的重复资料');
                    $duplicates++; continue;
                }
                $item['source_row'] = $offset + 2;
                $records[$domain] = $item;
            } catch (InvalidArgumentException $e) { throw new InvalidArgumentException($sheet . ' 第 ' . ($offset + 2) . ' 行：' . $e->getMessage()); }
            if (count($records) > 50000) throw new InvalidArgumentException('单次最多导入 50000 个域名');
        }
    }
    if (!$records) throw new InvalidArgumentException('表格内没有有效域名');
    return ['rows'=>array_values($records), 'duplicates'=>$duplicates];
}

function bc_read_export($path)
{
    if (!is_file($path) || filesize($path) > 10 * 1024 * 1024) throw new InvalidArgumentException('请上传不超过 10MB 的 XLSX 域名导出表');
    $date1904=false;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive(); if ($zip->open($path) !== true) throw new InvalidArgumentException('无法打开 XLSX 表格');
        try {
            $size = 0;
            $date1904=preg_match('/\bdate1904\s*=\s*["\x27](?:1|true)["\x27]/i',(string)$zip->getFromName('xl/workbook.xml'))===1;
            if ($zip->numFiles > 500) throw new InvalidArgumentException('表格内部文件过多');
            for ($i=0; $i<$zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); $size += $entry['size'];
                if ($size > 80 * 1024 * 1024) throw new InvalidArgumentException('表格解压后过大');
                if (preg_match('/\.xml$/i', $entry['name'])) {
                    $xml = $zip->getFromIndex($i);
                    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) throw new InvalidArgumentException('不支持包含外部实体的表格');
                }
            }
        } finally { $zip->close(); }
    }
    require_once __DIR__.'/../classes/SimpleXLSX.php';
    return bc_normalize_sheets(SimpleXLSX::parseAll($path),$date1904);
}

function bc_find($domain)
{
    try {
        $q = db()->prepare('SELECT * FROM bizcn_domain_catalog WHERE provider_account=? AND domainname=?');
        $q->execute([bd_config()['username'], $domain]); return $q->fetch() ?: null;
    } catch (PDOException $e) { if ($e->getCode() === '42S02') return null; throw $e; }
}

function bc_list($search, $page)
{
    $account = bd_config()['username'];
    try {
        $q = db()->prepare('SELECT MAX(CASE WHEN import_id>0 THEN source_as_of END) snapshot,COUNT(*) c,SUM(import_id=0) registrations FROM bizcn_domain_catalog WHERE provider_account=?'); $q->execute([$account]); $meta=$q->fetch();
    } catch (PDOException $e) { if ($e->getCode() === '42S02') return null; throw $e; }
    if (!$meta['c']) return null;
    $values=[$account]; $where='provider_account=?';
    if ($search !== '') {
        $pattern='%' . str_replace(['!','%','_'], ['!!','!%','!_'], $search) . '%';
        $where .= " AND (CONVERT(domainname USING utf8mb4) LIKE ? ESCAPE '!' OR display_name LIKE ? ESCAPE '!' OR owner_username LIKE ? ESCAPE '!' OR registrar LIKE ? ESCAPE '!')";
        array_push($values,$pattern,$pattern,$pattern,$pattern);
    }
    $q=db()->prepare('SELECT COUNT(*) FROM bizcn_domain_catalog WHERE '.$where); $q->execute($values); $total=(int)$q->fetchColumn();
    $page=max(1,min((int)$page,max(1,(int)ceil($total/30))));
    // Put upcoming renewals before old expired inventory, across the entire result set.
    $q=db()->prepare('SELECT domainname,display_name,registrar,owner_username,regdate,expireddate,status,source_as_of FROM bizcn_domain_catalog WHERE '.$where.' ORDER BY CASE WHEN expireddate>=? THEN 0 WHEN expireddate IS NOT NULL THEN 1 ELSE 2 END,expireddate,domainname LIMIT 30 OFFSET '.(($page-1)*30)); $q->execute(array_merge($values,[date('Y-m-d')]));
    return ['rows'=>$q->fetchAll(), 'total'=>$total, 'catalog_total'=>(int)$meta['c'], 'page'=>$page, 'page_size'=>30, 'source'=>'export', 'updated_at'=>$meta['snapshot'], 'registered_added'=>(int)$meta['registrations']];
}

function bc_import($path, $sourceName, $asOf, $actor)
{
    if (($actor['type'] ?? '') !== 'admin' || pv_admin_username($actor['id']) !== 'admin') throw new RuntimeException('仅超级管理员可导入账户全量域名目录');
    $asOf=bc_date($asOf,false); $account=bd_config()['username']; $hash=hash_file('sha256',$path);
    $q=db()->prepare('SELECT * FROM bizcn_domain_imports WHERE provider_account=? AND file_sha256=?'); $q->execute([$account,$hash]);
    if ($old=$q->fetch()) return ['import_id'=>(int)$old['id'],'created'=>0,'updated'=>0,'unchanged'=>(int)$old['rows_total'],'already_imported'=>true];
    $parsed=bc_read_export($path);
    db()->beginTransaction();
    try {
        // Serialize imports for this provider account, including an empty inventory.
        $lock=db()->prepare('SELECT username FROM admins WHERE username=? FOR UPDATE');$lock->execute(['admin']);
        $q->execute([$account,$hash]); if ($old=$q->fetch()) {db()->rollBack();return ['import_id'=>(int)$old['id'],'created'=>0,'updated'=>0,'unchanged'=>(int)$old['rows_total'],'already_imported'=>true];}
        $latest=db()->prepare('SELECT MAX(source_as_of) FROM bizcn_domain_imports WHERE provider_account=?');$latest->execute([$account]);
        if ((string)$latest->fetchColumn() > $asOf) throw new RuntimeException('已有更新日期的域名目录，旧表不会覆盖新资料');
        $sourceName=mb_substr(preg_replace('~^.*[\\\\/]~u','',(string)$sourceName),0,255);
        db()->prepare('INSERT INTO bizcn_domain_imports (provider_account,file_sha256,source_name,source_as_of,rows_total,actor_admin_id) VALUES (?,?,?,?,?,?)')->execute([$account,$hash,$sourceName,$asOf,count($parsed['rows']),$actor['id']]);
        $id=(int)db()->lastInsertId();$created=0;$updated=0;$unchanged=0;
        $find=db()->prepare('SELECT * FROM bizcn_domain_catalog WHERE provider_account=? AND domainname=?');
        $save=db()->prepare('INSERT INTO bizcn_domain_catalog (provider_account,domainname,display_name,provider_domain_id,registrar,owner_username,regdate,expireddate,status,source_as_of,import_id,source_row) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),provider_domain_id=VALUES(provider_domain_id),registrar=VALUES(registrar),owner_username=VALUES(owner_username),regdate=COALESCE(VALUES(regdate),regdate),expireddate=COALESCE(VALUES(expireddate),expireddate),status=VALUES(status),source_as_of=VALUES(source_as_of),import_id=VALUES(import_id),source_row=VALUES(source_row)');
        $journal=db()->prepare('INSERT INTO bizcn_domain_import_items (import_id,domainname,before_json,after_json) VALUES (?,?,?,?)');
        foreach($parsed['rows'] as $row) {
            $find->execute([$account,$row['domainname']]);$before=$find->fetch();
            if(!$before)$created++;
            else {
                foreach(['regdate','expireddate'] as $field) if($row[$field]===null)$row[$field]=$before[$field];
                $same=true;foreach($row as $field=>$value)if($field!=='source_row' && (string)$before[$field] !== (string)$value)$same=false;
                if($same)$unchanged++;else $updated++;
            }
            $journal->execute([$id,$row['domainname'],$before?json_encode($before,JSON_UNESCAPED_UNICODE):null,json_encode($row,JSON_UNESCAPED_UNICODE)]);
            $save->execute([$account,$row['domainname'],$row['display_name'],$row['provider_domain_id'],$row['registrar'],$row['owner_username'],$row['regdate'],$row['expireddate'],$row['status'],$asOf,$id,$row['source_row']]);
        }
        db()->prepare('UPDATE bizcn_domain_imports SET created_count=?,updated_count=?,unchanged_count=? WHERE id=?')->execute([$created,$updated,$unchanged,$id]);
        ps_audit('domain_import',$id,'import_export',$actor,['source'=>$sourceName,'as_of'=>$asOf,'rows'=>count($parsed['rows']),'created'=>$created,'updated'=>$updated,'unchanged'=>$unchanged]);
        db()->commit();
        return ['import_id'=>$id,'created'=>$created,'updated'=>$updated,'unchanged'=>$unchanged,'duplicates'=>$parsed['duplicates'],'already_imported'=>false];
    } catch(Throwable $e) {if(db()->inTransaction())db()->rollBack();throw $e;}
}
