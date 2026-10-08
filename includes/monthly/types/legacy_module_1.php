<?php

            $eid = (int)$rule['employee_id'];
            $empQuery = db()->prepare('SELECT id, name, department FROM employees WHERE id=?');
            $empQuery->execute([$eid]);
            $emp = $empQuery->fetch();