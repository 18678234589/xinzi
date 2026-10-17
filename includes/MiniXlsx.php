<?php
/**
 * 最小 xlsx 写入：多工作表、表头加粗、数字按金额格式、字符串一律按文本存（银行卡号不会变成科学计数法）。
 * $sheets = ['表名' => ['rows' => [[单元格…], …], 'widths' => [列宽…], 'header' => 1]]；单元格是 int / float 记数字，其余记文本，
 * 想强制按文本写数字（如卡号）用 ['text' => '6222…']。需要 zip 扩展。返回 xlsx 二进制字符串。
 */
function mxl_col($i)
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function mxl_xml($v)
{
    return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function mxl_build(array $sheets)
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('服务器缺少 zip 扩展，无法生成 xlsx');
    $tmp = tempnam(sys_get_temp_dir(), 'mxl');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    $n = count($sheets);
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $n; $i++) $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $zip->addFromString('[Content_Types].xml', $types . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $i = 0;
    foreach ($sheets as $name => $sheet) {
        $i++;
        $wb .= '<sheet name="' . mxl_xml(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', '', (string)$name), 0, 31)) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if (!empty($sheet['widths'])) { $x .= '<cols>'; foreach ($sheet['widths'] as $c => $w) $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (float)$w . '" customWidth="1"/>'; $x .= '</cols>'; }
        $x .= '<sheetData>';
        foreach ($sheet['rows'] as $r => $row) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $cell) {
                $ref = mxl_col($c) . ($r + 1);
                $isHead = $r < (int)($sheet['header'] ?? 0);
                if (is_array($cell) && isset($cell['text'])) $x .= '<c r="' . $ref . '" t="inlineStr"' . ($isHead ? ' s="1"' : ' s="3"') . '><is><t xml:space="preserve">' . mxl_xml($cell['text']) . '</t></is></c>';
                elseif ((is_int($cell) || is_float($cell)) && !$isHead) $x .= '<c r="' . $ref . '" s="2"><v>' . $cell . '</v></c>';
                elseif ($cell === null || $cell === '') $x .= '<c r="' . $ref . '"/>';
                else $x .= '<c r="' . $ref . '" t="inlineStr"' . ($isHead ? ' s="1"' : '') . '><is><t xml:space="preserve">' . mxl_xml($cell) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $x . '</sheetData></worksheet>');
    }
    $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels . '</Relationships>');
    // 0 默认；1 表头加粗 + 浅绿底；2 金额两位小数；3 文本
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Microsoft YaHei"/></font><font><b/><sz val="11"/><name val="Microsoft YaHei"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF3E6"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
