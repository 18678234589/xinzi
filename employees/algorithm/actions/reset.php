<?php

        if (SalaryCalculator::deleteCustomConfig($employee_id)) {
            $success = '已恢复默认算法（固定服务费 + 默认项目分成比例）';
        } else { $error = '恢复失败'; }
    