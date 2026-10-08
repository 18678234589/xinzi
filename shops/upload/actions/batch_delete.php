<?php

        // 批量删除订单
        $ids = $_POST['ids'] ?? [];
        $ids = array_filter(array_map('intval', (array)$ids));
        if (empty($ids)) {
            $error = '请勾选要删除的订单';
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge($ids, [$shop['name']]);
                $stmt = db()->prepare("UPDATE orders SET is_deleted=1 WHERE id IN ($placeholders) AND shop = ?");
                $stmt->execute($params);
                $success = '已批量删除 ' . $stmt->rowCount() . ' 条订单（移入回收站）';
            } catch (PDOException $ex) {
                $error = '批量删除失败: ' . $ex->getMessage();
            }
        }
    