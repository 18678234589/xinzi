<?php

        $eid = (int)($_POST['employee_id'] ?? 0);
        if ($eid > 0 && include_cs_perf_member($eid)) {
            $upMsg = '已恢复该合作人员参与客服绩效';
        } else {
            $upErr = '恢复失败，请重试';
        }
    