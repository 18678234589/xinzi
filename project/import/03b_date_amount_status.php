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
    $match['price'] !== null && ps_shop_compatible($lookup($row, 'shop'), $match['shop']); }));
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
                    if ($selectedBusiness === '回购订单' && $status === '') $record['delivery_status'] = 'finished'; // 回购订单表没有状态列：登记即完成
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
                    // 网站模板表里可直接选定制类型：该行已归入 AI网站定制时，可选类型按 AI网站定制的（博山定制 / AI定制 / 华梦定制）
                    if ($selectedBusiness === '网站模板') $orderKinds = ($record['project_type'] ?? '') === 'AI网站定制' ? array_merge(ps_business_order_kinds('网站模板'), ps_business_order_kinds('AI网站定制')) : ps_business_order_kinds('网站模板');
                    $kindText = $lookup($row, 'order_kind');
                    if ($kindText === '' && in_array($record['contact_note'], $orderKinds, true)) { $kindText = $record['contact_note']; $record['contact_note'] = ''; }
                    // 订单类型写的是下拉里的说明性名称（如“小程序商城新注册搭建”）：换回库里存的类型值
                    if ($kindText !== '') $kindText = pkl_kind_from_text($selectedBusiness, $kindText);
                    // 小程序技术：“新订单”特指在小程序商城新注册搭建的订单（每单 ¥20 补助）；其他订单（续费、注册公众号、重新注册等）没有补助。
                    // “其他订单 / 其它订单”记为技术服务（5%，无补助）；“业务”列或备注里写了“新订单 / 其他订单”也识别；定制、续费仍须明确写出
                    if ($selectedBusiness === '小程序开发') {
                        if (in_array($kindText, ['其他订单', '其它订单', '其他', '其它'], true)) $kindText = '技术服务';
                        elseif (in_array($kindText, ['新单', '新建', '新建站'], true)) $kindText = '新订单';
                        if ($kindText === '') foreach ([$lookup($row, 'business'), $record['contact_note']] as $kindCell) {
                            if (mb_strpos($kindCell, '新订单') !== false) { $kindText = '新订单'; break; }
                            if (mb_strpos($kindCell, '其他订单') !== false || mb_strpos($kindCell, '其它订单') !== false) { $kindText = '技术服务'; break; }
                        }
                    }
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
                    // 新版网站模板没有“订单类型”列：程序名称里的 博山定制 / AI定制 / 华梦定制 / 纯利润 / 补差价 / 域名注册 / SSL证书 直接决定订单类型
                    if ($kindText === '' && $selectedBusiness === '网站模板') {
                        require_once (dirname(__DIR__, 2)) . '/includes/ProjectMiniappTemplate.php';
                        $programKind = pmt_program_kind($lookup($row, 'program_name'));
                        if ($programKind !== '' && in_array($programKind, $orderKinds, true)) $kindText = $programKind;
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
                        $hint = ($record['business_text'] ?? '') . ' ' . $record['contact_note'] . ' ' . $lookup($row, 'detail:make_requirement') . ($selectedBusiness === '小程序开发' ? ' ' . $sheetBusiness : '');
                        $kindWords = ['续费' => '续费', '定制' => '定制', '技术服务' => '技术服务', '维护' => '技术服务'];
                        // 小程序商城新搭建才是新订单；注册公众号 / 重新注册 / 认证等杂项是其他订单。公众号等词排在商城词前面，避免“公众号”被商城误吞
                        if ($selectedBusiness === '小程序开发') $kindWords += ['公众号' => '技术服务', '重新注册' => '技术服务', '认证' => '技术服务', '商城' => '新订单', '外卖' => '新订单', '新注册' => '新订单', '新搭建' => '新订单'];
                        foreach ($kindWords as $word => $guess) if (in_array($guess,
    $orderKinds, true) && mb_strpos($hint, $word) !== false) { $record['kind_guess'] = $guess; break; }
                        $aiHint = $kindHint($row);
                        if (empty($record['kind_guess']) && $preferredKind && $selectedBusiness !== '小程序开发') { // 小程序：新订单有补助，不沿用历史偏好，描述不清按其他订单
                            $record['kind_guess'] = $preferredKind; $record['kind_from_preference'] = true; }
                        if (empty($record['kind_guess']) && isset($aiKind[$aiHint])) { $record['kind_guess'] = $aiKind[$aiHint]; $record['kind_from_ai'] = true; }
                        if (empty($record['kind_guess'])) { $record['kind_guess'] = in_array($businessDefinition['default_kind'] ?? '', $orderKinds, true) ? $businessDefinition['default_kind'
    ] : ($orderKinds[0] ?? ''); $record['kind_default_review'] = true; if ($selectedBusiness === '小程序开发') $record['kind_guess'] = '技术服务'; /* 没写清是小程序商城新搭建，不默认给新订单补助 */ }
                        $kindText = $record['kind_guess'];
                        $kindSource = !empty($record['kind_from_ai']) ? 'AI 建议' : (!empty($record['kind_from_preference']) ? '本人历史确认分类' : (!empty($record['kind_default_review'
    ]) ? '业务默认' : '业务描述'));
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '表格未写订单类型，已按' . $kindSource . '预选“' . $kindText . '”；可在本行改选，财务也可纠正'
    ;
                    }
                    if (!empty($record['writing'])) {
                        $writingKind = $record['writing']['kind'];
                        if ($writingKind === '') throw new RuntimeException('自接订单未明确淘宝或微信收款，请填写“收款方式”列，或补充店铺；不能默认按淘宝发每单补助');
                        $writingAmount = str_replace([',', '¥', '￥', ' '], '', $lookup($row, 'contract_amount'));
                        $writingUnit = $lookup($row, 'unit_marker');
                        if (is_numeric($writingAmount) && (float)$writingAmount < 0) $writingKind = '退款冲减';
                        elseif (($writingAmount === '' || (is_numeric($writingAmount) && (float)$writingAmount == 0)) && $writingUnit !== '' && is_numeric($writingUnit) && (float)$writingUnit == 0) $writingKind = '合并单';
                        $kindText = $writingKind;
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '已区分接单方式：'
                            . ($record['writing']['source'] === 'editor' ? '编辑对接（只按对接单量规则，不参与本单自接利润分成）' : pkl_label('微信代写', $writingKind));
                        if ($record['shop'] === '' && $writingKind === '微信付款') $record['shop'] = '微信';
                        elseif ($record['shop'] === '' && $writingKind === '店铺订单') $record['shop'] = '淘宝';
                    }
                    $record['order_kind'] = $kindText;
                    // 森动备案“二次备案”分表只登记已做完的工作（备案售后、迁移、改信息等），没有状态列：按已完成计，不再因状态为空落成“未完成”
                    if ($selectedBusiness === '森动备案' && $kindText === '二次备案') {
                        if (!isset($columnMap['status']) && $status === '') {
                            $record['delivery_status'] = 'finished';
                        }
                        if ($record['contract_amount'] === '') {
                            $record['contract_amount'] = '0.00';
                        }
                    }
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
                        // 二次备案（备案售后、迁移等杂项）没有成本：表里即使填了也不记
                        if ($hasCost && $kindText === '二次备案') {
                            if ($costTotal != 0) $record['warning'] .= ($record['warning'] ? '；' : '') . '二次备案没有成本，表里填的成本 ¥' . number_format($costTotal, 2, '.', '') . ' 已忽略';
                        } elseif ($hasCost) $record['direct_cost'] = number_format($costTotal, 2, '.', '');
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
                    // “年限”列（1-10 年下拉）：程序名称没写年数时拼成“N年+程序名称”，与原来直接写“5年JSP展示中级版”完全等价；名称里已有年数的以名称为准
                    if ($usesProgram && $record['program_name'] !== '') {
                        $yearsRaw = trim((string)$lookup($row, 'program_years'));
                        $yearsMap = ['一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9, '十' => 10];
                        $years = preg_match('/(\d{1,2})/', $yearsRaw, $ym) ? (int)$ym[1] : (preg_match('/^([一二两三四五六七八九十])年?$/u', $yearsRaw, $ym) ? $yearsMap[$ym[1]] : 0);
                        if ($yearsRaw !== '' && ($years < 1 || $years > 10)) $record['warning'] .= ($record['warning'] ? '；' : '') . '年限“' . $yearsRaw . '”不在 1-10 年内，已忽略';
                        elseif ($years >= 1) {
                            if (preg_match('/^\s*(\d+)年/u', $record['program_name'], $nm)) { if ((int)$nm[1] !== $years) $record['warning'] .= ($record['warning'] ? '；' : '') . '程序名称写的是' . $nm[1] . '年、年限列是' . $years . '年，以程序名称为准'; }
                            else $record['program_name'] = $years . '年' . $record['program_name'];
                        }
                    }
                    $record['program_template_id'] = 0;
                    $record['lines'] = [$record['line']];
                    $rawDetails = [];
                    foreach ($businessDefinition['fields'] as $key => $label) $rawDetails[$key] = $lookup($row, 'detail:' . $key);
                    if ($selectedBusiness === '小程序开发') {
                        // 业务种类：永久（一次买断，不续费）/ 年费（按年续费，须有到期日期和续费联系方式）。“永久”也可直接写在到期日期里
                        require_once (dirname(__DIR__, 1)) . '/../includes/ProjectMiniappTemplate.php';
                        $termRaw = trim((string)($rawDetails['service_term'] ?? ''));
                        $expiryRaw = trim($lookup($row, 'expiry_date')); $renewContact = trim($lookup($row, 'renew_contact'));
                        $term = pmt_normalize_term($termRaw);
                        if ($term === null) { $record['warning'] .= ($record['warning'] ? '；' : '') . '业务种类“' . mb_substr($termRaw, 0, 20) . '”不是“永久 / 年费”，已忽略'; $term = ''; }
                        if ($term === '' && pmt_is_permanent_text($expiryRaw)) $term = '永久';
                        $rawDetails['service_term'] = $term;
                        if ($term === '年费') {
                            if ($expiryRaw === '') $record['warning'] .= ($record['warning'] ? '；' : '') . '年费业务没填到期日期：续费提醒按订单日期 +1 年估算，请补填';
                        }
                    }
                    // 代写业务字数与售价防混淆及店铺流水校正防错机制
                    if (in_array($selectedBusiness, ['软文代写', '微信代写'], true)) {
                        $wordVal = trim((string)($rawDetails['writing_volume'] ?? ''));
                        $parsedAmt = is_numeric($record['contract_amount']) ? (float)$record['contract_amount'] : null;

                        // 1. 检查字数与售价是否倒置（例如售价解析成了 600，而字数列解析出 50）
                        if ($parsedAmt !== null && is_numeric($wordVal) && (float)$wordVal > 0) {
                            $wNum = (float)$wordVal;
                            if ($parsedAmt >= 200 && $wNum < $parsedAmt / 2 && ($parsedAmt >= 300 && $wNum <= 200 || in_array((int)$parsedAmt, [200, 300, 400, 500, 600, 800, 1000, 1200, 1500, 2000, 2500, 3000, 4000, 5000, 6000, 8000, 10000], true))) {
                                $record['contract_amount'] = number_format($wNum, 2, '.', '');
                                $rawDetails['writing_volume'] = (string)(int)$parsedAmt;
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '字数（' . (int)$parsedAmt . '）与售价（' . $wNum . '）列倒置，已自动纠正';
                                $parsedAmt = (float)$record['contract_amount'];
                            }
                        }

                        // 2. 结合店铺流水（orders 表）校验与校正：防止把字数当售价
                        if ($record['order_no'] !== '' && substr($record['order_no'], 0, 3) !== 'WX-') {
                            $shopMatches = array_values(array_filter(ps_shop_order_lookup($record['split_parent_no'] ?? $record['order_no']), function ($match) use ($row, $lookup) {
                                return $match['price'] !== null && ps_shop_compatible($lookup($row, 'shop'), $match['shop']);
                            }));
                            if (count($shopMatches) === 1 && $shopMatches[0]['price'] !== null && (float)$shopMatches[0]['price'] > 0) {
                                $storePrice = (float)$shopMatches[0]['price'];
                                if ($parsedAmt !== null && abs($parsedAmt - $storePrice) > 0.01) {
                                    if ($parsedAmt >= 200 && ($parsedAmt >= $storePrice * 2.5 || $parsedAmt == (float)$wordVal)) {
                                        $origAmt = $record['contract_amount'];
                                        $record['contract_amount'] = number_format($storePrice, 2, '.', '');
                                        if (empty($rawDetails['writing_volume']) || (float)$rawDetails['writing_volume'] == $storePrice) {
                                            $rawDetails['writing_volume'] = (string)(int)$parsedAmt;
                                        }
                                        $record['warning'] .= ($record['warning'] ? '；' : '') . '售价原为 ¥' . $origAmt . '，与店铺流水实付 ¥' . $record['contract_amount'] . ' 冲突（疑似字数），已自动按真实实付校正';
                                    }
                                }
                                if (empty($record['payment_nickname']) && !empty($shopMatches[0]['nickname'])) {
                                    $record['payment_nickname'] = $shopMatches[0]['nickname'];
                                }
                            }
                        }
                    }
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
                    require_once (dirname(__DIR__, 1)) . '/../includes/ProjectMiniappDelivery.php';
                    if (ps_miniapp_custom_order($record)) {
                        if (ps_miniapp_custom_unshipped($record, ['trade_status' => $status])) {
                            $record['delivery_status'] = 'unfinished';
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '小程序 AI 定制未发货不计分成，到账不能代替发货';
                        }
                        if ($status !== '') $record['trade_status'] = $status;
                    }
                    if ($existing && ($existing['delivery_status'] ?? '') === 'unfinished' && $record['delivery_status'] === 'finished' && !in_array($existing['settlement_status'], ['approved', 'locked'], true)) $record['warning'] .= ($record['warning'] ? '；' : '') . '原单状态是“未完成”，本次表格写已完成 / 到账，导入后将更新为已完成';
                    if ($existing) $record['existing_snapshot'] = $existing;
