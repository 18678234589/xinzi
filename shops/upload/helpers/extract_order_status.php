<?php
function extract_order_status($raw)
{
    if (!is_array($raw)) return '';
    if (isset($raw['__order_status__']) && $raw['__order_status__'] !== '') {
        return $raw['__order_status__'];
    }
    foreach ($raw as $k => $v) {
        if (strlen($k) > 4 && substr($k, 0, 2) === '__' && substr($k, -2) === '__') continue;
        if (mb_strpos($k, '订单状态') !== false || mb_strpos($k, '交易状态') !== false) {
            $v = trim((string)$v);
            if ($v !== '') return $v;
        }
    }
    return '';
}
