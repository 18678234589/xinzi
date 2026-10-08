<?php

            if (!$finance) throw new RuntimeException('无权限审核交付申请');
            $requestId = (int)($_POST['request_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            $note = trim((string)($_POST['review_note'] ?? ''));
            ps_review_order_request($requestId, $decision, $actor, $note);
        