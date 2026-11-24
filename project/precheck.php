<?php
require_once __DIR__ . '/../includes/ProjectMonthPrecheck.php';
require_once __DIR__ . '/../includes/ProjectPayrollSheet.php';
// 财务：月度上传后自检——把录入不对会导致分成算错的订单提前列出来，审核 / 结算前先处理。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('仅财务可用'); }
$month = (string)($_GET['month'] ?? pps_default_month());
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = pps_default_month();
$list = pmc_run($month);
$page_title = '月度上传后自检';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:1100px">
  <div style="background:linear-gradient(135deg,#f3f6f4,#fff);border:1px solid #dfe7e2;border-radius:18px;padding:18px 24px;margin:14px 0">
    <h2 style="margin:0 0 6px;font-size:1.3rem;font-weight:700;color:#1f3a2c"><i class="fas fa-stethoscope mr-2"></i>月度上传后自检</h2>
    <p style="margin:0;color:#5d6f64;font-size:.9rem;line-height:1.7">员工上传订单后，这里把“录得不对、会让分成算错”的订单提前列出来：订单类型和技术人数对不上、套餐重复计域名、同一付款号金额对不上、无订单号的附加行、没匹配到规则、SSL 成本没补等。审核和结算前先处理，就不会算出错的提成。</p>
    <form method="get" class="form-inline mt-2"><label class="mr-2 small text-muted">月份</label><input type="month" name="month" value="<?php echo e($month); ?>" class="form-control form-control-sm mr-2" onchange="this.form.submit()"></form>
  </div>
  <?php if (!$list): ?><div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i><?php echo e($month); ?> 没有发现需要处理的录入问题。</div><?php endif; ?>
  <?php foreach ($list as [$title, $desc, $rows]): ?>
  <div class="card mb-3"><div class="card-header"><b><?php echo e($title); ?></b> <span class="badge badge-warning ml-1"><?php echo count($rows); ?></span><?php if ($desc !== ''): ?> <span class="small text-muted ml-2"><?php echo e($desc); ?></span><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><tbody>
    <?php foreach (array_slice($rows, 0, 60) as [$no, $why]): ?><tr><td class="text-nowrap"><b><?php echo e($no); ?></b></td><td class="small"><?php echo e($why); ?></td></tr><?php endforeach; ?>
    <?php if (count($rows) > 60): ?><tr><td colspan="2" class="small text-muted">还有 <?php echo count($rows) - 60; ?> 条未显示</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
