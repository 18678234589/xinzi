<?php
try {
    $ids = $_POST['home_ids'] ?? [];
    if (!is_array($ids)) throw new RuntimeException('名单格式错误');
    if (($_POST['roster_mode'] ?? '') === 'upload') {
        $file = $_FILES['home_file'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('请选择居家办公名单文件');
        if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('名单文件不能超过5MB');
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            $xlsx = SimpleXLSX::parse($file['tmp_name']);
            if (!$xlsx) throw new RuntimeException('Excel读取失败，请检查文件格式');
            $rows = $xlsx->rows();
        } elseif ($ext === 'csv') {
            $raw = file_get_contents($file['tmp_name']);
            if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'GBK');
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $raw);
            rewind($handle);
            $rows = [];
            while (($row = fgetcsv($handle)) !== false) $rows[] = $row;
            fclose($handle);
        } else throw new RuntimeException('名单仅支持 .xlsx / .csv');
        $ids = attendance_home_match($rows, get_employees());
    }
    attendance_home_save(sprintf('%04d-%02d', $year, $month), $ids, $_SESSION['admin_id']);
    $success = '居家办公名单已保存（' . count(array_unique($ids)) . '人）。全员全勤奖已按本月考勤自动核算，可直接查看收入概览。';
} catch (Throwable $e) {
    $error = $e->getMessage();
}
