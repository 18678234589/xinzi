<?php

            if (!$finance && $actor['role'] !== 'technical') throw new RuntimeException('只有技术或财务可以确认资源');
            $mode = (string)($_POST['domain_mode'] ?? '');
            $confirmed = ps_intake_confirm_resources($id, $mode, $_POST['domain_template_id'] ?? 0, $_POST['server_template_id'] ?? 0, $actor, $_POST['program_template_id'] ?? 0);
            ps_audit('order', $id, 'confirm_resources', $actor, ['domain_mode' => $mode] + $confirmed);
        