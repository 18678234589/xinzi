<?php

            $order_scope = in_array($_POST['order_scope'] ?? '', ['personal','department']) ? $_POST['order_scope'] : 'personal';
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $dept_name   = trim($_POST['dept_name'] ?? '');
            $upload_month = trim($_POST['upload_month'] ?? ''); // 新增：上传时指定的归属月份

            // 部门订单：employee_id 可以为0，但需要有部门名
