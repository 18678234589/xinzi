<?php
/** 上传的最新店铺导出优先于旧居间缓存；不以交易日期判断导出文件的新旧。 */
function ps_shop_statement_merge(array $old, array $incoming): array
{
    $merged = array_merge($old, $incoming);
    // 后续 ETMLL 拉取仅关联，不再把旧缓存覆盖到这份新导出；同步关联与去重记录仍保留。
    if (!empty($old['__etmll_id__'])) {
        $merged['__etmll_linked_id__'] = (int)$old['__etmll_id__'];
    }
    unset($merged['__etmll_id__']); // 上传表头不能伪造 ETMLL 拥有权。
    if (!empty($old['__etmll_id__'])) {
        // 明确的新实付列优先，避免旧 ETMLL 的不同名实付列遮住新导出的值。
        foreach (['买家实付金额','实付金额','买家实际支付金额'] as $key) {
            if (isset($incoming[$key]) && is_numeric($incoming[$key])) {
                foreach (['买家实付金额','实付金额','买家实际支付金额'] as $alias) if (!array_key_exists($alias, $incoming)) unset($merged[$alias]);
                break;
            }
        }
    }
    $merged['__financial_source__'] = 'shop_statement';
    return $merged;
}
