<?php

            if (!$finance) throw new RuntimeException('无权限');
            $revoked = ps_revoke_approval($id, $actor, (string)($_POST['revoke_reason'] ?? ''), 'unfinished');
