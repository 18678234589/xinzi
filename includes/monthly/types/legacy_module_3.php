<?php

            $orders = ps_legacy_sheet_orders($month, $eid, (string)($emp['department'] ?? ''), trim((string)$emp['name']));
            $orderTotal = 0.0;
            foreach ($orders as $o) $orderTotal += (float)($o['order_amount'] ?? 0);
            $res = SalaryCalculator::runModuleFor((string)$module['type'], $module['config'] ?? [], $emp, $orders, $orderTotal, $month, (string)($module['name'] ?? $want));