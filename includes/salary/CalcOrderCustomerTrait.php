<?php
trait CalcOrderCustomerTrait
{
    private static function extractWangwang($order)
    {
        if (!empty($order['wangwang'])) {
            return trim($order['wangwang']);
        }
        
        $rawData = is_string($order['raw_data'] ?? '') ? json_decode($order['raw_data'], true) : ($order['raw_data'] ?? []);
        if (is_array($rawData)) {
            foreach ($rawData as $key => $value) {
                $lowerKey = strtolower(trim($key));
                if (strpos($lowerKey, '旺旺') !== false || 
                    strpos($lowerKey, 'wangwang') !== false ||
                    strpos($lowerKey, '买家') !== false ||
                    strpos($lowerKey, '用户') !== false) {
                    $ww = trim((string)$value);
                    if ($ww !== '') {
                        return $ww;
                    }
                }
            }
        }
        
        return '';
    }

}
