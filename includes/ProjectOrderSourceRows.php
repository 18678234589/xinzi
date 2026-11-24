<?php
/**
 * 上传表格原始内容：导入订单时把每一行表格的全部列（表头 → 内容）原样存下来，订单详情页完整显示，
 * 不管系统有没有对应字段，技术 / 客服 / 售后 / 备案写在表格里的内容都不会丢。
 */
function osr_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_order_source_rows (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, order_id INT UNSIGNED NOT NULL, file_id INT UNSIGNED NOT NULL DEFAULT 0,
        sheet VARCHAR(120) NOT NULL DEFAULT '', line INT NOT NULL DEFAULT 0, cells_json MEDIUMTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_row (order_id,file_id,sheet,line), KEY idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 表头 + 一行 → [['h' => 表头, 'v' => 内容]…]，只留有内容的列；没有表头的列记为“第 N 列”。 */
function osr_cells(array $head, array $row)
{
    $cells = [];
    foreach ($row as $i => $value) {
        if (is_array($value)) continue;
        $text = trim((string)$value);
        if ($text === '') continue;
        $h = trim((string)($head[$i] ?? ''));
        $cells[] = ['h' => $h !== '' ? mb_substr($h, 0, 60) : '第 ' . ($i + 1) . ' 列', 'v' => mb_substr($text, 0, 1000)];
    }
    return $cells;
}

/** 保存订单对应的表格行（同一订单同一文件同一行重复导入只更新）。 */
function osr_save($orderId, $fileId, array $sourceRows)
{
    if (!$sourceRows) return;
    osr_ensure();
    $ins = db()->prepare('INSERT INTO project_order_source_rows (order_id,file_id,sheet,line,cells_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE cells_json=VALUES(cells_json)');
    foreach ($sourceRows as $r) {
        if (empty($r['cells'])) continue;
        $ins->execute([(int)$orderId, (int)$fileId, mb_substr((string)($r['sheet'] ?? ''), 0, 120), (int)($r['line'] ?? 0), json_encode($r['cells'], JSON_UNESCAPED_UNICODE)]);
    }
}

function osr_for_order($orderId)
{
    osr_ensure();
    $q = db()->prepare('SELECT * FROM project_order_source_rows WHERE order_id=? ORDER BY created_at,sheet,line');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

/** 订单详情页里的“上传表格原始内容”卡片。 */
function osr_render(array $actor, $orderId)
{
    try { $rows = osr_for_order($orderId); } catch (Throwable $e) { return ''; }
    if (!$rows) return '';
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $out = '<div class="card mb-3 project-form-card"><div class="card-body"><h5><i class="fas fa-file-excel mr-2 text-success"></i>上传表格原始内容</h5><p class="text-muted small">下面是技术 / 客服 / 售后 / 备案上传表格里这张订单对应的每一行，表格里写了的内容全部在这里，不管系统有没有对应字段。</p>';
    foreach ($rows as $r) {
        $cells = json_decode((string)$r['cells_json'], true) ?: [];
        $out .= '<div class="small text-muted mt-2">' . ($r['sheet'] !== '' ? $h($r['sheet']) . ' · ' : '') . '第 ' . (int)($r['line'] % 10000) . ' 行</div><div class="table-responsive"><table class="table table-sm table-bordered mb-2"><tbody>';
        foreach ($cells as $c) $out .= '<tr><th class="text-nowrap bg-light" style="width:22%">' . $h($c['h']) . '</th><td style="white-space:pre-wrap">' . $h(function_exists('ps_contact_for') ? ps_contact_for($actor, $c['v']) : $c['v']) . '</td></tr>';
        $out .= '</tbody></table></div>';
    }
    return $out . '</div></div>';
}
