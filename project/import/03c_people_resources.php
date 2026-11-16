<?php
                    // 技术人员上传时，客服列写了非人名（如“大连”）不拦整行：忽略并提示；客服上传仍严格校验本人姓名
                    $unknownCs = [];
                    if (!empty($record['writing']) && $record['writing']['source'] === 'editor') {
                        $cs = []; // “接单客服”是原销售方，仅关联原单，不以编辑身份重新导入销售方。
                    } elseif ($actor['role'] === 'technical' && !$departmentMode) {
                        $cs = ps_import_names_lenient($lookup($row, 'customer_service'), $employeesByName, $selectedBusiness, $unknownCs);
                        foreach ($unknownCs as $unknownCsName) if (ps_import_name_resembles_employee($unknownCsName, $employeesByName)) ps_import_names($lookup($row, 'customer_service'), $employeesByName, $selectedBusiness); // 像合作人员的写法（多半是写错姓名）仍拦截
                        if ($unknownCs) $record['warning'] .= ($record['warning'] ? '；' : '') . '客服列写的“' . implode('、', $unknownCs) . '”不是合作人员，已忽略';
                    } else $cs = ps_import_names($lookup($row, 'customer_service'), $employeesByName, $selectedBusiness);
                    // 技术列写的不是合作人员（常见是把项目名称填进了“制作技术”）：不拦整行，提示后忽略；客服列仍严格校验
                    $unknownTech = [];
                    $front = ps_import_names_lenient($lookup($row, 'frontend'), $employeesByName, $selectedBusiness, $unknownTech);
                    $back = ps_import_names_lenient($lookup($row, 'backend'), $employeesByName, $selectedBusiness, $unknownTech);
                    if ($unknownTech) $record['warning'] .= ($record['warning'] ? '；' : '') . '技术列写的“' . implode('、', $unknownTech) . '”不是合作人员，已忽略'
    . ($actor['role'] === 'technical' ? '，由本人作为技术' : '') . '；如需指定技术请填姓名';
                    // 前面已有的信息自动补全业务说明（小程序名称 / 制作要求 / 客户微信等），表格可不再重复填写
                    $record['details'] = ps_import_autofill_details($record['details'], $record['business_text'] ?? '', $record['contact_note'], $record['payment_nickname'], $unknownTech
    ? implode('、', $unknownTech) : '');
                    foreach ($cs as $id => $name) $record['people']['customer_service'][$id] = ['id' => $id, 'role' => '客服', 'name' => $name];
                    foreach ($front as $id => $name) $record['people']['technical'][$id] = ['id' => $id, 'role' => $peopleLabels['frontend'], 'name' => $name];
                    foreach ($back as $id => $name) {
                        if (isset($record['people']['technical'][$id])) $record['people']['technical'][$id]['role'] .= '/' . $peopleLabels['backend'];
                        else $record['people']['technical'][$id] = ['id' => $id, 'role' => $peopleLabels['backend'], 'name' => $name];
                    }
                    $record['people'] = ps_import_website_people_roles($record['people'], $record['project_type']);
                    // 小程序定制：技术有专属岗位（如翟建跃“定制技术15”：15% + 每单 20 元）时，表格“制作技术”列写他的名字不能把他降成通用的“制作技术”（30%），按他的默认岗位记
                    if ($record['project_type'] === '小程序开发' && in_array((string)($record['order_kind'] ?? ''), ['定制', '开发定制', '定制开发'], true)) {
                        foreach ($record['people']['technical'] ?? [] as $techId => $techPerson) {
                            if (mb_strpos((string)$techPerson['role'], '制作技术') === false) continue;
                            $defaultRole = ps_employee_default_role((int)$techId, '小程序开发', 'technical');
                            if ($defaultRole !== null && in_array($defaultRole, ['定制技术15', '定制技术', '定制技术30'], true)) { $record['people']['technical'][$techId]['role'] = $defaultRole; $record['warning'] .= ($record['warning'] ? '；' : '') . $techPerson['name'] . ' 的小程序定制按默认岗位“' . $defaultRole . '”计算'; }
                        }
                    }
                    // AI网站定制没选订单类型：按技术人数判断（一个技术做＝AI定制，前端 + 后端两个技术＝博山定制），并提示核对；选了的以表格为准
                    if ($record['project_type'] === 'AI网站定制' && trim((string)$record['order_kind']) === '' && empty($record['writing'])) {
                        $techRoles = array_filter($record['people']['technical'] ?? [], function ($t) { return mb_strpos((string)$t['role'], '售后') === false && mb_strpos((string)$t['role'], '测试') === false; });
                        if (count($techRoles) === 1) $record['order_kind'] = 'AI定制'; elseif (count($techRoles) >= 2) $record['order_kind'] = '博山定制';
                        if ($record['order_kind'] !== '') $record['warning'] .= ($record['warning'] ? '；' : '') . '未选订单类型，按技术人数判为“' . $record['order_kind'] . '”（' . ($record['order_kind'] === 'AI定制' ? '一个技术做' : '前后端两个技术') . '），请核对';
                    }
                    // 订单类型和技术人数对不上：AI定制 是一个技术做前后端，写了两个技术会按“前后端分开”重复计提成；博山定制只写一个技术则少算一个人
                    if ($record['project_type'] === 'AI网站定制' && empty($record['writing']) && in_array((string)$record['order_kind'], ['AI定制', '博山定制'], true)) {
                        $techCount = count(array_filter($record['people']['technical'] ?? [], function ($t) { return mb_strpos((string)$t['role'], '售后') === false && mb_strpos((string)$t['role'], '测试') === false; }));
                        if ($record['order_kind'] === 'AI定制' && $techCount >= 2) $record['warning'] .= ($record['warning'] ? '；' : '') . '订单类型是“AI定制”（前后端由一个技术做），但技术栏写了 ' . $techCount . ' 个人：只填一个技术，“后端”选“无”；两个技术分做的请改选“博山定制”';
                        elseif ($record['order_kind'] === 'AI定制' && $techCount === 0) $record['warning'] .= ($record['warning'] ? '；' : '') . '订单类型是“AI定制”，请在“前端（技术）”选负责的技术';
                        elseif ($record['order_kind'] === '博山定制' && $techCount === 1) $record['warning'] .= ($record['warning'] ? '；' : '') . '订单类型是“博山定制”（前端、后端两个技术分做），但只写了一个技术：确认是一个技术做的请改选“AI定制”，否则请补上另一个技术';
                    }
                    if (!empty($record['writing'])) {
                        $record['people'] = psw_people($record['people'], $record['writing']['source'], (int)($actor['employee_id'] ?? 0));
                        // 没有姓名列的个人对接表也按编辑身份记录，不能用账号的客服角色追加自接提成。
                        if ($record['writing']['source'] === 'editor' && $actor['role'] !== 'finance' && !$record['people']['technical']) {
                            $selfId = (int)$actor['employee_id'];
                            $record['people']['technical'][$selfId] = ['id' => $selfId, 'role' => '对接编辑', 'name' => $actorName ?? '本人'];
                        }
                    }
                    if ($selectedBusiness === '森动备案') {
                        [$record['people'], $filingNote] = ps_import_filing_people($record['people'], $peopleLabels['frontend']);
                        if ($filingNote !== '') $record['warning'] .= ($record['warning'] ? '；' : '') . $filingNote;
                    }
                    if (!empty($record['join_parent']) && $actor['role'] !== 'finance') { // 同一笔销售：只记本人加入原订单的那一份，不沿用表格里写的其他人
                        $joinSelfId = (int)$actor['employee_id'];
                        $record['people'] = ['technical' => [], 'customer_service' => []];
                        $record['people'][$record['join_target']['group']][$joinSelfId] = ['id' => $joinSelfId, 'role' => $record['join_target']['role'], 'name' => $actorName ?? '本人'];
                    }
                    // 表格里写了别人、而那个人没有开通本业务账号：这单会出现在对方的项目订单里并按本业务规则给对方算分成（常见是写错了姓名 / 写成同名的人）——提示上传人确认
                    if ($actor['role'] !== 'finance' && !$departmentMode && empty($record['writing']) && empty($record['join_parent'])) {
                        $noBusinessAccount = [];
                        foreach (['customer_service', 'technical'] as $peopleGroup) foreach ($record['people'][$peopleGroup] as $peopleId => $personRow) {
                            if ((int)$peopleId === (int)($actor['employee_id'] ?? 0)) continue;
                            if (!ps_active_employee_for_business((int)$peopleId, $peopleGroup, $selectedBusiness)) $noBusinessAccount[(string)($personRow['name'] ?? ('#' . $peopleId))] = true;
                        }
                        if ($noBusinessAccount) $record['warning'] .= ($record['warning'] ? '；' : '') . '“' . implode('、', array_keys($noBusinessAccount)) . '”没有开通“' . $selectedBusiness . '”业务账号，这单会出现在对方的项目订单里，并按本业务规则给对方算分成；请确认姓名没写错（写错了请改表格后重传）';
                    }
                    if ($departmentMode) {
                        $namedIds = array_values(array_unique(array_merge(array_keys($record['people']['customer_service']), array_keys($record['people']['technical']))));
                        if ($namedIds) {
                            $namedPeople = ps_department_import_people($actor, $selectedBusiness, $namedIds);
                            $record['people'] = ['technical' => [], 'customer_service' => $namedPeople];
                        } elseif ($departmentDefaults) {
                            $record['people'] = ['technical' => [], 'customer_service' => $departmentDefaults];
                            if ($departmentDefaultSelf) $record['warning'] .= ($record['warning'] ? '；' : '') . '表格没写售后参与人，已默认记为上传人本人';
                        }
                    }
                    if ($actor['role'] !== 'finance' && !$departmentMode && !ps_management_can_business($actor, $selectedBusiness)) {
                        $selfId = (int)$actor['employee_id'];
                        if (!isset($record['people']['technical'][$selfId]) && !isset($record['people']['customer_service'][$selfId])) {
                            $selfGroup = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                            // 没有填写本组人员时可由上传人接单；若表格明确写了别人，不能擅自把订单据为己有。
                            $groupHasNamedPerson = $selfGroup === 'technical' ? (bool)($front || $back) : (bool)$cs;
                            // 商标：资料专员、提交专员各管一个岗位。表里只写了另一个岗位的人（如只写了资料专员），本岗位没人时上传人按自己的岗位加入，不再被当成“别人的订单”
                            $selfRoleKey = '';
                            if ($groupHasNamedPerson && $selectedBusiness === '商标' && $selfGroup === 'technical') {
                                $selfTrademarkRole = ps_employee_default_role($selfId, '商标', 'technical');
                                if ($selfTrademarkRole === $peopleLabels['backend'] && !$back) $selfRoleKey = 'backend';
                                elseif ($selfTrademarkRole === $peopleLabels['frontend'] && !$front) $selfRoleKey = 'frontend';
                                if ($selfRoleKey !== '') { $groupHasNamedPerson = false; $record['warning'] .= ($record['warning'] ? '；' : '') . '表格只写了另一岗位的专员，本人按“' . $selfTrademarkRole . '”加入此订单'; }
                            }
                            if (!$groupHasNamedPerson) {
                                $record['people'][$selfGroup][$selfId] = ['id' => $selfId, 'role' => ps_employee_default_role($selfId, $selectedBusiness, $selfGroup) ?? ($selfGroup
    === 'technical' ? $peopleLabels['frontend'] : '客服'), 'name' => $actorName ?? '本人'];
                                if ($selfGroup === 'technical') $front[$selfId] = true; else $cs[$selfId] = true;
                            }
                        }
                    }
                    if (!$record['people']['customer_service'] && !$record['people']['technical']) throw new RuntimeException($departmentMode ? '此行没有售后参与人，请在上传前选择默认参与人，或在表格填写姓名'
    : '至少需要匹配一名客服或技术参与人');
                    if ($actor['role'] !== 'finance' && !$departmentMode && !ps_management_can_business($actor, $selectedBusiness)) {
                        $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                        // 代写类：编辑员（客服账号）在代写订单上是“对接编辑”，本人在任一组即可
                        if (!empty($businessDefinition['import_cost']) && !isset($record['people'][$group][(int)$actor['employee_id']]) && isset($record['people']['technical'][(int)
    $actor['employee_id']])) $group = 'technical';
                        // 上传人只要在这行订单里（客服或技术任一栏）就算本人订单；另一栏写别人是正常业务（如环境配置同事写客服、技术是前后端同事）。
                        $otherGroup = $group === 'technical' ? 'customer_service' : 'technical';
                        if (!isset($record['people'][$group][(int)$actor['employee_id']]) && isset($record['people'][$otherGroup][(int)$actor['employee_id']])) $group = $otherGroup
    ;
                        if (!isset($record['people'][$group][(int)$actor['employee_id']])) {
                            $listed = implode('、', array_column($record['people'][$group], 'name'));
                            if ($listed !== '') $record['skip_status'] = '他人订单';
                            throw new RuntimeException($listed !== '' ? '此行' . ($group === 'technical' ? '技术' : '客服') . '是“' . $listed . '”，不是本人：请由本人上传或交财务导入，本人的订单不受影响'
    : '此行未写本人为' . ($group === 'technical' ? '技术' : '客服') . '，不可导入他人订单');
                        }
                        // 商标：资料专员、提交专员各自上传同一单，技术组按岗位区分，同岗位无人即可加入
                        $trademarkRoleOpen = function () use ($selectedBusiness, $group, $record, $actor) { return $selectedBusiness === '商标' && $group === 'technical' && ps_trademark_technical_role_open
    ((int)$record['existing_order_id'], (int)$actor['employee_id'], $record['people']['technical'][(int)$actor['employee_id']]['role']) !== null; };
                        if (!empty($record['attach_check']) && empty($record['join_parent']) && ps_import_group_taken((int)$record['existing_order_id'], $group) && !$trademarkRoleOpen()) {
                            // 同一订单多位客服分摊：非商标、原单客服权重均分时，本人作为共同客服加入，不必再交财务核对
                            $backendJoin = $group === 'technical' && ps_import_backend_join_open((int)$record['existing_order_id'], (string)$record['people']['technical'][(int)$actor['employee_id']]['role'], $selectedBusiness);
                            if ($backendJoin) {
                                $record['join_group'] = 'backend';
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '此单已有前端、还没有后端，本人将作为后端加入（前端不变），请核对';
                            } elseif ($group === 'technical' && $selectedBusiness !== '商标' && !pos_parent_of((int)$record['existing_order_id']) && poj_group_weights_equal((int)$record['existing_order_id'], 'technical') && !empty($fixJoin[$record['line']])) {
                                // 上传人在预览里勾选了“我也参与这单”：作为共同技术加入，该组分成按人数均分（订单审核时财务核对）
                                $record['join_group'] = 'technical';
                                $record['join_role'] = $record['people']['technical'][(int)$actor['employee_id']]['role'];
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '本人将作为共同技术加入此单，该订单技术分成按人数均分，财务审核订单时会核对';
                            } elseif ($group !== 'customer_service' || $selectedBusiness === '商标' || pos_parent_of((int)$record['existing_order_id']) || !poj_group_weights_equal((int)$record['existing_order_id'], $group)) {
                                if ($group === 'technical' && $selectedBusiness !== '商标' && !pos_parent_of((int)$record['existing_order_id']) && poj_group_weights_equal((int)$record['existing_order_id'], 'technical')) {
                                    $joinNames = db()->prepare("SELECT e.name FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=? AND p.commission_group='technical' ORDER BY p.id"); $joinNames->execute([(int)$record['existing_order_id']]);
                                    $record['can_join'] = ['group' => 'technical', 'names' => implode('、', $joinNames->fetchAll(PDO::FETCH_COLUMN))];
                                }
                                throw new RuntimeException('该订单号已存在且已有' . ($group === 'technical' ? '对接编辑 / 技术' : '客服') . '，本人尚未被关联；' . (!empty($record['can_join']) ? '如果本人确实参与了这单，在下面勾选“我也参与这单”后重新核对；否则请由财务核对' : '请由财务核对'));
                            } else {
                                $record['join_group'] = $group;
                                $record['join_role'] = $record['people'][$group][(int)$actor['employee_id']]['role'];
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '此单已有其他客服，本人将作为共同客服加入，该订单客服分成按人数均分，请核对';
                            }
                        }
                    }
                    if (!$existing && $actor['role'] === 'customer_service' && ps_business_requires_technical($selectedBusiness)) {
                        if (!$record['people']['technical'] && !$unknownTech) {
                            // 预览页里直接选接单技术，不必改表格重新上传
                            $pickedTechnical = (int)($fixTechnical[$record['line']] ?? 0);
                            $technicianChoices = poj_technician_choices($selectedBusiness);
                            if (($fixTechnical[$record['line']] ?? '') === 'outsource') {
                                // 外包技术（外部开发者）：不记本公司技术，外包费用作为成本入账（财务核对）
                                ps_ensure_outsource_templates();
                                $osq = db()->prepare("SELECT * FROM project_cost_templates WHERE category='outsourcing' AND is_active=1 AND requires_proof=0 AND (business_scope='' OR business_scope=?) ORDER BY business_scope DESC,id DESC LIMIT 1");
                                $osq->execute([ps_business_normalize($selectedBusiness)]); $osTemplate = $osq->fetch();
                                if (!$osTemplate) { $record['need_technical'] = true; throw new RuntimeException('当前业务还没有配置“外包”成本，请联系财务在成本中心添加，或选本公司技术'); }
                                $osAmount = round((float)($fixOutsourceAmounts[$record['line']] ?? 0), 2);
                                if (ps_outsource_is_actual($osTemplate) && $osAmount <= 0) { $record['need_technical'] = true; throw new RuntimeException('选了“外包技术”，请填写付给外包的实际费用（元）'); }
                                $record['outsource'] = ['template_id' => (int)$osTemplate['id'], 'amount' => $osAmount];
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '外包技术：' . $osTemplate['name'] . (ps_outsource_is_actual($osTemplate) ? ' ¥' . number_format($osAmount, 2, '.', '') : '') . '，成本待财务核对';
                            } elseif ($pickedTechnical && isset($technicianChoices[$pickedTechnical])) {
                                $record['people']['technical'][$pickedTechnical] = ['id' => $pickedTechnical, 'role' => ps_employee_default_role($pickedTechnical, $selectedBusiness, 'technical') ?? $peopleLabels['frontend'], 'name' => $technicianChoices[$pickedTechnical]];
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '接单技术由上传人在预览中选择：' . $technicianChoices[$pickedTechnical];
                            } else {
                                $record['need_technical'] = true;
                                throw new RuntimeException('客服导入新订单须指定接单技术（在本行下拉框直接选择即可，无需改表格）');
                            }
                        }
                        // 技术列写的是资料员等非合作人员（如负责传资料的同事）：照常导入，订单暂不记技术
                        if (!$record['people']['technical'] && empty($record['outsource'])) $record['warning'] .= ($record['warning'] ? '；' : '') . '订单暂无接单技术，如需记技术提成请财务在结算单补录'
    ;
                        // 技术有有效账号但未开通本业务：照常导入，提示财务开通业务，避免客服整批订单被拦；完全没有账号才拦
                        $techAccount = $techAccount ?? db()->prepare('SELECT 1 FROM project_users WHERE employee_id=? AND is_active=1 LIMIT 1');
                        $techNotOpen = [];
                        foreach ($record['people']['technical'] as $person) {
                            if (ps_active_employee_for_business($person['id'], 'technical', $selectedBusiness)) continue;
                            $techAccount->execute([(int)$person['id']]);
                            if (!$techAccount->fetchColumn()) throw new RuntimeException('接单技术“' . $person['name'] . '”还没有开通项目账号，请联系财务开通'
    );
                            $techNotOpen[] = $person['name'];
                        }
                        if ($techNotOpen) $record['warning'] .= ($record['warning'] ? '；' : '') . '技术' . implode('、', $techNotOpen) . '的账号未开通“' . $selectedBusiness
    . '”技术业务，已照常记录，请财务在账号管理中开通以免漏算提成';
                    }
                    if ($resourceSelection && !$record['resource_locked'] && $usesProgram && $record['program_name'] !== '') {
                        $programSuggestion = ps_intake_program_suggestion($record['program_name'], $programTemplates, (bool)preg_match('/^(客户提供|客户自备|客户自有|客户的|第三方|客户第三方)/u', trim((string)$record['domain_used'])), trim(($record['site_key'] ?? '') . ' ' . ($record['resource_note'] ?? '') . ' ' . $lookup($row, 'detail:website_url')));
                        if ($programSuggestion) $record['program_template_id'] = (int)$programSuggestion['id'];
                        else $record['warning'] .= ($record['warning'] ? '；' : '') . '程序名称“' . $record['program_name'] . '”未唯一匹配套餐，请在下方选择'
    ;
                    }
                    $record['domain_mode'] = !$businessDefinition['resources'] ? 'none' : ($resourceSelection && !$record['resource_locked'] ? ($hasDomainColumn ? ps_import_domain_mode
    ($record['domain_used']) : 'pending') : 'pending');
                    // 证书 / 加语言 / 补差价 / 修改网站这类附加行没有域名：域名来源留空不用选，直接记“无需另计”
                    if ($record['domain_mode'] === '' && $hasDomainColumn && (!empty($isSiteAddon) || preg_match('/ssl|证书|加语言|加中文|补差价|纯利润|修改网站|网站修改|域名注册/iu', $lookup($row, 'business') . ' ' . $lookup($row, 'program_name') . ' ' . $record['resource_note']))) $record['domain_mode'] = 'none';
                    // 程序套餐已含空间和域名；表格未写域名时不再要求另选域名。
                    $pureDomainProgram = false; // “纯域名注册”套餐的价格就是域名成本，不再另选域名模板
                    if (!empty($record['program_template_id'])) foreach ($programTemplates as $pt) if ((int)$pt['id'] === (int)$record['program_template_id'] && $pt['name'] === '纯域名注册') $pureDomainProgram = true;
                    if ((in_array($record['domain_mode'], ['', 'pending'], true) || ($pureDomainProgram && $record['domain_mode'] === 'template')) && $record['program_template_id'] && $resourceSelection && !$record['resource_locked']) $record['domain_mode'
    ] = 'none';
                    // “空间+域名”程序套餐（如 JSP展示中级版 1年 空间+域名 ¥360）本身已含域名：域名来源写“我们注册”也不能再另计一条域名成本（会变成 360 + 58 = 418）
                    if ($record['domain_mode'] === 'template' && !empty($record['program_template_id']) && $resourceSelection && !$record['resource_locked']) {
                        foreach ($programTemplates as $pt) if ((int)$pt['id'] === (int)$record['program_template_id'] && mb_strpos((string)$pt['specification'], '空间+域名') !== false) { $record['domain_mode'] = 'none'; $record['warning'] .= ($record['warning'] ? '；' : '') . '程序套餐已含域名，不再另计域名成本'; break; }
                    }
                    if ($record['domain_mode'] === 'pending' && $resourceSelection && !$record['resource_locked']) $record['warning'] .= ($record['warning'] ? '；' : '') . '表格没有域名列，资源留待技术在结算单确认'
    ;
                    if ($resourceSelection && !$record['resource_locked'] && $record['domain_mode'] === 'template') {
                        $suggestion = ps_intake_domain_suggestion(($record['site_key'] ?? '') . ' ' . $record['resource_note'], $domainTemplates, mb_strpos((string)($record['order_kind'] ?? ''), '续费') !== false ? '续费' : '注册');
                        if ($suggestion) $record['domain_template_id'] = (int)$suggestion['id'];
                        else { $record['status'] = '需选择域名模板'; $record['warning'] = '表格只写“是”，未能唯一确认域名规格与周期'; }
                    } elseif ($resourceSelection && !$record['resource_locked'] && $record['domain_mode'] === '') {
                        $record['status'] = '需确认域名';
                        $record['warning'] = '域名来源没填：请选择“客户提供”或“我们注册”（我们注册会按域名后缀计入域名成本）'; // 在线表格 / 模板里是下拉，预览页也可直接改选
                    }
                    // 空间来源写“客户自有空间”：系统照常按程序套餐 / 成本列核算，提示财务核对空间成本（套餐成本里含空间）
                    if (preg_match('/客户|自有|自备/u', (string)($record['details']['space_source'] ?? ''))) $record['warning'] .= ($record['warning'] ? '；' : '') . '空间来源为客户自有空间，空间成本请财务核对';
                    if ($record['existing_order_id']) $record['warning'] .= ($record['warning'] ? '；' : '') . '将补充到同一订单号，不会新建订单';
                    if (is_numeric($record['ssl_used']) && (float)$record['ssl_used'] > 0) $record['warning'] .= ($record['warning'] ? '；' : '') . 'SSL 实际成本需创建后补凭证'
    ;
