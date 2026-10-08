<?php

            $add($eid, $rule, (float)$override['value'], '财务批准 ¥' . money_plain($override['value']) . ($override['note'] !== '' ? '（' . $override['note'] . '）' : ''));