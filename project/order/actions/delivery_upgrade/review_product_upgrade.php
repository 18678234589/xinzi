<?php

            if (!$finance) throw new RuntimeException('无权限审核产品升级');
            $requestId = (int)($_POST['request_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            $diffAmount = trim((string)($_POST['diff_amount'] ?? '0'));
            $note = trim((string)($_POST['review_note'] ?? ''));
            $proof = (!empty($_FILES['diff_proof']['name']) && $_FILES['diff_proof']['error'] === UPLOAD_ERR_OK) ? ps_upload_proof('diff_proof') : null;
            ps_review_order_request($requestId, $decision, $actor, $note, [
                'diff_amount' => $diffAmount,
                'proof_path' => $proof
            ]);
        