<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/SalaryCalculator.php';
require_login();
require_once __DIR__ . '/../classes/SimpleXLSX.php';

$page_title = '订单上传';
$success = '';
$error = '';

// 是否通过员工管理跳转过来，锁定某员工
$locked_employee_id = (int)($_GET['employee_id'] ?? 0);
$locked_employee = null;
if ($locked_employee_id > 0) {
    $locked_employee = get_employee($locked_employee_id);
    if (!$locked_employee) {
        $locked_employee_id = 0;
    }
}

// AJAX接口：获取员工的提成模块列表
$ajax_employee_id = (int)($_GET['employee_id'] ?? 0);
if (($_GET['ajax'] ?? '') === 'modules' && $ajax_employee_id > 0) {
    header('Content-Type: application/json; charset=utf-8');
    $modCfg = SalaryCalculator::readModulesConfig($ajax_employee_id);
    $result = [];
    if ($modCfg && !empty($modCfg['modules'])) {
        foreach ($modCfg['modules'] as $m) {
            if (in_array($m['type'], ['standard','tiered','per_order','profit_commission','referral_order']) && ($m['enabled'] ?? true)) {
                $extra = '';
                if ($m['type'] === 'standard' && isset($m['config']['rate']) && $m['config']['rate'] !== '') {
                    $rVal = (float)$m['config']['rate'];
                    $extra = ' (' . rtrim(rtrim(number_format($rVal * 100, 4, '.', ''), '0'), '.') . '%)';
                } elseif ($m['type'] === 'profit_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                    $cVal = (float)$m['config']['commission_rate'];
                    $extra = ' (成本提成' . rtrim(rtrim(number_format($cVal * 100, 4, '.', ''), '0'), '.') . '%)';
                } elseif ($m['type'] === 'tiered') {
                    $extra = ' (阶梯)';
                } elseif ($m['type'] === 'per_order') {
                    $extra = ' (¥' . ($m['config']['per_amount'] ?? 0) . '/笔)';
                } elseif ($m['type'] === 'referral_order') {
                    $extra = ' (每单补助¥' . ($m['config']['subsidy'] ?? 0) . ')';
                }
                $result[] = ['name' => $m['name'], 'label' => $m['name'] . $extra];
            }
        }
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'manual_add') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $order_amount = (float)($_POST['order_amount'] ?? 0);
        $order_date = $_POST['order_date'] ?? '';
        $project = trim($_POST['project'] ?? '');

        if ($employee_id <= 0 || $order_amount < 0 || $order_date === '') {
            $error = '请填写完整且有效的订单信息';
        } else {
            try {
                // 自动创建 project 字段（如果表结构还没升级）
                ensureProjectColumn();
                $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, project) VALUES (?, ?, ?, ?)");
                $stmt->execute([$employee_id, $order_amount, $order_date, $project]);
                $success = '订单添加成功' . ($project ? "（项目: {$project}）" : '');
                if ($locked_employee_id === 0) { $locked_employee_id = $employee_id; $locked_employee = get_employee($employee_id); }
            } catch (PDOException $ex) {
                $error = '添加失败: ' . $ex->getMessage();
            }
        }
    } elseif ($action === 'upload') {
        $csvData = trim($_POST['csv_data'] ?? '');
        $hasFile = isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK;

        if ($csvData === '' && !$hasFile) {
            $error = '请选择要上传的文件';
        } else {
            $order_scope = in_array($_POST['order_scope'] ?? '', ['personal','department']) ? $_POST['order_scope'] : 'personal';
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $dept_name   = trim($_POST['dept_name'] ?? '');
            $upload_month = trim($_POST['upload_month'] ?? ''); // 新增：上传时指定的归属月份

            // 部门订单：employee_id 可以为0，但需要有部门名
            if ($order_scope === 'personal' && $employee_id <= 0) {
                $error = '请先选择员工';
            } elseif ($order_scope === 'department' && $dept_name === '') {
                $error = '请选择部门';
            } elseif ($upload_month === '') {
                $error = '请选择订单归属月份';
            } else {
                try {
                    ensureProjectColumn();
                    $project = trim($_POST['upload_project'] ?? '');

                    // 部门订单多员工配置：[{employee_id, module}, ...]
                    $deptEmpModules = [];
                    if ($order_scope === 'department') {
                        $dem = trim($_POST['dept_emp_modules'] ?? '');
                        if ($dem !== '') {
                            $decoded = json_decode($dem, true);
                            if (is_array($decoded)) {
                                foreach ($decoded as $item) {
                                    $eid = (int)($item['employee_id'] ?? 0);
                                    $mod = trim($item['module'] ?? '');
                                    if ($eid > 0) $deptEmpModules[] = ['employee_id' => $eid, 'module' => $mod];
                                }
                            }
                        }
                    }
                    $rows = [];

                    if ($csvData !== '') {
                        // 前端 SheetJS 传来的 JSON 二维数组，每行是一个数组
                        $decoded = json_decode($csvData, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $row) {
                                if (is_array($row) && count(array_filter($row, fn($v) => trim($v) !== '')) > 0) {
                                    $rows[] = $row;
                                }
                            }
                        } else {
                            $error = '数据格式错误，请重新上传';
                        }
                    } else {
                        $file     = $_FILES['excel_file'];
                        $filename = $file['name'];
                        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $tmp      = $file['tmp_name'];
                        if (!in_array($ext, ['xlsx', 'csv'])) {
                            $error = '文件类型仅支持 .xls / .xlsx / .csv';
                        } else {
                            if ($ext === 'csv') {
                                $fp = fopen($tmp, 'r');
                                $bom = fread($fp, 3);
                                if ($bom !== "\xEF\xBB\xBF") { rewind($fp); }
                                while (($data = fgetcsv($fp)) !== false) { $rows[] = $data; }
                                fclose($fp);
                            } else {
                                $rows = SimpleXLSX::parse($tmp);
                            }
                        }
                    }

                    if ($error === '' && !empty($rows)) {
                        $firstRow = $rows[0];
                        $dataRows = array_slice($rows, 1);

                        // 原样保存表头（空列用"列N"占位）
                        $normalizedHeaders = [];
                        $colMap = [];
                        foreach ($firstRow as $ci => $cv) {
                            $cv = trim(preg_replace('/[\x00-\x1F\x80-\x9F\xEF\xBB\xBF\xC2\xA0]/u', '', $cv));
                            $normalizedHeaders[$ci] = $cv !== '' ? $cv : ('列' . ($ci + 1));
                            if ($cv !== '') $colMap[$cv] = $ci;
                        }

                        // 找价格/成本/时间列（模糊匹配，支持多种表头名称）
                        $idxPrice = null; $idxCost = null; $idxDate = null; $idxAmount = null;
                        foreach ($colMap as $k => $idx) {
                            // 订单金额列：优先匹配（美工部等直接有金额列的）
                            if ($idxAmount === null && (mb_strpos($k, '订单金额') !== false || mb_strpos($k, '金额') !== false)) $idxAmount = $idx;
                            // 价格列：支持"价格"或"售价"
                            if ($idxPrice === null && (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false)) $idxPrice = $idx;
                            // 成本列：支持"成本"或"总成本"
                            if ($idxCost  === null && (mb_strpos($k, '成本') !== false)) $idxCost  = $idx;
                            // 日期列：支持"时间"或"日期"
                            if ($idxDate  === null && (mb_strpos($k, '时间') !== false || mb_strpos($k, '日期') !== false)) $idxDate = $idx;
                        }
                        
                        // 校验：要么有订单金额列，要么有价格和成本列
                        if ($idxAmount === null && ($idxPrice === null || $idxCost === null)) {
                            $error = '表头缺少金额字段：需要"订单金额"列，或同时有"价格/售价"和"成本/总成本"列';
                            goto upload_done;
                        }

                        // 保存表头到 upload_batches
                        $batchHeaders = json_encode(array_values($normalizedHeaders), JSON_UNESCAPED_UNICODE);
                        $batchStmt = db()->prepare("INSERT INTO upload_batches (employee_id, headers) VALUES (?, ?)");
                        $batchStmt->execute([$employee_id, $batchHeaders]);

                        // 清空当月旧数据，避免重复上传导致数据累加
                        $monthPattern = $upload_month . '%';
                        if ($order_scope === 'department' && $dept_name !== '') {
                            // 部门订单：清该部门当月的汇总行 + 归属员工的拆分行
                            $del = db()->prepare("DELETE FROM orders WHERE DATE_FORMAT(order_date, '%Y-%m') = ? AND order_scope = 'department' AND employee_id = 0 AND raw_data LIKE ?");
                            $del->execute([$upload_month, '%\"__dept__\":\"' . $dept_name . '\"%']);
                            $del2 = db()->prepare("DELETE FROM orders WHERE DATE_FORMAT(order_date, '%Y-%m') = ? AND order_scope = 'personal' AND raw_data LIKE ?");
                            $del2->execute([$upload_month, '%\"__from_dept__\":\"' . $dept_name . '\"%']);
                        } else {
                            // 个人订单：清该员工当月的个人订单（排除部门拆分行 __from_dept__）
                            $del = db()->prepare("DELETE FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(order_scope, 'personal') = 'personal' AND (raw_data IS NULL OR raw_data NOT LIKE '%\"__from_dept__\"%')");
                            $del->execute([$employee_id, $upload_month]);
                        }

                        $inserted = 0; $skipped = 0;
                        $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, project, order_no, raw_data, is_abnormal, abnormal_reason, order_scope) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

                        db()->beginTransaction();
                        try {
                        foreach ($dataRows as $row) {
                            if (count(array_filter($row, fn($v) => trim($v) !== '')) === 0) continue;

                            // 计算订单金额
                            if ($idxAmount !== null) {
                                // 直接使用订单金额列（美工部等）
                                $amount = (float)preg_replace('/[^\d.\-]/', '', trim($row[$idxAmount] ?? ''));
                            } else {
                                // 金额 = 售价 - 成本
                                $price  = (float)preg_replace('/[^\d.\-]/', '', trim($row[$idxPrice] ?? ''));
                                $cost   = (float)preg_replace('/[^\d.\-]/', '', trim($row[$idxCost]  ?? ''));
                                $amount = $price - $cost;
                                // 部门订单：按配置文件扣除手续费：利润 = 售价 - 售价×手续费率 - 成本
                                if ($order_scope === 'department' && $dept_name !== '') {
                                    static $deptFeeMap = null;
                                    if ($deptFeeMap === null) {
                                        $deptFeeMap = include __DIR__ . '/../config/dept_fee.php';
                                        if (!is_array($deptFeeMap)) $deptFeeMap = [];
                                    }
                                    $feeRate = isset($deptFeeMap[$dept_name]) ? (float)$deptFeeMap[$dept_name] : 0.0;
                                    if ($feeRate > 0) {
                                        $amount = round($price - $price * $feeRate - $cost, 2);
                                    }
                                }
                            }

                            // 日期：优先使用上传时选择的归属月份，忽略Excel中的日期列
                            // 使用归属月份的第一天作为订单日期
                            $parsedDate = $upload_month . '-01';
                            $dateErr = '';

                            // 异常标记
                            $isAbn = 0; $abnReason = '';
                            // 退款订单标记：如果金额为负数，标记为退款订单，在raw_data中记录
                            $isRefund = ($amount < 0);

                            // 原样存储每列；部门订单额外存入部门名
                            $rawMap = [];
                            foreach ($normalizedHeaders as $ci => $hdr) {
                                $rawMap[$hdr] = $row[$ci] ?? '';
                            }
                            if ($order_scope === 'department') {
                                $rawMap['__dept__'] = $dept_name;
                            }
                            if ($isRefund) {
                                $rawMap['__is_refund__'] = '1'; // 标记为退款订单
                            }
                            // 提取订单号（用于后续店铺/员工订单对比）
                            $orderNo = extract_order_no($rawMap);

                            // 部门订单 employee_id 存 0（或为每个归属员工各插一条）
                            if ($order_scope === 'department' && !empty($deptEmpModules)) {
                                // 先插一条部门汇总记录（employee_id=0）
                                $stmt->execute([0, $amount, $parsedDate, $project, $orderNo, json_encode($rawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason, $order_scope]);
                                $isAbn ? $skipped++ : $inserted++;
                                // 再为每个归属员工插一条 personal 记录（标记来源于部门订单）
                                foreach ($deptEmpModules as $dem) {
                                    $demRawMap = $rawMap;
                                    $demRawMap['__from_dept__'] = $dept_name;
                                    $stmt->execute([$dem['employee_id'], $amount, $parsedDate, $dem['module'], $orderNo, json_encode($demRawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason, 'personal']);
                                }
                            } else {
                                $bindEmpId = $order_scope === 'department' ? 0 : $employee_id;
                                $stmt->execute([$bindEmpId, $amount, $parsedDate, $project, $orderNo, json_encode($rawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason, $order_scope]);
                                $isAbn ? $skipped++ : $inserted++;
                            }
                        }
                        db()->commit();
                        } catch (Exception $txEx) {
                            db()->rollBack();
                            throw $txEx;
                        }
                    } // end if (!empty($rows))

                    if ($error === '') {
                        if ($order_scope === 'department') {
                            $empName = $dept_name . '（部门）';
                            $rq = ['upload_ok' => '1', 'msg' => urlencode("导入完成！为【{$empName}】成功导入 {$inserted} 条" . ($skipped > 0 ? "，{$skipped} 条标记为异常" : ""))];
                        } else {
                            $emp = get_employee($employee_id);
                            $empName = $emp ? $emp['name'] : '';
                            $rq = ['employee_id' => $employee_id, 'upload_ok' => '1', 'msg' => urlencode("导入完成！为【{$empName}】成功导入 {$inserted} 条" . ($skipped > 0 ? "，{$skipped} 条标记为异常" : ""))];
                        }
                        if ($per_page !== 20) $rq['per_page'] = $per_page;
                        header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
                        exit;
                    }
                } catch (Exception $ex) {
                    $error = '解析失败: ' . $ex->getMessage();
                }
                upload_done:
            }
        }
    } elseif ($action === 'delete') {
        $oid            = (int)($_POST['id'] ?? 0);
        $backPage       = max(1, (int)($_POST['_page'] ?? 1));
        $backPerPage    = (int)($_POST['_per_page'] ?? 20);
        $backMonth      = $_POST['_month'] ?? '';
        $backEmployeeId = (int)($_POST['_employee_id'] ?? 0);
        $backProject    = $_POST['_project'] ?? '';
        try {
            db()->prepare("DELETE FROM orders WHERE id = ?")->execute([$oid]);
            $rq = ['page' => $backPage];
            if ($backEmployeeId) $rq['employee_id'] = $backEmployeeId;
            if ($backMonth)      $rq['month']       = $backMonth;
            if ($backProject)    $rq['project']     = $backProject;
            if ($backPerPage && $backPerPage !== 20) $rq['per_page'] = $backPerPage;
            header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
            exit;
        } catch (PDOException $ex) {
            $error = '删除失败: ' . $ex->getMessage();
        }
    } elseif ($action === 'batch_delete') {
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        $backPage       = max(1, (int)($_POST['_page'] ?? 1));
        $backPerPage    = (int)($_POST['_per_page'] ?? 20);
        $backMonth      = $_POST['_month'] ?? '';
        $backEmployeeId = (int)($_POST['_employee_id'] ?? 0);
        $backProject    = $_POST['_project'] ?? '';
        if (empty($ids)) {
            $error = '请选择要删除的订单';
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                db()->prepare("DELETE FROM orders WHERE id IN ({$placeholders})")->execute($ids);
                $rq = ['page' => $backPage];
                if ($backEmployeeId) $rq['employee_id'] = $backEmployeeId;
                if ($backMonth)      $rq['month']       = $backMonth;
                if ($backProject)    $rq['project']     = $backProject;
                if ($backPerPage && $backPerPage !== 20) $rq['per_page'] = $backPerPage;
                header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
                exit;
            } catch (PDOException $ex) {
                $error = '批量删除失败: ' . $ex->getMessage();
            }
        }
    } elseif ($action === 'delete_months') {
        // 按月份批量删除（支持多选月份），仅删除当前视图可见范围
        $months = array_values(array_filter(array_map('trim', (array)($_POST['months'] ?? []))));
        $delEmp        = (int)($_POST['employee_id'] ?? 0);
        $delDept       = trim($_POST['department'] ?? '');
        $delDeptOrders = ($_POST['dept_orders'] ?? '') === '1';
        if (empty($months)) {
            $error = '请先勾选要删除的月份';
        } else {
            try {
                $where  = " WHERE 1=1";
                $params = [];
                if ($delEmp > 0) {
                    $where .= " AND (o.employee_id = ? OR (o.order_scope = 'department' AND (o.shop IS NULL OR o.shop = '')))";
                    $params[] = $delEmp;
                } elseif ($delDept !== '') {
                    $where .= " AND e.department = ?";
                    $params[] = $delDept;
                }
                if ($delDeptOrders) {
                    $where .= " AND o.order_scope = 'department'";
                }
                // 与列表一致：排除店铺上传的订单
                $where .= " AND (o.raw_data IS NULL OR o.raw_data NOT LIKE '%\"__from_dept__%\":%') AND NOT (o.order_scope = 'department' AND o.shop <> '')";
                $ph = implode(',', array_fill(0, count($months), '?'));
                $where .= " AND DATE_FORMAT(o.order_date, '%Y-%m') IN ($ph)";
                foreach ($months as $m) { $params[] = $m; }
                $sql = "DELETE o FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $where;
                db()->prepare($sql)->execute($params);
                $rq = [];
                if ($delEmp)        $rq['employee_id'] = $delEmp;
                if ($delDept)       $rq['department']   = $delDept;
                if ($delDeptOrders) $rq['dept_orders'] = '1';
                $rq['upload_ok'] = '1';
                $rq['msg'] = urlencode('已删除所选月份订单');
                header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
                exit;
            } catch (PDOException $ex) {
                $error = '删除失败: ' . $ex->getMessage();
            }
        }
    } elseif ($action === 'delete_project') {
        // 删除指定模块（project）的全部订单
        $delProject    = trim($_POST['project'] ?? '');
        $delEmp        = (int)($_POST['employee_id'] ?? 0);
        $delDept       = trim($_POST['department'] ?? '');
        $delDeptOrders = ($_POST['dept_orders'] ?? '') === '1';
        if ($delProject === '') {
            $error = '缺少模块名称';
        } else {
            try {
                $where  = " WHERE o.project = ?";
                $params = [$delProject];
                if ($delEmp > 0) {
                    $where .= " AND (o.employee_id = ? OR (o.order_scope = 'department' AND (o.shop IS NULL OR o.shop = '')))";
                    $params[] = $delEmp;
                } elseif ($delDept !== '') {
                    $where .= " AND e.department = ?";
                    $params[] = $delDept;
                }
                if ($delDeptOrders) {
                    $where .= " AND o.order_scope = 'department'";
                }
                $where .= " AND (o.raw_data IS NULL OR o.raw_data NOT LIKE '%\"__from_dept__%\":%') AND NOT (o.order_scope = 'department' AND o.shop <> '')";
                $sql = "DELETE o FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $where;
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                $deleted = $stmt->rowCount();
                $rq = [];
                if ($delEmp)        $rq['employee_id'] = $delEmp;
                if ($delDept)       $rq['department']   = $delDept;
                if ($delDeptOrders) $rq['dept_orders'] = '1';
                $rq['upload_ok'] = '1';
                $rq['msg'] = urlencode("已删除模块「{$delProject}」{$deleted}条订单");
                header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
                exit;
            } catch (PDOException $ex) {
                $error = '删除失败: ' . $ex->getMessage();
            }
        }
    }
}

