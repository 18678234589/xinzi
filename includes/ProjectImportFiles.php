<?php

/** 保存上传的原始表格（站点目录外），返回记录 id。 */
function ps_import_file_store($file, $business, $actor, $parsedFile = null)
{
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv', 'xls'], true)) throw new RuntimeException('文件仅支持 XLSX、XLS 或 CSV');
    if ($ext === 'xls' && (!$parsedFile || ($parsedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || strtolower(pathinfo((string)$parsedFile['name'], PATHINFO_EXTENSION)) !==
    'xlsx' || (int)($parsedFile['size'] ?? 0) > 25 * 1024 * 1024)) throw new RuntimeException('旧版 XLS 转换失败或文件过大，请使用新版浏览器重试或另存为 XLSX'
    );
    $stored = ps_private_store('imports', $file['tmp_name'], date('Ym') . '_' . bin2hex(random_bytes(12)) . '.' . $ext);
    $parsed = $ext === 'xls' ? ps_private_store('imports', $parsedFile['tmp_name'], date('Ym') . '_' . bin2hex(random_bytes(12)) . '.xlsx') : null;
    db()->prepare('INSERT INTO project_import_files (business_name,original_name,stored_name,parse_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES (?,?,?,?,?,?,?,?)'
    )
        ->execute([$business, ps_import_original_name($file['name']), $stored, $parsed, (int)$file['size'], $actor['type'], (int)$actor['id'], $actor['employee_id'] ?? null]);
    return (int)db()->lastInsertId();
}

/** 不依赖服务器 locale 的 basename()，保留中文文件名并去掉浏览器可能携带的 Windows 路径。 */
function ps_import_original_name($name)
{
    $name = str_replace('\\', '/', (string)$name);
    $position = strrpos($name, '/');
    return mb_substr($position === false ? $name : substr($name, $position + 1), 0, 255);
}

/** 读取记录并校验权限：财务看全部，合作人员只看本人上传的。 */
function ps_import_file_get($id, $actor)
{
    $q = db()->prepare('SELECT * FROM project_import_files WHERE id=?');
    $q->execute([(int)$id]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('原始表格不存在');
    if ($actor['role'] !== 'finance' && !ps_management_can_business($actor, $row['business_name']) && ((int)$row['employee_id'] !== (int)($actor['employee_id'] ?? 0) || $row['uploaded_by_type'] !== $actor['type'])) throw new RuntimeException(
    '只能查看本人上传的表格');
    $row['content'] = ps_private_read('imports', $row['stored_name']);
    if ($row['content'] === null) throw new RuntimeException('原始文件已不存在');
    if (!empty($row['parse_name'])) {
        $row['parse_content'] = ps_private_read('imports', $row['parse_name']);
        if ($row['parse_content'] === null) throw new RuntimeException('转换后的解析文件已不存在');
    }
    return $row;
}

/** 删除原始表格记录与私有文件：权限同查看（财务可删任何，合作人员只删本人上传的）；已导入的订单不受影响。 */
function ps_import_file_delete($id, $actor)
{
    $q = db()->prepare('SELECT * FROM project_import_files WHERE id=?');
    $q->execute([(int)$id]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('原始表格不存在');
    if ($actor['role'] !== 'finance' && ((int)$row['employee_id'] !== (int)($actor['employee_id'] ?? 0) || $row['uploaded_by_type'] !== $actor['type'])) throw new RuntimeException(
    '只能删除本人上传的表格');
    $pdo = db();
    $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_import_file_delete');
    else $pdo->beginTransaction();
    try {
        ps_audit('import_file', (int)$row['id'], 'delete', $actor, ['original_name' => $row['original_name'], 'business_name' => $row['business_name'], 'status' => $row['status'],
    'imported_count' => (int)$row['imported_count']]);
        $pdo->prepare('DELETE FROM project_import_files WHERE id=?')->execute([(int)$row['id']]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_import_file_delete');
        else $pdo->commit();
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_import_file_delete');
        else $pdo->rollBack();
        throw $e;
    }
    ps_private_delete('imports', $row['stored_name']);
    if (!empty($row['parse_name'])) ps_private_delete('imports', $row['parse_name']);
}

/** 解析全部工作表：['工作表名' => [[单元格...], ...]]；CSV 视为一张表。 */
function ps_import_file_sheets($row)
{
    // 解析需要真实文件路径（zip），临时复制到 /tmp，读完即删
    $row['path'] = tempnam(sys_get_temp_dir(), 'psx_');
    if (!empty($row['parse_name'])) $row['stored_name'] = $row['parse_name'];
    file_put_contents($row['path'], $row['parse_content'] ?? $row['content']);
    try { return ps_import_file_parse($row); } finally { @unlink($row['path']); }
}

function ps_import_file_parse($row)
{
    if (strtolower(pathinfo($row['stored_name'], PATHINFO_EXTENSION)) === 'csv') {
        $rows = [];
        $handle = fopen($row['path'], 'rb');
        while (($line = fgetcsv($handle)) !== false && count($rows) <= 5000) $rows[] = array_map(function ($v) { return mb_convert_encoding((string)$v, 'UTF-8', 'UTF-8,GBK,GB2312')
    ; }, $line);
        fclose($handle);
        if ($rows) $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($rows[0][0] ?? ''));
        return ['CSV' => $rows];
    }
    if (class_exists('ZipArchive') && class_exists('XMLReader')) return ps_xlsx_sheets($row['path']);
    if (!class_exists('SimpleXLSX')) require_once __DIR__ . '/../classes/SimpleXLSX.php';
    // 无 zip 扩展时退回 SimpleXLSX（旧版本无 parseAll 时只读第一个工作表）
    return method_exists('SimpleXLSX', 'parseAll') ? SimpleXLSX::parseAll($row['path']) : ['工作表1' => SimpleXLSX::parse($row['path'])];
}

/**
 * 流式读取 xlsx 全部工作表（XMLReader，逐行读取）：部门表常带上百万行“有格式的空行”（整张表拉满到 1048576 行），
 * 一次性载入会耗尽内存。只保留到最后一行有内容为止，保持 Excel 行号不变；每表最多 20000 行。
 * 返回 ['工作表名' => [[单元格字符串...], ...]]。
 */
function ps_xlsx_sheets($path, $maxRows = 20000)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('无法打开 xlsx 文件');
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook === false) { $zip->close(); throw new RuntimeException('xlsx 缺少工作簿信息'); }
    $targets = [];
    if ($rels !== false && preg_match_all('/<Relationship\s[^>]*>/', $rels, $m)) foreach ($m[0] as $tag) {
        if (preg_match('/\sId="([^"]+)"/', $tag, $id) && preg_match('/\sTarget="([^"]+)"/', $tag, $target)) {
            $t = html_entity_decode($target[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $targets[$id[1]] = $t[0] === '/' ? ltrim($t, '/') : (strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t);
        }
    }
    $sheets = [];
    preg_match_all('/<sheet\s[^>]*>/', $workbook, $m);
    foreach ($m[0] as $i => $tag) {
        $name = preg_match('/\sname="([^"]*)"/', $tag, $n) ? html_entity_decode($n[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '工作表' . ($i + 1);
        $rid = preg_match('/\sr:id="([^"]+)"/', $tag, $r) ? $r[1] : '';
        $sheets[$name] = $targets[$rid] ?? ('xl/worksheets/sheet' . ($i + 1) . '.xml');
    }
    $shared = [];
    if ($zip->locateName('xl/sharedStrings.xml') !== false) {
        $reader = new XMLReader();
        $reader->open('zip://' . $path . '#xl/sharedStrings.xml');
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $node = new SimpleXMLElement($reader->readOuterXml());
                $text = '';
                foreach ($node->xpath('.//*[local-name()="t"]') as $t) $text .= (string)$t;
                $shared[] = $text; // 不调用 next()：外层 read() 会继续向下，调用 next() 会跳过下一个兄弟节点
            }
        }
        $reader->close();
    }
    $zip->close();
    $out = [];
    foreach ($sheets as $name => $file) {
        $rowsByNumber = [];
        $reader = new XMLReader();
        if (!@$reader->open('zip://' . $path . '#' . $file)) { $out[$name] = []; continue; }
        $count = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
            if ($reader->isEmptyElement) continue; // 有格式的空行
            $rowNumber = (int)$reader->getAttribute('r');
            $node = new SimpleXMLElement($reader->readOuterXml());
            $cells = [];
            $position = 0;
            foreach ($node->c as $c) {
                $ref = (string)$c['r'];
                $col = $position;
                if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $letters)) { $col = 0; foreach (str_split($letters[1]) as $ch) $col = $col * 26 + (ord($ch) - 64); $col--; }
                $position = $col + 1;
                $type = (string)$c['t'];
                if ($type === 's') $value = $shared[(int)$c->v] ?? '';
                elseif ($type === 'inlineStr') { $value = ''; foreach ($c->xpath('.//*[local-name()="t"]') as $t) $value .= (string)$t; }
                elseif ($type === 'b') $value = (string)$c->v === '1' ? 'TRUE' : 'FALSE';
                else $value = isset($c->v) ? (string)$c->v : '';
                if ($value !== '') $cells[$col] = $value;
            }
            if (!$cells) continue;
            $max = max(array_keys($cells));
            $row = [];
            for ($k = 0; $k <= $max; $k++) $row[] = $cells[$k] ?? '';
            $rowsByNumber[$rowNumber ?: (count($rowsByNumber) + 1)] = $row;
            if (++$count >= $maxRows) break;
        }
        $reader->close();
        // 补齐中间的空行，保持与 Excel 行号一致（第 1 行为表头）
        $rows = [];
        if ($rowsByNumber) {
            ksort($rowsByNumber);
            $last = (int)max(array_keys($rowsByNumber));
            for ($n = (int)min(array_keys($rowsByNumber)); $n <= $last; $n++) $rows[] = $rowsByNumber[$n] ?? [];
        }
        $out[$name] = $rows;
    }
    return $out;
}

function ps_import_file_mark($id, $status, $fields)
{
    $sets = ['status=?'];
    $values = [$status];
    foreach (['sheets_used', 'rows_total', 'imported_count', 'skipped_count'] as $key) if (array_key_exists($key, $fields)) { $sets[] = $key . '=?'; $values[] = $fields[$key]; }
    if ($status === 'imported') $sets[] = 'imported_at=NOW()';
    $values[] = (int)$id;
    db()->prepare('UPDATE project_import_files SET ' . implode(',', $sets) . ' WHERE id=?')->execute($values);
}
