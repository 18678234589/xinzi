<?php
                    $record['project_type'] = ps_import_website_business($selectedBusiness, $lookup($row, 'program_name'), $lookup($row, 'frontend') . '/' . $lookup($row, 'backend'
    ), $allowedBusinesses);
                    $record['writing'] = null;
                    if (in_array($selectedBusiness, ['微信代写', '软文代写'], true)
                        && ($selectedBusiness === '微信代写' || psw_is_member((int)($actor['employee_id'] ?? 0)))) {
                        $record['writing'] = psw_source_from_row($head, $row, $sheetName);
                        $record['project_type'] = '微信代写';
                        $orderKinds = ps_business_order_kinds('微信代写');
                    }
                    if ($record['project_type'] !== $selectedBusiness) $record['warning'] .= ($record['warning'] ? '；' : '') . '按产品/技术岗位自动归入“' . $record['project_type'
    ] . '”分成方案';
                    $record['order_no'] = ps_order_no_resolve($lookup($row, 'order_no'));
                    // 对公收款：订单号格子写“对公”，无需店铺订单号，只认对公交易号（可写在订单号格“对公 xxxx”、交易号列，或在预览页补填）
                    $publicReference = '';
                    if (ps_import_public_transfer($lookup($row, 'order_no'), $publicReference)) {
                        $record['order_no'] = '';
                        $record['public_transfer'] = true;
                    }
                    // 订单号格子里只有姓名 / 店铺名（没有任何数字，如“内部”“张姐”）不是订单号：库里没有同名订单时当作没填，走下面的“无订单号”流程（稳定内部号、疑似重复提示），
                    // 不再把名字当订单号建单——否则补上真实订单号重传时会被当成另一张订单重复计算。库里已有同名订单（旧导入）时照旧沿用，避免旧表重传反而重复。
                    $textOnlyNo = false;
                    if ($record['order_no'] !== '' && !preg_match('/\d/', $record['order_no'])) {
                        $textNoQuery = db()->prepare('SELECT 1 FROM project_orders WHERE order_no=? LIMIT 1'); $textNoQuery->execute([$record['order_no']]);
                        if (!$textNoQuery->fetchColumn()) {
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '订单号格“' . mb_substr($record['order_no'], 0, 20) . '”不是订单号（没有数字，像是姓名 / 店铺名），已当作没有订单号处理';
                            $record['order_no'] = ''; $textOnlyNo = true;
                        }
                    }
                    if (!$textOnlyNo && $record['order_no'] !== $lookup($row, 'order_no') && $lookup($row, 'order_no') !== '') $record['warning'] .= ($record['warning'] ? '；' : '') . '订单号“'
    . mb_substr($lookup($row, 'order_no'), 0, 40) . '”按“' . $record['order_no'] . '”识别（已去掉标签 / 备注，同号不会重复建单）';
                    $siteBaseType = null; $siteBaseOrder = null;
                    if ($record['order_no'] !== '' && psp_is_website($record['project_type'])) {
                        $siteTypeQuery = db()->prepare('SELECT id,project_type FROM project_orders WHERE order_no=? LIMIT 1');
                        $siteTypeQuery->execute([$record['order_no']]);
                        $siteBaseOrder = $siteTypeQuery->fetch();
                        $siteBaseType = $siteBaseOrder['project_type'] ?? null;
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
                            $rootIdentity = $siteBaseOrder ? psp_order((int)$siteBaseOrder['id']) : null;
                            $legacyMerged = $siteBaseOrder && !$rootIdentity;
                            $rawSiteKey = $legacyMerged ? '' : ($rootIdentity ? psp_import_auto_key(psp_members((int)$rootIdentity['root_order_id']), $siteSeq[$siteBase], $lookup($row, 'contract_amount')) : 'auto-' . $siteSeq[$siteBase]);
                            $record['site_key_auto'] = !$legacyMerged;
                            $record['site_legacy_merged'] = $legacyMerged;
                            $firstIndex = $seen[$siteBase] ?? null;
                            if ($legacyMerged && $firstIndex !== null && isset($preview[$firstIndex])) $preview[$firstIndex]['site_legacy_merged'] = true;
                            if (!$legacyMerged && $firstIndex !== null && isset($preview[$firstIndex]) && empty($preview[$firstIndex]['site_key']) && !empty($preview[$firstIndex]['site_external_no'])) { $firstKey = $rootIdentity ? $rootIdentity['site_key'] : 'auto-1'; $preview[$firstIndex]['site_key'] = $firstKey; $preview[$firstIndex]['site_key_auto'] = !$rootIdentity; $siteSeenKeys[$siteBase][$firstKey] = true; }
                            $record['warning'] .= ($record['warning'] ? '；' : '') . ($legacyMerged ? '历史同付款号订单已合并，本次保持原订单并合并附加行；拆分网站须补充各网站标识，不能靠行数重复建单' : '同付款号网站按原登记标识匹配；新网站须核对付款分配');
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
                        } elseif ($siteSeq[$siteBase] > 1 && empty($record['site_legacy_merged'])) {
                            $record['order_no'] = $siteBase . '~pending-' . $record['line'];
                        }
                        if ($siteSeq[$siteBase] > 1) $record['warning'] .= ($record['warning'] ? '；' : '') . '同一付款号有多个网站，须逐个标识项目并由财务核对总付款分配'
    ;
                    }
                    if ($isSiteAddon && isset($siteLastOrderNos[$record['order_no']])) {
                        $addonBase = $record['order_no'];
                        $record['order_no'] = $siteLastOrderNos[$addonBase];
                        if ($record['order_no'] !== $addonBase) $record['multi_site_parent_no'] = $addonBase;
                    } elseif (!$isSiteAddon && !empty($record['site_external_no'])) {
                        $siteLastOrderNos[$record['site_external_no']] = $record['order_no'];
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
                    // 对公收款没有店铺订单号：订单编号写“对公”（或留空、店铺写对公）即可，没有交易流水号时按“日期 + 付款昵称 + 售价 + 序号”生成稳定的内部号（DG-…），重传不重复建单
                    if ($record['order_no'] === '' && $record['payment_reference'] === '' && trim((string)($fixOrderNos[$record['line']] ?? '')) === '' && (!empty($record['public_transfer']) || preg_match('/对公|公账/u', (string)$lookup($row, 'shop')))) {
                        $dgDate = ps_import_date($lookup($row, 'order_date')); $dgNick = trim((string)$lookup($row, 'payment_nickname')); $dgAmt = preg_replace('/[^\d.]/', '', (string)$lookup($row, 'contract_amount'));
                        if ($dgDate && $dgNick !== '' && $dgAmt !== '') {
                            $dgKey = $selectedBusiness . '|' . $dgDate . '|' . mb_strtolower($dgNick) . '|' . (float)$dgAmt;
                            $wxSeq['dg|' . $dgKey] = ($wxSeq['dg|' . $dgKey] ?? 0) + 1;
                            $record['order_no'] = 'DG-' . strtoupper(substr(hash('sha256', $dgKey . '|' . $wxSeq['dg|' . $dgKey]), 0, 12));
                            $record['public_transfer'] = true;
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '对公收款没有填对公编码：已按“日期 + 付款昵称 + 售价”临时生成内部关联号，请在“流水号/对公编码”列补上对公编码（对账用）';
                        }
                    }
                    if ($record['order_no'] === '' && $record['payment_reference'] !== '') {
                        $record['order_no'] = ps_payment_reference_order_no($selectedBusiness, $record['payment_reference']);
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '无店铺订单号，已按微信交易流水号生成内部关联号';
                    }
                    // 森动备案二次备案（备案售后、迁移、改信息等杂项）：没有订单号 / 流水号 / 售价，只有域名和联系微信号。
                    // 每一行是一次独立工作，各算一单：按“域名＋联系微信号＋月份＋同键序号”生成稳定的内部号（EB-…），重传不重复建单；与本月首次备案无关。
                    if ($selectedBusiness === '森动备案' && $record['order_no'] === '' && $record['payment_reference'] === '' && $lookup($row, 'detail:domain_name') !== '' && trim((string)$lookup($row, 'contract_amount')) === '') {
                        $ebDate = ps_import_date($lookup($row, 'order_date'));
                        $ebDomain = trim($lookup($row, 'detail:domain_name'));
                        $ebWechat = trim($lookup($row, 'detail:contact_wechat') !== '' ? $lookup($row, 'detail:contact_wechat') : $lookup($row, 'contact_note'));
                        $ebMonth = substr((string)$ebDate, 0, 7);
                        $ebKey = mb_strtolower($ebDomain . '|' . $ebWechat . '|' . $ebMonth);
                        $wxSeq['eb|' . $ebKey] = ($wxSeq['eb|' . $ebKey] ?? 0) + 1;
                        $seqIndex = $wxSeq['eb|' . $ebKey];
                        $record['order_no'] = 'EB-' . strtoupper(substr(hash('sha256', '森动备案|' . $ebKey . '|' . $seqIndex), 0, 12));
                        // 兼容历史不同版本模板（曾把微信号分别放在“联系方式”或“联系微信号”列，或两列同时填写，生成不同 EB 号）：若库里已存在对应的老单号，直接沿用原单号实现同步，不重复建单
                        $legacyKey1 = mb_strtolower($ebDomain . '||' . $ebWechat . '|' . $ebMonth);
                        $legacyNo1 = 'EB-' . strtoupper(substr(hash('sha256', '森动备案|' . $legacyKey1 . '|' . $seqIndex), 0, 12));
                        $legacyKey2 = mb_strtolower($ebDomain . '|' . $ebWechat . '||' . $ebMonth);
                        $legacyNo2 = 'EB-' . strtoupper(substr(hash('sha256', '森动备案|' . $legacyKey2 . '|' . $seqIndex), 0, 12));
                        $legacyKey3 = mb_strtolower($ebDomain . '|' . $ebWechat . '|' . $ebWechat . '|' . $ebMonth);
                        $legacyNo3 = 'EB-' . strtoupper(substr(hash('sha256', '森动备案|' . $legacyKey3 . '|' . $seqIndex), 0, 12));
                        foreach ([$legacyNo3, $legacyNo2, $legacyNo1] as $candidateNo) {
                            if ($candidateNo !== $record['order_no']) {
                                $legacyCheck = db()->prepare("SELECT order_no FROM project_orders WHERE order_no=? AND project_type='森动备案' AND order_kind='二次备案' LIMIT 1");
                                $legacyCheck->execute([$candidateNo]);
                                if ($legacyCheck->fetchColumn()) {
                                    $record['order_no'] = $candidateNo;
                                    break;
                                }
                            }
                        }
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '没有订单号：已按“域名＋联系微信号＋月份”生成内部订单号' . ($seqIndex > 1 ? '（同月同域名第 ' . $seqIndex . ' 次，按独立工作各记一单）' : '');
                    }
                    // 微信付款等没有订单号的订单：只要有日期、金额和一个识别信息（付款昵称 / 联系方式 / 客服），就按这些内容生成稳定的内部订单号（WX-…），
                    // 同一张表重复上传得到同一个号，不会重复建单；拿到真实订单号后可在订单页补录。
                    if ($record['order_no'] === '' && $record['payment_reference'] === '') {
                        $wxAmount = trim((string)$lookup($row, 'contract_amount')); $wxDate = trim((string)$lookup($row, 'order_date'));
                        $wxNick = trim((string)$lookup($row, 'payment_nickname')); $wxContact = trim((string)$lookup($row, 'contact_note')); $wxCs = trim((string)$lookup($row, 'customer_service'
    ));
                        if ($wxAmount !== '' && $wxDate !== '' && ($wxNick !== '' || $wxContact !== '' || $wxCs !== '')) {
                            $wxKey = mb_strtolower($selectedBusiness . '|' . $wxDate . '|' . trim((string)$lookup($row, 'shop')) . '|' . $wxNick . '|' . $wxAmount . '|' . $wxCs);
                            $legacyWxKey = $wxKey;
                            if (!empty($record['writing'])) $wxKey = mb_strtolower($record['project_type'] . '|' . ps_import_date($wxDate) . '|' . $record['writing']['kind'] . '|'
                                . trim((string)$lookup($row, 'shop')) . '|' . $wxNick . '|' . number_format((float)$wxAmount, 2, '.', '') . '|' . $wxCs);
                            $wxSeq[$wxKey] = ($wxSeq[$wxKey] ?? 0) + 1;
                            $record['order_no'] = 'WX-' . strtoupper(substr(hash('sha256', 'noorder|' . $wxKey . '|' . $wxSeq[$wxKey]), 0, 24));
                            if (!empty($record['writing'])) {
                                $wxSeq['legacy|' . $legacyWxKey] = ($wxSeq['legacy|' . $legacyWxKey] ?? 0) + 1;
                                $legacyNo = 'WX-' . strtoupper(substr(hash('sha256', 'noorder|' . $legacyWxKey . '|' . $wxSeq['legacy|' . $legacyWxKey]), 0, 24));
                                $legacyLookup = db()->prepare('SELECT order_no,project_type,order_kind FROM project_orders WHERE order_no=?');
                                $legacyLookup->execute([$legacyNo]); $legacyOrder = $legacyLookup->fetch();
                                if ($legacyOrder && $legacyOrder['project_type'] === $record['project_type'] && $legacyOrder['order_kind'] === $record['writing']['kind']) $record['order_no'] = $legacyOrder['order_no'];
                            }
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '没有订单号（微信付款等）：已按“日期＋店铺＋付款昵称＋金额”生成内部订单号，拿到真实订单号后可在订单页补录'
    ;
                        }
                    }
                    $exists->execute([$record['order_no']]);
                    $existing = $exists->fetch();
                    // 疑似重复：没有真实订单号（系统自己生成 WX- / EB- 号）的行，同业务、同日期、同金额、又不像别的客户，默认就是已有的那一单——沿用它，不再新建。
                    // 业务负责人认为是另一笔订单，可在预览里点“去申诉”，财务通过后重传即可作为独立订单导入（见 ProjectDupAppeal.php）。
                    $pdaSeqNow = 0;
                    if (empty($record['writing']) && $selectedBusiness === '森动备案' && strpos((string)$record['order_no'], 'EB-') === 0 && trim((string)$lookup($row, 'contract_amount')) === '' && trim((string)$lookup($row, 'detail:domain_name')) !== '') {
                        $pdaSeqKey0 = mb_strtolower(trim((string)$lookup($row, 'detail:domain_name'))) . '|' . (string)ps_import_date($lookup($row, 'order_date'));
                        $pdaSeq[$pdaSeqKey0] = ($pdaSeq[$pdaSeqKey0] ?? 0) + 1; // 同日期同域名第几次出现（不论库里是否已有对应号）
                        $pdaSeqNow = $pdaSeq[$pdaSeqKey0];
                    }
                    if (!$existing && empty($record['writing']) && preg_match('/^(?:WX|EB)-/', (string)$record['order_no'])) {
                        require_once (dirname((dirname(__DIR__, 1)), 1)) . '/includes/ProjectDupAppeal.php';
                        $pdaDate = (string)ps_import_date($lookup($row, 'order_date'));
                        $pdaAmount = (float)str_replace([',', '¥', '￥', ' '], '', (string)$lookup($row, 'contract_amount'));
                        $pdaNick = trim((string)$lookup($row, 'payment_nickname')); $pdaDomain = trim((string)$lookup($row, 'detail:domain_name'));
                        $pdaKey = pda_row_key($selectedBusiness, $pdaDate, $pdaAmount, $pdaNick, $pdaDomain);
                        if ($pdaAmount <= 0 && $pdaDomain !== '' && $pdaDate !== '' && strpos((string)$record['order_no'], 'EB-') === 0) {
                            // 二次备案（没有金额）：同日期、同域名，按出现顺序对应库里已有的二次备案；写法不同（联系微信号列 / 联系方式列）导致内部号不同时，不再重复建单
                            if (pda_approved_id($selectedBusiness, $pdaKey)) {
                                $record['order_no'] = 'EB-' . strtoupper(substr(hash('sha256', 'appeal|' . $pdaKey . '|' . $pdaSeqNow), 0, 12));
                                $exists->execute([$record['order_no']]);
                                $existing = $exists->fetch();
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '重复订单申诉已通过，按独立订单处理';
                            } elseif ($pdaMatch = pda_find_existing_secondary($selectedBusiness, $pdaDate, $pdaDomain, $pdaSeqNow)) {
                                $record['order_no'] = $pdaMatch['order_no'];
                                $exists->execute([$record['order_no']]);
                                $existing = $exists->fetch();
                                $record['dup_adopted'] = ['order_id' => (int)$pdaMatch['id'], 'order_no' => $pdaMatch['order_no'], 'url' => pda_appeal_url($selectedBusiness, $pdaMatch, $pdaDate, 0, $pdaNick, $pdaDomain, trim((string)$lookup($row, 'shop')))];
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '与已有二次备案 ' . $pdaMatch['order_no'] . ' 日期、域名相同，已按同一订单处理（不会新建）';
                            }
                        } elseif ($pdaAmount > 0 && $pdaDate !== '') {
                            if (pda_approved_id($selectedBusiness, $pdaKey)) {
                                // 申诉已通过：这一行是独立订单，用固定的另一个内部号（重传不重复建单）
                                $record['order_no'] = 'WX-' . strtoupper(substr(hash('sha256', 'appeal|' . $pdaKey . '|' . $record['order_no']), 0, 24));
                                $exists->execute([$record['order_no']]);
                                $existing = $exists->fetch();
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '重复订单申诉已通过，按独立订单处理';
                            } elseif (!isset($pdaAdopted[$pdaKey]) && ($pdaMatch = pda_find_existing($selectedBusiness, $pdaDate, $pdaAmount, $pdaNick, $pdaDomain, (string)$record['order_no']))) {
                                $pdaAdopted[$pdaKey] = true; // 同一张表里同样的两行：第一行沿用已有订单，第二行才可能是另一笔
                                $record['order_no'] = $pdaMatch['order_no'];
                                $exists->execute([$record['order_no']]);
                                $existing = $exists->fetch();
                                $record['dup_adopted'] = ['order_id' => (int)$pdaMatch['id'], 'order_no' => $pdaMatch['order_no'], 'url' => pda_appeal_url($selectedBusiness, $pdaMatch, $pdaDate, $pdaAmount, $pdaNick, $pdaDomain, trim((string)$lookup($row, 'shop')))];
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '与已有订单 ' . $pdaMatch['order_no'] . ' 日期、金额相同，已按同一订单处理（不会新建）';
                            }
                        }
                    }
                    $record['existing_order_id'] = $existing ? (int)$existing['id'] : 0;
                    if ($existing && !empty($record['writing']) && $record['writing']['source'] === 'editor'
                        && in_array($record['writing']['kind'], ['合并单','退款冲减'], true)) {
                        $record['skip_status'] = '不计对接单量';
                        throw new RuntimeException('编辑合并/退款行不计单量，不向原单新增计费编辑；已有原单及其财务数据保留');
                    }
                    if ($existing && !empty($record['writing']) && $record['writing']['source'] === 'editor'
                        && in_array(ps_business_normalize($existing['project_type']), ['微信代写', '软文代写'], true)
                        && (ps_business_normalize($existing['project_type']) === '软文代写' || $existing['order_kind'] !== '编辑对接')) {
                        $record['join_parent'] = ['id' => (int)$existing['id'], 'project_type' => $existing['project_type']];
                        $record['join_target'] = ['group' => 'technical', 'role' => '对接编辑'];
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '编辑对接只加入原单，不改变原接单客服、收入或成本';
                    }
                    $record['resource_locked'] = false;
                    // 同一订单号客户一次付款、由多位商标客服分别录入：后录入的客服另记自己那份（同业务分单）
                    $coCustomerService = $existing && $selectedBusiness === '商标' && $actor['role'] === 'customer_service' && !$departmentMode && !ps_is_management($actor)
                        && ps_business_normalize($existing['project_type']) === $record['project_type']
                        && !ps_import_order_visible((int)$existing['id'], $actor) && ps_import_group_taken((int)$existing['id'], 'customer_service');
                    if ($existing && empty($record['join_parent']) && (ps_business_normalize($existing['project_type']) !== $record['project_type'] || $coCustomerService)) {
                        if (!empty($record['writing']) && $record['writing']['source'] === 'self' && !ps_import_order_visible((int)$existing['id'], $actor)
                            && ps_business_normalize($existing['project_type']) === '软文代写') throw new RuntimeException('此订单已由其他客服录为软文代写：若本人负责对接，请标注“编辑对接”；确为自接请由财务核对原单归属，不能按空岗位猜分成');
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
                            $record['join_target'] = !empty($record['writing']) && $record['writing']['source'] === 'editor'
                                ? ['group' => 'technical', 'role' => '对接编辑']
                                : poj_join_target((int)$actor['employee_id'], $existing['project_type'], (int)$existing['id'], $actor['role']);
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
