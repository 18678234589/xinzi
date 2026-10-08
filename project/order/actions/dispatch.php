<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        $finance = $actor['role'] === 'finance';
        if (!in_array($action, ['approve_order', 'post_adjustment', 'set_order_kind', 'submit_commission_correction'], true)) {
            db()->beginTransaction();
            $lock = db()->prepare('SELECT settlement_status FROM project_orders WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            $currentStatus = $lock->fetchColumn();
            if ($currentStatus === false || in_array($currentStatus, ['approved','locked'], true)) throw new RuntimeException('订单已审核，修改须走调整流程');
            if (!$finance && $action !== 'claim_backend') {
                $access = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
                $access->execute([$id, $actor['employee_id']]);
                if (!$access->fetchColumn()) throw new RuntimeException('已不再参与此订单，请刷新页面');
            }
        }
        if ($action === 'link_item_cost') {
            /* split: project/order/actions/cost/link_item_cost.php */ include (dirname(__DIR__, 2)) . '/order/actions/cost/link_item_cost.php';
        } elseif ($action === 'save_customer_intake') {
            /* split: project/order/actions/order_meta/save_customer_intake.php */ include (dirname(__DIR__, 2)) . '/order/actions/order_meta/save_customer_intake.php';
        } elseif ($action === 'set_order_kind') {
            /* split: project/order/actions/order_meta/set_order_kind.php */ include (dirname(__DIR__, 2)) . '/order/actions/order_meta/set_order_kind.php';
        } elseif ($action === 'add_counterpart') {
            /* split: project/order/actions/participants/add_counterpart.php */ include (dirname(__DIR__, 2)) . '/order/actions/participants/add_counterpart.php';
        } elseif ($action === 'assign_backend') {
            /* split: project/order/actions/participants/assign_backend.php */ include (dirname(__DIR__, 2)) . '/order/actions/participants/assign_backend.php';
        } elseif ($action === 'claim_backend') {
            /* split: project/order/actions/participants/claim_backend.php */ include (dirname(__DIR__, 2)) . '/order/actions/participants/claim_backend.php';
        } elseif ($action === 'save_technical_details') {
            /* split: project/order/actions/order_meta/save_technical_details.php */ include (dirname(__DIR__, 2)) . '/order/actions/order_meta/save_technical_details.php';
        } elseif ($action === 'rename_order_no') {
            /* split: project/order/actions/order_meta/rename_order_no.php */ include (dirname(__DIR__, 2)) . '/order/actions/order_meta/rename_order_no.php';
        } elseif ($action === 'update_order') {
            /* split: project/order/actions/order_meta/update_order.php */ include (dirname(__DIR__, 2)) . '/order/actions/order_meta/update_order.php';
        } elseif ($action === 'confirm_resources') {
            /* split: project/order/actions/cost/confirm_resources.php */ include (dirname(__DIR__, 2)) . '/order/actions/cost/confirm_resources.php';
        } elseif ($action === 'add_cash') {
            /* split: project/order/actions/cash/add_cash.php */ include (dirname(__DIR__, 2)) . '/order/actions/cash/add_cash.php';
        } elseif ($action === 'review_cash') {
            /* split: project/order/actions/cash/review_cash.php */ include (dirname(__DIR__, 2)) . '/order/actions/cash/review_cash.php';
        } elseif ($action === 'add_participant') {
            /* split: project/order/actions/participants/add_participant.php */ include (dirname(__DIR__, 2)) . '/order/actions/participants/add_participant.php';
        } elseif ($action === 'remove_participant') {
            /* split: project/order/actions/participants/remove_participant.php */ include (dirname(__DIR__, 2)) . '/order/actions/participants/remove_participant.php';
        } elseif ($action === 'add_cost') {
            /* split: project/order/actions/cost/add_cost.php */ include (dirname(__DIR__, 2)) . '/order/actions/cost/add_cost.php';
        } elseif ($action === 'tm_cost') {
            /* split: project/order/actions/cost/tm_cost.php */ include (dirname(__DIR__, 2)) . '/order/actions/cost/tm_cost.php';
        } elseif ($action === 'review_cost') {
            /* split: project/order/actions/cost/review_cost.php */ include (dirname(__DIR__, 2)) . '/order/actions/cost/review_cost.php';
        } elseif ($action === 'void_cost') {
            /* split: project/order/actions/cost/void_cost.php */ include (dirname(__DIR__, 2)) . '/order/actions/cost/void_cost.php';
        } elseif ($action === 'post_adjustment') {
            /* split: project/order/actions/order_meta/post_adjustment.php */ include (dirname(__DIR__, 2)) . '/order/actions/order_meta/post_adjustment.php';
        } elseif ($action === 'apply_delivery_completion') {
            /* split: project/order/actions/delivery_upgrade/apply_delivery_completion.php */ include (dirname(__DIR__, 2)) . '/order/actions/delivery_upgrade/apply_delivery_completion.php';
        } elseif ($action === 'review_delivery_completion') {
            /* split: project/order/actions/delivery_upgrade/review_delivery_completion.php */ include (dirname(__DIR__, 2)) . '/order/actions/delivery_upgrade/review_delivery_completion.php';
        } elseif ($action === 'apply_product_upgrade') {
            /* split: project/order/actions/delivery_upgrade/apply_product_upgrade.php */ include (dirname(__DIR__, 2)) . '/order/actions/delivery_upgrade/apply_product_upgrade.php';
        } elseif ($action === 'review_product_upgrade') {
            /* split: project/order/actions/delivery_upgrade/review_product_upgrade.php */ include (dirname(__DIR__, 2)) . '/order/actions/delivery_upgrade/review_product_upgrade.php';
        } elseif ($action === 'submit_commission_correction') {
            /* split: project/order/actions/order_meta/submit_commission_correction.php */ include (dirname(__DIR__, 2)) . '/order/actions/order_meta/submit_commission_correction.php';
        } elseif ($action === 'approve_order') {
            /* split: project/order/actions/order_meta/approve_order.php */ include (dirname(__DIR__, 2)) . '/order/actions/order_meta/approve_order.php';
        } else throw new RuntimeException('操作无效');
        if (db()->inTransaction()) db()->commit();
        require_once (dirname(__DIR__, 2)) . '/../includes/ProjectAutoReview.php';
        pa_after_save([$id]);
        header('Location: ' . BASE_URL . '/project/order.php?id=' . $id . '&saved=1'); exit;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e->getMessage(); }
}

