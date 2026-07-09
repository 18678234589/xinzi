<?php
/**
 * 简易XLSX解析器（无需Composer依赖）
 * 利用 ZipArchive + SimpleXML 解析 .xlsx 文件
 *
 * 支持多工作表：
 *   SimpleXLSX::parse($filePath)            解析第一个工作表（向后兼容）
 *   SimpleXLSX::parse($filePath, $idx)      解析指定索引的工作表
 *   SimpleXLSX::sheetNames($filePath)       返回工作表名称列表
 *   SimpleXLSX::parseAll($filePath)         返回 ['工作表名' => 行数据, ...]
 */
class SimpleXLSX
{
    private $zip;
    private $sharedStrings = [];
    private $sheetNames = [];   // 顺序的工作表名称
    private $sheetFiles = [];   // 顺序的工作表文件路径（xl/worksheets/sheetN.xml）

    /**
     * 从文件路径解析（默认第一个工作表）
     * @param string $filePath
     * @param int    $sheetIndex 工作表索引（0基），默认0
     * @return array 行数据
     */
    public static function parse($filePath, $sheetIndex = 0)
    {
        $instance = new self();
        if (!class_exists('ZipArchive')) {
            throw new Exception('服务器未安装ZipArchive扩展，无法解析xlsx文件');
        }
        $instance->zip = new ZipArchive();
        if ($instance->zip->open($filePath) !== true) {
            throw new Exception('无法打开xlsx文件');
        }
        $instance->loadSharedStrings();
        $instance->loadWorkbook();
        $rows = $instance->readSheet($sheetIndex);
        $instance->zip->close();
        return $rows;
    }

    /**
     * 返回所有工作表名称（顺序与文件一致）
     */
    public static function sheetNames($filePath)
    {
        $instance = new self();
        if (!class_exists('ZipArchive')) {
            throw new Exception('服务器未安装ZipArchive扩展，无法解析xlsx文件');
        }
        $instance->zip = new ZipArchive();
        if ($instance->zip->open($filePath) !== true) {
            throw new Exception('无法打开xlsx文件');
        }
        $instance->loadSharedStrings();
        $instance->loadWorkbook();
        $names = $instance->sheetNames;
        $instance->zip->close();
        return $names;
    }

    /**
     * 解析所有工作表
     * @return array ['工作表名' => 行数据, ...]
     */
    public static function parseAll($filePath)
    {
        $instance = new self();
        if (!class_exists('ZipArchive')) {
            throw new Exception('服务器未安装ZipArchive扩展，无法解析xlsx文件');
        }
        $instance->zip = new ZipArchive();
        if ($instance->zip->open($filePath) !== true) {
            throw new Exception('无法打开xlsx文件');
        }
        $instance->loadSharedStrings();
        $instance->loadWorkbook();
        $result = [];
        foreach ($instance->sheetNames as $idx => $name) {
            $result[$name] = $instance->readSheet($idx);
        }
        $instance->zip->close();
        return $result;
    }

