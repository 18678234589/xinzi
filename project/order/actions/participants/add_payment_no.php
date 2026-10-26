<?php

            // 同一笔销售拍成多个订单号：把另一个订单号记为本单的关联付款订单号（单量仍算 1 单）。参与人和财务可操作。
            $payNo = trim((string)($_POST['payment_no'] ?? ''));
            $payAmount = trim((string)($_POST['payment_amount'] ?? ''));
            if ($payAmount !== '' && !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $payAmount)) throw new RuntimeException('该订单号的金额请填数字（可留空）');
            pop_add($id, $payNo, $payAmount === '' ? null : $payAmount, $actor, '订单页添加');
