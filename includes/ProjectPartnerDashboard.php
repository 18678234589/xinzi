<?php
require_once __DIR__ . '/ProjectSettlement.php';

/** 只按订单归属月统计；分单权重用于归属金额，同一人跨岗位参与同单最多计 100%。 */
function ps_partner_orders(int $employeeId, string $from, string $until): array
{
    $sql = "SELECT o.id,o.order_no,o.order_date,o.project_type,o.contract_amount,o.receipt_amount,o.refund_amount,
                   o.delivery_status,o.settlement_status,p.weight,
                   (SELECT COUNT(*) FROM project_costs c WHERE c.order_id=o.id AND c.review_status='pending') AS pending_costs,
                   (SELECT COUNT(*) FROM project_refund_import_rows r WHERE r.order_id=o.id AND r.review_status='pending') AS pending_refunds
            FROM project_orders o
            JOIN (SELECT order_id,LEAST(1,SUM(GREATEST(0,group_weight))) AS weight
                  FROM project_participants WHERE employee_id=? GROUP BY order_id) p ON p.order_id=o.id
            WHERE o.order_date>=? AND o.order_date<? ORDER BY o.order_date DESC,o.id DESC";
    $q = db()->prepare($sql);
    $q->execute([$employeeId, $from, $until]);
    return $q->fetchAll();
}

function ps_partner_month_bounds(string $month): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new InvalidArgumentException('月份格式无效');
    $start = new DateTimeImmutable($month . '-01');
    return [$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')];
}

function ps_partner_summary(array $rows): array
{
    $s = ['orders' => count($rows), 'contract' => 0.0, 'receipts' => 0.0, 'refunds' => 0.0,
          'net' => 0.0, 'refund_rate' => null, 'businesses' => [], 'alerts' => [], 'alert_count' => 0, 'unverified_cash_orders' => 0];
    $catalog = ps_business_catalog();
    foreach ($rows as $row) {
        $weight = min(1.0, max(0.0, (float)$row['weight']));
        $contract = (float)$row['contract_amount'] * $weight;
        $receipt = (float)$row['receipt_amount'] * $weight;
        $refund = (float)$row['refund_amount'] * $weight;
        $s['contract'] += $contract;
        $s['receipts'] += $receipt;
        $s['refunds'] += $refund;
        $business = ps_business_normalize((string)$row['project_type']);
        if (!isset($catalog[$business])) $business = '其他业务';
        if (!isset($s['businesses'][$business])) $s['businesses'][$business] = ['orders' => 0, 'net' => 0.0, 'refunds' => 0.0];
        $s['businesses'][$business]['orders']++;
        $s['businesses'][$business]['net'] += $receipt - $refund;
        $s['businesses'][$business]['refunds'] += $refund;
        $flags = [];
        if ($receipt <= 0 && $contract > 0) {
            $s['unverified_cash_orders']++;
            if (in_array($row['settlement_status'], ['review','approved','locked'], true)) $flags[] = '待核对已审核实收';
        }
        if ($receipt > 0 && $refund / $receipt >= 0.2) $flags[] = '退款占实收达到 20%';
        if ((int)$row['pending_costs'] > 0) $flags[] = '有待审核成本';
        if ((int)$row['pending_refunds'] > 0) $flags[] = '有待核对退款';
        if ($flags) {
            $s['alerts'][] = ['order_id' => (int)$row['id'], 'order_no' => (string)$row['order_no'],
                              'order_date' => (string)$row['order_date'], 'flags' => $flags];
        }
    }
    $s['net'] = $s['receipts'] - $s['refunds'];
    if ($s['receipts'] > 0) $s['refund_rate'] = $s['refunds'] / $s['receipts'];
    $s['alert_count'] = count($s['alerts']);
    uasort($s['businesses'], static fn($a, $b) => ($b['net'] <=> $a['net']) ?: ($b['orders'] <=> $a['orders']));
    foreach (['contract','receipts','refunds','net'] as $key) $s[$key] = round($s[$key], 2);
    return $s;
}

