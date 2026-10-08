<?php

            if ($order['delivery_status'] === 'finished') throw new RuntimeException('订单已是完成状态');
            $proof = ps_upload_proof('delivery_proof');
            $note = trim((string)($_POST['delivery_note'] ?? ''));
            ps_create_order_request($id, 'delivery_completion', $actor, [
                'proof_path' => $proof,
                'delivery_note' => $note
            ]);
        