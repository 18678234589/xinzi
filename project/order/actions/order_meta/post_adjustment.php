<?php

            if (!$finance) throw new RuntimeException('无权限');
            $refundText = trim((string)($_POST['refund_amount'] ?? ''));
            $costText = trim((string)($_POST['cost_delta'] ?? ''));
            if (($refundText !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $refundText)) || ($costText !== '' && !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $costText))) throw new RuntimeException
    ('金额最多两位小数；成本调整可为负数（冲减）');
            $created = ps_post_adjustment($id, $actor, $refundText === '' ? 0 : $refundText, $costText === '' ? 0 : $costText, (string)($_POST['reason'] ?? ''), (string)($_POST['adjust_month'
    ] ?? ''));
            header('Location: ' . BASE_URL . '/project/order.php?id=' . $id . '&adjusted=' . count($created)); exit;
        