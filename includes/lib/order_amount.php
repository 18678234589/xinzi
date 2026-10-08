<?php

/**
 * 格式化金额
 */
function money($amount)
{
    return number_format((float)$amount, 2, '.', ',');
}

/**
 * 从单元格值安全提取金额数字。
 *
 * 支持多种录入习惯：
 *  - 纯数字："4500" / "-1200"
 *  - 货币/千分位："¥1,200.50" / "1,200"
 *  - 成本等写成算式的："999+999+150+900"（=3048）、"(100+50)*2"
 *  - 只有 + 减 乘 除 与括号、小数点，其余字符一律剔除
 *
 * 无法解析时返回 0.0。避免把"成本=999+999+150+900"剥符号拼成天文数字。
 *
 * @param mixed $v
 * @return float
 */
function extract_amount($v)
{
    $s = trim((string)$v);
    if ($s === '') return 0.0;

    // 仅保留数字和四则运算符号、括号、小数点、空格
    $expr = trim(preg_replace('~[^0-9+\-*/(). ]~', '', $s));
    if ($expr === '' || $expr === '-' || $expr === '+' || $expr === '.') return 0.0;

    // 纯数字（含负数/小数）：直接返回
    if (preg_match('/^-?\d+(\.\d+)?$/', $expr)) {
        return (float)$expr;
    }

    // 其余按四则运算求值（安全：输入已被过滤为仅含数字与运算符）
    try {
        $val = eval_amount_expr($expr);
        return is_finite($val) ? $val : 0.0;
    } catch (\Throwable $e) {
        return 0.0;
    }
}

/**
 * 解析 SSL 证书使用金额（根据业务新规则）
 *
 * 规则（优先级从高到低）：
 *  1. 值含"通配符"字眼 → 250 元（通配符证书）
 *  2. 有明确数字 → 该数字（如 "30" → 30，"真实成本250元" → 250）
 *  3. 模糊"已购买"标记（是/一年/1/true 等）→ 30 元
 *  4. 空或无 SSL 信息 → null（表示未使用）
 *
 * @param mixed $v
 * @return float|null 金额；未使用/无法识别时返回 null
 */
function parse_ssl_amount($v)
{
    $sv = trim((string)$v);
    if ($sv === '') return null;

    // 通配符证书统一 250
    if (mb_strpos($sv, '通配符') !== false) return 250.0;

    // 有明确数字按数字（如 "30"、"真实成本250元"）
    $num = extract_amount($sv);
    if ($num > 0) return $num;

    // 模糊"已购买"标记 → 30
    if ($sv === '1' || $sv === 'true'
        || mb_strpos($sv, '是') !== false || mb_strpos($sv, '一年') !== false) {
        return 30.0;
    }

    return null;
}

/**
 * 解析域名使用年限：返回 0 = 未使用域名；否则返回年限（>=1）
 *
 * 识别规则：
 *  - 否 / 空 → 0（未使用域名）
 *  - "是"/"1"/"true" → 1 年
 *  - "是*4年" / "4年" / "四年" → 4 年（域名成本按 4 倍计）
 *  - 其它含"是"但无明确年限 → 默认 1 年
 *
 * @param mixed $val
 * @return int 年限；0 表示未使用
 */
