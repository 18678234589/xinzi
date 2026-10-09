<?php
                    $record['order_date'] = ps_import_date($lookup($row, 'order_date'));
                    if (!$record['order_date'] && trim((string)($fixDates[$record['line']] ?? '')) !== '') {
                        $record['order_date'] = ps_import_date($fixDates[$record['line']]);
                        if ($record['order_date']) $record['warning'] .= ($record['warning'] ? '；' : '') . '日期由上传人在预览中确认补填';
                    }
                    if (!$record['order_date'] && $existing && !empty($existing['order_date'])) {
                        $record['order_date'] = ps_import_date($existing['order_date']);
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '日期由同号结算单带入';
                    }
                    if (!$record['order_date'] && !empty($record['split_parent']['order_date'])) {
                        $record['order_date'] = ps_import_date($record['split_parent']['order_date']);
                        if ($record['order_date']) $record['warning'] .= ($record['warning'] ? '；' : '') . '日期由同号原订单带入';
                    }
                    if (!$record['order_date'] && $record['order_no'] !== '' && substr($record['order_no'], 0, 3) !== 'WX-') {
                        $matches = array_values(array_filter(ps_shop_order_lookup($record['split_parent_no'] ?? $record['order_no']), function ($match) use ($row, $lookup) { return
    $match['price'] !== null && ($lookup($row, 'shop') === '' || $lookup($row, 'shop') === $match['shop']); }));
                        if (count($matches) === 1 && !empty($matches[0]['date'])) {
                            $record['order_date'] = ps_import_date($matches[0]['date']);
                            if ($record['order_date']) $record['warning'] .= ($record['warning'] ? '；' : '') . '日期由同号店铺流水带入';
                        }
                    }
                    // 部门原表偶有把时间写进日期列（如 18.05、“17. 00”）：按今天建单并提示核对
                    if (!$record['order_date'] && !empty($businessDefinition['free_shop']) && $lookup($row, 'order_date') !== '' && ($lookup($row, 'order_no') !== '' && $lookup($row
    , 'contract_amount') !== '')) { $record['order_date'] = date('Y-m-d'); $record['warning'] = '日期“' . $lookup($row, 'order_date') . '”无法识别，已按今天建单，请核对'
    ; }
                    // 备案-单量表没有日期列：按导入当天记单（月度归属由上传时间决定，财务可在结算单调整）。
                    if (!$record['order_date'] && $selectedBusiness === '备案-单量') {
                        $record['order_date'] = date('Y-m-d');
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '表无日期列，已按导入当天记单';
                    }
                    $record['contract_amount'] = str_replace([',','¥','￥',' '], '', $lookup($row, 'contract_amount'));
                    // 售价写成算式（如 640+260）：按求和计，并提示核对
                    if (preg_match('/^\d+(?:\.\d+)?(?:[+＋]\d+(?:\.\d+)?)+$/u', $record['contract_amount'])) {
                        $amountExpression = $record['contract_amount'];
                        $record['contract_amount'] = number_format(array_sum(array_map('floatval', preg_split('/[+＋]/u', $amountExpression))), 2, '.', '');
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '售价写的是算式“' . $amountExpression . '”，已按合计 ¥' . $record['contract_amount'] . ' 计算，请核对';
                    }
                    if (preg_match('/^\d+\.\d{3,}$/', $record['contract_amount'])) $record['contract_amount'] = number_format((float)$record['contract_amount'], 2, '.', '');
                    if (!empty($record['split_parent'])) {
                        $parentAmount = (float)$record['split_parent']['contract_amount'];
                        if (!is_numeric($record['contract_amount']) || (float)$record['contract_amount'] <= 0) {
                            // 设计师等“同单另一岗位”的业务：表里不写售价，沿用设计客服已录入的原单售价，用于本人的月营业额
                            if (!empty($businessDefinition['price_from_order']) && $parentAmount > 0) {
                                $record['contract_amount'] = number_format($parentAmount, 2, '.', '');
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '同号订单已由“' . $record['split_parent']['project_type'] . '”业务录入：本行未写售价，沿用原单售价 ¥'
    . $record['contract_amount'] . '，另记“' . $record['project_type'] . '”用于本人月营业额，请财务核对';
                            } else {
                                // 技术表常不带售价：照常建分单，售价留空待补（客服或财务补填，分单合计须与客户实付一致）
                                $record['contract_amount'] = '';
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '同号订单已由其他业务 / 客服录入，本行作为分单加入；表里没写售价，分单售价待补（原单 ¥'
    . number_format($parentAmount, 2, '.', '') . '，合计须与客户实付一致）';
                            }
                        } else $record['warning'] .= ($record['warning'] ? '；' : '') . pos_summary_text($record['split_parent'], $record['project_type'], $record['contract_amount'
    ], $record['order_no']);
                    }
                    $status = $lookup($row, 'status');
                    $record['delivery_status'] = ps_import_delivery_status($status, $selectedBusiness);
                    if ($record['delivery_status'] === null && isset($aiStatus[$status])) { $record['delivery_status'] = $aiStatus[$status]; $record['warning'] .= ($record['warning'
    ] ? '；' : '') . '状态“' . $status . '”由 AI 识别为' . ($aiStatus[$status] === 'finished' ? '已完成' : '未完成'); }
                    // “到账情况”列写的是到账金额（如 740）：按已到账处理
                    if ($record['delivery_status'] === null && is_numeric(str_replace([',', '¥', '￥'], '', $status))) { $record['delivery_status'] = 'finished'; $record['warning'
    ] .= ($record['warning'] ? '；' : '') . '状态列写的是金额“' . $status . '”，按已到账处理'; }
                    // 状态列里写的是别的文字（如接入商名称、备注）：不拦整行，按“未完成”导入并提示，交付后在订单里再标记完成
                    if ($record['delivery_status'] === null) { $record['delivery_status'] = 'unfinished'; $record['warning'] .= ($record['warning'] ? '；' : '') . '状态“' . mb_substr
    ($status, 0, 20) . '”无法识别，已按未完成导入，交付后请在订单里标记完成'; }
                    $record['trade_status'] = mb_strpos($status, '交易关闭') !== false ? '交易关闭' : '';
                    if ($record['trade_status'] !== '') $record['warning'] = '表格写交易关闭：请财务核对退款';
                    $sheetBusiness = $lookup($row, 'business');
                    $record['business_text'] = '';
                    if ($sheetBusiness !== '' && ps_business_normalize($sheetBusiness) !== $selectedBusiness) {
                        if (isset(ps_business_catalog()[ps_business_normalize($sheetBusiness)])) throw new RuntimeException('表格写的业务是“' . $sheetBusiness . '”，与当前选中的“'
    . $selectedBusiness . '”不一致');
                        $record['business_text'] = mb_substr($sheetBusiness, 0, 300); // 部门表“业务”列常写项目描述
                    }
                    $record['shop'] = $lookup($row, 'shop');
                    if ($record['shop'] !== '' && empty($businessDefinition['free_shop']) && !in_array($record['shop'], $knownShops, true)) {
                        $shopText = $record['shop'];
                        $shopMatches = array_values(array_filter($knownShops, function ($known) use ($shopText) { return mb_strpos($known, $shopText) !== false || mb_strpos($shopText
    , $known) !== false; }));
                        if (count($shopMatches) === 1) $record['shop'] = $shopMatches[0];
                        else $record['warning'] .= ($record['warning'] ? '；' : '') . '店铺“' . $shopText . '”不在店铺列表，已按原文保存';
                    }
                    $record['payment_nickname'] = $lookup($row, 'payment_nickname');
                    $record['contact_note'] = $lookup($row, 'contact_note');
                    // 小程序结算表的“备注”常写 新订单 / 续费 / 定制：识别为订单类型。
                    $kindText = $lookup($row, 'order_kind');
                    if ($kindText === '' && in_array($record['contact_note'], $orderKinds, true)) { $kindText = $record['contact_note']; $record['contact_note'] = ''; }
                    if ($kindText !== '' && !in_array($kindText, $orderKinds, true)) {
                        // 网站续费表“拍建站”列常写拍下的具体内容（网站链接/小程序链接/域名等）：有值一律记为“拍链接”。
                        if ($selectedBusiness === '网站续费') {
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '拍建站“' . $kindText . '”已按拍链接处理';
                        $record['kind_mapped'] = true;
                            $kindText = '拍链接';
                        } else {
                            // 类型列常被写成付款方式（微信 / 对公）等：不拦整行，忽略后按描述 / 默认预选，本行可改选
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '订单类型列写的“' . $kindText . '”不是可选类型（' . implode('、', $orderKinds
    ) . '），已忽略';
                            $kindText = '';
                        }
                    }
                    // 备案-提成表“建站订单”列有值：一律记为拍链接类型（与网站续费“拍建站”列同逻辑），可另配每单奖励。
                    if ($selectedBusiness === '备案-提成' && $kindText === '' && $lookup($row, 'build_order_marker') !== '') {
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '建站订单“' . $lookup($row, 'build_order_marker') . '”已按拍链接处理';
                        $record['kind_mapped'] = true;
                        $kindText = '拍链接';
                    }
                    // 代写 / 期刊 / 微信代写按原表内容识别类型：负数行 = 退款冲减；“提成”列为 0 = 合并单；微信付款；期刊“杂志社版面费” = 代付版面费。
                    if ($kindText === '' && !empty($businessDefinition['import_cost'])) {
                        $amountText = str_replace([',','¥','￥',' '], '', $lookup($row, 'contract_amount'));
                        $unitText = $lookup($row, 'unit_marker');
                        $shopText = $lookup($row, 'shop');
                        if (in_array('退款冲减', $orderKinds, true) && is_numeric($amountText) && (float)$amountText < 0) $kindText = '退款冲减';
                        elseif (in_array('合并单', $orderKinds, true) && $unitText !== '' && is_numeric($unitText) && (float)$unitText == 0) $kindText = '合并单';
                        elseif (in_array('新订单', $orderKinds, true)) $kindText = '新订单';
                        elseif (in_array('代付版面费', $orderKinds, true) && mb_strpos($lookup($row, 'pay_mode'), '版面费') !== false) $kindText = '代付版面费';
                        elseif (mb_strpos($shopText, '微信') !== false && in_array('微信付款', $orderKinds, true)) $kindText = '微信付款';
                        elseif (in_array('店铺付款', $orderKinds, true)) $kindText = '店铺付款';
                        elseif (in_array('店铺订单', $orderKinds, true)) $kindText = '店铺订单';
                    }
                    // 设计：有“设计师佣金”列的是 PPT 总表，否则是图片总表；同一客服同一客户当月第二单起记“图片同客户”（不计单量）。
                    if ($kindText === '' && $selectedBusiness === '设计') {
                        $kindText = isset($columnMap['ppt_marker']) ? 'PPT' : '图片';
                        $designDate = ps_import_date($lookup($row, 'order_date'));
                        $customerKey = preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', mb_strtolower($lookup($row, 'payment_nickname')));
                        if ($kindText === '图片' && $customerKey !== '' && $designDate) {
                            $monthKey = substr($designDate, 0, 7) . '|' . $lookup($row, 'customer_service') . '|' . $customerKey;
                            $designRepeat = $designRepeat ?? db()->prepare("SELECT 1 FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.commission_group='customer_service' JOIN employees e ON e.id=p.employee_id WHERE o.project_type='设计' AND o.order_kind='图片' AND DATE_FORMAT(o.order_date,'%Y-%m')=? AND e.name=? AND REPLACE(LOWER(o.customer_name),' ','')=? LIMIT 1"
    );
                            $designRepeat->execute([substr($designDate, 0, 7), $lookup($row, 'customer_service'), $customerKey]);
                            if (isset($designSeen[$monthKey]) || $designRepeat->fetchColumn()) $kindText = '图片同客户';
                            $designSeen[$monthKey] = true;
                        }
                    }
                    // 平面设计：原表“老客户”列有内容（不是否 / 无）即老客户找回，其余为新订单
                    if ($selectedBusiness === '平面设计') {
                        $marker = $lookup($row, 'returning_marker');
                        $kindText = $marker !== '' && !in_array($marker, ['否', '无', '0', '不是'], true) ? '老客户找回' : '新订单';
                    }
                    // 商标业务识别订单类型：小额分表或包含小额 -> 小额返款；备注或类型含“新客” -> 新客户；其余 -> 普通订单。
                    if ($selectedBusiness === '商标') {
                        if (mb_strpos($sheetName, '小额') !== false || mb_strpos($lookup($row, 'order_kind'), '小额') !== false || mb_strpos($lookup($row, 'contact_note'), '小额'
    ) !== false) {
                            $kindText = '小额返款';
                        } elseif (mb_strpos($lookup($row, 'order_kind'), '新客') !== false || mb_strpos($lookup($row, 'contact_note'), '新客') !== false || mb_strpos($lookup($row
    , 'detail:service_type'), '新客') !== false) {
                            $kindText = '新客户';
                        } elseif ($kindText === '') {
                            $kindText = '普通订单';
                        }
                        // 单量补助按当月去重客户：同一客服同一客户（旺旺）当月第二单起记“同客户”（照常提成、不计单量）。
                        // 资料 / 提交专员表的旺旺写群名，不参与判断，由客服上传时更正。
                        $tmDate = ps_import_date($lookup($row, 'order_date'));
                        $tmCustomer = preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', mb_strtolower($lookup($row, 'payment_nickname')));
                        $tmService = $lookup($row, 'customer_service') !== '' ? $lookup($row, 'customer_service') : (string)$actorName;
                        if (in_array($kindText, ['普通订单', '新客户'], true) && $actor['role'] !== 'technical' && $tmDate && $tmCustomer !== '' && $tmService !== '') {
                            $tmKey = substr($tmDate, 0, 7) . '|' . $tmService . '|' . $tmCustomer;
                            $tmRepeat = $tmRepeat ?? db()->prepare("SELECT 1 FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.commission_group='customer_service' JOIN employees e ON e.id=p.employee_id WHERE o.project_type='商标' AND o.order_kind IN ('普通订单','新客户') AND DATE_FORMAT(o.order_date,'%Y-%m')=? AND e.name=? AND REPLACE(LOWER(o.customer_name),' ','')=? AND o.order_no<>? LIMIT 1"
    );
                            $tmRepeat->execute([substr($tmDate, 0, 7), $tmService, $tmCustomer, $lookup($row, 'order_no')]);
                            if (isset($trademarkSeen[$tmKey]) || $tmRepeat->fetchColumn()) $kindText = '同客户';
                            $trademarkSeen[$tmKey] = true;
                        }
                        // “小额”分表没有状态列：返款已完成，按已完成计
                        if ($kindText === '小额返款' && !isset($columnMap['status'])) $record['delivery_status'] = 'finished';
                    }
                    $record['kind_missing'] = false;
                    // 森动备案：表格没写订单类型时按工作表 / 店铺判断——“二次备案”分表 → 二次备案；淘宝店铺 → 备案-淘宝；其余 → 备案
                    // 森动备案：“业务”列直接写了订单类型（备案 / 二次备案）就以它为准；淘宝店铺的“备案”记为备案-淘宝
                    if ($kindText === '' && $selectedBusiness === '森动备案' && in_array($sheetBusiness, $orderKinds, true)) {
                        $kindText = $sheetBusiness === '备案' && mb_strpos($lookup($row, 'shop'), '淘宝') !== false ? '备案-淘宝' : $sheetBusiness;
                    }
                    if ($kindText === '' && $selectedBusiness === '森动备案') {
                        $kindText = mb_strpos((string)$sheetName, '二次') !== false ? '二次备案' : (mb_strpos($lookup($row, 'shop'), '淘宝') !== false ? '备案-淘宝' : '备案'
    );
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '订单类型按表格自动判断为“' . $kindText . '”（二次备案分表 → 二次备案；淘宝店铺 → 备案-淘宝），可在本行改选'
    ;
                    }
                    if ($kindText === '' && !empty($businessDefinition['kind_required'])) {
                        // 显式类型优先；之后按描述、本人确认过的同布局默认、AI 建议、业务默认依次托底。
                        $hint = ($record['business_text'] ?? '') . ' ' . $record['contact_note'] . ' ' . $lookup($row, 'detail:make_requirement');
                        foreach (['续费' => '续费', '定制' => '定制', '技术服务' => '技术服务', '维护' => '技术服务'] as $word => $guess) if (in_array($guess,
    $orderKinds, true) && mb_strpos($hint, $word) !== false) { $record['kind_guess'] = $guess; break; }
                        $aiHint = $kindHint($row);
                        if (empty($record['kind_guess']) && $preferredKind) { $record['kind_guess'] = $preferredKind; $record['kind_from_preference'] = true; }
                        if (empty($record['kind_guess']) && isset($aiKind[$aiHint])) { $record['kind_guess'] = $aiKind[$aiHint]; $record['kind_from_ai'] = true; }
                        if (empty($record['kind_guess'])) { $record['kind_guess'] = in_array($businessDefinition['default_kind'] ?? '', $orderKinds, true) ? $businessDefinition['default_kind'
    ] : ($orderKinds[0] ?? ''); $record['kind_default_review'] = true; }
                        $kindText = $record['kind_guess'];
                        $kindSource = !empty($record['kind_from_ai']) ? 'AI 建议' : (!empty($record['kind_from_preference']) ? '本人历史确认分类' : (!empty($record['kind_default_review'
    ]) ? '业务默认' : '业务描述'));
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '表格未写订单类型，已按' . $kindSource . '预选“' . $kindText . '”；可在本行改选，财务也可纠正'
    ;
                    }
                    $record['order_kind'] = $kindText;
                    // 部门代录补充：同号原单建单时默认记了类型，表格明确给出拍建站时提示按表格更正。
                    if ($departmentMode && !empty($record['kind_mapped']) && !empty($existing['order_kind']) && $existing['order_kind'] !== $kindText) $record['warning'] .= ($record
    ['warning'] ? '；' : '') . '原单类型“' . $existing['order_kind'] . '”将按表格更正为“' . $kindText . '”';
                    // 成本（稿费 / 杂志社费用 + 写手费用 + 备案-提成快递费）：随表导入的业务读成本列；退款冲减行为负数。
                    $record['direct_cost'] = '';
                    if (!empty($businessDefinition['import_cost'])) {
                        $costTotal = 0.0; $hasCost = false;
                        foreach (['direct_cost', 'direct_cost2', 'shipping_cost'] as $costKey) {
                            $costText = str_replace([',','¥','￥',' '], '', $lookup($row, $costKey));
                            if ($costText === '' || !is_numeric($costText)) continue;
                            $costTotal += (float)$costText; $hasCost = true;
                        }
                        if ($hasCost) $record['direct_cost'] = number_format($costTotal, 2, '.', '');
                        if ($selectedBusiness === '森动备案' && !$hasCost && pfc_applies($kindText)) {
                            $filingTemplate = $filingTemplate ?? (pfc_template() ?: false);
                            $record['warning'] .= ($record['warning'] ? '；' : '') . ($filingTemplate ? '首次备案没填成本：导入时按成本中心“' . $filingTemplate['name'] . '” ¥' . number_format((float)$filingTemplate['price'], 2, '.', '') . ' 自动补录' : '首次备案没填成本，成本中心也没有“森动备案成本”模板：提成按成本 0 计会偏高，请补“成本”列或联系财务设置模板');
                        }
                    }
                    $record['domain_used'] = $businessDefinition['resources'] ? $lookup($row, 'domain_used') : '否';
                    $record['ssl_used'] = $businessDefinition['resources'] ? $lookup($row, 'ssl_used') : '';
                    // 常见写法“30元”“¥30”：去掉单位按金额识别
                    if (preg_match('/^[¥￥]?\s*(\d+(?:\.\d{1,2})?)\s*元?$/u', $record['ssl_used'], $sslMatch)) $record['ssl_used'] = $sslMatch[1];
                    $record['resource_note'] = $businessDefinition['resources'] ? $lookup($row, 'resource_note') : '';
                    // 网站续费新表：空间/域名/域名真实成本不再另算订单成本（只取总成本），连同备注2 一起拼进订单备注备查。
                    if ($selectedBusiness === '网站续费') {
                        $renewalExtras = [];
                        foreach (['space_cost' => '空间成本', 'domain_cost' => '域名成本', 'domain_real_cost' => '域名真实成本'] as $extraKey => $extraLabel) {
                            $extraText = str_replace([',', '¥', '￥', ' '], '', $lookup($row, $extraKey));
                            if ($extraText !== '' && is_numeric($extraText) && (float)$extraText != 0) $renewalExtras[] = $extraLabel . '：¥' . number_format((float)$extraText, 2
    , '.', '');
                        }
                        $remark2Text = trim($lookup($row, 'remark2'));
                        if ($remark2Text !== '') $renewalExtras[] = '备注2：' . $remark2Text;
                        $record['renewal_extras'] = $renewalExtras;
                    }
                    // 网站修改新表（2026-10）：后台类型 / 分单备注金额仅记录，拼进订单备注备查；付款截图不参与计算。
                    if ($selectedBusiness === '网站修改') {
                        $modifyExtras = [];
                        $backendTypeText = trim($lookup($row, 'backend_type_marker'));
                        if ($backendTypeText !== '') $modifyExtras[] = '后台类型：' . $backendTypeText;
                        $splitAmountText = trim($lookup($row, 'split_amount_note'));
                        if ($splitAmountText !== '') $modifyExtras[] = '分单备注金额：' . $splitAmountText;
                        $record['renewal_extras'] = array_merge($record['renewal_extras'] ?? [], $modifyExtras);
                    }
                    $record['program_name'] = $usesProgram ? $lookup($row, 'program_name') : '';
                    $record['program_template_id'] = 0;
                    $record['lines'] = [$record['line']];
                    $rawDetails = [];
                    foreach ($businessDefinition['fields'] as $key => $label) $rawDetails[$key] = $lookup($row, 'detail:' . $key);
                    $record['details'] = ps_business_details($selectedBusiness, $rawDetails);
                    if ($record['ssl_used'] !== '' && !in_array($record['ssl_used'], ['无','否'], true) && (!preg_match('/^\d+(?:\.\d{1,2})?$/', $record['ssl_used']) || (float)$record
    ['ssl_used'] > 999999999999.99)) throw new RuntimeException('SSL 真实成本无效，请填写金额、0 或无');
                    if ($record['order_no'] === '' || strlen($record['order_no']) > 100 || !$record['order_date'] || ($record['contract_amount'] !== '' && !preg_match(($record['order_kind'
    ] === '退款冲减' ? '/^-?' : '/^') . '\d+(?:\.\d{1,2})?$/', $record['contract_amount'])) || (float)$record['contract_amount'] > 999999999999.99) throw new RuntimeException(!
    $record['order_date'] ? '日期无法识别' : ($record['order_no'] === '' ? '缺少店铺订单号或支付流水号' : '订单号或售价无效'));
                    // 日期年份明显写错（如把 2026 写成 2029）：不让订单悄悄落到错误的月份，要求核对
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$record['order_date']) && ($record['order_date'] > date('Y-m-d', strtotime('+35 days')) || $record['order_date']
    < date('Y-m-d', strtotime('-3 years')))) throw new RuntimeException('日期“' . $record['order_date'] . '”年份异常（应在今天前后合理范围内），请核对年份后重新上传'
    );
                    if ($existing) $record['existing_snapshot'] = $existing;
