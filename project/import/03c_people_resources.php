<?php
                    // 技术人员上传时，客服列写了非人名（如“大连”）不拦整行：忽略并提示；客服上传仍严格校验本人姓名
                    $unknownCs = [];
                    if ($actor['role'] === 'technical' && !$departmentMode) {
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
                    if (!empty($record['join_parent'])) { // 同一笔销售：只记本人加入原订单的那一份，不沿用表格里写的其他人
                        $joinSelfId = (int)$actor['employee_id'];
                        $record['people'] = ['technical' => [], 'customer_service' => []];
                        $record['people'][$record['join_target']['group']][$joinSelfId] = ['id' => $joinSelfId, 'role' => $record['join_target']['role'], 'name' => $actorName ?? '本人'];
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
                            if ($group !== 'customer_service' || $selectedBusiness === '商标' || pos_parent_of((int)$record['existing_order_id']) || !poj_group_weights_equal((int)$record['existing_order_id'], $group)) throw new RuntimeException
    ('该订单号已存在且已有' . ($group === 'technical' ? '对接编辑 / 技术' : '客服') . '，本人尚未被关联；请由财务核对');
                            $record['join_group'] = $group;
                            $record['join_role'] = $record['people'][$group][(int)$actor['employee_id']]['role'];
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '此单已有其他客服，本人将作为共同客服加入，该订单客服分成按人数均分，请核对';
                        }
                    }
                    if (!$existing && $actor['role'] === 'customer_service' && ps_business_requires_technical($selectedBusiness)) {
                        if (!$record['people']['technical'] && !$unknownTech) {
                            // 预览页里直接选接单技术，不必改表格重新上传
                            $pickedTechnical = (int)($fixTechnical[$record['line']] ?? 0);
                            $technicianChoices = poj_technician_choices($selectedBusiness);
                            if ($pickedTechnical && isset($technicianChoices[$pickedTechnical])) {
                                $record['people']['technical'][$pickedTechnical] = ['id' => $pickedTechnical, 'role' => ps_employee_default_role($pickedTechnical, $selectedBusiness, 'technical') ?? $peopleLabels['frontend'], 'name' => $technicianChoices[$pickedTechnical]];
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '接单技术由上传人在预览中选择：' . $technicianChoices[$pickedTechnical];
                            } else {
                                $record['need_technical'] = true;
                                throw new RuntimeException('客服导入新订单须指定接单技术（在本行下拉框直接选择即可，无需改表格）');
                            }
                        }
                        // 技术列写的是资料员等非合作人员（如负责传资料的同事）：照常导入，订单暂不记技术
                        if (!$record['people']['technical']) $record['warning'] .= ($record['warning'] ? '；' : '') . '订单暂无接单技术，如需记技术提成请财务在结算单补录'
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
                        $programSuggestion = ps_intake_program_suggestion($record['program_name'], $programTemplates);
                        if ($programSuggestion) $record['program_template_id'] = (int)$programSuggestion['id'];
                        else $record['warning'] .= ($record['warning'] ? '；' : '') . '程序名称“' . $record['program_name'] . '”未唯一匹配套餐，请在下方选择'
    ;
                    }
                    $record['domain_mode'] = !$businessDefinition['resources'] ? 'none' : ($resourceSelection && !$record['resource_locked'] ? ($hasDomainColumn ? ps_import_domain_mode
    ($record['domain_used']) : 'pending') : 'pending');
                    // 程序套餐已含空间和域名；表格未写域名时不再要求另选域名。
                    if (in_array($record['domain_mode'], ['', 'pending'], true) && $record['program_template_id'] && $resourceSelection && !$record['resource_locked']) $record['domain_mode'
    ] = 'none';
                    if ($record['domain_mode'] === 'pending' && $resourceSelection && !$record['resource_locked']) $record['warning'] .= ($record['warning'] ? '；' : '') . '表格没有域名列，资源留待技术在结算单确认'
    ;
                    if ($resourceSelection && !$record['resource_locked'] && $record['domain_mode'] === 'template') {
                        $suggestion = ps_intake_domain_suggestion($record['resource_note'], $domainTemplates);
                        if ($suggestion) $record['domain_template_id'] = (int)$suggestion['id'];
                        else { $record['status'] = '需选择域名模板'; $record['warning'] = '表格只写“是”，未能唯一确认域名规格与周期'; }
                    } elseif ($resourceSelection && !$record['resource_locked'] && $record['domain_mode'] === '') {
                        $record['status'] = '需确认域名';
                        $record['warning'] = '域名使用未明确写是/否，请在下方选择';
                    }
                    if ($record['existing_order_id']) $record['warning'] .= ($record['warning'] ? '；' : '') . '将补充到同一订单号，不会新建订单';
                    if (is_numeric($record['ssl_used']) && (float)$record['ssl_used'] > 0) $record['warning'] .= ($record['warning'] ? '；' : '') . 'SSL 实际成本需创建后补凭证'
    ;
