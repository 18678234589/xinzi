<?php

            if (!$finance) throw new RuntimeException('无权限');
            $refundText = trim((string)($_POST['refund_amount'] ?? ''));
            $costText = trim((string)($_POST['cost_delta'] ?? ''));
            // 直接填“实际总成本”（如表格里的实际直接成本 2610）：系统用它减去订单现有已核实成本，得到成本调整额，不必自己算差额。
            $actualText = trim((string)($_POST['cost_actual'] ?? ''));
            if ($actualText !== '') {
                if ($costText !== '') throw new RuntimeException('“成本调整”和“改成实际总成本”只填一个');
                if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $actualText)) throw new RuntimeException('实际总成本须为不小于 0 的金额，最多两位小数');
                $currentCost = 0.0;
                foreach (ps_costs($id) as $c) if ($c['review_status'] === 'approved') $currentCost += (float)$c['amount'];
                $costText = (string)round((float)$actualText - $currentCost, 2);
                if ((float)$costText == 0.0) throw new RuntimeException('实际总成本与现有成本一致（¥' . money_plain($currentCost) . '），无需调整');
            }
            if (($refundText !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $refundText)) || ($costText !== '' && !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $costText))) throw new RuntimeException
    ('金额最多两位小数；成本调整可为负数（冲减）');
            $created = ps_post_adjustment($id, $actor, $refundText === '' ? 0 : $refundText, $costText === '' ? 0 : $costText, (string)($_POST['reason'] ?? ''), (string)($_POST['adjust_month'
    ] ?? ''));
            header('Location: ' . BASE_URL . '/project/order.php?id=' . $id . '&adjusted=' . count($created)); exit;
        