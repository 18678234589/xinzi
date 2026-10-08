<?php
                    $target = &$preview[$seen[$orderKey]];
                    $target['lines'][] = $record['line'];
                    $target['items'] = array_merge($target['items'] ?? [], $record['items']);
                    if (empty($record['base_valid']) || empty($target['base_valid'])) {
                        $target['base_valid'] = false;
                        $target['status'] = '需处理';
                        $target['error'] = trim(($target['error'] ?? '') . '；同号第 ' . $record['line'] . ' 行：' . ($record['error'] ?: '所在订单有错误'), '；');
                    } else {
                        // 按店铺流水带入的售价是整单价：同号重复出现时不再累加
                        if ($record['contract_amount'] !== '' && empty($record['amount_from_shop'])) $target['contract_amount'] = number_format((float)$target['contract_amount'] + (float)$record['contract_amount'], 2, '.', '');
                        if (($record['direct_cost'] ?? '') !== '') $target['direct_cost'] = number_format((float)($target['direct_cost'] ?? 0) + (float)$record['direct_cost'], 2, '.', '');
                        if (is_numeric($record['ssl_used']) && (float)$record['ssl_used'] > 0) $target['ssl_used'] = number_format((float)(is_numeric($target['ssl_used']) ? $target['ssl_used'] : 0) + (float)$record['ssl_used'], 2, '.', '');
                        foreach (['technical', 'customer_service'] as $groupKey) foreach ($record['people'][$groupKey] as $personId => $person) $target['people'][$groupKey][$personId] = $target['people'][$groupKey][$personId] ?? $person;
                        foreach ($record['details'] as $key => $value) if ($value !== '' && ($target['details'][$key] ?? '') === '') $target['details'][$key] = $value;
                        $target['renewal_extras'] = array_values(array_unique(array_merge($target['renewal_extras'] ?? [], $record['renewal_extras'] ?? [])));
                        foreach (['payment_nickname', 'contact_note', 'resource_note', 'order_kind', 'program_name', 'shop'] as $field) if (($target[$field] ?? '') === '' && ($record[$field] ?? '') !== '') $target[$field] = $record[$field];
                        if (!$target['program_template_id'] && $record['program_template_id']) $target['program_template_id'] = $record['program_template_id'];
                        if ($record['domain_mode'] === 'template' && $target['domain_mode'] !== 'template') { $target['domain_mode'] = 'template'; $target['domain_template_id'] = $record['domain_template_id']; $target['status'] = $record['status']; }
                        if ($record['delivery_status'] === 'unfinished') $target['delivery_status'] = 'unfinished';
                        $target['warning'] = trim(($target['warning'] ?? '') . '；已合并同号第 ' . $record['line'] . ' 行（加购 / 补差价），售价合计 ¥' . $target['contract_amount'], '；');
                    }
                    unset($target);
