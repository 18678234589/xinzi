/* 原始表格在线编辑：像 Excel 一样改单元格，自动保存，提交后更正自己的订单。依赖 jspreadsheet-ce（本地 assets/lib/jspreadsheet）。 */
(function () {
  'use strict';
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  window.SheetEditor = function (root) {
    var cfg = { api: root.getAttribute('data-api'), csrf: root.getAttribute('data-csrf'), file: root.getAttribute('data-file'), sheet: root.getAttribute('data-sheet'), canEdit: root.getAttribute('data-can-edit') === '1' };
    var gridEl = root.querySelector('.se-grid'), statusEl = root.querySelector('.se-status'), submitBtn = root.querySelector('.se-submit'), modal = root.querySelector('.se-modal');
    var table = null, meta = null, queue = {}, timer = null, inflight = false, pendingCount = 0, localChanged = {};

    function setStatus(text, kind) { statusEl.textContent = text; statusEl.className = 'se-status small ' + (kind || 'text-muted'); }
    function call(payload) {
      payload.csrf = cfg.csrf; payload.file = cfg.file; payload.sheet = cfg.sheet;
      return fetch(cfg.api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
        .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || '操作失败'); return d; }); });
    }
    function updateSubmit() {
      submitBtn.disabled = !cfg.canEdit || pendingCount <= 0;
      submitBtn.textContent = pendingCount > 0 ? '提交更正（' + pendingCount + ' 处待提交）' : '提交更正';
    }
    function mark(y, x, applied) {
      var name = jspreadsheet.getColumnNameFromId([x, y]);
      table.setStyle(name, 'background-color', applied ? '#e7f4ea' : '#fff3c4');
    }

    // ---- 自动保存：合并连续编辑，停手 0.9 秒后一次提交；保存中的新编辑排队，不丢 ----
    function flush() {
      if (inflight) return;
      var keys = Object.keys(queue); if (!keys.length) return;
      var edits = keys.map(function (k) { return queue[k]; }); queue = {};
      inflight = true; setStatus('正在保存…');
      call({ action: 'save', edits: edits }).then(function (d) {
        inflight = false; setStatus('已自动保存 ' + d.at, 'text-success');
        if (Object.keys(queue).length) flush();
      }).catch(function (e) {
        inflight = false; edits.forEach(function (ed) { var k = ed.row + ':' + ed.col; if (!queue[k]) queue[k] = ed; });
        setStatus('保存失败：' + e.message + '（修改还在，5 秒后自动重试）', 'text-danger');
        clearTimeout(timer); timer = setTimeout(flush, 5000);
      });
    }
    function schedule() { clearTimeout(timer); setStatus('有修改，稍后自动保存…', 'text-warning'); timer = setTimeout(flush, 900); }

    function onAfterChanges(instance, records) {
      var any = false;
      records.forEach(function (r) {
        if (!r) return; // 只读单元格没有记录
        var dx = parseInt(r.x, 10), y = parseInt(r.y, 10);
        var x = meta.order[dx]; // 显示列 → 原始列
        if (!meta.editable[x]) return;
        var nv = r.newValue !== undefined ? r.newValue : r.value;
        var v = nv == null ? '' : String(nv).trim();
        queue[meta.rowNos[y] + ':' + x] = { row: meta.rowNos[y], col: x, v: v };
        var key = y + ':' + x; if (!localChanged[key]) { localChanged[key] = 1; pendingCount++; }
        mark(y, dx, false); any = true;
      });
      if (any) { updateSubmit(); schedule(); }
    }

    function build(sheet) {
      if (table) { try { jspreadsheet.destroy(gridEl, true); } catch (e) { /* 重新载入时先销毁旧表 */ } gridEl.innerHTML = ''; table = null; }
      localChanged = {}; queue = {};
      meta = sheet;
      // 列顺序：订单号在最前（并冻结），紧跟着是可编辑列（客户手机号 / 域名 / 到期日 / 售价…），最后才是只读的原表其它列，
      // 这样要填的地方一打开就能看到，不用先横向拖到最右边。
      var n = sheet.head.length, order = [], used = {}, first = sheet.orderCol != null ? sheet.orderCol : 0;
      if (n > 0) { order.push(first); used[first] = 1; }
      sheet.editable.forEach(function (e, i) { if (e && !used[i]) { order.push(i); used[i] = 1; } });
      for (var i2 = 0; i2 < n; i2++) if (!used[i2]) order.push(i2);
      var inv = []; order.forEach(function (orig, pos) { inv[orig] = pos; });
      meta.order = order; meta.inv = inv;
      var cols = order.map(function (i) {
        var h = sheet.head[i], kind = sheet.kinds[i], w = kind === 'order_no' ? 190 : (kind === 'date' ? 100 : (sheet.editable[i] ? 140 : Math.min(220, Math.max(80, (h.length || 3) * 18 + 30))));
        return { type: 'text', title: h || ('列' + (i + 1)), width: w, readOnly: !cfg.canEdit || !sheet.editable[i], wordWrap: false };
      });
      var rows = sheet.rows.map(function (r) { return order.map(function (o) { return r[o]; }); });
      pendingCount = sheet.pending || 0;
      var h = Math.max(320, (window.innerHeight || 700) - 330);
      table = jspreadsheet(gridEl, {
        data: rows.length ? rows : [[]], columns: cols, freezeColumns: 1,
        tableOverflow: true, tableWidth: '100%', tableHeight: h + 'px', lazyLoading: true, loadingSpin: true,
        columnSorting: false, allowInsertRow: false, allowManualInsertRow: false, allowInsertColumn: false, allowManualInsertColumn: false,
        allowDeleteRow: false, allowDeleteColumn: false, allowRenameColumn: false, allowComments: false, allowExport: false,
        selectionCopy: true, search: true, defaultColWidth: 110, rowResize: false, columnResize: true,
        contextMenu: function () { return []; }, onafterchanges: onAfterChanges,
        text: { search: '在表中查找…', showingPage: '第 {0} / {1} 页', entries: '', noRecordsFound: '没有找到' }
      });
      // 可编辑列的表头加底色，让人一眼知道哪些能改
      var heads = gridEl.querySelectorAll('thead tr td[data-x]');
      heads.forEach(function (td) { var x = parseInt(td.getAttribute('data-x'), 10); if (sheet.editable[order[x]] && cfg.canEdit) td.classList.add('se-edit-head'); });
      (sheet.changed || []).forEach(function (c) { mark(c[0], inv[c[1]], c[2] === 1); });
      var note = root.querySelector('.se-trunc'); if (note) note.hidden = !sheet.truncated;
      updateSubmit(); setStatus(cfg.canEdit ? '修改会自动保存' : '只读（你没有编辑权限）', 'text-muted');
    }

    function load() {
      setStatus('正在载入表格…');
      return fetch(cfg.api + '?action=load&file=' + encodeURIComponent(cfg.file) + '&sheet=' + encodeURIComponent(cfg.sheet), { credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || '载入失败'); return d.sheet; }); })
        .then(build).catch(function (e) { setStatus('载入失败：' + e.message, 'text-danger'); });
    }

    // ---- 提交更正：先预览将要改什么，确认后提交 ----
    function showModal(html, onOk, okText) {
      modal.querySelector('.se-modal-body').innerHTML = html;
      var ok = modal.querySelector('.se-ok'); ok.style.display = onOk ? '' : 'none'; ok.textContent = okText || '确认提交'; ok.disabled = false; ok.onclick = onOk;
      modal.hidden = false;
    }
    function closeModal() { modal.hidden = true; }
    modal.querySelectorAll('.se-close').forEach(function (b) { b.addEventListener('click', closeModal); });
    function table2(results) {
      var rows = results.map(function (r) {
        var ch = Object.keys(r.changes).map(function (k) { return '<span class="badge badge-light border">' + esc(k) + '：' + esc(r.changes[k]) + '</span>'; }).join(' ');
        var cls = r.result === '失败' ? 'text-danger' : (r.result === '待提交' ? 'text-muted' : 'text-success');
        return '<tr><td>' + r.row + '</td><td>' + esc(r.order_no) + '</td><td>' + ch + '</td><td class="' + cls + '"><strong>' + esc(r.result) + '</strong>' + (r.message ? '<div class="small">' + esc(r.message) + '</div>' : '') + '</td></tr>';
      }).join('');
      return '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>行</th><th>订单号</th><th>修改内容</th><th>状态</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }
    submitBtn.addEventListener('click', function () {
      clearTimeout(timer);
      var go = function () {
        showModal('<div class="text-muted">正在核对你的修改…</div>', null);
        call({ action: 'preview' }).then(function (d) {
          if (!d.results.length) { showModal('<div class="alert alert-info mb-0">没有需要提交的修改。</div>', null); return; }
          var bad = d.results.filter(function (r) { return r.result === '失败'; }).length;
          showModal('<p>将更正 <strong>' + d.results.length + '</strong> 个订单：手机号 / 域名直接写入续费资料；售价、店铺、付款昵称' + (document.body.getAttribute('data-finance') === '1' ? '直接生效。' : '提交给财务确认后生效。') + (bad ? '<br><span class="text-danger">其中 ' + bad + ' 行有问题，提交时会跳过并说明原因。</span>' : '') + '</p>' + table2(d.results), function () {
            var ok = modal.querySelector('.se-ok'); ok.disabled = true; ok.textContent = '正在提交…';
            call({ action: 'submit' }).then(function (s) {
              showModal('<div class="alert alert-success">已处理 ' + s.results.length + ' 个订单，结果如下：</div>' + table2(s.results), null);
              load(); // 刷新状态（已提交的格子变绿）
            }).catch(function (e) { showModal('<div class="alert alert-danger mb-0">' + esc(e.message) + '</div>', null); });
          });
        }).catch(function (e) { showModal('<div class="alert alert-danger mb-0">' + esc(e.message) + '</div>', null); });
      };
      // 先把还在排队的修改保存完再预览
      if (Object.keys(queue).length || inflight) { var t = setInterval(function () { if (!inflight && !Object.keys(queue).length) { clearInterval(t); go(); } else flush(); }, 300); } else go();
    });
    window.addEventListener('beforeunload', function (e) { if (Object.keys(queue).length || inflight) { e.preventDefault(); e.returnValue = ''; } });
    load();
    return { reload: load, flush: flush };
  };
})();
