<?php
trait ModulesTypesTrait
{
    public static function getAvailableTypes()
    {
        return [
            'standard' => [
                'label' => '标准比例项目分成',
                'icon' => 'fa-percentage',
                'color' => 'primary',
                'desc' => '按订单总额的固定比例计算',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：续费、新单、线下渠道、淘宝店A','default'=>''],
                    ['key'=>'rate','label'=>'项目分成比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.018=1.8%, 0.05=5%','default'=>''],
                    ['key'=>'service_fee_rate','label'=>'手续费扣除比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.03=3%, 0表示不扣除','default'=>'0','desc'
    =>'上传订单时按此比例从售价中扣除手续费'],
                    ['key'=>'min_amount','label'=>'最小订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制，如 50','default'=>''],
                    ['key'=>'max_amount','label'=>'最大订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制，如 10000','default'=>''],
                    ['key'=>'shop_keyword','label'=>'店铺关键字','type'=>'text','placeholder'=>'留空=不限制，如：老客户','default'=>''],
                    ['key'=>'price_source','label'=>'金额来源','type'=>'select','options'=>['order_amount'=>'订单金额（利润）','selling_price'=>'售价（需提取价格/成本列）'
    ],'default'=>'order_amount','desc'=>'售价模式时，手续费按售价总额计算'],
                    ['key'=>'filter_column','label'=>'按列值筛选（列名）','type'=>'text','placeholder'=>'留空=不筛选，如：技术','default'=>''],
                    ['key'=>'filter_value','label'=>'按列值筛选（值）','type'=>'text','placeholder'=>'列值精确匹配，如：纪鹏程','default'=>''],
                ],
            ],
            'tiered' => [
                'label' => '阶梯项目分成',
                'icon' => 'fa-stairs',
                'color' => 'warning',
                'desc' => '按订单总额分档，越高档项目分成越多',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：续费阶梯、新单阶梯','default'=>''],
                    ['key'=>'service_fee_rate','label'=>'手续费扣除比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.03=3%, 0表示不扣除','default'=>'0','desc'
    =>'上传订单时按此比例从售价中扣除手续费'],
                    ['key'=>'min_amount','label'=>'最小订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制，如 50','default'=>''],
                    ['key'=>'max_amount','label'=>'最大订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制，如 10000','default'=>''],
                    ['key'=>'shop_keyword','label'=>'店铺关键字','type'=>'text','placeholder'=>'留空=不限制，如：老客户','default'=>''],
                ],
            ],
            'per_order' => [
                'label' => '每笔订单奖励',
                'icon' => 'fa-receipt',
                'color' => 'info',
                'desc' => '按订单笔数计算固定项目分成+可选奖励',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：续费单奖、新单奖励','default'=>''],
                    ['key'=>'per_amount','label'=>'每笔项目分成','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 80 元','default'=>'80'],
                    ['key'=>'per_reward','label'=>'每笔额外奖励','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'可选，如 20','default'=>'0'],
                    ['key'=>'service_fee_rate','label'=>'手续费扣除比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.03=3%, 0表示不扣除','default'=>'0','desc'
    =>'上传订单时按此比例从售价中扣除手续费'],
                    ['key'=>'count_column','label'=>'计数列名','type'=>'text','placeholder'=>'填列名如"域名"，按该列去重计数；留空则按订单笔数','default'
    =>''],
                    ['key'=>'count_distinct','label'=>'是否去重','type'=>'select','options'=>['是'=>'是（按列值去重计数）','否'=>'否（按列值非空计数）'],'default'
    =>'是'],
                ],
            ],
            'attendance_full' => [
                'label' => '全勤项目奖励',
                'icon' => 'fa-calendar-check',
                'color' => 'success',
                'desc' => '出满勤给予固定全勤项目奖励，请假按小时折算或扣除',
                'fields' => [
                    ['key'=>'full_amount','label'=>'全勤项目奖励额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 200','default'=>'200'],
                    ['key'=>'deduct_mode','label'=>'请假扣除方式','type'=>'select','options'=>['none'=>'不扣除（请假照发）','prorate'=>'按小时比例折算','fixed'
    =>'每小时扣固定金额'],'default'=>'none'],
                    ['key'=>'work_hours','label'=>'当月应出勤总小时数','type'=>'number','step'=>'0.1','min'=>'0','placeholder'=>'如 22天×8小时=176','default'=>'176'],
                    ['key'=>'absent_hours','label'=>'当月请假小时数','type'=>'number','step'=>'0.1','min'=>'0','placeholder'=>'每月结算时填写，如 4=半天','default'
    =>'0'],
                    ['key'=>'absent_threshold_hours','label'=>'请假阈值(小时)','type'=>'number','step'=>'0.1','min'=>'0','placeholder'=>'请假超过此值全勤奖归0，留空=不限制'
    ,'default'=>''],
                ],
            ],
            'attendance_daily' => [
                'label' => '考勤日薪',
                'icon' => 'fa-calendar-day',
                'color' => 'teal',
                'desc' => '按实际出勤天数乘以日薪计算',
                'fields' => [
                    ['key'=>'work_days','label'=>'本月出勤天数','type'=>'number','step'=>'1','min'=>'0','max'=>'31','placeholder'=>'实际出勤天数','default'=>'22'],
                    ['key'=>'daily_rate','label'=>'日薪金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'每日项目报酬','default'=>'150'],
                ],
            ],
            'attendance_deduct' => [
                'label' => '缺勤扣款',
                'icon' => 'fa-calendar-times',
                'color' => 'danger',
                'desc' => '按缺勤天数扣减项目报酬',
                'fields' => [
                    ['key'=>'absent_days','label'=>'缺勤天数','type'=>'number','step'=>'1','min'=>'0','placeholder'=>'缺勤天数','default'=>'0'],
                    ['key'=>'deduct_per_day','label'=>'每天扣款','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'每缺勤一天扣多少','default'=>'100'],
                ],
            ],
            'base_salary' => [
                'label' => '固定服务费（自定义）',
                'icon' => 'fa-coins',
                'color' => 'secondary',
                'desc' => '自定义该合作人员固定服务费金额，会覆盖合作人员表中录入的固定服务费；不配置则用合作人员表固定服务费',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：固定服务费','default'=>''],
                    ['key'=>'base_amount','label'=>'固定服务费金额','type'=>'number','step'=>'any','placeholder'=>'如 3300','default'=>3300],
                ],
            ],
            'profit_commission' => [
                'label' => '标书项目分成',
                'icon' => 'fa-coins',
                'color' => 'info',
                'desc' => '基于订单金额和成本计算：((订单金额 - 成本) - 订单金额×服务费比例) × 项目分成比例',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：成本项目分成A','default'=>''],
                    ['key'=>'commission_rate','label'=>'项目分成比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.1=10%','default'=>''],
                    ['key'=>'service_fee_rate','label'=>'服务费比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.05=5%','default'=>''],
                ],
            ],
            'trademark_commission' => [
                'label' => '商标部项目分成',
                'icon' => 'fa-trademark',
                'color' => 'primary',
                'desc' => '基于售价和成本计算；((售价 - 成本) - 售价×服务费比例) × 项目分成比例（负数也参与计算）',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：商标部项目分成','default'=>''],
                    ['key'=>'commission_rate','label'=>'项目分成比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.1=10%','default'=>''],
                    ['key'=>'service_fee_rate','label'=>'服务费比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.05=5%','default'=>''],
                ],
            ],
            'trademark_cashback' => [
                'label' => '商标部小额返现项目分成',
                'icon' => 'fa-money-bill-wave',
                'color' => 'success',
                'desc' => '筛选备注包含"小额返"的订单，按单量×项目分成金额计算',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：小额返现项目分成','default'=>''],
                    ['key'=>'per_amount','label'=>'每单项目分成金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 10 元/单','default'=>'10'],
                ],
            ],
            'base_salary_tiered' => [
                'label' => '固定服务费（阶梯）',
                'icon' => 'fa-layer-group',
                'color' => 'secondary',
                'desc' => '按订单总额分档的固定服务费',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：阶梯固定服务费','default'=>''],
                ],
            ],
            'referral_order' => [
                'label' => '引流订单',
                'icon' => 'fa-bullhorn',
                'color' => 'purple',
                'desc' => '设置每单补助金额，按指定列内容筛选符合条件的订单数量',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：引流、小红书引流','default'=>''],
                    ['key'=>'subsidy','label'=>'每个订单补助金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 5 元/单','default'=>'5'],
                    ['key'=>'count_column','label'=>'计数列名','type'=>'text','placeholder'=>'填列名如"建站订单"，按该列内容筛选计数','default'=>''],
                    ['key'=>'count_keyword','label'=>'关键词（+分隔）','type'=>'text','placeholder'=>'如"拍+链接"，按下方匹配方式筛选该列值','default'=>''],
                    ['key'=>'count_keyword_match','label'=>'关键词匹配方式','type'=>'select','options'=>['all'=>'同时包含所有词(且)','any'=>'包含任一词(或)'],'default'
    =>'all'],
                    ['key'=>'count_mode','label'=>'计数模式','type'=>'select','options'=>['keyword'=>'关键词匹配(按列+关键词计数)','staff_match'=>'接单客服匹配(按合作人员姓名匹配+旺旺日期去重)'
    ],'default'=>'keyword'],
                ],
            ],
            'customer_reward' => [
                'label' => '新老客户订单奖励',
                'icon' => 'fa-users',
                'color' => 'purple',
                'desc' => '根据个人订单备注识别新老客户，按客户旺旺号去重计算奖励',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：新老客户奖励','default'=>''],
                    ['key'=>'new_customer_reward','label'=>'新客户奖励金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'每个新客户奖励金额','default'
    =>'50'],
                    ['key'=>'old_customer_reward','label'=>'老客户奖励金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'每个老客户奖励金额','default'
    =>'30'],
                ],
            ],
            'miniprogram_commission' => [
                'label' => '小程序项目分成',
                'icon' => 'fa-mobile-alt',
                'color' => 'success',
                'desc' => '小程序订单项目分成：利润项目分成 + 新老客户补助',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：小程序项目分成','default'=>''],
                    ['key'=>'commission_rate','label'=>'项目分成比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.1=10%','default'=>''],
                    ['key'=>'service_fee_rate','label'=>'服务费比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.006=0.6%','default'=>''],
                    ['key'=>'filter_column','label'=>'新老客户筛选字段名','type'=>'text','placeholder'=>'如：订单类型','default'=>''],
                    ['key'=>'filter_value','label'=>'新客户字段值','type'=>'text','placeholder'=>'如：新订单','default'=>''],
                    ['key'=>'customer_subsidy','label'=>'新客户补助金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 20 元/单','default'=>'0'],
                ],
            ],
            'fixed_subsidy' => [
                'label' => '固定补助',
                'icon' => 'fa-hand-holding-usd',
                'color' => 'info',
                'desc' => '每月固定金额补助，不随订单量变化',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：交通补助、餐补、住房补贴','default'=>''],
                    ['key'=>'amount','label'=>'补助金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 500 元/月','default'=>'500'],
                ],
            ],
            'cs_performance' => [
                'label' => '客服绩效固定服务费',
                'icon' => 'fa-comments-dollar',
                'color' => 'success',
                'desc' => '部门已配置绩效时自动按「部门基数×综合达成率」计算（无需此模块）；本模块仅在该合作人员所在部门未配置绩效时作为兜底（使用下方旧参数：回复速度/进线人数/转化率加权，可设保底与封顶）。绩效数据在「客服绩效」页上传官网导出表后自动匹配'
    ,
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：绩效固定服务费','default'=>''],
                    ['key'=>'base','label'=>'绩效基数(元)','type'=>'number','step'=>'any','placeholder'=>'全达成时发放金额，如 3300','default'=>'3300'],
                    ['key'=>'target_reply_sec','label'=>'目标回复速度(秒)','type'=>'number','step'=>'1','placeholder'=>'如 60 = 平均60秒内首次回复','default'=>''],
                    ['key'=>'target_incoming','label'=>'目标进线人数(个)','type'=>'number','step'=>'1','placeholder'=>'当月进线会话目标数','default'=>''],
                    ['key'=>'target_conversion_pct','label'=>'目标转化率(%)','type'=>'number','step'=>'any','placeholder'=>'如 30 表示 30%','default'=>''],
                    ['key'=>'weight_reply','label'=>'回复速度权重','type'=>'number','step'=>'any','placeholder'=>'如 2','default'=>'1'],
                    ['key'=>'weight_incoming','label'=>'进线人数权重','type'=>'number','step'=>'any','placeholder'=>'如 1','default'=>'1'],
                    ['key'=>'weight_conversion','label'=>'转化率权重','type'=>'number','step'=>'any','placeholder'=>'如 1','default'=>'1'],
                    ['key'=>'floor_pct','label'=>'保底比例(%)','type'=>'number','step'=>'any','placeholder'=>'如 50=至少发基数的50%，0=不保底','default'=>'0'],
                    ['key'=>'cap_pct','label'=>'封顶比例(%)','type'=>'number','step'=>'any','placeholder'=>'如 120=最多发基数的120%，0=不封顶','default'=>'0'],
                ],
            ],
        ];
    }

}
