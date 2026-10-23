/* 原始表格在线编辑：像 Excel 一样改单元格，自动保存，提交后更正自己的订单。依赖 jspreadsheet-ce（本地 assets/lib/jspreadsheet）。 */
(function () {
  'use strict';
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  // ---- 金额求和：选中区域 / 整列求和（编辑视图与只读视图共用） ----
  var SheetSum = window.SheetSum = {
    // 把 ¥1,234.50、1234.5元 这类写法转成数字；订单号 / 手机号这种超长整数不当金额。
    num: function (v) {
      if (v == null) return null;
      var s = String(v).replace(/[\s,，¥￥$元]/g, '');
      if (!/^[-+]?(\d+\.?\d*|\.\d+)$/.test(s)) return null;
      if (s.replace(/^[-+]/, '').split('.')[0].length >= 11) return null;
      return parseFloat(s);
    },
    stats: function (values) {
      var st = { cells: 0, count: 0, sum: 0, max: -Infinity, min: Infinity };
      values.forEach(function (v) {
        if (v == null || String(v).trim() === '') return;
        st.cells++;
        var x = SheetSum.num(v); if (x === null) return;
        st.count++; st.sum += x; if (x > st.max) st.max = x; if (x < st.min) st.min = x;
      });
      st.sum = Math.round(st.sum * 100) / 100; st.avg = st.count ? st.sum / st.count : 0;
      return st;
    },
    fmt: function (v) { return Number(v).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    // 数字占多数的列才进“整列求和”下拉
    isAmountColumn: function (values) {
      var n = 0, k = 0; values.forEach(function (v) { if (v == null || String(v).trim() === '') return; n++; if (SheetSum.num(v) !== null) k++; });
      return k > 0 && k / n >= 0.6;
    },
    bar: function (mount) {
      var el = document.createElement('div'); el.className = 'se-sumbar'; el.setAttribute('aria-live', 'polite');
      el.innerHTML = '<div class="se-sum-main"><span class="se-sum-icon">Σ</span><span class="se-sum-text se-sum-hint">拖选一片单元格（或点列标题选整列），这里显示金额求和</span></div>' +
        '<div class="se-sum-col"><label>整列求和</label><select aria-label="选择要求和的列"><option value="">选择金额列…</option></select><b class="se-sum-colval"></b></div>';
      mount(el);
      var text = el.querySelector('.se-sum-text'), sel = el.querySelector('select'), colVal = el.querySelector('.se-sum-colval'), cols = [];
      function copy(t, node) {
        var done = function () { var o = node.getAttribute('data-t') || node.textContent; node.setAttribute('data-t', o); node.textContent = '已复制'; setTimeout(function () { node.textContent = o; }, 900); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(done); else { var a = document.createElement('textarea'); a.value = t; a.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(a); }
      }
      text.addEventListener('click', function (e) { var b = e.target.closest('b[data-copy]'); if (b) copy(b.getAttribute('data-copy'), b); });
      colVal.addEventListener('click', function () { if (colVal.getAttribute('data-copy')) copy(colVal.getAttribute('data-copy'), colVal); });
      function showCol() {
        var c = cols[Number(sel.value)]; if (sel.value === '' || !c) { colVal.textContent = ''; colVal.removeAttribute('data-copy'); return; }
        var st = SheetSum.stats(c.get()); colVal.textContent = '¥' + SheetSum.fmt(st.sum) + '（' + st.count + ' 行）'; colVal.setAttribute('data-copy', String(st.sum)); colVal.removeAttribute('data-t');
      }
      sel.addEventListener('change', showCol);
      return {
        showSel: function (values, desc) {
          var st = SheetSum.stats(values);
          if (!st.cells) { text.className = 'se-sum-text se-sum-hint'; text.textContent = '拖选一片单元格（或点列标题选整列），这里显示金额求和'; return; }
          text.className = 'se-sum-text';
          text.innerHTML = (desc ? '<span class="se-sum-desc">' + esc(desc) + '</span>' : '') + '已选 ' + st.cells + ' 格' +
            (st.count ? ' · 数字 ' + st.count + ' 个 · 求和 <b data-copy="' + st.sum + '" title="点击复制">¥' + SheetSum.fmt(st.sum) + '</b> · 平均 ' + SheetSum.fmt(st.avg) + ' · 最大 ' + SheetSum.fmt(st.max) + ' · 最小 ' + SheetSum.fmt(st.min) : ' · 没有可求和的数字');
        },
        setColumns: function (list) {
          cols = list; var keep = sel.value; sel.innerHTML = '<option value="">选择金额列…</option>';
          list.forEach(function (c, i) { var o = document.createElement('option'); o.value = String(i); o.textContent = c.label; sel.appendChild(o); });
          if (keep !== '' && list[Number(keep)]) sel.value = keep;
          showCol();
        },
        refresh: showCol
      };
    }
  };

  window.SheetEditor = function (root) {
    var cfg = { api: root.getAttribute('data-api'), csrf: root.getAttribute('data-csrf'), file: root.getAttribute('data-file'), sheet: root.getAttribute('data-sheet'), canEdit: root.getAttribute('data-can-edit') === '1' };
    var gridEl = root.querySelector('.se-grid'), statusEl = root.querySelector('.se-status'), submitBtn = root.querySelector('.se-submit'), modal = root.querySelector('.se-modal');
    var importBtn = root.querySelector('.se-import'), saveBtn = root.querySelector('.se-save'), cellInput = root.querySelector('.se-cell-input'), cellLabel = root.querySelector('.se-cell-label');
    var scrollBar = root.querySelector('.se-scrollbar'), scrollSpace = root.querySelector('.se-scroll-space'), selected = null, saving = null, submitting = false;
    var table = null, meta = null, queue = {}, timer = null, inflight = false, pendingCount = 0, localChanged = {};

    function setStatus(text, kind) { statusEl.textContent = text; statusEl.className = 'se-status small ' + (kind || 'text-muted'); }
    function call(payload) {
      payload.csrf = cfg.csrf; payload.file = cfg.file; payload.sheet = cfg.sheet;
      return fetch(cfg.api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
        .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || '操作失败'); return d; }); });
    }
    function updateSubmit() {
      submitBtn.disabled = submitting || !cfg.canEdit || pendingCount <= 0;
      if (importBtn) importBtn.disabled = submitting || !cfg.canEdit || !meta || !meta.rows.length;
      if (saveBtn) saveBtn.disabled = submitting || !cfg.canEdit || !meta;
      submitBtn.textContent = pendingCount > 0 ? '提交更正（' + pendingCount + ' 处待提交）' : '提交更正';
    }
    function mark(y, x, applied) {
      var name = jspreadsheet.getColumnNameFromId([x, y]);
      table.setStyle(name, 'background-color', applied ? '#e7f4ea' : '#fff3c4');
    }

    // ---- 自动保存：合并连续编辑，停手 0.9 秒后一次提交；保存中的新编辑排队，不丢 ----
    function flush() {
      if (saving) return saving.then(flush);
      var keys = Object.keys(queue).slice(0, 500); if (!keys.length) return Promise.resolve();
      var edits = keys.map(function (k) { var ed = queue[k]; delete queue[k]; return ed; });
      inflight = true; setStatus('正在保存…');
      saving = call({ action: 'save', edits: edits }).then(function (d) {
        inflight = false; saving = null; setStatus('已保存 ' + d.at, 'text-success');
        if (!Object.keys(queue).length && typeof d.pending === 'number') { pendingCount = d.pending; updateSubmit(); }
        return flush();
      }, function (e) {
        inflight = false; saving = null; edits.forEach(function (ed) { var k = ed.row + ':' + ed.col; if (!queue[k]) queue[k] = ed; });
        setStatus('保存失败：' + e.message + '（修改还在，5 秒后自动重试）', 'text-danger');
        clearTimeout(timer); timer = setTimeout(function () { flush().catch(function () {}); }, 5000);
        throw e;
      });
      return saving;
    }
    function schedule() { clearTimeout(timer); setStatus('有修改，稍后自动保存…', 'text-warning'); timer = setTimeout(function () { flush().catch(function () {}); }, 900); }

    // 选中区域 → 金额求和；同时沿用单元格编辑框
    var sumBar = SheetSum.bar(function (el) { var body = root.querySelector('.se-body'); body.parentNode.insertBefore(el, body.nextSibling); });
    function onSelect(instance, x1, y1, x2, y2) {
      selectCell(instance, x1, y1);
      if (!table) return;
      var xa = Math.min(x1, x2), xb = Math.max(x1, x2), ya = Math.min(y1, y2), yb = Math.max(y1, y2), vals = [], data = table.options.data;
      for (var y = ya; y <= yb; y++) for (var x = xa; x <= xb; x++) if (data[y]) vals.push(data[y][x]);
      var wholeCol = ya === 0 && yb >= data.length - 1 && xa === xb;
      sumBar.showSel(vals, wholeCol ? '整列「' + (meta.head[meta.order[xa]] || ('列' + (xa + 1))) + '」' : '');
    }
    function refreshColumns() {
      if (!table) return;
      var data = table.options.data, list = [];
      meta.head.forEach(function (h, i) {
        var x = meta.inv[i]; if (meta.kinds[i] === 'order_no') return;
        var vals = []; for (var y = 0; y < data.length; y++) if (data[y]) vals.push(data[y][x]);
        if (SheetSum.isAmountColumn(vals)) list.push({ label: h || ('列' + (i + 1)), get: (function (xx) { return function () { return table.options.data.map(function (r) { return r ? r[xx] : ''; }); }; })(x) });
      });
      sumBar.setColumns(list);
    }
    function selectCell(instance, x, y) {
      if (!table || !meta || !cellInput || !meta.rowNos[y]) return;
      selected = { x: Number(x), y: Number(y) };
      cellLabel.textContent = '第 ' + (meta.rowNos[y] + 1) + ' 行 · ' + (meta.head[meta.order[x]] || ('列 ' + (Number(x) + 1)));
      cellInput.value = table.getValueFromCoords(Number(x), Number(y)); cellInput.disabled = !cfg.canEdit;
    }
    function applyCell() {
      if (selected && cfg.canEdit && !cellInput.disabled) table.setValueFromCoords(selected.x, selected.y, cellInput.value);
    }
    function finishEditing() {
      if (table && table.edition) table.closeEditor(table.edition[0], true);
      applyCell();
    }
    function resizeGrid() {
      if (!table) return;
      var content = gridEl.querySelector('.jexcel_content');
      if (!content) return;
      var width = Math.max(160, root.clientWidth - 2);
      content.style.width = width + 'px';
      table.options.tableWidth = width + 'px';
      if (scrollSpace) scrollSpace.style.width = content.scrollWidth + 'px';
    }
    if (cellInput) {
      cellInput.addEventListener('change', applyCell);
      cellInput.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); applyCell(); cellInput.blur(); } });
      root.querySelector('.se-cell-apply').addEventListener('click', applyCell);
    }
    if (scrollBar) scrollBar.addEventListener('scroll', function () { var c = gridEl.querySelector('.jexcel_content'); if (c && c.scrollLeft !== scrollBar.scrollLeft) c.scrollLeft = scrollBar.scrollLeft; });
    root.querySelectorAll('[data-se-scroll]').forEach(function (btn) {
      btn.addEventListener('click', function () { var c = gridEl.querySelector('.jexcel_content'); if (c) c.scrollLeft += Number(btn.getAttribute('data-se-scroll')) * c.clientWidth * 0.7; });
    });
    window.addEventListener('resize', resizeGrid);
    if (window.ResizeObserver) new ResizeObserver(resizeGrid).observe(root);

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
        if (selected && selected.x === dx && selected.y === y && cellInput) cellInput.value = v;
      });
      if (any) { updateSubmit(); schedule(); sumBar.refresh(); }
    }

    function build(sheet) {
      if (table) { try { jspreadsheet.destroy(gridEl, true); } catch (e) { /* 重新载入时先销毁旧表 */ } gridEl.innerHTML = ''; table = null; }
      localChanged = {}; queue = {};
      meta = sheet;
      if (typeof sheet.canEdit === 'boolean') cfg.canEdit = sheet.canEdit;
      // 保留上传表的列顺序，复制整片区域时不会错列。
      var n = sheet.head.length, order = [];
      for (var i2 = 0; i2 < n; i2++) order.push(i2);
      var inv = []; order.forEach(function (orig, pos) { inv[orig] = pos; });
      meta.order = order; meta.inv = inv;
      var cols = order.map(function (i) {
        var h = sheet.head[i], kind = sheet.kinds[i], w = kind === 'order_no' ? 190 : (kind === 'date' ? 100 : (sheet.editable[i] ? 140 : Math.min(220, Math.max(80, (h.length || 3) * 18 + 30))));
        var opts = sheet.options && sheet.options[i];
        // 与 Excel 模板同名的下拉列：点单元格出下拉（原表里不在选项内的旧值照样显示，不会被清掉）
        if (opts && opts.length) {
          var src = opts.slice(), seen = {}; src.forEach(function (v) { seen[v] = 1; });
          sheet.rows.forEach(function (r) { var v = r[i] == null ? '' : String(r[i]); if (v !== '' && !seen[v]) { seen[v] = 1; src.push(v); } }); // 原表里不在选项内的旧值也列出来，否则下拉格会显示成空白
          return { type: 'dropdown', source: src, autocomplete: true, title: h || ('列' + (i + 1)), width: Math.max(w, 130), readOnly: !cfg.canEdit || !sheet.editable[i], wordWrap: false };
        }
        return { type: 'text', title: h || ('列' + (i + 1)), width: w, readOnly: !cfg.canEdit || !sheet.editable[i], wordWrap: false };
      });
      var rows = sheet.rows.map(function (r) { return order.map(function (o) { return r[o]; }); });
      pendingCount = sheet.pending || 0;
      var h = Math.max(320, (window.innerHeight || 700) - 330);
      table = jspreadsheet(gridEl, {
        data: rows.length ? rows : [[]], columns: cols, freezeColumns: 0,
        tableOverflow: true, tableWidth: Math.max(160, root.clientWidth - 2) + 'px', tableHeight: h + 'px', lazyLoading: true, loadingSpin: true,
        parseFormulas: false, autoCasting: false,
        columnSorting: false, allowInsertRow: false, allowManualInsertRow: false, allowInsertColumn: false, allowManualInsertColumn: false,
        allowDeleteRow: false, allowDeleteColumn: false, allowRenameColumn: false, allowComments: false, allowExport: false,
        selectionCopy: true, search: true, defaultColWidth: 110, rowResize: false, columnResize: true,
        contextMenu: function () { return []; }, onafterchanges: onAfterChanges, onselection: onSelect, onresizecolumn: resizeGrid,
        text: { search: '在表中查找…', showingPage: '第 {0} / {1} 页', entries: '', noRecordsFound: '没有找到' }
      });
      var content = gridEl.querySelector('.jexcel_content');
      if (content && scrollBar) content.addEventListener('scroll', function () { if (scrollBar.scrollLeft !== content.scrollLeft) scrollBar.scrollLeft = content.scrollLeft; });
      if (content) {
        var touch = null;
        content.addEventListener('touchstart', function (e) {
          if (e.touches.length !== 1 || e.target.closest('input,textarea')) { touch = null; return; }
          touch = { x: e.touches[0].clientX, y: e.touches[0].clientY, left: content.scrollLeft };
        }, { passive: true });
        content.addEventListener('touchmove', function (e) {
          if (!touch || e.touches.length !== 1) return;
          var dx = touch.x - e.touches[0].clientX, dy = touch.y - e.touches[0].clientY;
          if (Math.abs(dx) > 8 && Math.abs(dx) > Math.abs(dy)) {
            content.scrollLeft = touch.left + dx;
            if (typeof jspreadsheet.touchEndControls === 'function') jspreadsheet.touchEndControls(e);
            if (e.cancelable) e.preventDefault();
          }
        }, { passive: false });
        content.addEventListener('touchend', function () { touch = null; });
        content.addEventListener('touchcancel', function () { touch = null; });
      }
      resizeGrid();
      selected = null;
      if (cellInput) { cellInput.value = ''; cellInput.disabled = true; cellLabel.textContent = '点选单元格后编辑'; }
      // 可编辑列的表头加底色，让人一眼知道哪些能改
      var heads = gridEl.querySelectorAll('thead tr td[data-x]');
      heads.forEach(function (td) { var x = parseInt(td.getAttribute('data-x'), 10); if (sheet.editable[order[x]] && cfg.canEdit) td.classList.add('se-edit-head'); });
      (sheet.changed || []).forEach(function (c) { mark(c[0], inv[c[1]], c[2] === 1); });
      var note = root.querySelector('.se-trunc'); if (note) note.hidden = !sheet.truncated;
      refreshColumns();
      updateSubmit(); setStatus(cfg.canEdit ? '全部字段可编辑，修改会自动保存' : '只读（你没有编辑权限）', 'text-muted');
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
    if (saveBtn) saveBtn.addEventListener('click', function () { finishEditing(); clearTimeout(timer); flush().catch(function () {}); });
    if (importBtn) importBtn.addEventListener('click', function () {
      finishEditing(); clearTimeout(timer); submitting = true; updateSubmit();
      flush().then(function () {
        var form = document.createElement('form'); form.method = 'POST'; form.action = root.getAttribute('data-import-url');
        var fields = { csrf: cfg.csrf, action: 'repreview', resume_file: cfg.file, file_id: cfg.file, business: meta.file.business, all_sheets: '1' };
        Object.keys(fields).forEach(function (name) { var input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = fields[name]; form.appendChild(input); });
        document.body.appendChild(form); form.submit();
      }).catch(function () { submitting = false; updateSubmit(); });
    });
    function table2(results) {
      var rows = results.map(function (r) {
        var ch = Object.keys(r.changes).map(function (k) { return '<span class="badge badge-light border">' + esc(k) + '：' + esc(r.changes[k]) + '</span>'; }).join(' ');
        var cls = r.result === '失败' ? 'text-danger' : (r.result === '待提交' ? 'text-muted' : 'text-success');
        return '<tr><td>' + r.row + '</td><td>' + esc(r.order_no) + '</td><td>' + ch + '</td><td class="' + cls + '"><strong>' + esc(r.result) + '</strong>' + (r.message ? '<div class="small">' + esc(r.message) + '</div>' : '') + '</td></tr>';
      }).join('');
      return '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>行</th><th>订单号</th><th>修改内容</th><th>状态</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }
    submitBtn.addEventListener('click', function () {
      finishEditing(); clearTimeout(timer);
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
      submitting = true; updateSubmit();
      flush().then(go).catch(function () {}).then(function () { submitting = false; updateSubmit(); });
    });
    window.addEventListener('beforeunload', function (e) { if (Object.keys(queue).length || inflight) { e.preventDefault(); e.returnValue = ''; } });
    load();
    return { reload: load, flush: flush };
  };
})();
