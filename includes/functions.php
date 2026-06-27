<?php
/**
 * 通用函数库
 */

/**
 * HTML转义输出
 */
function e($str)
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * 格式化金额
 */
function money($amount)
{
    return number_format((float)$amount, 2, '.', ',');
}

/**
 * JSON响应并退出
 */
function json_response($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取所有部门列表（名称数组）
 * 优先从 departments 表读取；表不存在时回退到 employees 去重
 */
function get_departments()
{
    try {
        $stmt = db()->query("SELECT name FROM departments ORDER BY sort ASC, id ASC");
        $list = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($list)) return $list;
    } catch (PDOException $e) {
        // 表不存在时回退
    }
    $stmt = db()->query("SELECT DISTINCT department FROM employees WHERE department != '' ORDER BY department");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * 获取所有部门记录（含id/sort）
 */
function get_department_list()
{
    try {
        $stmt = db()->query("SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department = d.name) AS emp_count FROM departments d ORDER BY d.sort ASC, d.id ASC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * 获取单个部门
 */
function get_department($id)
{
    $stmt = db()->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * 获取所有员工
 */
function get_employees($department = null)
{
    if ($department) {
        $stmt = db()->prepare("SELECT * FROM employees WHERE department = ? ORDER BY name");
        $stmt->execute([$department]);
    } else {
        $stmt = db()->query("SELECT * FROM employees ORDER BY department, name");
    }
    return $stmt->fetchAll();
}

/**
 * 获取单个员工
 */
function get_employee($id)
{
    $stmt = db()->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * 生成Excel CSV下载
 * @param array $headers 表头
 * @param array $rows 数据行
 * @param string $filename 文件名
 */
function export_csv($headers, $rows, $filename)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $fp = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    exit;
}

/**
 * 生成简单Excel(XML Spreadsheet)下载 - 支持中文
 */
function export_excel($headers, $rows, $filename)
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
    echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n";
    echo " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n";
    echo " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
    echo "<Worksheet ss:Name=\"Sheet1\">\n<Table>\n";

    // 表头
    echo "<Row>";
    foreach ($headers as $h) {
        echo "<Cell><Data ss:Type=\"String\">" . e($h) . "</Data></Cell>";
    }
    echo "</Row>\n";

    // 数据
    foreach ($rows as $row) {
        echo "<Row>";
        foreach ($row as $val) {
            $type = is_numeric($val) ? 'Number' : 'String';
            echo "<Cell><Data ss:Type=\"{$type}\">" . e($val) . "</Data></Cell>";
        }
        echo "</Row>\n";
    }

    echo "</Table>\n</Worksheet>\n</Workbook>\n";
    exit;
}
