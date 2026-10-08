<?php

            if (!$finance) throw new RuntimeException('无权限');
            ps_approve_order($id, $actor, (string)($_POST['payroll_month'] ?? ''));
        