<?php

        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $upY = (int)($_POST['upload_year'] ?? 0);
            $upM = (int)($_POST['upload_month'] ?? 0);
            if (!($upY >= 2000 && $upM >= 1 && $upM <= 12)) { $upY = (int)date('Y', strtotime('-1 month')); $upM = (int)date('n', strtotime('-1 month')); }
            $store = trim((string)($_POST['store'] ?? ''));
            $r = import_cs_perf_file($_FILES['file']['tmp_name'], 'admin:' . basename($_FILES['file']['name']), $upY, $upM, $store);
            $upMsg = sprintf('导入完成（归入 %d-%02d 月%s）：匹配 %d 人，未匹配 %d 条，错误 %d 条', $upY, $upM, $store !== '' ? '，「店铺」=' . htmlspecialchars($store, ENT_QUOTES, 'UTF-8') : '', $r['matched'], $r['pending'], $r['errors']);
        } else {
            $upErr = '请选择要导入的绩效表文件（XLSX / CSV / TXT）';
        }
    