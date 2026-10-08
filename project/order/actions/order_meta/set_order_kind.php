<?php

            $kind = ps_order_kind_valid(ps_business_normalize($order['project_type']), $_POST['order_kind'] ?? '');
            if (!$finance && trim((string)$order['order_kind']) !== '') throw new RuntimeException('订单类型已填写，如需更改请联系财务');
            if ($finance) ps_reclassify_order_kind($id, $kind, $actor, (string)($_POST['adjust_month'] ?? date('Y-m')), !empty($_POST['apply_future']));
            else {
                db()->prepare("UPDATE project_orders SET order_kind=?,row_version=row_version+1 WHERE id=? AND order_kind=''")->execute([$kind, $id]);
                ps_audit('order', $id, 'order_kind', $actor, ['order_kind' => $kind]);
            }
        