function ps_partner_fallback_guidance(array $s): array
{
    $business = array_key_first($s['businesses']) ?: '当前业务';
    $tips = [
        'AI网站定制' => '把需求确认、交付验收和后续维护拆成清晰节点，优先复盘返工最多的环节。',
        '网站模板' => '核对模板适配、上线检查和域名续期提醒，降低重复沟通。',
        '小程序开发' => '上线前核实平台审核、支付配置和客户验收清单。',
        '网站续费' => '提前整理即将到期的域名与服务器，试行到期前提醒。',
        '网站修改' => '区分原需求和新增改动，确认范围后再排期。',
        '设计' => '用简短的风格确认和交付清单减少反复修改。',
        '软文代写' => '记录选题、审稿轮次与交稿时间，找出返工原因。',
        '期刊' => '对刊期和材料完整度设检查点，不对录用时间作保证。',
    ];
    $paths = [
        'AI网站定制' => '从单纯页面制作延伸到需求澄清、AI 工具集成、数据安全和上线验收；先做一个有真实反馈的小样。',
        '网站模板' => '把模板搭建经验升级为客户需求诊断、迁移维护、性能与安全检查，练习解释方案价值。',
        '小程序开发' => '在开发之外学习平台规则、支付与数据联通、验收测试，积累完整交付案例。',
        '网站续费' => '把到期提醒与客户沟通经验转成账户健康检查、续费方案和客户成功服务。',
        '网站修改' => '将常见修改流程产品化，学习需求拆解、质量检查与自动化工具使用。',
        '设计' => '在工具提速的同时强化品牌理解、创意判断、客户沟通和设计验收。',
        '软文代写' => '从重复写稿走向采访研究、事实核验、编辑策划和效果复盘。',
        '期刊' => '从材料传递走向合规核查、选题沟通、流程管理与客户风险说明。',
    ];
    if (!$s['orders']) {
        return ['strength' => '这个月还没有关联到你的订单；先检查参与人是否已正确关联。',
                'risk' => '现在的数据还不够完整，我们先不急着判断表现或趋势。',
                'opportunity' => '先积累完整订单、收款和退款记录，再比较业务方向。',
                'future_path' => '现有数据不足以判断个人优势或业务替代风险。先记录项目过程，再与平台一起挑一项可迁移技能做小范围学习。',
                'plan' => ['核对订单参与人和分单权重', '补全订单日期与实收记录', '下月再看趋势'],
                'encouragement' => '不用着急，我们先把每一步记录清楚，再一起看见新的可能。'];
    }
    return ['strength' => '你上月参与了 ' . $s['orders'] . ' 笔订单；' . $business . '是当前主要业务方向。',
            'risk' => $s['alert_count'] ? '有 ' . $s['alert_count'] . ' 笔订单值得和平台一起再核对一下，先从下方清单开始就好。' : ($s['unverified_cash_orders'] ? '还有 ' . $s['unverified_cash_orders'] . ' 笔订单等待收款核对，现阶段不适合用净实收或退款率评价你的表现。' : '目前没有需要特别留意的订单；有空时仍可以抽查收款和交付记录。'),
            'opportunity' => '可把 ' . $business . ' 的服务流程做成可复用清单，并通过实际转化和退款数据验证效果；这不是行业预测。',
            'future_path' => '随着工具帮我们分担更多重复性工作，可以和平台共创团队一起，把在 ' . $business . ' 积累的经验带到更需要判断与沟通的环节：' . ($paths[$business] ?? '学习客户需求分析、质量核验与 AI 工具协作，并在平台项目中验证。'),
            'plan' => [$s['alert_count'] ? '和平台一起核对待关注订单与退款原因' : ($s['unverified_cash_orders'] ? '和财务一起核对收款是否已经同步' : '和平台一起抽查交付与客户反馈'), $tips[$business] ?? '整理常见需求与交付清单，找出可标准化的步骤。', '月底与平台比较订单数、净实收和退款率，保留有效做法'],
            'encouragement' => '你的经验不是一张数据表能概括的。我们把每次认真交付积累起来，一起慢慢变得更好。'];
}

function ps_partner_ai_input(array $s, array $previous, string $month): array
{
    return ['guidance_version' => 6, 'period' => $month, 'orders' => $s['orders'], 'contract' => $s['contract'],
            'receipts' => $s['receipts'], 'refunds' => $s['refunds'], 'net' => $s['net'],
            'refund_rate' => $s['refund_rate'] === null ? null : round($s['refund_rate'] * 100, 2),
            'alert_count' => $s['alert_count'], 'unverified_cash_orders' => $s['unverified_cash_orders'], 'businesses' => $s['businesses'],
            'previous_orders' => $previous['orders'], 'previous_net' => $previous['net']];
}

function ps_partner_ai_text(string $value, int $limit): string
{
    $clean = trim(strip_tags($value));
    if (mb_strlen($clean) <= $limit) return $clean;
    $cut = mb_substr($clean, 0, $limit);
    $stop = -1;
    foreach (['。','！','？','；'] as $mark) $stop = max($stop, (int)mb_strrpos($cut, $mark));
    if ($stop >= (int)($limit * .55)) return mb_substr($cut, 0, $stop + 1);
    return rtrim($cut, '，、；：,;: ') . '…';
}

