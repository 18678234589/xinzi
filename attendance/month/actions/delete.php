<?php

        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("DELETE FROM attendances WHERE id=? AND year=? AND month=?")->execute([$id, $year, $month]);
            $success = '已删除';
        } catch (PDOException $ex) { $error = '删除失败'; }
    