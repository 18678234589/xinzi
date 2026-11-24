<?php
/**
 * 成本中心加“纯域名注册”程序套餐（网站模板 / AI网站定制的“程序套餐”下拉里选）。
 * 价格与商务中国一致：首年价 + 续费价 × (年数 − 1)。.com 58/87、.cn 35/37、.net 93/97 取自 includes/BizcnRegistration.php 的 br_tld_rules()，
 * .org 取成本中心现有域名模板（85/92）。已存在的（同名同规格）不动，价格以后由财务在成本中心调整。
 * 用法：php tools/seed_pure_domain_templates.php   （连接 config 里的库；测试请先设 DB_* 指向本地隔离库）
 */
function pdt_seed(PDO $pdo)
{
    $rules = ['com' => [58, 87], 'cn' => [35, 37], 'net' => [93, 97], 'org' => [85, 92]];
    $added = 0;
    $exists = $pdo->prepare("SELECT COUNT(*) FROM project_cost_templates WHERE category='program' AND name='纯域名注册' AND specification=?");
    $insert = $pdo->prepare("INSERT INTO project_cost_templates (category,business_scope,name,specification,unit,price_mode,cost_kind,price,supplier_price,requires_proof,auto_approve,is_active,version) VALUES ('program','','纯域名注册',?,'套','fixed','one_time',?,?,0,1,1,1)");
    foreach ($rules as $tld => [$first, $renew]) {
        foreach ([1, 2, 3, 5] as $years) {
            $spec = '.' . $tld . ' ' . $years . '年（商务中国价）';
            $exists->execute([$spec]);
            if ((int)$exists->fetchColumn()) continue;
            $price = $first + $renew * ($years - 1);
            $insert->execute([$spec, $price, $price]);
            $added++;
        }
    }
    return $added;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    require_once __DIR__ . '/../config/database.php';
    echo '新增 ' . pdt_seed(db()) . " 个“纯域名注册”套餐\n";
}
