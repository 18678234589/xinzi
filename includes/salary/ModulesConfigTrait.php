<?php
trait ModulesConfigTrait
{
    public static function saveModulesConfig($employeeId, $modules, $deptShare = 1)
    {
        $dir = self::dir();
        self::$lastError = '';
        // 确保目录可写
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            self::$lastError = "算法目录不可写或不存在：{$dir}";
            error_log("SalaryCalculator: algorithms directory not writable: " . $dir);
            return false;
        }

        // 对比新旧配置，模块名变更时自动同步订单 project
        $oldConfig = self::readModulesConfig($employeeId);
        if ($oldConfig && !empty($oldConfig['modules'])) {
            $oldNames = [];
            foreach ($oldConfig['modules'] as $m) {
                $oldNames[$m['name']] = $m;
            }
            foreach ($modules as $newMod) {
                $newName = $newMod['name'] ?? '';
                if ($newName === '') continue;
                // 新模块名不在旧配置里，检查是否有旧模块名出现在订单 project 中但新名没有
                // 这种情况下无法自动判断对应关系，跳过
            }
            // 按模块位置顺序匹配旧→新（假设用户不会增删模块只改名）
            $oldMods = $oldConfig['modules'];
            $renameMap = []; // 旧名 => 新名
            $count = min(count($oldMods), count($modules));
            for ($i = 0; $i < $count; $i++) {
                $oldName = $oldMods[$i]['name'] ?? '';
                $newName = $modules[$i]['name'] ?? '';
                if ($oldName !== '' && $newName !== '' && $oldName !== $newName) {
                    $renameMap[$oldName] = $newName;
                }
            }
            // 同步数据库订单 project
            if (!empty($renameMap)) {
                try {
                    foreach ($renameMap as $oldName => $newName) {
                        $stmt = db()->prepare("UPDATE orders SET project = ? WHERE employee_id = ? AND project = ?");
                        $stmt->execute([$newName, $employeeId, $oldName]);
                    }
                    error_log("SalaryCalculator: synced project names for employee $employeeId: " . json_encode($renameMap, JSON_UNESCAPED_UNICODE));
                } catch (Exception $e) {
                    error_log("SalaryCalculator: failed to sync project names: " . $e->getMessage());
                }
            }
        }

        // 保存前自动补全缺失字段（如新加的 service_fee_rate），确保旧配置升级后字段完整
        $allTypes = self::getAvailableTypes();
        foreach ($modules as &$mod) {
            $type = $mod['type'] ?? 'standard';
            $typeDef = $allTypes[$type] ?? null;
            if ($typeDef && !empty($typeDef['fields'])) {
                if (!isset($mod['config']) || !is_array($mod['config'])) {
                    $mod['config'] = [];
                }
                foreach ($typeDef['fields'] as $f) {
                    $key = $f['key'] ?? '';
                    if ($key === '' || $key === '_name') continue;
                    if (!array_key_exists($key, $mod['config'])) {
                        $mod['config'][$key] = $f['default'] ?? '';
                    }
                }
            }
        }
        unset($mod);

        $data = ['modules' => $modules, 'dept_share' => $deptShare, 'updated_at' => date('Y-m-d H:i:s')];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            self::$lastError = "配置编码失败（数据格式异常）";
            error_log("SalaryCalculator: json_encode failed for employee $employeeId");
            return false;
        }
        $file = self::getConfigFile($employeeId);
        // 先写临时文件，再 rename 覆盖目标文件。
        // 目录可写时，rename 的“删除旧文件”是目录级操作，
        // 不依赖旧文件自身权限，可绕过“旧文件所有者与 PHP 进程用户不同导致无法覆盖”的问题。
        $tmp = $file . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
        $result = file_put_contents($tmp, $json);
        if ($result === false) {
            self::$lastError = "写入临时文件失败：{$tmp}";
            error_log("SalaryCalculator: failed to write temp config to $tmp");
            return false;
        }
        // 跨平台 rename 覆盖
        if (!@rename($tmp, $file)) {
            // rename 失败（如跨设备），退回到复制+删除临时文件
            if (!@copy($tmp, $file)) {
                @unlink($tmp);
                self::$lastError = "写入配置文件失败：{$file}";
                error_log("SalaryCalculator: failed to write config to $file");
                return false;
            }
            @unlink($tmp);
        }
        // 尽量放宽权限，便于后续覆盖写入
        @chmod($file, 0664);

        if ($deptShare) {
            // 参与部门订单项目分成：部门汇总行已在 orders 表中，结算时通过 __dept_modules__ 虚拟拆分，
            // 新合作人员配置后自动生效，无需物理同步拆分行
        } else {
            // 不参与部门订单项目分成：清理旧的物理拆分行（历史数据，新数据不再产生拆分行）
            try {
                $del = db()->prepare("UPDATE orders SET is_deleted=1 WHERE employee_id = ? AND raw_data LIKE '%__from_dept__%'");
                $del->execute([$employeeId]);
                $deleted = $del->rowCount();
                if ($deleted > 0) {
                    error_log("SalaryCalculator: removed {$deleted} legacy dept split orders for employee {$employeeId} (dept_share=0)");
                }
            } catch (Exception $e) {
                error_log("SalaryCalculator: failed to remove dept orders: " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * 读取多模块配置
     */
    public static function readModulesConfig($employeeId)
    {
        $file = self::getConfigFile($employeeId);
        if (!file_exists($file)) {
            return null; // 无配置 = 使用默认算法
        }
        $data = json_decode(file_get_contents($file), true);
        if (!$data || empty($data['modules'])) {
            return null;
        }
        return $data;
    }

    /**
     * 删除自定义配置
     */
    public static function deleteCustomConfig($employeeId)
    {
        $ok = true;
        $cf = self::getConfigFile($employeeId);
        if (file_exists($cf)) $ok = @unlink($cf) && $ok;
        $lf = self::getLegacyFile($employeeId);
        if (file_exists($lf)) $ok = @unlink($lf) && $ok;
        return $ok;
    }

}
