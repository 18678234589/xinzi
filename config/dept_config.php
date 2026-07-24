<?php
/**
 * 网站售后部 独立配置文件
 *
 * 本文件为「网站售后部」专用配置，与通用 config/dept_fee.php 隔离。
 * 其他分支修改通用逻辑时不会影响网站售后部的提成计算。
 *
 * settle.php 在 loadEmployeeOrdersWithDept() 中优先加载本文件，
 * 获取部门手续费率、参与提成的员工列表及默认模块配置。
 *
 * 字段说明：
 * - dept_name        部门名称（与 departments 表 name 字段一致）
 * - service_fee_rate 部门订单手续费率（小数，0.03 = 3%）
 * - dept_share       部门订单是否参与提成（true/false）
 * - employees        部门员工列表，key=员工ID，value=该员工默认模块配置
 *   每个员工的 modules 数组结构与 algorithms/config_XX.json 中 modules 一致，
 *   当员工个人 JSON 配置缺失时可回退使用此默认值。
 *
 * 修改本文件不会影响其他部门；同理，修改 dept_fee.php 也不会影响本部门。
 */
return [
    'dept_name'        => '网站售后部',
    'service_fee_rate' => 0.03,
    'dept_share'       => true,

    // 网站售后部全员（6人），按员工ID索引
    'employees' => [
        14 => [
            'name' => '何静',
            'modules' => [
                ['name' => '续费2.2%', 'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.022, 'service_fee_rate' => '0.03']],
                ['name' => '修改20%',  'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.2,  'service_fee_rate' => '0']],
                ['name' => '底薪800',  'type' => 'base_salary', 'enabled' => true, 'config' => ['base_amount' => 800]],
            ],
        ],
        15 => [
            'name' => '何青青',
            'modules' => [
                ['name' => '续费2.2%', 'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.022, 'service_fee_rate' => '0.03']],
                ['name' => '修改20%',  'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.2,  'service_fee_rate' => '0']],
                ['name' => '底薪800',  'type' => 'base_salary', 'enabled' => true, 'config' => ['base_amount' => 800]],
            ],
        ],
        16 => [
            'name' => '邓美玲',
            'modules' => [
                ['name' => '续费2.2%', 'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.022, 'service_fee_rate' => '0.03']],
                ['name' => '修改20%',  'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.2,  'service_fee_rate' => '0']],
                ['name' => '底薪800',  'type' => 'base_salary', 'enabled' => true, 'config' => ['base_amount' => 800]],
            ],
        ],
        20 => [
            'name' => '陈鑫',
            'modules' => [
                ['name' => '续费2.2%', 'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.022, 'service_fee_rate' => '0.03']],
                ['name' => '修改20%',  'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.2,  'service_fee_rate' => '0']],
                ['name' => '底薪800',  'type' => 'base_salary', 'enabled' => true, 'config' => ['base_amount' => 800]],
            ],
        ],
        21 => [
            'name' => '张宁',
            'modules' => [
                ['name' => '续费2.2%', 'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.022, 'service_fee_rate' => '0.03']],
                ['name' => '修改20%',  'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.2,  'service_fee_rate' => '0']],
                ['name' => '底薪800',  'type' => 'base_salary', 'enabled' => true, 'config' => ['base_amount' => 800]],
            ],
        ],
        22 => [
            'name' => '胡蝶',
            'modules' => [
                ['name' => '续费2.2%', 'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.022, 'service_fee_rate' => '0.03']],
                ['name' => '修改20%',  'type' => 'standard', 'enabled' => true, 'config' => ['rate' => 0.2,  'service_fee_rate' => '0']],
                ['name' => '底薪800',  'type' => 'base_salary', 'enabled' => true, 'config' => ['base_amount' => 800]],
            ],
        ],
    ],
];
