<?php

        // 读取旧配置，防止表单字段被浏览器清空时数据丢失
        $oldConfig = SalaryCalculator::readModulesConfig($employee_id);
        $oldModules = [];
        if ($oldConfig && !empty($oldConfig['modules'])) {
            foreach ($oldConfig['modules'] as $om) {
                $oldModules[$om['name']] = $om['config'] ?? [];
            }
        }
        // 收集模块列表
        $modules = [];
        if (!empty($_POST['mod_name'])) {
            foreach ($_POST['mod_name'] as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $type   = $_POST['mod_type'][$i] ?? 'standard';
                $enabled = isset($_POST['mod_enabled'][$i]);
                // 模块名称：优先使用表单中的 _name，否则使用默认的 mod_name
                $customName = trim(($_POST['mod_cfg']['_name'][$i] ?? '') ?: '');
                $modName = $customName ?: $name;
                // 根据类型收集配置字段
                $typeInfo = SalaryCalculator::getAvailableTypes()[$type] ?? [];
                $cfg     = [];
                foreach ($typeInfo['fields'] ?? [] as $f) {
                    $val = $_POST['mod_cfg'][$f['key']][$i] ?? ($f['default'] ?? 0);
                    // 阶梯类型特殊处理：tiers 数组
                    if ($type === 'tiered' || $type === 'mixed') {
                        $cfg[$f['key']] = $val;  // tiered 的 fields 为空， tiers 在下面单独处理
                    } else {
                        $cleanVal = is_numeric($val) ? (float)$val : trim($val);
                        // 防止数据丢失：表单值为空时保留旧配置的值
                        if ($cleanVal === '' && isset($oldModules[$modName][$f['key']]) && $oldModules[$modName][$f['key']] !== '') {
                            $cleanVal = $oldModules[$modName][$f['key']];
                        }
                        // 只有当值不为空字符串时才保存（避免保存空的 min_amount 和 max_amount）
                        if ($cleanVal !== '') {
                            $cfg[$f['key']] = $cleanVal;
                        }
                    }
                }
                // 阶梯类型的 tiers 数据
                if (($type === 'tiered' || $type === 'mixed') && !empty($_POST['t_threshold'][$i])) {
                    $cfg['tiers'] = [];
                    foreach ($_POST['t_threshold'][$i] as $ti => $th) {
                        $rt = (float)($_POST['t_rate'][$i][$ti] ?? 0);
                        $subsidy = (float)($_POST['t_subsidy'][$i][$ti] ?? 0);
                        $cfg['tiers'][] = ['threshold' => (float)$th, 'rate' => $rt, 'subsidy' => $subsidy];
                    }
                    usort($cfg['tiers'], fn($a,$b)=>$a['threshold']<=>$b['threshold']);
                }
                // 阶梯固定服务费类型的 tiers 数据
                if ($type === 'base_salary_tiered' && !empty($_POST['t_threshold'][$i])) {
                    $cfg['tiers'] = [];
                    foreach ($_POST['t_threshold'][$i] as $ti => $th) {
                        $baseAmount = (float)($_POST['t_base_amount'][$i][$ti] ?? 0);
                        $cfg['tiers'][] = ['threshold' => (float)$th, 'base_amount' => $baseAmount];
                    }
                    usort($cfg['tiers'], fn($a,$b)=>$a['threshold']<=>$b['threshold']);
                }

                $modules[] = [
                    'name'    => $modName,
                    'type'    => $type,
                    'enabled' => $enabled,
                    'config'  => $cfg,
                ];
            }
        }

        if (!empty($modules)) {
            $deptShare = isset($_POST['dept_share']) ? 1 : 0;
            if (SalaryCalculator::saveModulesConfig($employee_id, $modules, $deptShare)) {
                $count = count($modules);
                $success = "已保存 {$count} 个项目报酬模块" . ($deptShare ? '' : '（不参与部门订单项目分成）');
            } else {
                $lastErr = SalaryCalculator::getLastError();
                $error = '保存失败' . ($lastErr ? '：' . $lastErr : '');
            }
        } else {
            $error = '请至少添加一个模块';
        }
    