<?php

            $eid = (int)$rule['employee_id'];
            $nameQuery = db()->prepare('SELECT name FROM employees WHERE id=?');
            $nameQuery->execute([$eid]);
            $employeeName = trim((string)$nameQuery->fetchColumn());