function domain_years($val)
{
    $s = trim((string)$val);
    if ($s === '') return 0;
    if (mb_strpos($s, '否') !== false) return 0;   // 否 = 未使用域名

    // 阿拉伯数字年限："4年"、"是*4年"
    if (preg_match('/(\d+)\s*年/', $s, $m)) return (int)$m[1];

    // 中文数字年限："四年"、"两年" …
    $cn = ['一' => 1, '两' => 2, '二' => 2, '三' => 3, '四' => 4,
           '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9, '十' => 10];
    foreach ($cn as $c => $n) {
        if (mb_strpos($s, $c . '年') !== false) return $n;
    }

    // 明确"是"而未写年限 → 1 年
    if (mb_strpos($s, '是') !== false || $s === '1' || $s === 'true') return 1;

    return 0;
}

/**
 * 求值单值金额表达式（仅支持 + - * / 与括号、小数、一元正负号）。
 * 输入必须是已经 extract_amount 过滤、仅含数字和运算符的字符串。
 *
 * @param string $expr
 * @return float
 * @throws \RuntimeException
 */
function eval_amount_expr($expr)
{
    $expr = str_replace(['(', ')', '+', '-', '*', '/'],
                        [' ( ', ' ) ', ' + ', ' - ', ' * ', ' / '], $expr);
    $tokens = array_values(array_filter(preg_split('/\s+/', trim($expr)), fn($t) => $t !== ''));

    $ops      = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
    $numStack = [];
    $opStack  = [];
    $needOperand = true; // 期待操作数（用于一元正负号）

    $applyTop = function () use (&$numStack, &$opStack, $ops) {
        $op = array_pop($opStack);
        $b  = array_pop($numStack);
        $a  = array_pop($numStack);
        if ($op === '+')      $r = $a + $b;
        elseif ($op === '-')  $r = $a - $b;
        elseif ($op === '*')  $r = $a * $b;
        else { if ($b == 0) throw new \RuntimeException('division by zero'); $r = $a / $b; }
        $numStack[] = $r;
    };

    foreach ($tokens as $t) {
        if ($t === '(') {
            $opStack[] = '(';
            $needOperand = true;
        } elseif ($t === ')') {
            while ($opStack && end($opStack) !== '(') $applyTop();
            if (!$opStack) throw new \RuntimeException('unmatched )');
            array_pop($opStack);
            $needOperand = false;
        } elseif (isset($ops[$t])) {
            // 一元正负号：期待操作数时，把 -x 当作 0-x，+x 忽略
            if ($needOperand) {
                if ($t === '-') $numStack[] = 0.0;
                $needOperand = false;
            }
            while ($opStack && end($opStack) !== '(' && $ops[end($opStack)] >= $ops[$t]) $applyTop();
            $opStack[] = $t;
            $needOperand = true;
        } else {
            if (!preg_match('/^-?\d+(\.\d+)?$/', $t)) throw new \RuntimeException('bad token ' . $t);
            $numStack[] = (float)$t;
            $needOperand = false;
        }
    }
    while ($opStack) {
        if (end($opStack) === '(') { array_pop($opStack); continue; }
        $applyTop();
    }
    if (count($numStack) !== 1) throw new \RuntimeException('expression parse failed');
    return $numStack[0];
}

/**
 * 获取订单的手续费信息（用于前端展示）
 *
 * 优先级：
 * 1. raw_data 里存的 __fee_rate__（新上传的订单）
 * 2. 员工算法配置里对应模块的 service_fee_rate
 * 3. config/dept_fee.php 按部门名查
 *
 * @param array $rawData  订单的 raw_data 解码后的数组
 * @param array $order    订单记录（含 order_amount, employee_id, project, order_scope 等）
 * @return array ['rate'=>费率, 'amount'=>手续费, 'original_price'=>原售价, 'net'=>净额]
 */
function get_order_fee_info($rawData, $order)
{
    // 1. 优先用 raw_data 里存的费率
    $feeRate = (float)($rawData['__fee_rate__'] ?? 0);
    $feeAmount = (float)($rawData['__fee_amount__'] ?? 0);
    $origPrice = (float)($rawData['__original_price__'] ?? 0);

    if ($feeRate > 0) {
        return [
            'rate'          => $feeRate,
            'amount'        => $feeAmount,
            'original_price' => $origPrice,
            'net'           => (float)$order['order_amount'],
        ];
    }

    // 2. 回退：从算法配置或 dept_fee.php 查费率
    $deptName = $rawData['__dept__'] ?? '';
    $employeeId = (int)($order['employee_id'] ?? 0);
    $project = $order['project'] ?? '';
    $scope = $order['order_scope'] ?? 'personal';

    $feeRate = 0;
    $moduleMatched = false; // 是否按 project 名匹配到了模块

    // 2a. 查员工算法配置里的 service_fee_rate（按 project 名精确匹配模块）
    if ($employeeId > 0 && class_exists('SalaryCalculator', false)) {
        $modCfg = SalaryCalculator::readModulesConfig($employeeId);
        if ($modCfg && !empty($modCfg['modules'])) {
            // 优先按 project 名匹配模块，匹配到就用该模块的费率（即使为 0）
            if ($project !== '') {
                foreach ($modCfg['modules'] as $m) {
                    if (($m['enabled'] ?? true) && $m['name'] === $project) {
                        $feeRate = (float)($m['config']['service_fee_rate'] ?? 0);
                        $moduleMatched = true;
                        break;
                    }
                }
            }
            // 没匹配到 project，取第一个有费率的模块
            if (!$moduleMatched) {
                foreach ($modCfg['modules'] as $m) {
                    if ($m['enabled'] ?? true) {
                        $sfr = (float)($m['config']['service_fee_rate'] ?? 0);
                        if ($sfr > 0) { $feeRate = $sfr; break; }
                    }
                }
            }
        }
    }

    // 2b. 回退到 dept_config.php / dept_fee.php（仅当模块未匹配且为部门订单时）
    if (!$moduleMatched && $feeRate === 0 && $deptName !== '') {
        // 优先查 dept_config.php（网站售后部独立配置，隔离其他分支修改）
        static $deptConfigMap = null;
        if ($deptConfigMap === null) {
            $deptConfigFile = (dirname(__DIR__, 1)) . '/../config/dept_config.php';
            if (file_exists($deptConfigFile)) {
                $dc = include $deptConfigFile;
                if (is_array($dc) && isset($dc['dept_name'], $dc['service_fee_rate'])) {
                    $deptConfigMap = [$dc['dept_name'] => (float)$dc['service_fee_rate']];
                } else {
                    $deptConfigMap = [];
                }
            } else {
                $deptConfigMap = [];
            }
        }
        if (isset($deptConfigMap[$deptName])) {
            $feeRate = $deptConfigMap[$deptName];
        }

        // 再回退到 dept_fee.php（通用部门费率配置）
        if ($feeRate === 0) {
            static $deptFeeMap = null;
            if ($deptFeeMap === null) {
                $deptFeeFile = (dirname(__DIR__, 1)) . '/../config/dept_fee.php';
                if (file_exists($deptFeeFile)) {
                    $deptFeeMap = include $deptFeeFile;
                    if (!is_array($deptFeeMap)) $deptFeeMap = [];
                } else {
                    $deptFeeMap = [];
                }
            }
            $feeRate = isset($deptFeeMap[$deptName]) ? (float)$deptFeeMap[$deptName] : 0;
        }
    }

    if ($feeRate > 0) {
        // 从 raw_data 里找售价（价格列）
        $price = 0;
        foreach ($rawData as $k => $v) {
            if (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false) {
                $price = extract_amount($v);
                if ($price > 0) break;
            }
        }
        // 找不到售价，用 order_amount + 手续费反推
        if ($price <= 0) {
            $price = round((float)$order['order_amount'] / (1 - $feeRate), 2);
        }
        $feeAmount = round($price * $feeRate, 2);
        return [
            'rate'          => $feeRate,
            'amount'        => $feeAmount,
            'original_price' => $price,
            'net'           => (float)$order['order_amount'],
        ];
    }

    return ['rate' => 0, 'amount' => 0, 'original_price' => 0, 'net' => (float)$order['order_amount']];
}
