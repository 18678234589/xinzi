<?php
            if (!$preview || $previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness || $previewScope !== $scope) throw new RuntimeException('预览已失效，请重新上传'
    );
            pse_ensure();
            $choices = $_POST['domain_choice'] ?? [];
            $serverChoices = $_POST['server_template_id'] ?? [];
            $programChoices = $_POST['program_choice'] ?? [];
            $kindChoices = $_POST['kind_choice'] ?? [];
            $commitKinds = ps_business_order_kinds($selectedBusiness);
            $kindMissingLines = [];
            $ready = [];
            foreach ($preview as $row) {
                if (empty($row['base_valid'])) { $skipped++; continue; }
                $line = (int)$row['line'];
                $pickedKind = trim((string)($kindChoices[$line] ?? ''));
                if ($pickedKind !== '' && in_array($pickedKind, $commitKinds, true)) $row['order_kind'] = $pickedKind;
                if (($row['order_kind'] ?? '') === '' && !empty($businessDefinition['kind_required'])) { $skipped++; $kindMissingLines[] = $line % 10000; continue; }
                if ($actor['role'] !== 'finance' && !$departmentMode && !ps_management_can_business($actor, $selectedBusiness)) {
                    $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                    if (!empty($businessDefinition['import_cost']) && !isset($row['people'][$group][(int)$actor['employee_id']]) && isset($row['people']['technical'][(int)$actor['employee_id'
    ]])) $group = 'technical';
                    // 与预览一致：上传人在客服或技术任一栏即可，另一栏可以是别人
                    $otherGroup = $group === 'technical' ? 'customer_service' : 'technical';
                    if (!isset($row['people'][$group][(int)$actor['employee_id']]) && isset($row['people'][$otherGroup][(int)$actor['employee_id']])) $group = $otherGroup;
                    if (!isset($row['people'][$group][(int)$actor['employee_id']])) throw new RuntimeException('第 ' . $line . ' 行不属于当前登录人员，请重新上传核对'
    );
                }
                $needsResources = $resourceSelection && empty($row['resource_locked']);
                // 自动导入时资源未填写也先建单，留“待技术确认”，绝不擅自选免费资源或估算成本。
                if ($needsResources && !empty($_POST['auto_import']) && ($row['domain_mode'] ?? '') === '' && !isset($choices[$line])) $row['domain_mode'] = 'pending';
                $programId = $needsResources && $usesProgram ? (int)($programChoices[$line] ?? $row['program_template_id']) : 0;
                $programTemplate = $programId > 0 ? ps_intake_template($programId, 'program') : null;
                $choice = $needsResources ? (string)($choices[$line] ?? ($row['domain_mode'] === 'none' || ($programTemplate && in_array($row['domain_mode'], ['', 'pending'], true)
    ) ? 'none' : ($row['domain_mode'] === 'pending' ? 'pending' : ($row['domain_template_id'] ?: '')))) : 'none';
                if ($choice === 'pending' && $programTemplate) $choice = 'none';
                if ($choice === 'pending' && $row['domain_mode'] !== 'pending') $choice = '';
                if ($choice === 'pending') { $ready[] = [$row, null, null, $programTemplate, 'pending']; continue; }
                if ($choice !== 'none' && !ctype_digit($choice)) { $skipped++; continue; }
                $domainTemplate = $choice === 'none' ? null : ps_intake_template((int)$choice, 'domain');
                if ($needsResources && $row['domain_mode'] === 'template' && $choice === 'none') throw new RuntimeException('第 ' . $line . ' 行写了使用域名，不能选无需域名；请修正表格或选择模板'
    );
                if ($needsResources && $row['domain_mode'] === 'none' && $choice !== 'none') throw new RuntimeException('第 ' . $line . ' 行写了无需域名，不能选择域名模板；请修正表格'
    );
                $serverId = $needsResources ? (int)($serverChoices[$line] ?? 0) : 0;
                $serverTemplate = $serverId > 0 ? ps_intake_template($serverId, 'server') : null;
                $ready[] = [$row, $domainTemplate, $serverTemplate, $programTemplate, null];
            }
            if (!$ready) throw new RuntimeException($kindMissingLines ? '请先为每行选择订单类型（可用“全部设为”一次选好）' : '没有已核对可导入的订单；请先补齐域名选项'
    );
            $pdo = db();
            // 可选的重复单提醒会懒建表；MySQL DDL 会隐式提交，必须在导入事务之前初始化。
            if (is_file((dirname((dirname(__DIR__, 1)), 1)) . '/../includes/dup_feedback.php')) { require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/dup_feedback.php'
    ; if (function_exists('pd_ensure')) pd_ensure(); }
            $nested = $pdo->inTransaction();
            if ($nested) $pdo->exec('SAVEPOINT project_order_import');
            else $pdo->beginTransaction();
