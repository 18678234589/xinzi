<?php

            require_once (dirname(__DIR__, 2)) . '/ProjectReviewPolicy.php';
            $allowNoReceipt = prp_allow_no_receipt('monthly', $rule);
            $orders = ps_legacy_sheet_orders($month, $eid, (string)($p['dept'] ?? ''), trim((string)($p['backend'] ?? '')), $allowNoReceipt);
            $getCol = function ($rd, $colName) {
                if (isset($rd[$colName])) return trim($rd[$colName]);
                foreach ($rd as $k => $v) {
                    if (mb_strpos($k, $colName) !== false) return trim($v);
                }
                return '';
            };
            $gateColumn = trim((string)($p['gate_column'] ?? '')) ?: '接单客服';
            $ownsTable = false;
            foreach ($orders as $o) {
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                $kefu = $getCol($rd, $gateColumn);
                if ($kefu === '') continue;
                $names = array_map('trim', explode(',', $kefu));
                if (in_array($employeeName, $names, true)) { $ownsTable = true; break; }
            }