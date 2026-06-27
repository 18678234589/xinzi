<?php
/**
 * 简易XLSX解析器（无需Composer依赖）
 * 利用 ZipArchive + SimpleXML 解析 .xlsx 文件
 */
class SimpleXLSX
{
    private $zip;
    private $sharedStrings = [];

    /**
     * 从文件路径解析
     */
    public static function parse($filePath)
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
        return $instance->readSheet();
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
     * 读取第一个工作表
     */
    private function readSheet()
    {
        $data = $this->zip->getFromName('xl/worksheets/sheet1.xml');
        if ($data === false) {
            // 尝试其他可能的文件名
            for ($i = 2; $i <= 10; $i++) {
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

        $this->zip->close();
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
