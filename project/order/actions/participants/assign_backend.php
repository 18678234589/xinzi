<?php

            $mode = (string)($_POST['backend_mode'] ?? 'colleague');
            $backendId = (int)($_POST['backend_employee_id'] ?? ($_POST['frontend_employee_id'] ?? 0));
            ps_order_assign_backend($id, $backendId, $mode, $actor);
        