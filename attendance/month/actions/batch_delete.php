<?php

        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if (empty($ids)) { $error = '请勾选要删除的记录'; }
        else {
            try {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = db()->prepare("DELETE FROM attendances WHERE id IN ($ph) AND year=? AND month=?");
                $stmt->execute(array_merge($ids, [$year, $month]));
                $delCnt = $stmt->rowCount();
                $success = "已批量删除 {$delCnt} 条（选中" . count($ids) . "条）";
            } catch (PDOException $ex) {
                $error = '删除失败: ' . $ex->getMessage();
            }
        }
    