// 订单列表查询：锁定员工时只显示该员工订单

/**
 * 确保 orders 表有 project 字段（自动升级，兼容旧表结构）
 */
/**
 * 将日期字符串标准化为 Y-m-d，自动处理 Excel 序列号
 * 返回 [date_string_or_false, error_reason]
 */
function parseOrderDate($raw) {
    $raw = trim($raw);
    if ($raw === '') return [false, '日期为空'];
    // 纯数字：可能是 Excel 日期序列号（1900/1904 基准）
    if (ctype_digit($raw)) {
        $num = (int)$raw;
        if ($num >= 1 && $num <= 2958465) {
            $ts = ($num - 25569) * 86400;
            $date = gmdate('Y-m-d', $ts);
            if ($date && $date > '1900-01-01') return [$date, ''];
        }
        return [false, '日期格式错误(' . $raw . ')'];
    }
    $raw = str_replace('/', '-', $raw);
    // 处理 "2026-06-05 00:00:00" 这类带时间的字符串
    $raw = preg_replace('/\s+\d{2}:\d{2}(:\d{2})?$/', '', $raw);
    $raw = trim($raw);

    // 补全年份：处理 "5.25" / "5-25" / "05-25" 这类只有月日的格式
    if (preg_match('/^(\d{1,2})[.\-](\d{1,2})$/', $raw, $m)) {
        $raw = date('Y') . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
    }
    // 处理 "24.2.28" / "2024.2.28" 这类点分格式
    if (preg_match('/^(\d{2,4})[.](\d{1,2})[.](\d{1,2})$/', $raw, $m)) {
        $year = strlen($m[1]) === 2 ? '20' . $m[1] : $m[1];
        $raw = $year . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[3], 2, '0', STR_PAD_LEFT);
    }

    $ts = strtotime($raw);
    if ($ts === false) return [false, '日期格式错误(' . $raw . ')'];

    return [date('Y-m-d', $ts), ''];
}

