<?php

        $eid = (int)($_POST['employee_id'] ?? 0);
        if ($eid > 0 && exclude_cs_perf_member($eid)) {
            $upMsg = '已排除该合作人员，之后不再计入客服绩效';
        } else {
            $upErr = '排除失败，请重试';
        }
    