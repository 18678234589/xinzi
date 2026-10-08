<?php

        $src = trim($_POST['source_file'] ?? '');
        if ($src !== '') {
            $q = db()->quote($src);
            db()->exec("DELETE FROM customer_service_performance WHERE source_file=$q");
            db()->exec("DELETE FROM cs_perf_pending WHERE source_file=$q");
            db()->exec("DELETE FROM cs_perf_sync_log WHERE source_file=$q");
            cs_perf_cache_reset();
            $upMsg = '已删除该次上传的数据（匹配数据、待匹配记录及导入记录）';
        } else {
            $upErr = '删除失败：未指定来源文件';
        }
    