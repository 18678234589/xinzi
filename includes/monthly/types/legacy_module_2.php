<?php

            $want = trim((string)($p['module_name'] ?? ''));
            $module = null;
            $cfg = SalaryCalculator::readModulesConfig($eid);
            if ($want !== '' && $cfg && !empty($cfg['modules'])) {
                foreach ($cfg['modules'] as $mod) {
                    if (!($mod['enabled'] ?? true)) continue;
                    if (trim((string)($mod['name'] ?? '')) === $want) { $module = $mod; break; }
                }
            }
            if ($module === null && is_array($p['module'] ?? null)) $module = $p['module'];