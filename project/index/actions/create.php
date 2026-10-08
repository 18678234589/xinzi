<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    if (!in_array($actor['role'], ['finance', 'customer_service', 'technical'], true)) { http_response_code(403); exit('无权限'); }
    try {
        $no = ps_order_no_resolve($_POST['order_no'] ?? '');
        $date = trim((string)($_POST['order_date'] ?? ''));
        $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
        $contract = trim((string)($_POST['contract_amount'] ?? ''));
        $receipt = $actor['role'] === 'finance' ? (string)($_POST['receipt_amount'] ?? '') : '0';
        if ($receipt === '') $receipt = '0';
        $projectType = (string)($_POST['project_type'] ?? '');
        $business = ps_require_business($actor, $projectType);
        if ($no === '' && $paymentReference !== '') $no = ps_payment_reference_order_no($projectType, $paymentReference);
        // 微信付款客服没拿到单号：勾选“没有订单号”后系统生成内部号（拿到真实单号后在订单页补录）
        if ($no === '' && $paymentReference === '' && !empty($_POST['no_order_no'])) $no = 'WX-' . strtoupper(substr(hash('sha256', 'noorder|' . $projectType . '|' . $date . '|' . $contract . '|' . microtime(true) . '|' . bin2hex(random_bytes(6))), 0, 24));
        // 续费所需资料：网站客服 / 售后填客户手机号，网站技术填域名，小程序填服务器到期日；暂时拿不到可勾选“稍后补充”（未补充将不会获得本订单的续费分成）
        $custPhone = trim((string)($_POST['customer_phone'] ?? '')); $custDomain = trim((string)($_POST['customer_domain'] ?? '')); $serverExpiry = trim((string)($_POST['server_expiry'] ?? ''));
        if ($actor['role'] !== 'finance' && empty($_POST['info_later'])) {
            $webBiz = in_array($projectType, ['AI网站定制', '网站模板', '网站续费', '网站修改', '备案-提成'], true);
            $contactNote = trim((string)($_POST['contact_note'] ?? ''));
            $hasContact = ($custPhone !== '') || ($contactNote !== '');
            if ($webBiz && $actor['role'] === 'customer_service' && !$hasContact) $needInfo[] = '客户手机号或微信号';
            if ($webBiz && $actor['role'] === 'technical' && $custDomain === '') $needInfo[] = '域名';
            if ($projectType === '小程序开发' && $serverExpiry === '') $needInfo[] = '服务器到期日';
            if ($needInfo) throw new RuntimeException('请填写' . implode('、', $needInfo) . '；暂时拿不到的可勾选“稍后补充”（未补充将不会获得本订单的续费分成）');
        }
        $peopleLabels = ps_business_people_labels($projectType);
        $orderKind = ps_order_kind_valid($projectType, $_POST['order_kind'] ?? '');
        if ($orderKind === '' && !empty($business['default_kind'])) $orderKind = $business['default_kind'];
        if ($orderKind === '' && !empty($roleDefaultKinds[$projectType])) $orderKind = $roleDefaultKinds[$projectType];
        $isOffset = $orderKind === '退款冲减';
        // 代写 / 期刊 / 微信代写 / 网站续费 / 网站修改：录单时直接填写稿费或成本（¥500 以内自动通过，超过由财务审核）
        $directCost = !empty($business['import_cost']) ? trim((string)($_POST['direct_cost'] ?? '')) : '';
        if ($directCost !== '' && (!preg_match($isOffset ? '/^-?\d+(?:\.\d{1,2})?$/' : '/^\d+(?:\.\d{1,2})?$/', $directCost) || abs((float)$directCost) > 999999999999.99)) throw new RuntimeException(($business['cost_label'] ?? '成本') . '须为金额，最多两位小数');
        if ($orderKind === '' && !empty($business['kind_required'])) throw new RuntimeException('请选择订单类型（新订单 / 定制 / 续费…），它决定分成比例和每单补助');
        $canChooseResources = $business['resources'] && $actor['role'] !== 'customer_service';
        $programTemplate = $canChooseResources && !empty($business['program']) && (int)($_POST['program_template_id'] ?? 0) > 0 ? ps_intake_template((int)$_POST['program_template_id'], 'program') : null;
        $domainMode = $business['resources'] ? ($canChooseResources ? (string)($_POST['domain_mode'] ?? 'pending') : 'pending') : 'none';
        // 程序套餐已含空间与域名：只选套餐即视为资源已确认、无需另购域名。
        if ($programTemplate && $domainMode === 'pending') $domainMode = 'none';
        $domainTemplate = $domainMode === 'template' ? ps_intake_template((int)($_POST['domain_template_id'] ?? 0), 'domain') : null;
        $serverTemplate = $canChooseResources && (int)($_POST['server_template_id'] ?? 0) > 0 ? ps_intake_template((int)$_POST['server_template_id'], 'server') : null;
        if (!in_array($domainMode, ['pending', 'none', 'template'], true)) throw new RuntimeException('请选择待补充、无需域名或具体域名成本模板');
        if ($no === '' || strlen($no) > 100) throw new RuntimeException('请填写店铺订单号，或填写微信交易流水号/支付订单号');
        $existing = db()->prepare('SELECT id FROM project_orders WHERE order_no=?');
        $existing->execute([$no]);
        $existingId = (int)$existing->fetchColumn();
        if ($existingId) {
            if ($actor['role'] !== 'finance') {
                $access = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?');
                $access->execute([$existingId, $actor['employee_id']]);
                if (!$access->fetchColumn()) throw new RuntimeException('该订单编号已由同事建档，请让对方在结算单中关联你，或联系财务核对归属');
            }
            header('Location: ' . BASE_URL . '/project/order.php?id=' . $existingId); exit;
        }
        if ($date === '' && substr($no, 0, 3) !== 'WX-') {
            $shopHint = trim((string)($_POST['shop'] ?? ''));
            $matches = array_values(array_filter(ps_shop_order_lookup($no), function ($match) use ($shopHint) { return $match['price'] !== null && ($shopHint === '' || $match['shop'] === $shopHint); }));
            if (count($matches) === 1) $date = (string)$matches[0]['date'];
        }
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) throw new RuntimeException('请填写订单日期；已有同号店铺流水时可留空自动带入');
        if (($contract !== '' && !preg_match($isOffset ? '/^-?\d+(?:\.\d{1,2})?$/' : '/^\d+(?:\.\d{1,2})?$/', $contract)) || !preg_match('/^\d+(?:\.\d{1,2})?$/', $receipt) || (float)$contract > 999999999999.99 || (float)$receipt > 999999999999.99) throw new RuntimeException('金额须为非负数，最多两位小数');
        $sslCost = $canChooseResources ? trim((string)($_POST['ssl_cost'] ?? '')) : '';
        if ($sslCost !== '' && (!preg_match('/^\d+(?:\.\d{1,2})?$/', $sslCost) || (float)$sslCost > 999999999999.99)) throw new RuntimeException('SSL 实际成本最多两位小数');
        $customer = trim((string)($_POST['customer_name'] ?? ''));
        $shop = trim((string)($_POST['shop'] ?? ''));
        if ($shop !== '' && empty($business['free_shop']) && !in_array($shop, $shops, true)) throw new RuntimeException('请选择店铺列表中的店铺');
        $details = ps_business_details($projectType, $actor['role'] === 'customer_service' && $projectType === '网站模板' ? [] : ($_POST['details'] ?? []));
        if (mb_strlen($customer) > 200 || mb_strlen($shop) > 150 || mb_strlen($projectType) > 100) throw new RuntimeException('客户、店铺或业务类型过长');
        $paymentNickname = trim((string)($_POST['payment_nickname'] ?? ''));
        $tradeStatus = trim((string)($_POST['trade_status'] ?? ''));
        $contactNote = trim((string)($_POST['contact_note'] ?? ''));
        $resourceNote = trim((string)($_POST['resource_note'] ?? ''));
        if (mb_strlen($paymentNickname) > 200 || mb_strlen($paymentReference) > 200 || mb_strlen($tradeStatus) > 100 || mb_strlen($contactNote) > 500 || mb_strlen($resourceNote) > 500) throw new RuntimeException('备注或支付流水号过长');
        // 外包给下游（如华梦）：成本按成本中心的外包模板计入，不需要指定本公司技术。
        $outsourceTemplate = (int)($_POST['outsource_template_id'] ?? 0) > 0 ? ps_intake_template((int)$_POST['outsource_template_id'], 'outsourcing') : null;
        if ($outsourceTemplate && $outsourceTemplate['business_scope'] !== '' && $outsourceTemplate['business_scope'] !== $projectType) throw new RuntimeException('所选外包成本不适用于' . $projectType);
        if ($outsourceTemplate && ($outsourceTemplate['price_mode'] ?? 'fixed') === 'percent' && ($contract === '' || (float)$contract <= 0)) throw new RuntimeException('外包成本按售价比例计算，请先填写售价');
        $groups = ['technical' => [], 'customer_service' => []];
        foreach (['customer_service_id' => ['customer_service', '客服'], 'frontend_id' => ['technical', $peopleLabels['frontend']], 'backend_id' => ['technical', $peopleLabels['backend']]] as $field => $target) {
            $personId = (int)($_POST[$field] ?? 0);
            if ($personId <= 0) continue;
            if (!isset($employeesById[$personId])) throw new RuntimeException('所选合作人员不存在');
            $group = $target[0];
            if (isset($groups[$group][$personId])) $groups[$group][$personId]['role'] .= '/' . $target[1];
            else $groups[$group][$personId] = ['id' => $personId, 'role' => $target[1]];
        }
        if ($actor['role'] !== 'finance') {
            // 本人已在任一栏（如纪鹏程作为客服录入别人做的环境配置单）时不再自动追加，避免多算一份分成。
            $selfGroup = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
            $selfId = (int)$actor['employee_id'];
            if (!isset($groups['technical'][$selfId]) && !isset($groups['customer_service'][$selfId])) $groups[$selfGroup][$selfId] = ['id' => $selfId, 'role' => $selfGroup === 'technical' ? $peopleLabels['frontend'] : '客服'];
        }
        require_once (dirname(__DIR__, 2)) . '/../includes/ProjectJointIntake.php';
        $jointIds = ps_joint_customer_ids($_POST['co_customer_service_ids'] ?? [], array_map('intval', array_column($customerServiceChoices, 'id')));
        foreach ($jointIds as $jointId) if (!isset($groups['customer_service'][$jointId])) $groups['customer_service'][$jointId] = ['id' => $jointId, 'role' => '客服'];
        if (!$groups['technical'] && !$groups['customer_service']) throw new RuntimeException('请至少选择一位客服或技术参与人');
        // 客服与制作人员共用一张订单：客服建单须指定已开通同业务账号的技术，对方登录即可看到并补成本。
        if ($actor['role'] === 'customer_service' && ps_business_requires_technical($projectType) && !$outsourceTemplate) {
            if (!$groups['technical']) throw new RuntimeException('请指定接收此单的技术（外包给华梦等下游时，请在“外包”里选择外包成本）');
            foreach ($groups['technical'] as $person) if ((int)$person['id'] !== $selfEmployeeId && !ps_active_employee_for_business($person['id'], 'technical', $projectType)) throw new RuntimeException('指定的技术未开通当前业务的有效账号，请联系财务配置');
        }
        if ($actor['role'] === 'technical' && ps_business_requires_technical($projectType)) {
            foreach ($groups['customer_service'] as $person) if ((int)$person['id'] !== $selfEmployeeId && !ps_active_employee_for_business($person['id'], 'customer_service', $projectType)) throw new RuntimeException('指定的客服未开通当前业务的有效账号，请联系财务配置');
        }
        // 设计图片：同一客服同一客户当月已有图片单时，本单记“图片同客户”（不计 0.5 元单量）
        if ($projectType === '设计' && $orderKind === '图片' && trim($paymentNickname . $customer) !== '') {
            $csIds = array_keys($groups['customer_service']);
            if ($csIds) {
                $repeat = db()->prepare("SELECT 1 FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.commission_group='customer_service' WHERE o.project_type='设计' AND o.order_kind='图片' AND DATE_FORMAT(o.order_date,'%Y-%m')=? AND p.employee_id IN (" . implode(',', array_map('intval', $csIds)) . ") AND REPLACE(LOWER(o.customer_name),' ','')=? LIMIT 1");
                $repeat->execute([substr($date, 0, 7), preg_replace('/\s+/u', '', mb_strtolower($customer !== '' ? $customer : $paymentNickname))]);
                if ($repeat->fetchColumn()) $orderKind = '图片同客户';
            }
        }
        if ($customer === '' && $paymentNickname !== '') $customer = $paymentNickname;
        $noteParts = [];
        if ($paymentNickname !== '') $noteParts[] = '付款昵称：' . $paymentNickname;
        if ($contactNote !== '') $noteParts[] = '客户联系方式：' . $contactNote;
        if ($programTemplate) $noteParts[] = '程序套餐：' . $programTemplate['name'] . ' ' . $programTemplate['specification'];
        if ($outsourceTemplate) $noteParts[] = '外包：' . $outsourceTemplate['name'] . ' ' . $outsourceTemplate['specification'];
        if ($business['resources'] && $domainMode !== 'pending' && !$programTemplate) $noteParts[] = $domainMode === 'none' ? '域名：无需域名' : '域名：' . $domainTemplate['name'] . ' ' . $domainTemplate['specification'];
        if ($resourceNote !== '') $noteParts[] = '域名/空间说明：' . $resourceNote;
        if ($sslCost !== '' && (float)$sslCost > 0) $noteParts[] = 'SSL 实际成本报备：¥' . $sslCost . '（待技术补充成本凭证）';
        db()->beginTransaction();
        $q = db()->prepare('INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,note,created_by_admin) VALUES (?,?,?,?,?,?,0,?,?,?,?)');
        $q->execute([$no, $customer, $projectType, $orderKind, $shop, $contract === '' ? 0 : round((float)$contract, 2), $date, ($_POST['delivery_status'] ?? '') === 'finished' ? 'finished' : 'unfinished', implode('；', $noteParts), $actor['role'] === 'finance' ? $actor['id'] : null]);
        $id = (int)db()->lastInsertId();
        ps_source_record($id, $contract === '' ? 'missing' : 'manual', $paymentNickname, $tradeStatus, $paymentReference);
        ps_save_business_details($id, $projectType, $details);
        if ($actor['role'] === 'finance' && (float)$receipt > 0) {
            db()->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,'新建订单初始实收','approved','admin',?,?,NOW())")
                ->execute([$id, round((float)$receipt, 2), $actor['id'], $actor['id']]);
            ps_audit('cash', (int)db()->lastInsertId(), 'create', $actor, ['order_id' => $id, 'type' => 'receipt', 'amount' => round((float)$receipt, 2), 'status' => 'approved']);
            ps_recalculate_cash($id);
        }
        ps_intake_participants($id, $groups, $projectType);
        if ($business['resources']) ps_intake_save_resources($id, 'manual', null, $domainTemplate, $serverTemplate, $sslCost !== '' ? $sslCost : null, $domainMode, $programTemplate);
        if ($programTemplate) ps_intake_add_template_cost($id, $programTemplate, $actor, '手动录入：程序套餐');
        if ($outsourceTemplate) ps_intake_add_template_cost($id, $outsourceTemplate, $actor, '手动录入：外包');
        if ($domainTemplate) ps_intake_add_template_cost($id, $domainTemplate, $actor, '手动录入：域名');
        if ($serverTemplate) ps_intake_add_template_cost($id, $serverTemplate, $actor, '手动录入：服务器');
        if ($directCost !== '' && (float)$directCost != 0) {
            $costAmount = round((float)$directCost, 2);
            db()->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,'outsourcing',?,1,'项',?,?,'one_time',1,'手动录入',?,?)")
                ->execute([$id, $business['cost_label'] ?? '成本', $costAmount, $costAmount, abs($costAmount) <= 500 ? 'approved' : 'pending', $actor['employee_id'] ?? null]);
        }
        ps_audit('order', $id, 'create', $actor, ['order_no' => $no, 'order_kind' => $orderKind, 'program_template_id' => $programTemplate['id'] ?? null, 'domain_template_id' => $domainTemplate['id'] ?? null, 'server_template_id' => $serverTemplate['id'] ?? null, 'receipt_unconfirmed' => $actor['role'] !== 'finance']);
        ps_sync_existing_shop_order($id, $no, $shop);
        db()->commit();
        pa_after_save([$id]);
        if ($custPhone !== '' || $custDomain !== '' || $serverExpiry !== '') {
            try {
                require_once (dirname(__DIR__, 2)) . '/../includes/ProjectSheetEdit.php';
                if ($custPhone !== '') pse_set_phone($id, $custPhone, $actor);
                if ($custDomain !== '') pse_set_domain($id, $custDomain, $actor);
                if ($serverExpiry !== '') pse_set_server_expiry($id, $serverExpiry, $actor);
            } catch (Throwable $infoError) { ps_audit('order', $id, 'renewal_info_failed', $actor, ['error' => mb_substr($infoError->getMessage(), 0, 200)]); }
        }
        if (($_POST['after_save'] ?? '') === 'next') {
            header('Location: ' . BASE_URL . '/project/index.php?' . http_build_query(['business' => $projectType, 'created' => $id, 'entry' => 1])); exit;
        }
        header('Location: ' . BASE_URL . '/project/order.php?id=' . $id); exit;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e instanceof PDOException ? '订单号已存在或数据保存失败' : $e->getMessage(); }
}

// GET 只展示核对结果；自动核对由上传完成、显式 POST 或定时任务执行。
$autoFinishInfo = null;

