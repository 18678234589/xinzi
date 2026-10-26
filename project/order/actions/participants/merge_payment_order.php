<?php

            // 财务：把上传时被拍成两单的另一张订单并入本单（它的订单号变成本单的关联付款订单号）。
            $mergeResult = pop_merge($id, (string)($_POST['other_order_no'] ?? ''), !empty($_POST['add_amount']), $actor);
