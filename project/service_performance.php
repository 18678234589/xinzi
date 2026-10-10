<?php
// 客服绩效（设计客服）：上传店铺每月导出的“咨询接待分析”表 → 按评分规则算每人每店得分 → 部门内排名，前三名 850 / 800 / 750。
// 财务、管理层账号、部门主管、设计客服部门的员工可看可上传；绑定昵称、改评分规则、删整份上传限财务 / 管理层 / 主管。
require_once __DIR__ . '/../includes/lib/cs_perf_reception.php';
$actor = ps_require_actor();
if (!csr_can_view($actor)) { http_response_code(403); exit('此页面仅财务、设计客服和部门主管可访问'); }
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
            if ($store === '') throw new RuntimeException('请填写这份表是哪家店铺的（两家店都要各传一份）');
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
.sp-hero{background:linear-gradient(135deg,#f3f6f4,#fff);border:1px solid #dfe7e2;border-radius:18px;padding:20px 26px;margin:14px 0}
.sp-hero h2{margin:0 0 6px;font-size:1.35rem;font-weight:700;color:#1f3a2c}.sp-hero p{margin:0;color:#5d6f64;font-size:.9rem;line-height:1.7}
.sp-rank{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:18px}
.sp-card{flex:1 1 200px;border:1px solid #dfe7e2;border-radius:16px;background:#fff;padding:16px 20px;position:relative}
.sp-card .no{font-size:.8rem;color:#7a8a80}.sp-card .nm{font-size:1.15rem;font-weight:700;color:#1f3a2c;margin:2px 0}
.sp-card .amt{font-size:1.5rem;font-weight:700;color:#2e7d4f}.sp-card .sc{font-size:.8rem;color:#7a8a80}.sp-card.zero .amt{color:#9aa7a0}
.sp-pts{display:block;font-size:.72rem;color:#8a9a90}.sp-table td,.sp-table th{vertical-align:middle;white-space:nowrap}
.sp-weights input{width:70px;display:inline-block}
</style>
<div class="container-fluid" style="max-width:1280px">
  <div class="sp-hero">
    <h2><i class="fas fa-headset mr-2"></i>客服绩效</h2>
    <p>每月初把店铺导出的“咨询接待分析”表传上来（两家店各传一份，传错了重传同月同店会直接覆盖），系统按下方评分规则给每个人打分，<b>多店取平均后在部门内排名：第 1 名 850、第 2 名 800、第 3 名 750</b>，结果直接用于当月报酬结算。</p>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php foreach ($notes as $n): ?><div class="alert alert-warning"><?php echo e($n); ?></div><?php endforeach; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

  <form method="get" class="form-inline mb-3">
    <label class="mr-2">统计月份</label>
    <select name="year" class="form-control mr-2"><?php for ($y = (int)date('Y') + 1; $y >= 2025; $y--): ?><option value="<?php echo $y; ?>"<?php echo $y === $year ? ' selected' : ''; ?>><?php echo $y; ?> 年</option><?php endfor; ?></select>
    <select name="month" class="form-control mr-2"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo $m; ?>"<?php echo $m === $month ? ' selected' : ''; ?>><?php echo $m; ?> 月</option><?php endfor; ?></select>
    <button class="btn btn-outline-secondary">查看</button>
  </form>

  <?php if ($hasData): ?>
  <div class="sp-rank">
    <?php foreach ($ranking as $i => $it): $amt = $i < count($tiers) ? (float)$tiers[$i] : 0.0; ?>
      <div class="sp-card<?php echo $amt > 0 ? '' : ' zero'; ?>">
        <div class="no">第 <?php echo $i + 1; ?> 名</div><div class="nm"><?php echo e($it['name']); ?></div>
        <div class="amt">¥<?php echo number_format($amt, 0); ?></div>
        <div class="sc">综合得分 <?php echo number_format($it['score'] * 100, 1); ?> 分<?php echo count($it['rates']) > 1 ? '（' . count($it['rates']) . ' 家店平均）' : ''; ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($ampN): ?><div class="sp-card" style="background:#f7faf8"><div class="no">前 <?php echo $ampN; ?> 名平均</div><div class="amt" style="color:#1f3a2c">¥<?php echo number_format($ampSum / $ampN, 0); ?></div><div class="sc">金额按当月排名发放，再按服务时长确认折算</div></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header"><i class="fas fa-file-upload mr-2"></i>上传 <?php echo $year; ?> 年 <?php echo $month; ?> 月的表格</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="form-row align-items-end">
        <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="upload">
        <input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>">
        <div class="col-md-4 mb-2"><label class="small text-muted mb-1">店铺名称（两家店各传一份）</label><input name="store" class="form-control" list="sp-stores" placeholder="例如：清风易软件专营店" required></div>
        <datalist id="sp-stores"><?php foreach ($stores as $s): ?><option value="<?php echo e($s); ?>"><?php endforeach; ?></datalist>
        <div class="col-md-5 mb-2"><label class="small text-muted mb-1">店铺导出的 .xlsx（咨询接待分析）</label><input type="file" name="sheet" accept=".xlsx" class="form-control-file" required></div>
        <div class="col-md-3 mb-2"><button class="btn btn-success btn-block"><i class="fas fa-upload mr-1"></i>上传并计算</button></div>
      </form>
      <?php if ($uploads): ?>
      <table class="table table-sm mt-3 mb-0"><thead><tr><th>已上传店铺</th><th>文件</th><th>人数</th><th>上传人 / 时间</th><th></th></tr></thead><tbody>
        <?php foreach ($uploads as $u): ?><tr>
          <td><?php echo e($u['store'] !== '' ? $u['store'] : '（未填店铺）'); ?></td><td class="small"><?php echo e($u['file']); ?></td><td><?php echo (int)$u['n']; ?></td>
          <td class="small"><?php echo e($u['by']); ?> <span class="text-muted"><?php echo e($u['at']); ?></span></td>
          <td class="text-right"><?php if ($canManage): ?><form method="post" class="d-inline" onsubmit="return confirm('删除这份上传？删除后排名按剩余数据重算')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>"><input type="hidden" name="store" value="<?php echo e($u['store']); ?>"><button class="btn btn-sm btn-outline-danger">删除</button></form><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($unmatched): ?>
  <div class="card mb-4 border-warning"><div class="card-header bg-warning-light">待对应的昵称（表里有，但系统里没找到对应员工）</div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>旺旺昵称</th><th>店铺</th><th>接待人数</th><th>对应员工</th></tr></thead><tbody>
      <?php foreach ($unmatched as $u): ?><tr><td><strong><?php echo e($u['nick']); ?></strong></td><td><?php echo e($u['store']); ?></td><td><?php echo (int)$u['incoming_cnt']; ?></td>
        <td><?php if ($canManage): ?><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="bind"><input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>"><input type="hidden" name="nick" value="<?php echo e($u['nick']); ?>">
          <select name="employee_id" class="form-control form-control-sm mr-2" required><option value="">选择员工…</option><?php foreach ($empChoices as $em): ?><option value="<?php echo (int)$em['id']; ?>"><?php echo e($em['name'] . '（' . $em['department'] . '）'); ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-success">对应</button></form>
          <?php else: ?><span class="text-muted small">请联系财务或主管对应</span><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>

  <div class="card mb-4"><div class="card-header"><i class="fas fa-table mr-2"></i><?php echo $year; ?> 年 <?php echo $month; ?> 月明细（每项后面小字是该项得分，满分 100）</div>
    <div class="table-responsive"><table class="table table-hover mb-0 sp-table">
      <thead class="thead-light"><tr><th>客服</th><th>店铺</th><th class="text-right">接待人数</th>
        <?php foreach ($defs as $k => $d): if ($params[$k]['weight'] <= 0) continue; ?><th class="text-right"><?php echo e($d[0]); ?></th><?php endforeach; ?><th class="text-right">综合得分</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $sc = $r['scored']; ?>
        <tr><td><strong><?php echo e($r['emp_name'] ?: $r['nick']); ?></strong><?php echo $r['emp_name'] && $r['emp_name'] !== $r['nick'] ? ' <span class="small text-muted">' . e($r['nick']) . '</span>' : ''; ?><?php echo (int)$r['employee_id'] === 0 ? ' <span class="badge badge-warning">未对应</span>' : ''; ?></td>
          <td class="small"><?php echo e($r['store']); ?></td><td class="text-right"><?php echo $r['incoming_cnt'] === null ? '—' : (int)$r['incoming_cnt']; ?></td>
          <?php foreach ($defs as $k => $d): if ($params[$k]['weight'] <= 0) continue; $p = $sc['parts'][$k] ?? null; ?>
            <td class="text-right"><?php echo $p ? csr_fmt($p['value'], $d[1] === '秒' ? 1 : 2) . e($d[1] === '%' ? '%' : ' 秒') . '<span class="sp-pts">' . csr_fmt($p['points'], 0) . ' 分</span>' : '—'; ?></td>
          <?php endforeach; ?>
          <td class="text-right"><strong><?php echo $sc['score'] === null ? '—' : csr_fmt($sc['score'], 1); ?></strong></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?php echo 4 + count($defs); ?>" class="text-center text-muted py-5">这个月还没有上传数据</td></tr><?php endif; ?>
      </tbody></table></div></div>

  <div class="card mb-5"><div class="card-header"><i class="fas fa-sliders-h mr-2"></i>评分规则<?php echo $canManage ? '（可调整）' : ''; ?></div>
    <div class="card-body">
      <p class="small text-muted">每项在“0 分对应值 → 100 分对应值”之间按比例打分，超出两端取 0 或 100；综合得分 = 各项得分按权重加权平均；两家店分别算，取平均；万一同分，按系统里的员工编号先后排。表里没有的指标自动跳过。</p>
      <form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="params"><input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>">
      <table class="table table-sm sp-weights mb-3"><thead><tr><th>指标</th><th>说明</th><th>权重</th><th>0 分对应值</th><th>100 分对应值</th></tr></thead><tbody>
        <?php foreach ($defs as $k => $d): $p = $params[$k]; ?><tr><td><?php echo e($d[0]); ?></td><td class="small text-muted"><?php echo e($d[5]); ?></td>
          <?php if ($canManage): ?><td><input class="form-control form-control-sm" name="p[<?php echo $k; ?>][weight]" value="<?php echo e(rtrim(rtrim(number_format($p['weight'], 2, '.', ''), '0'), '.')); ?>"></td>
          <td><input class="form-control form-control-sm" name="p[<?php echo $k; ?>][bad]" value="<?php echo e(rtrim(rtrim(number_format($p['bad'], 2, '.', ''), '0'), '.')); ?>"> <?php echo e($d[1]); ?></td>
          <td><input class="form-control form-control-sm" name="p[<?php echo $k; ?>][good]" value="<?php echo e(rtrim(rtrim(number_format($p['good'], 2, '.', ''), '0'), '.')); ?>"> <?php echo e($d[1]); ?></td>
          <?php else: ?><td><?php echo e(rtrim(rtrim(number_format($p['weight'], 2, '.', ''), '0'), '.')); ?></td><td><?php echo e(rtrim(rtrim(number_format($p['bad'], 2, '.', ''), '0'), '.') . ' ' . $d[1]); ?></td><td><?php echo e(rtrim(rtrim(number_format($p['good'], 2, '.', ''), '0'), '.') . ' ' . $d[1]); ?></td><?php endif; ?></tr><?php endforeach; ?>
      </tbody></table>
      <?php if ($canManage): ?><button class="btn btn-success">保存规则</button><?php endif; ?>
      </form>
    </div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
