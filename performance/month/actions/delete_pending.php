<?php

        db()->prepare("DELETE FROM cs_perf_pending WHERE id=?")->execute([(int)($_POST['pending_id'] ?? 0)]);
        cs_perf_cache_reset();
        $msg = '已删除该待匹配记录';
    