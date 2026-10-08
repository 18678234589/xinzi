<?php
            if ($order_scope === 'personal' && $employee_id <= 0) {
                $error = '请先选择合作人员';
            } elseif ($order_scope === 'department' && $dept_name === '网站售后部') {
                $error = '网站售后部门订单请从“项目订单 → 网站售后部门订单”上传，避免旧账与新版项目分成重复';
            } elseif ($order_scope === 'department' && $dept_name === '') {
                $error = '请选择部门';
            } elseif ($upload_month === '') {
                $error = '请选择订单归属月份';
            } else {/* split: orders/index/actions/upload/parse_rows.php */ include __DIR__ . '/../../parse_rows.php';}
        