function ps_partner_ai_validate(array $value, array $input = []): array
{
    $out = [];
    foreach (['strength','risk','opportunity','future_path','encouragement'] as $key) {
        if (!isset($value[$key]) || !is_string($value[$key])) throw new RuntimeException('AI 建议格式不完整');
        $out[$key] = ps_partner_ai_text($value[$key], $key === 'future_path' ? 190 : ($key === 'encouragement' ? 80 : 150));
        if ($out[$key] === '') throw new RuntimeException('AI 建议内容为空');
    }
    if (!isset($value['plan']) || !is_array($value['plan'])) throw new RuntimeException('AI 行动计划格式不完整');
    $out['plan'] = [];
    foreach (array_slice($value['plan'], 0, 3) as $step) {
        if (is_string($step) && trim($step) !== '') $out['plan'][] = ps_partner_ai_text($step, 80);
    }
    if (count($out['plan']) < 2) throw new RuntimeException('AI 行动计划不足');
    $text = implode(' ', [$out['strength'], $out['risk'], $out['opportunity'], $out['future_path'], $out['encouragement'], ...$out['plan']]);
    if (preg_match('/私单|绕开平台|脱离平台|离开平台|独立接单|跳槽/u', $text)) throw new RuntimeException('AI 建议不符合平台共创要求');
    if (($input['unverified_cash_orders'] ?? 0) > 0 && preg_match('/完成了.{0,12}笔订单|订单量稳定|收入稳定|长期处理/u', $text)) throw new RuntimeException('AI 建议超出已核对数据');
    return $out;
}

function ps_partner_ai_generate(array $input, array $groundedFallback): array
{
    $system = '你是项目合作结算中心的经营复盘助手。只根据提供的匿名汇总数据给个人合作方中文建议。'
        . '不要编造行业数据、市场增长率、客户事实或实时趋势；未来机会要写成可验证的假设。'
        . '若 unverified_cash_orders 较多或 receipts 为零，应明确数据尚未核对，不能把净实收、退款率或月度变化解释成个人能力或业务成败。'
        . 'orders 仅表示参与的订单记录，不代表已经交付、已完成或已收款。单月数据不能推断长期经验、稳定增长或个人能力；previous_orders 为零时尤其不能说稳定。'
        . '未审核实收可能是旧数据尚未同步，应建议与财务核对数据来源或批量同步，不要要求合作方逐笔找全部付款凭证。'
        . '加入职业韧性建议：指出该业务中可能被自动化的重复环节，但不能断言个人岗位必然被取代。只能从业务类型和订单表现推断可能的可迁移优势，不能捏造个人能力。'
        . '建议一条低成本、可验证的学习或相邻业务转型路径，强调人类判断、客户沟通、质量核验与 AI 协作中最贴切的能力。'
        . '所有改进、学习与新服务试点都必须以和当前共创平台共同推进为前提；不要建议私下接单、绕开平台、离开平台或跳槽。'
        . '退款与待审核只是核对线索，不要直接归咎于个人，也不要用收入评价人的价值。先肯定具体付出，再温和提醒，再给可执行的小步骤。'
        . '语气温柔、治愈、共情、平等，像并肩陪伴而非考核或说教；不制造被替代的恐慌，不空泛奉承，也不揣测当事人的情绪。'
        . '文字简洁完整：strength、risk、opportunity 各不超过100汉字，future_path 不超过140汉字，encouragement 不超过50汉字，plan每条不超过55汉字。'
        . '只返回 JSON 对象：strength(做得好的具体点),risk(待改进与局限),opportunity(未来3个月可验证的业务机会),future_path(自动化情景、可迁移优势、学习及转型试点),plan(恰好3条下月行动),encouragement(一句鼓励)。';
    $reply = ps_ai_chat([['role' => 'system', 'content' => $system],
                         ['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)]], 1100);
    $insight = ps_partner_ai_validate(ps_ai_json($reply), $input);
    // 系统没有个人技能档案，不能让模型把业务经历猜成已具备的能力。
    $insight['future_path'] = $groundedFallback['future_path'];
    if (($input['previous_orders'] ?? 0) === 0 || ($input['unverified_cash_orders'] ?? 0) >= ($input['orders'] ?? 0)) {
        $insight['strength'] = $groundedFallback['strength'];
    }
    return $insight;
}
