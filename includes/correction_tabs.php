<?php
// “分成更正申请”统一栏目：订单分成更正（对某张订单显示的分成有疑问）、分成算法更正（对规则本身有疑问）、
// 订单资料更正（上传表格与原单的售价 / 店铺 / 昵称不一致，按表格更正原单）三个页签。
// 用法：页面里 require_once 本文件后调用 pc_tabs('order'|'rule'|'fix')。
require_once __DIR__ . '/commission_explain.php';
require_once __DIR__ . '/ProjectRuleAlgo.php';
require_once __DIR__ . '/ProjectOrderFix.php';
if (!function_exists('pc_counts')) {
    function pc_counts() { return ['order' => (int)ps_corr_pending_count(), 'rule' => (int)pra_pending_count(), 'fix' => (int)pof_pending_count()]; }
    function pc_pending_total() { return array_sum(pc_counts()); }
    /** 侧栏入口的落点：第一个有待办的页签；都没有就进订单分成更正。 */
    function pc_first_url()
    {
        $c = pc_counts();
        foreach (['order' => '/project/corrections.php', 'rule' => '/project/rule_requests.php', 'fix' => '/project/order_fixes.php'] as $k => $url) if ($c[$k]) return $url;
        return '/project/corrections.php';
    }
    function pc_tabs($active)
    {
        $c = pc_counts();
        $tabs = ['order' => ['/project/corrections.php', '订单分成更正', 'fa-file-invoice-dollar'], 'rule' => ['/project/rule_requests.php', '分成算法更正', 'fa-calculator'], 'fix' => ['/project/order_fixes.php', '订单资料更正', 'fa-pen-to-square']];
        echo '<style>.pc-tabs{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 1rem;padding:.35rem;border:1px solid #e1e9e4;border-radius:14px;background:#f6faf7;width:fit-content;max-width:100%}'
            . '.pc-tabs a{display:inline-flex;align-items:center;gap:.45rem;padding:.5rem 1.1rem;border-radius:10px;font-size:.92rem;font-weight:600;text-decoration:none!important;color:#2b3f38!important;background:transparent!important;transition:background .2s}'
            . '.pc-tabs a:hover{background:#e6f1ea!important}'
            . '.pc-tabs a.is-on{background:#1f6a52!important;color:#fff!important;box-shadow:0 6px 14px -8px #1f6a52}'
            . '.pc-tabs b{min-width:1.35rem;padding:0 .4rem;border-radius:999px;background:#a8650a;color:#fff;font-size:.72rem;text-align:center;line-height:1.35rem}'
            . '.pc-tabs a.is-on b{background:#fff;color:#1f6a52}</style>';
        echo '<nav class="pc-tabs" aria-label="更正申请类型">';
        foreach ($tabs as $key => [$href, $label, $icon]) {
            echo '<a class="' . ($active === $key ? 'is-on' : '') . '" href="' . e(BASE_URL . $href) . '"' . ($active === $key ? ' aria-current="page"' : '') . '><i class="fas ' . $icon . '"></i> ' . e($label) . ($c[$key] ? ' <b>' . $c[$key] . '</b>' : '') . '</a>';
        }
        echo '</nav>';
    }
}