    /**
     * 加载共享字符串表
     */
    private function loadSharedStrings()
    {
        $data = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($data === false) {
            return; // 没有共享字符串
        }
        $xml = simplexml_load_string($data);
        if ($xml === false) return;
        foreach ($xml->si as $si) {
            // 兼容含格式和不含格式的情况
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } else {
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
            }
            $this->sharedStrings[] = $text;
        }
    }

    /**
     * 读取工作表清单（xl/workbook.xml + rels）
     * 将工作表名称与 worksheets/sheetN.xml 文件一一对应
     */
    private function loadWorkbook()
    {
        $wbData = $this->zip->getFromName('xl/workbook.xml');
        if ($wbData === false) {
            // 没有 workbook.xml，退化为扫描 sheet1..sheet10
            for ($i = 1; $i <= 10; $i++) {
                $f = "xl/worksheets/sheet{$i}.xml";
                if ($this->zip->getFromName($f) !== false) {
                    $this->sheetNames[] = "Sheet{$i}";
                    $this->sheetFiles[] = $f;
                }
            }
            return;
        }

        // 解析 rels：r:id -> worksheets/sheetN.xml
        $relMap = [];
        $relsData = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsData !== false) {
            $rx = simplexml_load_string($relsData);
            if ($rx !== false) {
                foreach ($rx->Relationship as $rel) {
                    $rid = (string)$rel['Id'];
                    $target = (string)$rel['Target'];
                    // Target 相对于 xl/ 目录；处理绝对路径写法
                    if ($target !== '' && $target[0] === '/') {
                        $target = ltrim($target, '/');
                    } else {
                        $target = 'xl/' . $target;
                    }
                    $relMap[$rid] = $target;
                }
            }
        }

        $wb = simplexml_load_string($wbData);
        if ($wb === false) {
            // 解析失败，退化为扫描
            for ($i = 1; $i <= 10; $i++) {
                $f = "xl/worksheets/sheet{$i}.xml";
                if ($this->zip->getFromName($f) !== false) {
                    $this->sheetNames[] = "Sheet{$i}";
                    $this->sheetFiles[] = $f;
                }
            }
            return;
        }

        $nsR = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $idx = 0;
        foreach ($wb->sheets->sheet as $s) {
            $name = (string)$s['name'];
            $rid = '';
            $attrs = $s->attributes($nsR);
            if ($attrs !== null && isset($attrs['id'])) {
                $rid = (string)$attrs['id'];
            }
            $file = $relMap[$rid] ?? null;
            if ($file === null) {
                // rels 没找到，按顺序兜底
                $file = "xl/worksheets/sheet" . ($idx + 1) . ".xml";
            }
            $this->sheetNames[] = $name;
            $this->sheetFiles[] = $file;
            $idx++;
        }

        // 兜底：若一个都没收集到，扫描 sheet1..sheet10
        if (empty($this->sheetFiles)) {
            for ($i = 1; $i <= 10; $i++) {
                $f = "xl/worksheets/sheet{$i}.xml";
                if ($this->zip->getFromName($f) !== false) {
                    $this->sheetNames[] = "Sheet{$i}";
                    $this->sheetFiles[] = $f;
                }
            }
        }
    }

    /**
     * 读取指定索引的工作表
     */
    private function readSheet($sheetIndex = 0)
    {
        $file = $this->sheetFiles[$sheetIndex] ?? 'xl/worksheets/sheet1.xml';
        $data = $this->zip->getFromName($file);
        if ($data === false) {
            // 兜底尝试 sheet1..sheet10
            for ($i = 1; $i <= 10; $i++) {
                $data = $this->zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if ($data !== false) break;
            }
        }
        if ($data === false) {
            throw new Exception('xlsx中未找到工作表数据');
        }

        $xml = simplexml_load_string($data);
        if ($xml === false) {
            throw new Exception('无法解析工作表XML');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            $maxCol = 0;
            foreach ($row->c as $cell) {
                $ref = (string)$cell['r']; // 如 A1, B1
                $colIndex = $this->colToIndex($ref);
                $cellValue = '';
                $type = (string)$cell['t'];

                if (isset($cell->v)) {
                    $val = (string)$cell->v;
                    if ($type === 's') {
                        // 共享字符串
                        $cellValue = $this->sharedStrings[(int)$val] ?? '';
                    } elseif ($type === 'str') {
                        $cellValue = $val;
                    } else {
                        $cellValue = $val;
                    }
                } elseif (isset($cell->is)) {
                    // 内联字符串
                    if (isset($cell->is->t)) {
                        $cellValue = (string)$cell->is->t;
                    }
                }

                $rowData[$colIndex] = $cellValue;
                if ($colIndex > $maxCol) $maxCol = $colIndex;
            }
            // 填充空列
            $filled = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $filled[] = $rowData[$i] ?? '';
            }
            $rows[] = $filled;
        }

        return $rows;
    }

    /**
     * 列引用(如A1, AB3)转为0基准列号
     */
    private function colToIndex($ref)
    {
        $col = 0;
        $ref = preg_replace('/[0-9]/', '', $ref);
        $len = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $col = $col * 26 + (ord($ref[$i]) - ord('A') + 1);
        }
        return $col - 1;
    }
}
