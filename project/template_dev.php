<?php
require_once __DIR__ . '/../includes/ProjectTemplateDev.php';
require_once __DIR__ . '/../includes/ProjectPayrollSheet.php';
// 优站 / WP 模板开发登记：系统自动列出当月的优站订单，开发技术选自己（或上传“优站模板填写”表）；订单完成并审核后补助自动计入报酬。
$actor = ps_require_actor();
if (!ptmd_can_edit($actor)) { http_response_code(403); exit('仅技术人员和财务可用'); }
$isFinance = ($actor['role'] ?? '') === 'finance' || ($actor['type'] ?? '') === 'admin';
$me = (int)($actor['employee_id'] ?? 0);
$month = (string)($_GET['month'] ?? $_POST['month'] ?? pps_default_month());
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = pps_default_month();
if (isset($_GET['download']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template-dev.xlsx"; filename*=UTF-8' . chr(39) . chr(39) . rawurlencode('优站模板开发登记-示例模板.xlsx'));
    echo pmt_xlsx('优站模板开发登记');
    exit;
}
$error = ''; $success = ''; $skipped = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'set') {
            ptmd_set((int)($_POST['order_id'] ?? 0), (int)($_POST['dev'] ?? 0), (string)($_POST['kind'] ?? ''), $actor);
            $success = '已保存';
        } elseif ($do === 'upload') {
            $f = $_FILES['sheet'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) throw new RuntimeException('请选择不超过 20MB 的 xlsx 文件');
            if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('只支持 .xlsx 文件（.xls 请先另存为 xlsx）');
            $r = ptmd_import($f['tmp_name'], $actor);
            if (!$r['set'] && !$r['skipped']) throw new RuntimeException('没有识别到内容：表头需要有“订单编号”，以及“优站模板技术”或“WP模板技术”');
            $success = '已登记 ' . $r['set'] . ' 单'; $skipped = $r['skipped'];
        }
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请稍后再试'; }
}
$orders = ptmd_orders($month); $cands = ptmd_candidates();
$name = []; foreach ($cands as $c) $name[(int)$c['id']] = $c['name'];
$sum = []; $missing = 0;
foreach ($orders as $o) {
    if (empty($o['dev'])) { if ($o['delivery_status'] === 'finished') $missing++; continue; }
    $k = $o['dev']['kind'] === 'wp' ? 'wp' : 'youzhan'; $d = (int)$o['dev']['dev_employee_id'];
    $sum[$d] = $sum[$d] ?? ['n' => 0, 'ok' => 0, 'amt' => 0, 'pending' => 0];
    $sum[$d]['n']++;
    if (in_array($o['settlement_status'], ['approved', 'locked'], true) && $o['delivery_status'] === 'finished') { $sum[$d]['ok']++; $sum[$d]['amt'] += PTMD_AMOUNT[$k]; }
    elseif ($o['delivery_status'] === 'finished') $sum[$d]['pending'] += PTMD_AMOUNT[$k];
}
$page_title = '优站 / WP 模板开发登记';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:1180px">
  <div style="background:linear-gradient(135deg,#e4f3e7,#fff0d8);border:1px solid #e1ebdc;border-radius:20px;padding:18px 26px;margin:14px 0">
    <h2 style="margin:0 0 6px;font-size:1.3rem;font-weight:800;color:#243a34"><i class="fas fa-puzzle-piece mr-2"></i>优站 / WP 模板开发登记</h2>
    <p style="margin:0;color:#4f655c;font-size:.9rem;line-height:1.8">系统自动列出当月的优站订单，不用再统计、发群。<b>开发这个站模板的技术在自己那行选自己并保存</b>即可（优站模板 ¥15 / 站；WP 是优站里的高级模板，¥50 / 站）。<b>订单完成并审核通过后</b>，补助自动进入开发技术当月的报酬（自 2026-11 起），未完成的订单不给。也可以直接上传原来的“优站模板填写”表（表头含“订单编号”和“优站模板技术 / WP模板技术”）。</p>
    <form method="get" class="form-inline mt-2"><label class="mr-2 small text-muted">月份</label><input type="month" name="month" value="<?php echo e($month); ?>" class="form-control form-control-sm" onchange="this.form.submit()"></form>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($skipped): ?><div class="alert alert-warning"><b>这些没登记：</b><ul class="mb-0"><?php foreach (array_slice($skipped, 0, 40) as $s): ?><li><?php echo e($s); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($missing): ?><div class="alert alert-info">还有 <b><?php echo (int)$missing; ?></b> 个已完成的优站订单没有登记开发技术，登记后补助才会计入。</div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="form-inline mb-3">
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="upload"><input type="hidden" name="month" value="<?php echo e($month); ?>">
    <input type="file" name="sheet" accept=".xlsx" class="form-control-file mr-2" required><button class="btn btn-outline-primary btn-sm mr-2">上传“优站模板填写”表</button> <a class="btn btn-link btn-sm" href="template_dev.php?download=1"><i class="fas fa-download mr-1"></i>下载示例模板</a>
  </form>
  <?php if ($sum): ?><div class="card mb-3"><div class="card-header"><b>本月补助汇总</b> <span class="small text-muted">（已完成并审核的才计入；已完成待审核的审核后计入）</span></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead class="thead-light"><tr><th>开发技术</th><th class="text-right">登记单数</th><th class="text-right">已计入单数</th><th class="text-right">已计入补助</th><th class="text-right">待审核后可得</th></tr></thead><tbody>
    <?php foreach ($sum as $d => $s): ?><tr><td><?php echo e($name[$d] ?? ('#' . $d)); ?></td><td class="text-right"><?php echo (int)$s['n']; ?></td><td class="text-right"><?php echo (int)$s['ok']; ?></td><td class="text-right">¥<?php echo number_format($s['amt'], 2); ?></td><td class="text-right">¥<?php echo number_format($s['pending'], 2); ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div><?php endif; ?>
  <div class="card mb-4"><div class="card-header"><b><?php echo e($month); ?> 优站订单</b> <span class="badge badge-secondary"><?php echo count($orders); ?></span></div>
    <div class="table-responsive"><table class="table table-sm mb-0" style="vertical-align:middle"><thead class="thead-light"><tr><th>日期</th><th>订单号</th><th>买家</th><th>订单技术</th><th>完成情况</th><th>补助</th><th>开发技术</th><th>类型</th><th></th></tr></thead><tbody>
    <?php foreach ($orders as $id => $o):
        $dev = $o['dev'] ?? null; $curKind = $dev ? ($dev['kind'] === 'wp' ? 'wp' : 'youzhan') : $o['kind_auto'];
        $finished = $o['delivery_status'] === 'finished'; $approved = in_array($o['settlement_status'], ['approved', 'locked'], true);
        $mine = $dev && (int)$dev['dev_employee_id'] === $me; $lockedToOther = $dev && !$isFinance && !$mine;
        if (!$dev) $st = '<span class="text-muted">未登记</span>';
        elseif (!$finished) $st = '<span class="text-warning">订单未完成，暂不给</span>';
        elseif (!$approved) $st = '<span class="text-info">审核后计入 ¥' . PTMD_AMOUNT[$curKind] . '</span>';
        else $st = '<span class="text-success">已计入 ¥' . PTMD_AMOUNT[$curKind] . '</span>'; ?>
    <tr>
      <td class="text-nowrap"><?php echo e($o['order_date']); ?></td><td class="small text-nowrap"><?php echo e($o['order_no']); ?></td><td class="small"><?php echo e($o['nick']); ?></td><td class="small"><?php echo e($o['techs']); ?></td>
      <td><?php echo $finished ? '已完成' : '<span class="text-warning">未完成</span>'; ?></td><td class="small"><?php echo $st; ?></td>
      <td><select name="dev" form="tf<?php echo (int)$id; ?>" class="form-control form-control-sm" <?php echo $lockedToOther ? 'disabled' : ''; ?>><option value="0">— 未登记 —</option>
        <?php foreach ($cands as $c): if (!$isFinance && (int)$c['id'] !== $me && (int)$c['id'] !== (int)($dev['dev_employee_id'] ?? 0)) continue; ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $dev && (int)$dev['dev_employee_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></td>
      <td><select name="kind" form="tf<?php echo (int)$id; ?>" class="form-control form-control-sm" <?php echo $lockedToOther ? 'disabled' : ''; ?>><option value="youzhan" <?php echo $curKind === 'youzhan' ? 'selected' : ''; ?>>优站模板 ¥15</option><option value="wp" <?php echo $curKind === 'wp' ? 'selected' : ''; ?>>WP 模板 ¥50</option></select></td>
      <td><form method="post" id="tf<?php echo (int)$id; ?>"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="set"><input type="hidden" name="month" value="<?php echo e($month); ?>"><input type="hidden" name="order_id" value="<?php echo (int)$id; ?>"><?php if (!$lockedToOther): ?><button class="btn btn-sm btn-outline-success">保存</button><?php endif; ?></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="9" class="text-muted text-center py-3"><?php echo e($month); ?> 没有优站订单（订单里商品是“优站”的才会出现）。</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
