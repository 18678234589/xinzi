<?php
// 客服绩效（设计客服）：上传店铺每月导出的“咨询接待分析”表 → 按评分规则算每人每店得分 → 部门内排名，前三名 850 / 800 / 750。
// 后台财务账号、设计客服主管（张光萍）、设计客服部门的员工可看可上传；对应昵称、改评分规则、删整份上传限核算财务（刘群）和主管（张光萍）。
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/lib/cs_perf_reception.php';
$actor = ps_require_actor();
if (!csr_can_view($actor)) { http_response_code(403); exit('此页面仅财务、设计客服和设计客服主管可访问'); }
$canManage = csr_can_manage($actor);
csr_ensure();

$year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y', strtotime('-1 month')));
$month = (int)($_GET['month'] ?? $_POST['month'] ?? date('n', strtotime('-1 month')));
if ($year < 2020 || $year > 2100) $year = (int)date('Y');
if ($month < 1 || $month > 12) $month = (int)date('n');
$error = ''; $success = ''; $notes = [];

$actorName = '财务';
if (!empty($actor['employee_id'])) {
    $q = db()->prepare('SELECT name FROM employees WHERE id=?');
    $q->execute([(int)$actor['employee_id']]);
    $actorName = (string)$q->fetchColumn() ?: '员工';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    $do = (string)($_POST['do'] ?? '');
    try {
        if ($do === 'upload') {
            $f = $_FILES['sheet'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('请选择要上传的表格文件');
            if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('请上传店铺导出的 .xlsx 表格');
            if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('文件过大（最大 5MB）');
            $store = trim((string)($_POST['store'] ?? ''));
            if ($store === '') throw new RuntimeException('请填写这份表是哪家店铺的（有接待的店各传一份，只有一家店有订单就只传这一家）');
            $r = csr_import($f['tmp_name'], $f['name'], $year, $month, $store, $actor, $actorName);
            ps_audit('service_performance', $year . '-' . sprintf('%02d', $month), 'upload', $actor, ['store' => $store, 'file' => $f['name'], 'rows' => $r['saved'], 'unmatched' => $r['unmatched']]);
            $success = sprintf('已导入 %s 的 %d 位客服数据（%d 位对上了员工）', $store, $r['saved'], $r['matched']);
            if ($r['unmatched']) $notes[] = '有 ' . count($r['unmatched']) . ' 个昵称没对上员工（' . implode('、', $r['unmatched']) . '），请在下方“待对应的昵称”里选择员工，绑定一次后每月自动对上。';
        } elseif ($do === 'bind' && $canManage) {
            csr_bind((string)($_POST['nick'] ?? ''), (int)($_POST['employee_id'] ?? 0));
            ps_audit('service_performance', $year . '-' . sprintf('%02d', $month), 'bind', $actor, ['nick' => (string)($_POST['nick'] ?? ''), 'employee_id' => (int)($_POST['employee_id'] ?? 0)]);
            $success = '已对应，排名已更新';
        } elseif ($do === 'params' && $canManage) {
            $saved = csr_params_save((array)($_POST['p'] ?? []));
            ps_audit('service_performance', 'params', 'update', $actor, $saved);
            $success = '评分规则已保存，排名按新规则重新计算';
        } elseif ($do === 'delete' && $canManage) {
            $n = csr_delete_upload($year, $month, (string)($_POST['store'] ?? ''));
            ps_audit('service_performance', $year . '-' . sprintf('%02d', $month), 'delete_upload', $actor, ['store' => (string)($_POST['store'] ?? ''), 'rows' => $n]);
            $success = '已删除这份上传（' . $n . ' 行）';
        } else {
            throw new RuntimeException('没有权限执行这个操作');
        }
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请稍后再试'; }
}

$defs = csr_metric_defs();
$params = csr_params();
$rows = csr_month_rows($year, $month);
$hasData = csr_has_data($year, $month);
$ranking = $hasData ? cs_perf_rank_list($year, $month) : [];
$tiers = CS_PERF_RANK_TIERS;
$ampSum = 0; $ampN = 0;
foreach ($ranking as $i => $it) if ($i < count($tiers)) { $ampSum += $tiers[$i]; $ampN++; }
$rankOf = [];
foreach ($ranking as $i => $it) $rankOf[(int)$it['id']] = $i + 1;
usort($rows, function ($x, $y) use ($rankOf) { // 明细按名次排，没排上名的在后
    $rx = $rankOf[(int)$x['employee_id']] ?? 999; $ry = $rankOf[(int)$y['employee_id']] ?? 999;
    return $rx <=> $ry ?: strcmp((string)$x['store'], (string)$y['store']);
});
$unmatched = [];
foreach ($rows as $r) if ((int)$r['employee_id'] === 0) $unmatched[$r['nick']] = $r;
$uploads = [];
foreach ($rows as $r) {
    $k = $r['store'];
    if (!isset($uploads[$k])) $uploads[$k] = ['store' => $k, 'file' => $r['source_file'], 'by' => $r['uploaded_by_name'], 'at' => $r['updated_at'], 'n' => 0];
    $uploads[$k]['n']++;
    if ($r['updated_at'] > $uploads[$k]['at']) { $uploads[$k]['at'] = $r['updated_at']; $uploads[$k]['file'] = $r['source_file']; $uploads[$k]['by'] = $r['uploaded_by_name']; }
}
$stores = db()->query("SELECT DISTINCT store FROM cs_perf_reception WHERE store<>'' ORDER BY store")->fetchAll(PDO::FETCH_COLUMN);
$empChoices = [];
if ($unmatched && $canManage) $empChoices = db()->query("SELECT id, name, department FROM employees ORDER BY (department='" . CS_PERF_RANK_DEPT . "') DESC, department, name")->fetchAll();

function csr_fmt($v, $d = 1) { return $v === null ? '—' : number_format((float)$v, $d); }

$page_title = '客服绩效';
include __DIR__ . '/../includes/header.php';
?>
<style>
.sp-hero{background:linear-gradient(135deg,#eef4f0,#fff 70%);border:1px solid #dfe7e2;border-radius:18px;padding:20px 26px;margin:14px 0}
.sp-hero h2{margin:0 0 4px;font-size:1.35rem;font-weight:700;color:#1f3a2c}.sp-hero .sub{margin:0 0 12px;color:#5d6f64;font-size:.9rem}
.sp-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:0;padding:0;list-style:none;counter-reset:s}
.sp-steps li{counter-increment:s;position:relative;background:rgba(255,255,255,.75);border:1px solid #e3ebe6;border-radius:12px;padding:10px 12px 10px 42px;font-size:.84rem;line-height:1.55;color:#40524a}
.sp-steps li:before{content:counter(s);position:absolute;left:12px;top:10px;width:22px;height:22px;border-radius:50%;background:#2e7d4f;color:#fff;font-size:.78rem;font-weight:700;text-align:center;line-height:22px}
.sp-who{margin:10px 0 0;font-size:.8rem;color:#7a8a80}.sp-who b{color:#3d5a4b}
.sp-rank{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:18px}
.sp-card{border:1px solid #dfe7e2;border-radius:16px;background:#fff;padding:14px 18px 14px 22px;position:relative;overflow:hidden}
.sp-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:6px;background:#cfd9d3}
.sp-card.r1:before{background:#d9a21b}.sp-card.r2:before{background:#97a3ae}.sp-card.r3:before{background:#b87333}
.sp-card .no{font-size:.78rem;color:#7a8a80;letter-spacing:.04em}.sp-card .nm{font-size:1.15rem;font-weight:700;color:#1f3a2c;margin:2px 0}
.sp-card .amt{font-size:1.55rem;font-weight:700;color:#2e7d4f;line-height:1.2}.sp-card .sc{font-size:.78rem;color:#7a8a80;margin-top:2px}.sp-card.zero .amt{color:#9aa7a0}
.sp-card.avg{background:#f6faf7}.sp-card.avg:before{background:#2e7d4f}
.sp-up{display:grid;grid-template-columns:minmax(0,4fr) minmax(0,5fr) minmax(0,3fr);gap:12px;align-items:end}
.sp-up label.l{display:block;font-size:.78rem;color:#6b7c72;margin:0 0 4px}
.sp-up .form-control,.sp-file{height:42px}
.sp-file{display:flex;align-items:center;margin:0;border:1px dashed #b9c9bf;border-radius:8px;background:#f8fbf9;cursor:pointer;overflow:hidden}
.sp-file:hover{border-color:#2e7d4f;background:#f1f8f3}.sp-file input{position:absolute;opacity:0;width:0;height:0}
.sp-file .b{flex:none;padding:0 14px;height:100%;display:flex;align-items:center;background:#e4efe8;color:#2e5a40;font-size:.85rem;font-weight:600}
.sp-file .n{flex:1;min-width:0;padding:0 12px;color:#7a8a80;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sp-file.has .n{color:#1f3a2c}
.sp-pts{display:block;font-size:.72rem;color:#8a9a90}.sp-table td,.sp-table th{vertical-align:middle;white-space:nowrap}
.sp-rk{display:inline-block;min-width:20px;height:20px;border-radius:10px;background:#e8eeea;color:#5d6f64;font-size:.72rem;font-weight:700;text-align:center;line-height:20px;margin-right:6px}
.sp-rk.r1{background:#f6e3ae;color:#7a5600}.sp-rk.r2{background:#e0e5ea;color:#4a5560}.sp-rk.r3{background:#efd2b8;color:#6e4220}
.sp-hint{display:none;font-size:.75rem;color:#8a9a90;padding:6px 14px 0}
.sp-rules{display:grid;gap:8px;margin-bottom:14px}
.sp-rule{display:grid;grid-template-columns:minmax(180px,3fr) repeat(3,minmax(90px,1fr));gap:10px 14px;align-items:center;border:1px solid #e6ede9;border-radius:12px;padding:10px 14px;background:#fbfdfc}
.sp-rule .t{font-weight:600;color:#1f3a2c}.sp-rule .d{font-size:.78rem;color:#7a8a80}
.sp-rule .f label{display:block;font-size:.72rem;color:#8a9a90;margin:0 0 2px}.sp-rule .f .u{font-size:.8rem;color:#7a8a80;margin-left:4px}
.sp-rule .f .v{font-weight:600;color:#33443a}.sp-rule .form-control{height:34px}
.sp-in{display:flex;align-items:center}
@media (max-width:900px){.sp-steps{grid-template-columns:1fr}.sp-up{grid-template-columns:1fr}.sp-rule{grid-template-columns:repeat(3,1fr)}.sp-rule .m{grid-column:1/-1}.sp-hint{display:block}.sp-hero{padding:16px}}
@media (max-width:420px){.sp-rank{grid-template-columns:1fr 1fr;gap:10px}.sp-card{padding:12px 12px 12px 16px}.sp-card .amt{font-size:1.3rem}}
</style>
<div class="container-fluid" style="max-width:1280px">
  <div class="sp-hero">
    <h2><i class="fas fa-headset mr-2"></i>客服绩效</h2>
    <p class="sub">每月初上传店铺导出的“咨询接待分析”表，系统自动打分、排名。</p>
    <ol class="sp-steps">
      <li><b>上传表格</b>：选月份，填店铺名，传 .xlsx。有接待的店各传一份；传错了，重传同月同店会直接覆盖。</li>
      <li><b>自动打分</b>：按下方评分规则给每个人逐项打分，多家店取平均。</li>
      <li><b>排名发放</b>：部门内第 1 名 850、第 2 名 800、第 3 名 750，用于当月报酬结算。</li>
    </ol>
    <p class="sp-who">设计客服主管：<b>张光萍</b> · 核算财务：<b>刘群</b></p>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php foreach ($notes as $n): ?><div class="alert alert-warning"><?php echo e($n); ?></div><?php endforeach; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

  <form method="get" class="form-inline mb-3">
    <label class="mr-2 font-weight-bold text-muted">统计月份</label>
    <select name="year" class="form-control mr-2 mb-1"><?php for ($y = (int)date('Y') + 1; $y >= 2025; $y--): ?><option value="<?php echo $y; ?>"<?php echo $y === $year ? ' selected' : ''; ?>><?php echo $y; ?> 年</option><?php endfor; ?></select>
    <select name="month" class="form-control mr-2 mb-1"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo $m; ?>"<?php echo $m === $month ? ' selected' : ''; ?>><?php echo $m; ?> 月</option><?php endfor; ?></select>
    <button class="btn btn-outline-secondary mb-1">查看</button>
  </form>

  <?php if ($hasData): ?>
  <div class="sp-rank">
    <?php foreach ($ranking as $i => $it): $amt = $i < count($tiers) ? (float)$tiers[$i] : 0.0; ?>
      <div class="sp-card<?php echo $amt > 0 ? ' r' . ($i + 1) : ' zero'; ?>">
        <div class="no">第 <?php echo $i + 1; ?> 名</div><div class="nm"><?php echo e($it['name']); ?></div>
        <div class="amt">¥<?php echo number_format($amt, 0); ?></div>
        <div class="sc">得分 <?php echo number_format($it['score'] * 100, 1); ?> 分<?php echo count($it['rates']) > 1 ? ' · ' . count($it['rates']) . ' 家店平均' : ''; ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($ampN): ?><div class="sp-card avg"><div class="no">前 <?php echo $ampN; ?> 名平均</div><div class="amt" style="color:#1f3a2c">¥<?php echo number_format($ampSum / $ampN, 0); ?></div><div class="sc">按当月排名发放，再按服务时长确认折算</div></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header"><i class="fas fa-file-upload mr-2"></i>上传 <?php echo $year; ?> 年 <?php echo $month; ?> 月的表格</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="sp-up">
        <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="upload">
        <input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>">
        <div><label class="l">店铺名称（只有一家店有接待，就只传这家）</label><input name="store" class="form-control" list="sp-stores" placeholder="例如：清风易软件专营店" required></div>
        <datalist id="sp-stores"><?php foreach ($stores as $s): ?><option value="<?php echo e($s); ?>"><?php endforeach; ?></datalist>
        <div><label class="l">店铺导出的 .xlsx（咨询接待分析）</label><label class="sp-file" id="spFile"><input type="file" name="sheet" accept=".xlsx" required><span class="b"><i class="fas fa-folder-open mr-2"></i>选择文件</span><span class="n" id="spFileName">未选择文件</span></label></div>
        <div><button class="btn btn-success btn-block" style="height:42px"><i class="fas fa-upload mr-1"></i>上传并计算</button></div>
      </form>
      <script>document.querySelector('#spFile input').addEventListener('change',function(){var f=this.files&&this.files[0];document.getElementById('spFileName').textContent=f?f.name:'未选择文件';document.getElementById('spFile').classList.toggle('has',!!f);});</script>
      <?php if ($uploads): ?>
      <div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead><tr><th>已上传店铺</th><th>文件</th><th>人数</th><th>上传人 / 时间</th><th></th></tr></thead><tbody>
        <?php foreach ($uploads as $u): ?><tr>
          <td class="text-nowrap"><?php echo e($u['store'] !== '' ? $u['store'] : '（未填店铺）'); ?></td><td class="small"><?php echo e($u['file']); ?></td><td><?php echo (int)$u['n']; ?></td>
          <td class="small text-nowrap"><?php echo e($u['by']); ?> <span class="text-muted"><?php echo e($u['at']); ?></span></td>
          <td class="text-right"><?php if ($canManage): ?><form method="post" class="d-inline" onsubmit="return confirm('删除这份上传？删除后排名按剩余数据重算')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>"><input type="hidden" name="store" value="<?php echo e($u['store']); ?>"><button class="btn btn-sm btn-outline-danger">删除</button></form><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($unmatched): ?>
  <div class="card mb-4 border-warning"><div class="card-header" style="background:#fff8e6">待对应的昵称 <span class="small text-muted ml-2">表里有，但系统里没找到对应员工；对应一次，以后每月自动对上</span></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>旺旺昵称</th><th>店铺</th><th>接待人数</th><th>对应员工</th></tr></thead><tbody>
      <?php foreach ($unmatched as $u): ?><tr><td><strong><?php echo e($u['nick']); ?></strong></td><td><?php echo e($u['store']); ?></td><td><?php echo (int)$u['incoming_cnt']; ?></td>
        <td><?php if ($canManage): ?><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="bind"><input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>"><input type="hidden" name="nick" value="<?php echo e($u['nick']); ?>">
          <select name="employee_id" class="form-control form-control-sm mr-2" required><option value="">选择员工…</option><?php foreach ($empChoices as $em): ?><option value="<?php echo (int)$em['id']; ?>"><?php echo e($em['name'] . '（' . $em['department'] . '）'); ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-success">对应</button></form>
          <?php else: ?><span class="text-muted small">请联系财务或主管对应</span><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>

  <div class="card mb-4"><div class="card-header"><i class="fas fa-table mr-2"></i><?php echo $year; ?> 年 <?php echo $month; ?> 月明细 <span class="small text-muted ml-2">每项下面的小字是该项得分，满分 100</span></div>
    <div class="sp-hint"><i class="fas fa-arrows-alt-h mr-1"></i>表格较宽，可左右滑动查看</div>
    <div class="table-responsive"><table class="table table-hover mb-0 sp-table">
      <thead class="thead-light"><tr><th>客服</th><th>店铺</th><th class="text-right">接待人数</th>
        <?php foreach ($defs as $k => $d): if ($params[$k]['weight'] <= 0) continue; ?><th class="text-right"><?php echo e($d[0]); ?></th><?php endforeach; ?><th class="text-right">综合得分</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $sc = $r['scored']; $rk = $rankOf[(int)$r['employee_id']] ?? 0; ?>
        <tr><td><?php if ($rk): ?><span class="sp-rk<?php echo $rk <= 3 ? ' r' . $rk : ''; ?>"><?php echo $rk; ?></span><?php endif; ?><strong><?php echo e($r['emp_name'] ?: $r['nick']); ?></strong><?php echo $r['emp_name'] && $r['emp_name'] !== $r['nick'] ? ' <span class="small text-muted">' . e($r['nick']) . '</span>' : ''; ?><?php echo (int)$r['employee_id'] === 0 ? ' <span class="badge badge-warning">未对应</span>' : ''; ?></td>
          <td class="small"><?php echo e($r['store']); ?></td><td class="text-right"><?php echo $r['incoming_cnt'] === null ? '—' : (int)$r['incoming_cnt']; ?></td>
          <?php foreach ($defs as $k => $d): if ($params[$k]['weight'] <= 0) continue; $p = $sc['parts'][$k] ?? null; ?>
            <td class="text-right"><?php echo $p ? csr_fmt($p['value'], $d[1] === '秒' ? 1 : 2) . e($d[1] === '%' ? '%' : ' 秒') . '<span class="sp-pts">' . csr_fmt($p['points'], 0) . ' 分</span>' : '—'; ?></td>
          <?php endforeach; ?>
          <td class="text-right"><strong><?php echo $sc['score'] === null ? '—' : csr_fmt($sc['score'], 1); ?></strong></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?php echo 4 + count($defs); ?>" class="text-center text-muted py-5"><i class="far fa-folder-open fa-2x d-block mb-2" style="opacity:.4"></i>这个月还没有上传数据，在上面选好店铺和文件即可</td></tr><?php endif; ?>
      </tbody></table></div></div>

  <div class="card mb-5"><div class="card-header"><i class="fas fa-sliders-h mr-2"></i>评分规则<?php echo $canManage ? '（可调整）' : ''; ?></div>
    <div class="card-body">
      <p class="small text-muted">每项在“0 分对应值 → 100 分对应值”之间按比例打分，超出两端取 0 或 100；综合得分 = 各项得分按权重加权平均；多家店分别算后取平均，只传一家店就用这一家；万一同分，按系统里的员工编号先后排。表里没有的指标自动跳过。</p>
      <form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="params"><input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>">
      <div class="sp-rules">
        <?php $fv = function ($x) { return rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.'); }; foreach ($defs as $k => $d): $p = $params[$k]; ?>
        <div class="sp-rule">
          <div class="m"><div class="t"><?php echo e($d[0]); ?></div><div class="d"><?php echo e($d[5]); ?></div></div>
          <?php foreach ([['weight', '权重', ''], ['bad', '0 分对应值', $d[1]], ['good', '100 分对应值', $d[1]]] as $fld): ?>
          <div class="f"><label><?php echo e($fld[1]); ?></label>
            <?php if ($canManage): ?><div class="sp-in"><input class="form-control form-control-sm" inputmode="decimal" name="p[<?php echo $k; ?>][<?php echo $fld[0]; ?>]" value="<?php echo e($fv($p[$fld[0]])); ?>"><span class="u"><?php echo e($fld[2]); ?></span></div>
            <?php else: ?><span class="v"><?php echo e($fv($p[$fld[0]])); ?></span><span class="u"><?php echo e($fld[2]); ?></span><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($canManage): ?><button class="btn btn-success px-4">保存规则</button><?php endif; ?>
      </form>
    </div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
