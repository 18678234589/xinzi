<?php
                    $record['project_type'] = ps_import_website_business($selectedBusiness, $lookup($row, 'program_name'), $lookup($row, 'frontend') . '/' . $lookup($row, 'backend'
    ), $allowedBusinesses);
                    if ($record['project_type'] !== $selectedBusiness) $record['warning'] .= ($record['warning'] ? '；' : '') . '按产品/技术岗位自动归入“' . $record['project_type'
    ] . '”分成方案';
                    $record['order_no'] = ps_order_no_resolve($lookup($row, 'order_no'));
                    // 对公收款：订单号格子写“对公”，无需店铺订单号，只认对公交易号（可写在订单号格“对公 xxxx”、交易号列，或在预览页补填）
                    $publicReference = '';
                    if (ps_import_public_transfer($lookup($row, 'order_no'), $publicReference)) {
                        $record['order_no'] = '';
                        $record['public_transfer'] = true;
                    }
                    if ($record['order_no'] !== $lookup($row, 'order_no') && $lookup($row, 'order_no') !== '') $record['warning'] .= ($record['warning'] ? '；' : '') . '订单号“'
    . mb_substr($lookup($row, 'order_no'), 0, 40) . '”按“' . $record['order_no'] . '”识别（已去掉标签 / 备注，同号不会重复建单）';
                    $siteBaseType = null;
                    if ($record['order_no'] !== '' && psp_is_website($record['project_type'])) {
                        $siteTypeQuery = db()->prepare('SELECT project_type FROM project_orders WHERE order_no=? LIMIT 1');
                        $siteTypeQuery->execute([$record['order_no']]);
                        $siteBaseType = $siteTypeQuery->fetchColumn();
                    }
                    // 同付款号的附加项行（SSL / 安全证书 / 补差价等）并回原单，不算另一个网站，也不需要网站项目标识
                    $isSiteAddon = $record['order_no'] !== '' && psp_is_website($record['project_type']) && psp_is_addon_program($lookup($row, 'program_name')) && trim((string)($fixSiteKeys[$record['line']] ?? '')) === '' && $lookup($row, 'site_project_key') === '' && $lookup($row, 'detail:website_url') === '';
                    if ($isSiteAddon) $record['warning'] .= ($record['warning'] ? '；' : '') . '附加项“' . ($lookup($row, 'program_name') ?: '未写程序名称') . '”并入同号订单（不另算网站）';
                    if (!$isSiteAddon && $record['order_no'] !== '' && psp_is_website($record['project_type']) && (!$siteBaseType || psp_is_website($siteBaseType))) {
                        $siteBase = $record['order_no'];
                        $record['site_external_no'] = $siteBase;
                        $siteSeq[$siteBase] = ($siteSeq[$siteBase] ?? 0) + 1;
                        $rawSiteKey = trim((string)($fixSiteKeys[$record['line']] ?? ''));
                        if ($rawSiteKey === '') $rawSiteKey = $lookup($row, 'site_project_key') ?: $lookup($row, 'detail:website_url');
                        if ($rawSiteKey !== '') $siteExplicit[$siteBase] = true;
                        elseif ($siteSeq[$siteBase] > 1 && empty($siteExplicit[$siteBase])) {
                            // 同一付款号有多个网站、表格没有域名 / 网站项目标识：按表格顺序自动标识（第 1 个网站同时补上标识），财务核对付款分配
                            $rawSiteKey = 'auto-' . $siteSeq[$siteBase];
                            $record['site_key_auto'] = true;
                            $firstIndex = $seen[$siteBase] ?? null;
                            if ($firstIndex !== null && isset($preview[$firstIndex]) && empty($preview[$firstIndex]['site_key']) && !empty($preview[$firstIndex]['site_external_no'])) { $preview[$firstIndex]['site_key'] = 'auto-1'; $preview[$firstIndex]['site_key_auto'] = true; $siteSeenKeys[$siteBase]['auto-1'] = true; }
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '同一付款号有多个网站但表里没有域名 / 网站项目标识：已按表格顺序标为第 ' . $siteSeq[$siteBase] . ' 个网站';
                        }
                        $record['site_key'] = $rawSiteKey !== '' ? psp_key($rawSiteKey) : '';
                        if ($record['site_key'] !== '') {
                            if (isset($siteSeenKeys[$siteBase][$record['site_key']])) {
                                $record['order_no'] = $siteBase . '~duplicate-' . $record['line'];
                                $record['site_duplicate'] = true;
                            } else {
                                $siteSeenKeys[$siteBase][$record['site_key']] = true;
                                $registered = psp_lookup($siteBase, $record['site_key']);
                                if ($registered) {
                                    $record['order_no'] = $registered['order_no'];
                                    if ((int)$registered['root_order_id'] !== (int)$registered['order_id']) $record['multi_site_parent_no'] = $siteBase;
                                } else {
                                    $baseCheck = db()->prepare('SELECT id,project_type FROM project_orders WHERE order_no=?');
                                    $baseCheck->execute([$siteBase]);
                                    $baseOrder = $baseCheck->fetch();
                                    if ($baseOrder) {
                                        $baseSite = psp_order((int)$baseOrder['id']);
                                        if (!$baseSite || (int)$baseSite['root_order_id'] !== (int)$baseOrder['id'] || !psp_is_website($baseOrder['project_type'])) throw new RuntimeException
    ('原订单尚未绑定网站项目标识，请财务先在原订单页登记第一个网站');
                                        $record['order_no'] = psp_child_no($siteBase, $record['site_key']);
                                        $record['multi_site_parent_no'] = $siteBase;
                                    } elseif ($siteSeq[$siteBase] > 1) {
                                        $record['order_no'] = psp_child_no($siteBase, $record['site_key']);
                                        $record['multi_site_parent_no'] = $siteBase;
                                    }
                                }
                            }
                        } elseif ($siteSeq[$siteBase] > 1) {
                            $record['order_no'] = $siteBase . '~pending-' . $record['line'];
                        }
                        if ($siteSeq[$siteBase] > 1) $record['warning'] .= ($record['warning'] ? '；' : '') . '同一付款号有多个网站，须逐个标识项目并由财务核对总付款分配'
    ;
                    }
                    $record['payment_reference'] = trim((string)($fixPaymentReferences[$record['line']] ?? $lookup($row, 'payment_reference')));
                    if ($record['payment_reference'] === '' && $publicReference !== '') $record['payment_reference'] = $publicReference;
                    if (!empty($record['public_transfer']) && $record['order_no'] === '' && trim((string)($fixOrderNos[$record['line']] ?? '')) === '') {
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '对公收款：无需订单号，只需对公交易号' . ($record['payment_reference'] !== '' ? '，已按对公交易号生成内部关联号' : '，请在本行填写对公交易号');
                    }
                    if (mb_strlen($record['payment_reference']) > 200) throw new RuntimeException('微信交易流水号或支付订单号过长');
                    if ($record['order_no'] === '' && trim((string)($fixOrderNos[$record['line']] ?? '')) !== '') {
                        $record['order_no'] = trim((string)$fixOrderNos[$record['line']]);
                        $record['warning'] = '订单号由上传人在预览中补填，请核对原始交易记录';
                    }
                    if ($record['order_no'] === '' && $record['payment_reference'] !== '') {
                        $record['order_no'] = ps_payment_reference_order_no($selectedBusiness, $record['payment_reference']);
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '无店铺订单号，已按微信交易流水号生成内部关联号';
                    }
                    // 森动备案二次备案（备案售后、迁移、改信息等杂项）：没有订单号 / 流水号 / 售价，只有域名和联系微信号。
                    // 每一行是一次独立工作，各算一单：按“域名＋联系微信号＋月份＋同键序号”生成稳定的内部号（EB-…），重传不重复建单；与本月首次备案无关。
                    if ($selectedBusiness === '森动备案' && $record['order_no'] === '' && $record['payment_reference'] === '' && $lookup($row, 'detail:domain_name') !== '') {
                        $ebDate = ps_import_date($lookup($row, 'order_date'));
                        $ebKey = mb_strtolower(trim($lookup($row, 'detail:domain_name')) . '|' . trim($lookup($row, 'detail:contact_wechat')) . '|' . trim($lookup($row, 'contact_note')) . '|' . substr((string)$ebDate, 0, 7));
                        $wxSeq['eb|' . $ebKey] = ($wxSeq['eb|' . $ebKey] ?? 0) + 1;
                        $record['order_no'] = 'EB-' . strtoupper(substr(hash('sha256', '森动备案|' . $ebKey . '|' . $wxSeq['eb|' . $ebKey]), 0, 12));
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '没有订单号：已按“域名＋联系微信号＋月份”生成内部订单号' . ($wxSeq['eb|' . $ebKey] > 1 ? '（同月同域名第 ' . $wxSeq['eb|' . $ebKey] . ' 次，按独立工作各记一单）' : '');
                    }
                    // 微信付款等没有订单号的订单：只要有日期、金额和一个识别信息（付款昵称 / 联系方式 / 客服），就按这些内容生成稳定的内部订单号（WX-…），
                    // 同一张表重复上传得到同一个号，不会重复建单；拿到真实订单号后可在订单页补录。
                    if ($record['order_no'] === '' && $record['payment_reference'] === '') {
                        $wxAmount = trim((string)$lookup($row, 'contract_amount')); $wxDate = trim((string)$lookup($row, 'order_date'));
                        $wxNick = trim((string)$lookup($row, 'payment_nickname')); $wxContact = trim((string)$lookup($row, 'contact_note')); $wxCs = trim((string)$lookup($row, 'customer_service'
    ));
                        if ($wxAmount !== '' && $wxDate !== '' && ($wxNick !== '' || $wxContact !== '' || $wxCs !== '')) {
                            $wxKey = mb_strtolower($selectedBusiness . '|' . $wxDate . '|' . trim((string)$lookup($row, 'shop')) . '|' . $wxNick . '|' . $wxAmount . '|' . $wxCs);
                            $wxSeq[$wxKey] = ($wxSeq[$wxKey] ?? 0) + 1;
                            $record['order_no'] = 'WX-' . strtoupper(substr(hash('sha256', 'noorder|' . $wxKey . '|' . $wxSeq[$wxKey]), 0, 24));
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '没有订单号（微信付款等）：已按“日期＋店铺＋付款昵称＋金额”生成内部订单号，拿到真实订单号后可在订单页补录'
    ;
                        }
                    }
                    $exists->execute([$record['order_no']]);
                    $existing = $exists->fetch();
                    $record['existing_order_id'] = $existing ? (int)$existing['id'] : 0;
                    $record['resource_locked'] = false;
                    // 同一订单号客户一次付款、由多位商标客服分别录入：后录入的客服另记自己那份（同业务分单）
                    $coCustomerService = $existing && $selectedBusiness === '商标' && $actor['role'] === 'customer_service' && !$departmentMode && !ps_is_management($actor)
                        && ps_business_normalize($existing['project_type']) === $record['project_type']
                        && !ps_import_order_visible((int)$existing['id'], $actor) && ps_import_group_taken((int)$existing['id'], 'customer_service');
                    if ($existing && (ps_business_normalize($existing['project_type']) !== $record['project_type'] || $coCustomerService)) {
                        if (!$coCustomerService && ps_import_order_visible((int)$existing['id'], $actor)) { $record['skip_status'] = '已导入过'; throw new RuntimeException('此单已在项目订单中，保留原业务“'
    . $existing['project_type'] . '”和分成规则，不重复建单；改类目请由财务核对'); }
                        if (pos_parent_of((int)$existing['id']) || strpos($record['order_no'], 'WX-') === 0) throw new RuntimeException('该订单号已属于其他业务，请联系财务核对'
    );
                        // 同一笔销售只记一次：另一业务已按相同金额录过（如软文代写与微信代写同一笔），本人加入原订单，不再另建一张重复分单
                        $joinSameSale = !$coCustomerService && !$departmentMode && $actor['role'] !== 'finance' && !ps_is_management($actor)
                            && poj_same_sale_allowed($existing['project_type'], $record['project_type'], poj_amount_value($lookup($row, 'contract_amount')), $record['order_no'], $existing['contract_amount']);
                        if ($joinSameSale) { // 本人此前已按旧规则建过这笔订单的分单子单：继续沿用，不再同时加入原单造成重复
                            $exists->execute([pos_child_order_no($record['order_no'], $record['project_type'], 0)]);
                            $joinSameSale = !$exists->fetch();
                        }
                        if ($joinSameSale) {
                            $record['join_parent'] = ['id' => (int)$existing['id'], 'project_type' => $existing['project_type']];
                            $record['join_target'] = poj_join_target((int)$actor['employee_id'], $existing['project_type'], (int)$existing['id'], $actor['role']);
                            $record['existing_order_id'] = (int)$existing['id'];
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '同一笔销售只记一次：此单已由“' . $existing['project_type'] . '”按相同金额录入，本人将作为“' . $record['join_target']['role'] . '”加入原订单，不另建分单、不重复计金额';
                        } else {
                        // 他人用另一业务录过的同号订单：本人这份另建分单子单（各记各的金额与业务规则），不再被拦
                        $record['split_parent_id'] = (int)$existing['id'];
                        $record['split_parent_no'] = $record['order_no'];
                        $record['split_parent'] = ['id' => (int)$existing['id'], 'order_no' => $existing['order_no'] ?? $record['order_no'], 'project_type' => $existing['project_type'
    ], 'contract_amount' => $existing['contract_amount'], 'order_date' => $existing['order_date'] ?? null];
                        $record['order_no'] = pos_child_order_no($record['split_parent_no'], $record['project_type'], $coCustomerService ? (int)$actor['employee_id'] : 0);
                        $exists->execute([$record['order_no']]);
                        $existing = $exists->fetch();
                        $record['existing_order_id'] = $existing ? (int)$existing['id'] : 0;
                        }
                    }
                    if ($existing) {
                        if (in_array($existing['settlement_status'], ['approved','locked'], true)) { $record['skip_status'] = ps_import_order_visible((int)$existing['id'], $actor)
    ? '已导入过' : '他人订单'; throw new RuntimeException('此单已导入并经财务审核，本次自动跳过；如需更改请联系财务'); }
                        if ($departmentMode && !ps_department_import_is_order((int)$existing['id']) && $actor['role'] !== 'finance') {
                            // 本人之前用个人上传导入过的同一单：视为已导入，自动跳过，不当成错误
                            $existingAccess->execute([(int)$existing['id'], (int)$actor['employee_id']]);
                            if ($existingAccess->fetchColumn()) { $record['skip_status'] = '已导入过'; throw new RuntimeException('此单本人已导入过（个人订单），本次自动跳过'
    ); }
                            throw new RuntimeException('同号订单不是网站售后部门订单，请由财务核对');
                        }
                        if ($actor['role'] !== 'finance' && !$departmentMode && !ps_management_can_business($actor, $selectedBusiness)) {
                            $existingAccess->execute([(int)$existing['id'], (int)$actor['employee_id']]);
                            if (!$existingAccess->fetchColumn()) {
                                $record['attach_check'] = true; // 仅本人所在分成组还无人时，允许与同号订单关联。
                            }
                        }
                        $existingResource->execute([(int)$existing['id']]);
                        $existingMode = $existingResource->fetchColumn();
                        $record['resource_locked'] = $existingMode !== false && $existingMode !== 'pending';
                        $record['status'] = '补充已有订单';
                    }
