<?php

            if (!$finance) throw new RuntimeException('无权限');
            $participantId = (int)($_POST['participant_id'] ?? 0);
            $q = db()->prepare('DELETE FROM project_participants WHERE id=? AND order_id=?');
            $q->execute([$participantId, $id]);
            if (!$q->rowCount()) throw new RuntimeException('参与人不存在');
            ps_audit('order', $id, 'remove_participant', $actor, ['participant_id' => $participantId]);
        