function ensureProjectColumn() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'project'")->fetchAll();
        if (empty($cols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `project` VARCHAR(100) DEFAULT '' COMMENT '项目/业务来源' AFTER `order_date`");
            db()->exec("ALTER TABLE `orders` ADD INDEX `idx_project_employee` (`employee_id`, `project`)");
        }
        // 售后部字段：店铺
        $shopCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'shop'")->fetchAll();
        if (empty($shopCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `shop` VARCHAR(100) DEFAULT '' COMMENT '店铺' AFTER `project`");
        }
        // 售后部字段：付款旺旺
        $wwCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'wangwang'")->fetchAll();
        if (empty($wwCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `wangwang` VARCHAR(100) DEFAULT '' COMMENT '付款旺旺' AFTER `shop`");
        }
        // 异常标记字段
        $abnCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'is_abnormal'")->fetchAll();
        if (empty($abnCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `is_abnormal` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0正常 1异常' AFTER `wangwang`");
            db()->exec("ALTER TABLE `orders` ADD COLUMN `abnormal_reason` VARCHAR(200) DEFAULT '' COMMENT '异常原因' AFTER `is_abnormal`");
        }
        // 退款关联原订单字段
        $refundCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'refund_for_order_id'")->fetchAll();
        if (empty($refundCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `refund_for_order_id` INT NULL DEFAULT NULL COMMENT '退款订单关联的原订单ID' AFTER `is_abnormal`");
            db()->exec("ALTER TABLE `orders` ADD INDEX `idx_refund_for` (`refund_for_order_id`)");
        }
        // 上传时间字段
        $ctCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'created_at'")->fetchAll();
        if (empty($ctCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '上传时间' AFTER `abnormal_reason`");
        }
        // 归属范围：personal=个人订单，department=部门订单
        $scopeCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'order_scope'")->fetchAll();
        if (empty($scopeCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `order_scope` VARCHAR(20) NOT NULL DEFAULT 'personal' COMMENT 'personal=个人,department=部门' AFTER `created_at`");
        }
        // 售后部扩展字段
        $remarkCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'remark'")->fetchAll();
        if (empty($remarkCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `platform_no` VARCHAR(100) DEFAULT '' COMMENT '平台编号' AFTER `wangwang`");
            db()->exec("ALTER TABLE `orders` ADD COLUMN `remark` VARCHAR(500) DEFAULT '' COMMENT '特殊情况备注' AFTER `platform_no`");
            db()->exec("ALTER TABLE `orders` ADD COLUMN `split_amount` DECIMAL(10,2) DEFAULT 0 COMMENT '分单备注金额' AFTER `remark`");
        }
        // 原始行数据字段（存完整的原始列 JSON）
        $rawCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'raw_data'")->fetchAll();
        if (empty($rawCols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `raw_data` TEXT DEFAULT NULL COMMENT '原始行数据JSON' AFTER `split_amount`");
        }
        // 上传批次表头记录表
        db()->exec("CREATE TABLE IF NOT EXISTS `upload_batches` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL,
            `headers` TEXT NOT NULL COMMENT '表头JSON数组',
            `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // 移除 employee_id 外键约束（部门订单需要存 0）
        try {
            $fkRows = db()->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND REFERENCED_TABLE_NAME='employees'")->fetchAll();
            foreach ($fkRows as $fk) {
                db()->exec("ALTER TABLE `orders` DROP FOREIGN KEY `" . $fk['CONSTRAINT_NAME'] . "`");
            }
        } catch (\Throwable $e) {}
    } catch (\Throwable $e) {}
}
$filter_employee = $locked_employee_id ?: (int)($_GET['employee_id'] ?? 0);
$filter_dept = $_GET['department'] ?? '';
$filter_month = $_GET['month'] ?? '';  // 空字符串=不限月份
$filter_project = $_GET['project'] ?? ''; // 展开某个模块时使用
$filter_dept_orders = isset($_GET['dept_orders']) && $_GET['dept_orders'] === '1';
$filter_abnormal = (($_GET['abnormal'] ?? '') === '1') ? 1 : 0;
$page     = max(1, (int)($_GET['page'] ?? 1));
$allowed_per_page = [20, 50, 100, 200, 500, 1000];
$_pp = (int)($_GET['per_page'] ?? 20);
$per_page = in_array($_pp, $allowed_per_page) ? $_pp : 20;
$baseQ = [];
if ($locked_employee) $baseQ['employee_id'] = $locked_employee['id'];
elseif ($filter_employee) $baseQ['employee_id'] = $filter_employee;
if ($filter_dept) $baseQ['department'] = $filter_dept;
if ($filter_month) $baseQ['month'] = $filter_month;
if ($filter_dept_orders) $baseQ['dept_orders'] = '1';

ensureProjectColumn(); // 确保 upload_batches 等表/字段存在
ensureOrderNoColumn(); // 确保 orders.order_no 字段存在

// 基础 WHERE（不含 project 筛选，用于分组汇总）
// 排除店铺上传的订单（order_scope='department' 且 shop 非空），它们只在店铺管理页展示
$baseWhere  = " WHERE NOT (o.order_scope = 'department' AND o.shop <> '')";
$baseParams = [];
if ($filter_employee) {
    // 个人订单匹配 employee_id，部门订单（非店铺）不过滤（employee_id=0 不属于任何人，显示给所有人看）
    $baseWhere .= " AND (o.employee_id = ? OR (o.order_scope = 'department' AND (o.shop IS NULL OR o.shop = '')))";
    $baseParams[] = $filter_employee;
}
if ($filter_dept) {
    $baseWhere .= " AND e.department = ?";
    $baseParams[] = $filter_dept;
}
if ($filter_month)    { $baseWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?"; $baseParams[] = $filter_month; }
// 部门订单视图：只看 employee_id=0 的部门汇总订单（用 __dept__ 匹配，绕过 e.department 过滤）
if ($filter_dept_orders) {
    // 重建 WHERE：去掉 e.department 过滤（部门订单 JOIN 不到员工），改用 __dept__
    $baseWhere = " WHERE NOT (o.order_scope = 'department' AND o.shop <> '')"
        . " AND o.employee_id = 0 AND o.order_scope = 'department'"
        . " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ?";
    $baseParams = [$filter_dept];
    if ($filter_month) {
        $baseWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?";
        $baseParams[] = $filter_month;
    }
}

// 按年份-月份-project三级分组汇总
$groupSql = "SELECT DATE_FORMAT(o.order_date, '%Y') as order_year,
                    DATE_FORMAT(o.order_date, '%Y-%m') as order_month,
                    COALESCE(NULLIF(o.project,''),'订单') as grp_name,
                    COUNT(*) as cnt,
                    COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount,
                    SUM(CASE WHEN o.is_abnormal=1 THEN 1 ELSE 0 END) as abn_cnt,
                    SUM(CASE WHEN o.order_scope='department' THEN 1 ELSE 0 END) as dept_cnt,
                    SUM(CASE WHEN o.order_scope='personal' OR o.order_scope IS NULL OR o.order_scope='' THEN 1 ELSE 0 END) as personal_cnt
             FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $baseWhere .
             " GROUP BY order_year, order_month, grp_name ORDER BY order_year DESC, order_month DESC, normal_amount DESC";
$gStmt = db()->prepare($groupSql);
$gStmt->execute($baseParams);
$allGroups = $gStmt->fetchAll();

// 按年份和月份重新组织数据结构
$projectGroups = [];
$yearGroups = [];
foreach ($allGroups as $row) {
    $year = $row['order_year'];
    $month = $row['order_month'];
    
    if (!isset($yearGroups[$year])) {
        $yearGroups[$year] = [
            'year' => $year,
            'total_cnt' => 0,
            'total_amount' => 0,
            'months' => []
        ];
    }
    
    if (!isset($yearGroups[$year]['months'][$month])) {
        $yearGroups[$year]['months'][$month] = [
            'month' => $month,
            'total_cnt' => 0,
            'total_amount' => 0,
            'projects' => []
        ];
    }
    
    $yearGroups[$year]['months'][$month]['projects'][] = $row;
    $yearGroups[$year]['months'][$month]['total_cnt'] += $row['cnt'];
    $yearGroups[$year]['months'][$month]['total_amount'] += $row['normal_amount'];
    $yearGroups[$year]['total_cnt'] += $row['cnt'];
    $yearGroups[$year]['total_amount'] += $row['normal_amount'];
    
    // 兼容原有的平铺结构（用于总计等）
    $projectGroups[] = $row;
}

// 按部门分组统计（部门订单通过 raw_data.__dept__ 命名部门，个人订单回退到员工部门）
$deptSql = "SELECT COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')), ''),
                e.department,
                '未分配'
              ) as dept_name,
                   COUNT(*) as cnt,
                   COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount
            FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $baseWhere .
            " GROUP BY dept_name ORDER BY dept_name";
$deptStmt = db()->prepare($deptSql);
$deptStmt->execute($baseParams);
$deptGroups = $deptStmt->fetchAll();

// 如果选择了部门，按员工分组统计
$empGroups = [];
if ($filter_dept) {
    $empSql = "SELECT e.id as emp_id, e.name as emp_name,
                      COUNT(*) as cnt,
                      COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount
               FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $baseWhere .
               " GROUP BY e.id, e.name ORDER BY e.name";
    $empStmt = db()->prepare($empSql);
    $empStmt->execute($baseParams);
    $empGroups = $empStmt->fetchAll();

    // 追加"部门订单"虚拟行（employee_id=0 的部门汇总订单）
    // 注意：部门订单 employee_id=0，JOIN 不到员工，不能用 e.department 过滤，
    // 要用 raw_data.__dept__ 匹配部门名。重建 WHERE，不复用含 e.department 的 baseWhere。
    $deptSumParams = [];
    $deptSumWhere = " WHERE NOT (o.order_scope = 'department' AND o.shop <> '')"
        . " AND o.employee_id = 0 AND o.order_scope = 'department'"
        . " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ?";
    $deptSumParams[] = $filter_dept;
    if ($filter_month) {
        $deptSumWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?";
        $deptSumParams[] = $filter_month;
    }
    $deptSumSql = "SELECT COUNT(*) as cnt,
        COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount
        FROM orders o" . $deptSumWhere;
    $deptSumStmt = db()->prepare($deptSumSql);
    $deptSumStmt->execute($deptSumParams);
    $deptSum = $deptSumStmt->fetch();
    if ($deptSum && (int)$deptSum['cnt'] > 0) {
        array_unshift($empGroups, [
            'emp_id'        => 0,
            'emp_name'      => '部门订单',
            'cnt'           => $deptSum['cnt'],
            'normal_amount' => $deptSum['normal_amount'],
        ]);
    }
}

// 总计
$total_count  = array_sum(array_column($projectGroups, 'cnt'));
$total_amount = array_sum(array_column($projectGroups, 'normal_amount'));

// 展开某分组时，查询该分组的订单明细
$orders = [];
$total_pages = 1;
$uploadHeaders = [];
$expand_project = $filter_project; // 当前展开的分组名（空=全部收起）
$detailQ = [];

if ($expand_project !== '') {
    $detailWhere  = $baseWhere;
    $detailParams = $baseParams;
    $detailQ = array_merge($baseQ, ['project' => $expand_project, 'page' => 1]);
    if ($filter_abnormal) $detailQ['abnormal'] = '1';
    if ($expand_project === '订单') {
        $detailWhere .= " AND (o.project = '' OR o.project IS NULL)";
    } else {
        $detailWhere .= " AND o.project = ?";
        $detailParams[] = $expand_project;
    }
    if ($filter_abnormal) {
        $detailWhere .= " AND o.is_abnormal = 1";
    }

    $cntRow = db()->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(o.order_amount),0) as total FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $detailWhere);
    $cntRow->execute($detailParams);
    $cntRow = $cntRow->fetch();
    $detail_count  = (int)$cntRow['cnt'];
    $detail_amount = (float)$cntRow['total'];
    $total_pages   = max(1, ceil($detail_count / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $detailSql = "SELECT o.*, COALESCE(e.name,'') as name, COALESCE(e.department,'') as department FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $detailWhere . " ORDER BY o.is_abnormal ASC, o.order_date DESC, o.id DESC LIMIT {$per_page} OFFSET {$offset}";
    $dStmt = db()->prepare($detailSql);
    $dStmt->execute($detailParams);
    $orders = $dStmt->fetchAll();

    // 直接从第一条有 raw_data 的订单推断表头，过滤掉"列N"占位列
    foreach ($orders as $o) {
        if (!empty($o['raw_data'])) {
            $rd = json_decode($o['raw_data'], true);
            if (is_array($rd)) {
                $uploadHeaders = array_values(array_filter(array_keys($rd), function($h) {
                    return $h !== '__amount__' && !preg_match('/^列\d+$/', $h);
                }));
                break;
            }
        }
    }
} else {
    $detail_count  = 0;
    $detail_amount = 0;
}

$departments = get_departments();
$employees   = get_employees();

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="font-weight-bold mb-0 d-inline-block"><i class="fas fa-file-upload"></i> 订单上传</h4>
        <?php if ($locked_employee): ?>
            <span class="badge badge-success ml-2" style="font-size:.9em">
                <i class="fas fa-user-lock"></i> 已锁定员工：<?php echo e($locked_employee['name']); ?>（<?php echo e($locked_employee['department']); ?>）
            </span>
        <?php endif; ?>
    </div>
    <?php if ($locked_employee): ?>
        <a href="<?php echo BASE_URL; ?>/employees/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> 返回员工管理
        </a>
    <?php endif; ?>
</div>

<?php if ($success || isset($_GET['upload_ok'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success ?: urldecode($_GET['msg'] ?? '导入完成')); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="row">
    <!-- 左侧：上传 & 手动添加 -->
    <div class="col-md-5">
        <!-- 批量上传 -->
        <div class="card mb-3">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-file-excel text-success"></i> 批量上传订单Excel</h5></div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload">
                    <?php if ($locked_employee): ?>
                        <!-- 锁定员工模式：固定为个人订单 -->
                        <input type="hidden" name="employee_id" value="<?php echo $locked_employee['id']; ?>">
                        <input type="hidden" name="order_scope" value="personal">
                        <div class="form-group">
                            <label>归属员工</label>
                            <input type="text" class="form-control" value="<?php echo e($locked_employee['name']); ?>（<?php echo e($locked_employee['department']); ?>）" disabled>
                            <small class="text-muted"><i class="fas fa-info-circle"></i> 已从员工管理锁定，本批订单将归属该员工</small>
                        </div>
                    <?php else: ?>
                        <!-- 归属类型选择 -->
                        <div class="form-group">
                            <label>归属类型 <span class="required">*</span></label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="order_scope" id="scopePersonal" value="personal" checked onchange="toggleScopeFields()">
                                    <label class="form-check-label" for="scopePersonal"><i class="fas fa-user text-primary"></i> 个人订单</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="order_scope" id="scopeDept" value="department" onchange="toggleScopeFields()">
                                    <label class="form-check-label" for="scopeDept"><i class="fas fa-users text-success"></i> 部门订单</label>
                                </div>
                            </div>
                        </div>
                        <!-- 个人订单：选部门+员工 -->
                        <div id="personalFields">
                            <div class="form-group">
                                <label>选择部门</label>
                                <select name="department" id="uploadDept" class="form-control" onchange="loadEmployees('upload')">
                                    <option value="">-- 选择部门 --</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>选择员工 <span class="required">*</span></label>
                                <select name="employee_id" id="uploadEmp" class="form-control">
                                    <option value="">-- 请先选择部门 --</option>
                                </select>
                            </div>
                        </div>
                        <!-- 部门订单：只选部门 -->
                        <div id="deptFields" style="display:none">
                            <div class="form-group">
                                <label>选择部门 <span class="required">*</span></label>
                                <select name="dept_name" id="uploadDeptName" class="form-control" onchange="loadDeptEmployees(this.value)">
                                    <option value="">-- 选择部门 --</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">部门订单不归属于具体员工，仅记录部门整体业绩</small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-users text-primary"></i> 提成归属员工 <small class="text-muted">（可添加多个，每人选各自的提成模块）</small></label>
                                <div id="deptEmpRows">
                                    <!-- 动态行由JS生成 -->
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addDeptEmpRow()"><i class="fas fa-plus"></i> 添加员工</button>
                                <input type="hidden" name="dept_emp_modules" id="deptEmpModules">
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 订单归属月份选择（必填） -->
                    <div class="form-group">
                        <label><i class="fas fa-calendar text-warning"></i> 订单归属月份 <span class="required">*</span></label>
                        <input type="month" name="upload_month" class="form-control" value="<?php echo date('Y-m'); ?>" min="2020-01" max="2030-12" required>
                        <small class="text-muted"><i class="fas fa-info-circle"></i> 该批订单将统一归属到所选月份，用于薪资结算（不受Excel中日期列影响）</small>
                    </div>
                    
                    <div class="form-group">
                        <label>选择文件 <span class="required">*</span></label>
                        <div class="upload-area" id="uploadArea">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                            <p class="mb-1" id="uploadTip">点击或拖拽文件到此处</p>
                            <p class="text-muted small mb-0">支持 .xls / .xlsx / .csv 格式</p>
                            <input type="file" name="excel_file" id="excelFile" class="d-none" accept=".xls,.xlsx,.csv">
                        </div>
                        <a href="<?php echo BASE_URL; ?>/orders/template_safehou.csv" class="btn btn-sm btn-link text-warning" download><i class="fas fa-download"></i> 下载模板</a>
                    </div>
                    <div class="form-group" id="uploadProjectGroup">
                        <label><i class="fas fa-percentage text-warning"></i> 对应提成模块 <small class="text-muted">（选择该员工已配置的提成模块，订单会按对应比例结算）</small></label>
                        <select name="upload_project" id="uploadProject" class="form-control">
                            <option value="">-- 不指定（按默认全部订单总额） --</option>
                            <?php
                            if ($locked_employee):
                                $modCfg = SalaryCalculator::readModulesConfig($locked_employee['id']);
                                if ($modCfg && !empty($modCfg['modules'])):
                                    foreach ($modCfg['modules'] as $m):
                                        if (in_array($m['type'], ['standard','tiered','per_order','profit_commission','referral_order']) && ($m['enabled'] ?? true)):
                                            $modName = $m['name'];
                                            $extra = '';
                                            if ($m['type'] === 'standard' && isset($m['config']['rate']) && $m['config']['rate'] !== '') {
                                                $extra = ' (' . rtrim(rtrim(number_format((float)$m['config']['rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'profit_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                                                $extra = ' (成本提成' . rtrim(rtrim(number_format((float)$m['config']['commission_rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'tiered') {
                                                $extra = ' (阶梯)';
                                            } elseif ($m['type'] === 'per_order') {
                                                $extra = ' (¥' . ($m['config']['per_amount'] ?? 0) . '/笔)';
                                            } elseif ($m['type'] === 'referral_order') {
                                                $extra = ' (每单补助¥' . ($m['config']['subsidy'] ?? 0) . ')';
                                            }
                                            echo '<option value="' . e($modName) . '">' . e($modName) . $extra . '</option>';
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success btn-block"><i class="fas fa-upload"></i> 开始上传</button>
                </form>
            </div>
        </div>

        <!-- 手动添加 -->
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-hand-pointer text-primary"></i> 手动添加单笔订单</h5></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="manual_add">
                    <?php if ($locked_employee): ?>
                        <input type="hidden" name="employee_id" value="<?php echo $locked_employee['id']; ?>">
                        <div class="form-group">
                            <label>归属员工</label>
                            <input type="text" class="form-control" value="<?php echo e($locked_employee['name']); ?>（<?php echo e($locked_employee['department']); ?>）" disabled>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>选择部门</label>
                            <select name="department" id="manualDept" class="form-control" onchange="loadEmployees('manual')">
                                <option value="">-- 选择部门 --</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>选择员工 <span class="required">*</span></label>
                            <select name="employee_id" id="manualEmp" class="form-control" required>
                                <option value="">-- 请先选择部门 --</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>订单金额 <span class="required">*</span></label>
                            <input type="number" name="order_amount" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>订单日期 <span class="required">*</span></label>
                            <input type="date" name="order_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-percentage text-warning"></i> 对应提成模块 <small class="text-muted">（选择该员工已配置的提成模块，订单会按对应比例结算）</small></label>
                        <select name="project" id="manualProject" class="form-control">
                            <option value="">-- 不指定 --</option>
                            <?php
                            if ($locked_employee):
                                $modCfg2 = SalaryCalculator::readModulesConfig($locked_employee['id']);
                                if ($modCfg2 && !empty($modCfg2['modules'])):
                                    foreach ($modCfg2['modules'] as $m):
                                        if (in_array($m['type'], ['standard','tiered','per_order','profit_commission','referral_order']) && ($m['enabled'] ?? true)):
                                            $modName = $m['name'];
                                            $extra = '';
                                            if ($m['type'] === 'standard' && isset($m['config']['rate']) && $m['config']['rate'] !== '') {
                                                $extra = ' (' . rtrim(rtrim(number_format((float)$m['config']['rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'profit_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                                                $extra = ' (成本提成' . rtrim(rtrim(number_format((float)$m['config']['commission_rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'tiered') {
                                                $extra = ' (阶梯)';
                                            } elseif ($m['type'] === 'per_order') {
                                                $extra = ' (¥' . ($m['config']['per_amount'] ?? 0) . '/笔)';
                                            } elseif ($m['type'] === 'referral_order') {
                                                $extra = ' (每单补助¥' . ($m['config']['subsidy'] ?? 0) . ')';
                                            }
                                            echo '<option value="' . e($modName) . '">' . e($modName) . $extra . '</option>';
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-plus"></i> 添加订单</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 右侧：订单记录 -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <h5 class="mb-0"><i class="fas fa-list text-info"></i> 订单记录
                        <small class="text-muted" style="font-size:.8em">共 <?php echo $total_count; ?> 条 / ¥<?php echo money($total_amount); ?></small>
                    </h5>
                    <form method="get" class="form-inline" id="filterForm">
                        <input type="hidden" name="page" value="1">
                        <?php if ($locked_employee): ?>
                            <input type="hidden" name="employee_id" value="<?php echo $locked_employee['id']; ?>">
                        <?php endif; ?>
                        <?php if ($filter_dept): ?>
                            <input type="hidden" name="department" value="<?php echo e($filter_dept); ?>">
                        <?php endif; ?>
                        <?php if ($filter_dept_orders): ?>
                            <input type="hidden" name="dept_orders" value="1">
                        <?php endif; ?>
                        <input type="month" name="month" class="form-control form-control-sm mr-1" value="<?php echo e($filter_month); ?>" onchange="this.form.submit()">
                        <?php if ($filter_month): ?>
                            <button type="submit" name="month" value="" class="btn btn-sm btn-outline-secondary mr-1" title="显示全部月份">全部</button>
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- 面包屑导航 -->
                <?php if ($filter_dept || $filter_employee || $filter_dept_orders): ?>
                <div class="mt-2">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 bg-light" style="padding:0.5rem 1rem">
                            <li class="breadcrumb-item"><a href="?<?php echo $filter_month ? 'month='.$filter_month : ''; ?>">全部部门</a></li>
                            <?php if ($filter_dept): ?>
                                <li class="breadcrumb-item <?php echo ($filter_employee || $filter_dept_orders) ? '' : 'active'; ?>">
                                    <?php if ($filter_employee || $filter_dept_orders): ?>
                                        <a href="?department=<?php echo urlencode($filter_dept); ?><?php echo $filter_month ? '&month='.$filter_month : ''; ?>"><?php echo e($filter_dept); ?></a>
                                    <?php else: ?>
                                        <?php echo e($filter_dept); ?>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                            <?php if ($filter_dept_orders): ?>
                                <li class="breadcrumb-item active"><i class="fas fa-users text-warning"></i> 部门订单</li>
                            <?php endif; ?>
                            <?php if ($filter_employee): ?>
                                <?php $emp = array_filter($employees, fn($e) => $e['id'] == $filter_employee)[0] ?? null; ?>
                                <li class="breadcrumb-item active"><?php echo $emp ? e($emp['name']) : '员工'.$filter_employee; ?></li>
                            <?php endif; ?>
                        </ol>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body p-2">

                <?php if (empty($projectGroups)): ?>
                    <div class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>暂无订单数据</div>
                <?php else: ?>

                <!-- 第一级：部门卡片（未选择部门时显示） -->
                <?php if (!$filter_dept && !$filter_employee && !$locked_employee): ?>
                    <div class="row">
                        <?php foreach ($deptGroups as $dept): ?>
                        <div class="col-md-6 mb-3">
                            <a href="?department=<?php echo urlencode($dept['dept_name']); ?><?php echo $filter_month ? '&month='.$filter_month : ''; ?>" 
                               class="text-decoration-none">
                                <div class="card border-primary" style="transition:all 0.2s;cursor:pointer" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="mb-1">
                                                    <i class="fas fa-building text-primary"></i> 
                                                    <?php echo e($dept['dept_name']); ?>
                                                </h5>
                                                <small class="text-muted"><?php echo $dept['cnt']; ?> 笔订单</small>
                                            </div>
                                            <div class="text-right">
                                                <div class="h4 mb-0 text-success">¥<?php echo money($dept['normal_amount']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                
                <!-- 第二级：员工卡片（选择了部门但未选择员工且非部门订单视图时显示） -->
                <?php elseif ($filter_dept && !$filter_employee && !$locked_employee && !$filter_dept_orders): ?>
                    <div class="row">
                        <?php foreach ($empGroups as $emp):
                            $isDeptOrderCard = ((int)$emp['emp_id'] === 0);
                            $cardLink = $isDeptOrderCard
                                ? "?department=" . urlencode($filter_dept) . "&dept_orders=1" . ($filter_month ? '&month=' . $filter_month : '')
                                : "?department=" . urlencode($filter_dept) . "&employee_id=" . $emp['emp_id'] . ($filter_month ? '&month=' . $filter_month : '');
                        ?>
                        <div class="col-md-6 mb-3">
                            <a href="<?php echo $cardLink; ?>"
                               class="text-decoration-none">
                                <div class="card <?php echo $isDeptOrderCard ? 'border-warning' : 'border-info'; ?>" style="transition:all 0.2s;cursor:pointer" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="mb-1">
                                                    <i class="fas <?php echo $isDeptOrderCard ? 'fa-users text-warning' : 'fa-user text-info'; ?>"></i>
                                                    <?php echo e($emp['emp_name']); ?>
                                                </h5>
                                                <small class="text-muted"><?php echo $emp['cnt']; ?> 笔订单</small>
                                            </div>
                                            <div class="text-right">
                                                <div class="h4 mb-0 <?php echo $isDeptOrderCard ? 'text-warning' : 'text-success'; ?>">¥<?php echo money($emp['normal_amount']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                
                <!-- 第三级：按年份-月份-提成模块三级分组（选择了员工/锁定员工/部门订单时显示） -->
                <?php elseif ($filter_employee || $locked_employee || $filter_dept_orders): ?>
                <!-- 按月份批量删除（勾选下方各月份前的复选框，可单选/多选后一次性删除） -->
                <form method="post" id="monthDeleteForm" onsubmit="return confirm('确定删除所选月份的【全部】订单？此操作不可恢复！')">
                    <input type="hidden" name="action" value="delete_months">
                    <?php if ($locked_employee): ?><input type="hidden" name="employee_id" value="<?php echo $locked_employee['id']; ?>"><?php endif; ?>
                    <?php if ($filter_employee): ?><input type="hidden" name="employee_id" value="<?php echo $filter_employee; ?>"><?php endif; ?>
                    <?php if ($filter_dept): ?><input type="hidden" name="department" value="<?php echo e($filter_dept); ?>"><?php endif; ?>
                    <?php if ($filter_dept_orders): ?><input type="hidden" name="dept_orders" value="1"><?php endif; ?>
                    <div class="d-flex align-items-center mb-2 p-2 bg-light border rounded">
                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash-alt"></i> 删除所选月份订单（<span id="monthSelCount">0</span>）</button>
                        <small class="text-muted ml-2">勾选下方各月份前的复选框，可单选或多选（如 6月 + 4月）后一次性删除该月全部订单</small>
                    </div>
                <?php foreach ($yearGroups as $yearData): ?>
                <!-- 年份卡片 -->
                <div class="card mb-3 border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-alt"></i> <?php echo $yearData['year']; ?>年
                            <span class="badge badge-light text-primary ml-2"><?php echo $yearData['total_cnt']; ?> 笔</span>
                            <span class="float-right">¥<?php echo money($yearData['total_amount']); ?></span>
                        </h5>
                    </div>
                    <div class="card-body p-2">
                        <?php foreach ($yearData['months'] as $monthData): ?>
                        <!-- 月份折叠卡片 -->
                        <div class="card mb-2 border-info">
                            <div class="card-header bg-info text-white py-2">
                                <h6 class="mb-0">
                                    <input type="checkbox" name="months[]" value="<?php echo $monthData['month']; ?>" class="month-check mr-2 align-middle" title="勾选后可批量删除该月订单">
                                    <i class="fas fa-calendar"></i> <?php echo date('Y年m月', strtotime($monthData['month'] . '-01')); ?>
                                    <span class="badge badge-light text-info ml-2"><?php echo $monthData['total_cnt']; ?> 笔</span>
                                    <span class="float-right">¥<?php echo money($monthData['total_amount']); ?></span>
                                </h6>
                            </div>
                            <div class="card-body p-2">
                                <?php foreach ($monthData['projects'] as $grp): ?>
                                <?php
                                $grpName   = $grp['grp_name'];
                                $isExpand  = ($expand_project === $grpName);
                                $grpQ      = array_merge($baseQ, ['project' => $grpName, 'page' => 1]);
                                $abnQ      = array_merge($grpQ, ['abnormal' => '1']);
                                $collapseQ = $baseQ; // 点击已展开的分组则收起（不带 project 参数）
                                ?>
                                <div class="order-group mb-2">
                                    <!-- 分组标题行：点击展开/收起 -->
                                    <div class="d-flex align-items-stretch">
                                    <a href="<?php echo '?' . http_build_query($isExpand ? $collapseQ : $grpQ); ?>"
                                       class="order-group-header d-flex align-items-center justify-content-between flex-grow-1 px-3 py-2 text-decoration-none <?php echo $isExpand ? 'expanded' : ''; ?>"
                                       <?php if ($isExpand): ?>data-toggle="modal" data-target="#orderDetailModal"<?php endif; ?>>
                                        <span>
                                            <i class="fas fa-<?php echo $isExpand ? 'folder-open' : 'folder'; ?> mr-2 text-warning"></i>
                                            <strong><?php echo e($grpName); ?></strong>
                                            <span class="badge badge-secondary ml-2"><?php echo $grp['cnt']; ?> 条</span>
                                            <?php if ($grp['personal_cnt'] > 0): ?>
                                                <span class="badge badge-primary ml-1" title="个人订单"><i class="fas fa-user"></i> <?php echo $grp['personal_cnt']; ?></span>
                                            <?php endif; ?>
                                            <?php if ($grp['dept_cnt'] > 0): ?>
                                                <span class="badge badge-success ml-1" title="部门订单"><i class="fas fa-users"></i> <?php echo $grp['dept_cnt']; ?></span>
                                            <?php endif; ?>
                                            <?php if ($grp['abn_cnt'] > 0): ?>
                                                <span class="badge badge-danger ml-1 abnormal-filter-badge" title="只看异常订单" onclick="event.preventDefault();event.stopPropagation();location.href='<?php echo '?' . http_build_query($abnQ); ?>';"><i class="fas fa-exclamation-triangle"></i> <?php echo $grp['abn_cnt']; ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="text-success font-weight-bold d-flex align-items-center">
                                            ¥<?php echo money($grp['normal_amount']); ?>
                                            <i class="fas fa-chevron-<?php echo $isExpand ? 'up' : 'down'; ?> ml-2 text-muted" style="font-size:.8em"></i>
                                        </span>
                                    </a>
                                    <button type="button" class="btn btn-link text-danger p-0 px-2 d-flex align-items-center" style="font-size:.8em;border-left:1px solid #dee2e6"
                                        title="删除此模块全部订单"
                                        onclick='deleteProject(<?php echo json_encode($grpName, JSON_UNESCAPED_UNICODE); ?>, <?php echo (int)$grp['cnt']; ?>, <?php echo (int)($locked_employee ? $locked_employee['id'] : $filter_employee); ?>, <?php echo json_encode($filter_dept, JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode($filter_dept_orders ? '1' : '', JSON_UNESCAPED_UNICODE); ?>)'>
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    </div>

                                    <?php if ($isExpand): ?>
                                        <div class="small text-muted px-3 py-1 bg-light border-left border-right border-bottom rounded-bottom">
                                            明细已在弹窗中打开。
                                        </div>
                                    <?php endif; ?>


                </div><!-- /order-group -->
                <?php endforeach; // projects in month ?>
                
                            </div><!-- /card-body for month -->
                        </div><!-- /card for month -->
                        <?php endforeach; // months in year ?>
                    </div><!-- /card-body for year -->
                </div><!-- /card for year -->
                <?php endforeach; // years ?>
                </form><!-- /monthDeleteForm -->
                
                <?php endif; ?> <!-- 结束三级判断 -->

                <?php endif; // empty projectGroups ?>

            </div>
        </div>
    </div>
</div>

<?php if ($expand_project !== ''): ?>
<?php $detailAllQ = $detailQ; unset($detailAllQ['abnormal']); $detailAbnormalQ = array_merge($detailQ, ['abnormal' => '1', 'page' => 1]); ?>
<div class="modal fade" id="orderDetailModal" tabindex="-1" role="dialog" aria-labelledby="orderDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl order-detail-modal" role="document">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div>
                    <h5 class="modal-title mb-0" id="orderDetailModalLabel"><i class="fas fa-list text-info"></i> <?php echo e($expand_project); ?> 明细</h5>
                    <small class="text-muted">共 <?php echo $detail_count; ?> 条，¥<?php echo money($detail_amount); ?><?php if ($filter_abnormal): ?> <span class="badge badge-danger ml-1">仅异常</span><?php endif; ?></small>
                </div>
                <div class="d-flex align-items-center flex-wrap justify-content-end">
                    <select class="form-control form-control-sm mr-2" style="width:auto" onchange="location.href='<?php echo '?' . http_build_query(array_merge($detailQ, ['per_page' => '__PP__'])); ?>'.replace('__PP__', this.value)">
                        <?php foreach ([20,50,100,200,500,1000] as $n): ?>
                            <option value="<?php echo $n; ?>" <?php echo $per_page == $n ? 'selected' : ''; ?>><?php echo $n; ?> 条/页</option>
                        <?php endforeach; ?>
                    </select>
                    <a class="btn btn-sm <?php echo $filter_abnormal ? 'btn-danger' : 'btn-outline-danger'; ?> mr-2" href="<?php echo '?' . http_build_query($detailAbnormalQ); ?>"><i class="fas fa-exclamation-triangle"></i> 只看异常</a>
                    <?php if ($filter_abnormal): ?>
                        <a class="btn btn-sm btn-outline-primary mr-2" href="<?php echo '?' . http_build_query($detailAllQ); ?>">全部订单</a>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo '?' . http_build_query($baseQ); ?>">退出明细</a>
                    <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                </div>
            </div>
            <div class="modal-body p-2">
                <form method="post" id="batchForm">
                    <input type="hidden" name="action" value="batch_delete">
                    <input type="hidden" name="_page" value="<?php echo $page; ?>">
                    <input type="hidden" name="_per_page" value="<?php echo $per_page; ?>">
                    <input type="hidden" name="_month" value="<?php echo e($filter_month); ?>">
                    <input type="hidden" name="_employee_id" value="<?php echo $filter_employee; ?>">
                    <input type="hidden" name="_project" value="<?php echo e($expand_project); ?>">
                    <div class="align-items-center mb-2" id="batchBar" style="display:none">
                        <span class="text-muted small mr-2" id="selectedCount">已选 0 条</span>
                        <button type="submit" class="btn btn-sm btn-danger" id="batchDelBtn" onclick="return confirm('确定删除所选订单？此操作不可恢复！')"><i class="fas fa-trash-alt"></i> 批量删除</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary ml-2" onclick="clearSelection()">取消选择</button>
                    </div>
                    <div class="table-responsive order-detail-table-wrap">
                        <div class="mb-2">
                            <small class="text-muted mr-3"><span class="badge badge-primary"><i class="fas fa-user"></i></span> 个人订单</small>
                            <small class="text-muted mr-3"><span class="badge badge-success"><i class="fas fa-building"></i></span> 部门汇总</small>
                            <small class="text-muted mr-3"><span class="badge badge-warning"><i class="fas fa-share-alt"></i></span> 部门拆分(员工提成来源)</small>
                            <small class="text-muted"><span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i></span> 异常</small>
                        </div>
                        <table class="table table-sm table-hover mb-0 order-detail-table" id="ordersTable">
                            <thead class="thead-light sticky-top">
                                <tr>
                                    <th style="width:32px"><input type="checkbox" id="checkAll" title="全选" onclick="var cbs=document.querySelectorAll('.row-check');cbs.forEach(function(c){c.checked=this.checked;}.bind(this));document.getElementById('batchBar').style.display=this.checked?'flex':'none';document.getElementById('batchBar').style.alignItems='center';document.getElementById('selectedCount').textContent='已选 '+(this.checked?cbs.length:0)+' 条';document.getElementById('batchDelBtn').disabled=!this.checked;"></th>
                                    <th>ID</th><th>员工</th>
                                    <?php foreach ($uploadHeaders as $hdr): ?>
                                        <th><?php echo e($hdr); ?></th>
                                    <?php endforeach; ?>
                                    <th>计算金额</th><th>上传日期</th><th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($orders): foreach ($orders as $o): ?>
                                <?php $rawData = !empty($o['raw_data']) ? (json_decode($o['raw_data'], true) ?: []) : []; ?>
                                <?php $isFromDept = isset($rawData['__from_dept__']); ?>
                                <?php $isDeptSummary = ($o['order_scope'] === 'department' && (int)$o['employee_id'] === 0); ?>
                                <tr class="<?php echo !empty($o['is_abnormal']) ? 'table-danger' : ($isFromDept ? 'table-warning' : ($isDeptSummary ? 'table-info' : '')); ?>">
                                    <td><input type="checkbox" class="row-check" name="ids[]" value="<?php echo $o['id']; ?>" onclick="var cbs=document.querySelectorAll('.row-check'),n=0;cbs.forEach(function(c){if(c.checked)n++;});var ca=document.getElementById('checkAll');if(ca){ca.checked=n===cbs.length;ca.indeterminate=n>0&&n<cbs.length;}var bb=document.getElementById('batchBar');if(bb){bb.style.display=n>0?'flex':'none';bb.style.alignItems='center';}var st=document.getElementById('selectedCount');if(st)st.textContent='已选 '+n+' 条';var bd=document.getElementById('batchDelBtn');if(bd)bd.disabled=n===0;"></td>
                                    <td><?php echo $o['id']; ?></td>
                                    <td>
                                        <?php if ($isDeptSummary): ?>
                                            <?php $dept = $rawData['__dept__'] ?? ''; ?>
                                            <span class="badge badge-success" title="部门订单汇总行"><i class="fas fa-building"></i> <?php echo e($dept ?: '部门'); ?></span>
                                        <?php elseif ($isFromDept): ?>
                                            <span class="badge badge-warning" title="部门订单拆分到员工（提成来源）"><i class="fas fa-share-alt"></i> <?php echo e($o['name'] ?: '--'); ?></span><small class="text-muted d-block">来自：<?php echo e($rawData['__from_dept__']); ?></small>
                                        <?php else: ?>
                                            <span class="badge badge-primary"><i class="fas fa-user"></i> <?php echo e($o['name'] ?: '--'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($uploadHeaders as $hdr): ?>
                                        <td><?php echo isset($rawData[$hdr]) && $rawData[$hdr] !== '' ? e($rawData[$hdr]) : '<span class="text-muted small">--</span>'; ?></td>
                                    <?php endforeach; ?>
                                    <td class="<?php echo !empty($o['is_abnormal']) ? 'text-danger' : 'text-success font-weight-bold'; ?>"><?php if (!empty($o['is_abnormal'])): ?><i class="fas fa-exclamation-triangle"></i> ¥<?php echo money($o['order_amount']); ?><br><small><?php echo e($o['abnormal_reason'] ?? ''); ?></small><?php else: ?>¥<?php echo money($o['order_amount']); ?><?php endif; ?></td>
                                    <td class="text-muted small"><?php echo !empty($o['created_at']) ? date('m-d H:i', strtotime($o['created_at'])) : '--'; ?></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="deleteSingle(<?php echo $o['id']; ?>)"><i class="fas fa-times"></i></button></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="10" class="text-center text-muted py-3">暂无数据</td></tr>
                            <?php endif; ?>
                            </tbody>
                            <?php if ($orders): ?>
                            <tfoot><tr class="table-light font-weight-bold"><td colspan="<?php echo 3 + count($uploadHeaders); ?>">本页合计</td><td class="text-success">¥<?php echo money(array_sum(array_column(array_filter($orders, fn($r) => empty($r['is_abnormal'])), 'order_amount'))); ?></td><td colspan="2"></td></tr></tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </form>
                <?php if ($total_pages > 1): $detailPageQ = array_merge($detailQ, ['per_page' => $per_page]); ?>
                <nav class="mt-2"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ, ['page' => 1])); ?>">«</a></li>
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ, ['page' => $page-1])); ?>">‹</a></li>
                    <?php $start = max(1, $page - 2); $end = min($total_pages, $page + 2); if ($start > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ, ['page' => $i])); ?>"><?php echo $i; ?></a></li>
                    <?php endfor; if ($end < $total_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ, ['page' => $page+1])); ?>">›</a></li>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ, ['page' => $total_pages])); ?>">»</a></li>
                </ul><p class="text-center text-muted small mt-1 mb-0">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页，每页 <?php echo $per_page; ?> 条</p></nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 单条删除专用 form（不能嵌套在 batchForm 里） -->
<form method="post" id="singleDeleteForm" style="display:none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="singleDeleteId">
    <input type="hidden" name="_page" value="<?php echo $page; ?>">
    <input type="hidden" name="_per_page" value="<?php echo $per_page; ?>">
    <input type="hidden" name="_month" value="<?php echo e($filter_month); ?>">
    <input type="hidden" name="_employee_id" value="<?php echo $filter_employee; ?>">
    <input type="hidden" name="_project" value="<?php echo e($expand_project); ?>">
</form>

<script src="<?php echo BASE_URL; ?>/assets/lib/jquery/jquery.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/lib/xlsx.full.min.js"></script>
<script>
var allEmployees = <?php echo json_encode($employees, JSON_UNESCAPED_UNICODE); ?>;

// 切换个人/部门订单显示字段
function toggleScopeFields() {
    var isDept = document.getElementById('scopeDept') && document.getElementById('scopeDept').checked;
    var pf = document.getElementById('personalFields');
    var df = document.getElementById('deptFields');
    if (!pf || !df) return;
    pf.style.display = isDept ? 'none' : '';
    df.style.display = isDept ? '' : 'none';
    // 部门订单模式下隐藏统一的"对应提成模块"（每个员工各自配置）
    var pg = document.getElementById('uploadProjectGroup');
    if (pg) pg.style.display = isDept ? 'none' : '';
    // 个人字段的 required
    var empSel = document.getElementById('uploadEmp');
    if (empSel) empSel.required = !isDept;
}

function loadEmployees(prefix) {
    var dept = $('#' + prefix + 'Dept').val();
    var $sel = $('#' + prefix + 'Emp');
    $sel.empty();
    if (!dept) {
        $sel.append('<option value="">-- 请先选择部门 --</option>');
        return;
    }
    $sel.append('<option value="">-- 选择员工 --</option>');
    allEmployees.forEach(function(emp) {
        if (emp.department === dept) {
            $sel.append('<option value="' + emp.id + '">' + emp.name + '</option>');
        }
    });
    // 只有一个员工时自动选中并触发模块加载
    if ($sel.find('option[value!=""]').length === 1) {
        $sel.find('option[value!=""]').prop('selected', true);
        $sel.trigger('change');
    }
}

// 选择员工后，加载该员工的提成模块到下拉框
function loadEmployeeModules(empId, prefix) {
    var $proj = $('#' + prefix + 'Project');
    $proj.empty().append('<option value="">-- 不指定（按默认全部订单总额） --</option>');
    if (!empId) return;
    $.get('<?php echo BASE_URL; ?>/orders/index.php?employee_id=' + empId + '&ajax=modules', function(data) {
        if (data && data.length) {
            data.forEach(function(m) {
                $proj.append('<option value="' + m.name + '">' + m.label + '</option>');
            });
        } else {
            $proj.append('<option value="" disabled>（该员工未配置提成模块，请先去算法设置）</option>');
        }
    }, 'json').fail(function() {
        $proj.append('<option value="" disabled>加载失败</option>');
    });
}

// 部门订单：选部门后重置员工行
function loadDeptEmployees(dept) {
    $('#deptEmpRows').empty();
    if (dept) addDeptEmpRow();
}

// 员工名→id 映射（按当前所选部门过滤后重建）
var deptEmpMap = {};
function rebuildDeptEmpMap(dept) {
    deptEmpMap = {};
    allEmployees.forEach(function(emp) {
        if (!dept || emp.department === dept) {
            deptEmpMap[emp.name + '（' + (emp.department || '') + '）'] = emp.id;
            deptEmpMap[emp.name] = emp.id;
        }
    });
}

var deptEmpSeq = 0;
// 添加一行员工+模块选择（datalist 搜索下拉，可输入匹配也可直接选）
function addDeptEmpRow() {
    var dept = $('#uploadDeptName').val();
    rebuildDeptEmpMap(dept);
    var seq = ++deptEmpSeq;
    var datalistId = 'deptEmpList_' + seq;
    // 构建 datalist 候选项
    var opts = '';
    allEmployees.forEach(function(emp) {
        if (!dept || emp.department === dept) {
            var label = emp.name + '（' + (emp.department || '') + '）';
            opts += '<option value="' + label.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '">';
        }
    });
    var row = $(
        '<div class="dept-emp-row d-flex align-items-center mb-1" style="gap:6px">' +
            '<div style="flex:1;min-width:0">' +
                '<input type="text" class="form-control form-control-sm dept-emp-search" list="' + datalistId + '" placeholder="输入姓名搜索选择…" autocomplete="off">' +
                '<datalist id="' + datalistId + '">' + opts + '</datalist>' +
                '<input type="hidden" class="dept-emp-id">' +
            '</div>' +
            '<select class="form-control form-control-sm dept-mod-sel" style="flex:1"><option value="">-- 先选员工 --</option></select>' +
            '<button type="button" class="btn btn-sm btn-outline-danger" onclick="$(this).closest(\'.dept-emp-row\').remove()"><i class="fas fa-times"></i></button>' +
        '</div>'
    );
    $('#deptEmpRows').append(row);

    // 输入/选中后同步 employee_id 并加载该员工的提成模块
    var $search = row.find('.dept-emp-search');
    var $id = row.find('.dept-emp-id');
    var lastEmpId = undefined; // 防止 input+change 重复触发
    $search.on('input change', function() {
        var text = $(this).val().trim();
        var empId = deptEmpMap[text] || '';
        if (empId === lastEmpId) return; // 同一员工不重复加载
        lastEmpId = empId;
        $id.val(empId);
        var $modSel = row.find('.dept-mod-sel');
        $modSel.empty().append('<option value="">-- 不指定 --</option>');
        if (empId) {
            $.get('<?php echo BASE_URL; ?>/orders/index.php?employee_id=' + empId + '&ajax=modules', function(data) {
                if (data && data.length) {
                    data.forEach(function(m) {
                        $modSel.append('<option value="' + m.name + '">' + m.label + '</option>');
                    });
                }
            }, 'json');
        }
    });
}

// 提交前把所有行序列化到隐藏字段
$('#uploadForm').on('submit', function() {
    var scope = $('input[name="order_scope"]:checked').val();
    if (scope === 'department') {
        var rows = [];
        $('#deptEmpRows .dept-emp-row').each(function() {
            var empId = $(this).find('.dept-emp-id').val();
            var mod   = $(this).find('.dept-mod-sel').val();
            if (empId) rows.push({employee_id: empId, module: mod});
        });
        $('#deptEmpModules').val(JSON.stringify(rows));
    }
});

<?php if (!$locked_employee): ?>
// 非锁定模式：员工选择变化时动态加载模块
$(document).ready(function() {
    $('#uploadEmp').on('change', function() { loadEmployeeModules($(this).val(), 'upload'); });
    $('#manualEmp').on('change', function() { loadEmployeeModules($(this).val(), 'manual'); });
});
<?php endif; ?>

// 上传区域点击 & 拖拽
(function() {
    var area = document.getElementById('uploadArea');
    var input = document.getElementById('excelFile');
    var tip = document.getElementById('uploadTip');
    var dragCount = 0;

    // 点击区域打开文件选择
    area.addEventListener('click', function() {
        input.click();
    });

    area.addEventListener('dragenter', function(e) {
        e.preventDefault();
        dragCount++;
        area.classList.add('dragover');
    });

    area.addEventListener('dragover', function(e) {
        e.preventDefault();
    });

    area.addEventListener('dragleave', function(e) {
        dragCount--;
        if (dragCount === 0) area.classList.remove('dragover');
    });

    area.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dragCount = 0;
        area.classList.remove('dragover');
        var files = e.dataTransfer.files;
        if (!files || !files.length) return;
        handleFile(files[0]);
    });

    input.addEventListener('change', function() {
        if (input.files[0]) handleFile(input.files[0]);
    });

    function handleFile(file) {
        var ext = file.name.split('.').pop().toLowerCase();
        if (['xls','xlsx','csv'].indexOf(ext) === -1) {
            tip.textContent = '格式不支持，请上传 .xls / .xlsx / .csv';
            tip.style.color = '#dc3545';
            return;
        }
        tip.textContent = '解析中：' + file.name + ' ...';
        tip.style.color = '#6c757d';

        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                var data = new Uint8Array(e.target.result);
                // raw:true 完全不做任何转换，所有值保持原始
                var wb = XLSX.read(data, {type: 'array', raw: true});
                var ws = wb.Sheets[wb.SheetNames[0]];
                var range = XLSX.utils.decode_range(ws['!ref'] || 'A1');

                // 逐行逐列读取，完全原样，不做任何判断或转换
                var rows = [];
                for (var r = range.s.r; r <= range.e.r; r++) {
                    var row = [];
                    var hasVal = false;
                    for (var c = range.s.c; c <= range.e.c; c++) {
                        var cell = ws[XLSX.utils.encode_cell({r: r, c: c})];
                        var val = '';
                        if (cell && cell.v !== undefined && cell.v !== null) {
                            val = String(cell.v);
                            hasVal = true;
                        }
                        row.push(val);
                    }
                    if (hasVal) rows.push(row);
                }

                // 拼 JSON 传输（避免 CSV 逗号/换行导致列错位）
                var hidden = document.getElementById('csvData');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'csv_data';
                    hidden.id = 'csvData';
                    input.parentNode.appendChild(hidden);
                }
                hidden.value = JSON.stringify(rows);
                // 清空 file input，防止服务端走旧的文件解析
                input.value = '';
                tip.textContent = file.name + ' ✓（已解析 ' + (rows.length - 1) + ' 行数据）';
                tip.style.color = '#28a745';
            } catch(err) {
                tip.textContent = '解析失败：' + err.message;
                tip.style.color = '#dc3545';
            }
        };
        reader.readAsArrayBuffer(file);
    }
})();

<?php if ($expand_project !== ''): ?>
window.addEventListener('load', function() {
    if (window.jQuery && $('#orderDetailModal').modal) {
        $('#orderDetailModal').modal('show');
    }
});
<?php endif; ?>

// 月份批量删除：更新已选月份计数
$(function() {
    if (typeof $ === 'function' && $('.month-check').length) {
        $('.month-check').on('change', function() {
            var n = $('.month-check:checked').length;
            var sc = document.getElementById('monthSelCount');
            if (sc) sc.textContent = n;
        });
    }
});

// 删除模块全部订单
var jsEscape = function(s) { return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"'); };
function deleteProject(project, count, empId, dept, deptOrders) {
    if (!confirm('确定删除模块「' + project + '」的全部 ' + count + ' 条订单？此操作不可恢复！')) return;
    var f = document.createElement('form');
    f.method = 'post';
    var html = '<input type="hidden" name="action" value="delete_project">'
        + '<input type="hidden" name="project" value="' + jsEscape(project) + '">';
    if (empId > 0) html += '<input type="hidden" name="employee_id" value="' + empId + '">';
    if (dept) html += '<input type="hidden" name="department" value="' + jsEscape(dept) + '">';
    if (deptOrders) html += '<input type="hidden" name="dept_orders" value="1">';
    f.innerHTML = html;
    document.body.appendChild(f);
    f.submit();
}

// 取消选择 & 单条删除
(function() {
    window.clearSelection = function() {
        document.querySelectorAll('.row-check').forEach(function(cb) { cb.checked = false; });
        var ca = document.getElementById('checkAll');
        if (ca) { ca.checked = false; ca.indeterminate = false; }
        var bb = document.getElementById('batchBar');
        if (bb) bb.style.display = 'none';
        var st = document.getElementById('selectedCount');
        if (st) st.textContent = '已选 0 条';
    };

    window.deleteSingle = function(id) {
        if (!confirm('确定删除该订单？')) return;
        document.getElementById('singleDeleteId').value = id;
        document.getElementById('singleDeleteForm').submit();
    };
})();
</script>

<style>
.order-group-header {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    color: #343a40;
    transition: background .15s;
}
.order-group-header:hover { background: #e9ecef; color: #343a40; }
.abnormal-filter-badge { cursor: pointer; }
.abnormal-filter-badge:hover { filter: brightness(.95); }
.order-group-header.expanded {
    background: #fff3cd;
    border-color: #ffc107;
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
}
.order-detail-modal { max-width: min(1600px, 96vw); }
.order-detail-modal .modal-content { max-height: 92vh; }
.order-detail-modal .modal-body { overflow: hidden; }
.order-detail-table-wrap {
    max-height: calc(92vh - 170px);
    overflow: auto;
}
.order-detail-table { white-space: nowrap; }
.order-detail-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
}
</style>
<?php include __DIR__ . '/../includes/footer.php'; ?>
