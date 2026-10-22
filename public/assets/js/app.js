// StockFlow WMS — tiện ích giao diện (không phụ thuộc thư viện).
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // KPI đếm tăng dần
  document.querySelectorAll('[data-count]').forEach((el) => {
    const target = +el.dataset.count;
    if (reduce || target < 2) return;
    const t0 = performance.now(), dur = 900, fmt = new Intl.NumberFormat('vi-VN');
    const tick = (t) => {
      const p = Math.min(1, (t - t0) / dur);
      el.textContent = fmt.format(Math.round(target * (1 - Math.pow(1 - p, 3))));
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });

  // Biểu đồ (Chart.js nạp cục bộ từ /assets/vendor)
  const initCharts = () => {
    if (!window.Chart) return;
    const palette = ['#d12f3f', '#2b2f3a', '#f59e0b', '#2563eb', '#16a34a', '#7a8190', '#0ea5e9'];
    Chart.defaults.font.family = '"Be Vietnam Pro", system-ui, sans-serif';
    const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    const paintChartTheme = () => { Chart.defaults.color = css('--muted'); Chart.defaults.borderColor = css('--line'); };
    paintChartTheme();
    document.addEventListener('themechange', () => { paintChartTheme(); Object.values(Chart.instances).forEach((c) => c.update('none')); });
    document.querySelectorAll('canvas[data-chart]').forEach((cv) => {
      const labels = JSON.parse(cv.dataset.labels || '[]');
      if (cv.dataset.chart === 'doughnut') {
        new Chart(cv, { type: 'doughnut',
          data: { labels, datasets: [{ data: JSON.parse(cv.dataset.values || '[]'), backgroundColor: (c) => (c.dataIndex === 1 && document.documentElement.dataset.theme === 'dark' ? '#8b95b3' : palette[c.dataIndex % palette.length]), borderWidth: 2, borderColor: () => css('--surface'), hoverOffset: 6 }] },
          options: { cutout: '66%', maintainAspectRatio: false, animation: reduce ? false : { duration: 900 },
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } } } } });
      } else if (cv.dataset.chart === 'flowbar') {
        new Chart(cv, { type: 'bar',
          data: { labels, datasets: [
            { label: 'Tổng vào (nhập + chuyển đến)', data: JSON.parse(cv.dataset.vao || '[]'), backgroundColor: '#16a34a', borderRadius: 8, maxBarThickness: 38 },
            { label: 'Tổng ra (xuất + chuyển đi)', data: JSON.parse(cv.dataset.ra || '[]'), backgroundColor: '#f59e0b', borderRadius: 8, maxBarThickness: 38 } ] },
          options: { maintainAspectRatio: false, animation: reduce ? false : { duration: 900 },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: () => css('--line') }, border: { display: false } } },
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } } } } });
      } else if (cv.dataset.chart === 'dailyline') {
        new Chart(cv, { type: 'line',
          data: { labels, datasets: [
            { label: 'Tổng vào', data: JSON.parse(cv.dataset.vao || '[]'), borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.12)', fill: true, tension: .3, pointRadius: 3 },
            { label: 'Tổng ra', data: JSON.parse(cv.dataset.ra || '[]'), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.10)', fill: true, tension: .3, pointRadius: 3 } ] },
          options: { maintainAspectRatio: false, animation: reduce ? false : { duration: 900 }, interaction: { mode: 'index', intersect: false },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: () => css('--line') }, border: { display: false } } },
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } } } } });
      } else {
        new Chart(cv, { type: 'bar',
          data: { labels, datasets: [
            { label: 'Số mã hàng', data: JSON.parse(cv.dataset.skus || '[]'), backgroundColor: '#d12f3f', borderRadius: 8, maxBarThickness: 38 },
            { label: 'Phiếu chờ xác nhận', data: JSON.parse(cv.dataset.drafts || '[]'), backgroundColor: '#2b2f3a', borderRadius: 8, maxBarThickness: 38 } ] },
          options: { maintainAspectRatio: false, animation: reduce ? false : { duration: 900 },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: () => css('--line') }, border: { display: false } } },
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } } } } });
      }
    });
  };
  if (document.readyState === 'complete') initCharts(); else addEventListener('load', initCharts);

  // Menu trên mobile
  const toggle = document.querySelector('[data-menu-toggle]');
  if (toggle) toggle.addEventListener('click', () => document.getElementById('sidebar').classList.toggle('open'));

  // ── Tìm kiếm: chữ thường, bỏ dấu (đ → d), mọi từ khoá đều phải khớp ──
  const fold = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const matches = (hay, q) => fold(q).split(/\s+/).filter(Boolean).every((w) => hay.includes(w));
  const vn = new Intl.NumberFormat('vi-VN');
  const SUGGEST = 8; // số gợi ý hiển thị mỗi lần

  // Ô chọn hàng của phiếu: bấm vào là gợi ý vài mặt hàng đầu danh sách, gõ để tìm, chọn xong mới sinh dòng có +/−
  const picker = document.getElementById('picker');
  if (picker) {
    const items = JSON.parse(picker.dataset.items || '[]').map((i) => ({ ...i, hay: fold([i.sku, i.name, i.lot].join(' ')) }));
    const stock = JSON.parse(picker.dataset.stock || '{}');
    const check = !!picker.dataset.check;
    const form = picker.closest('form');
    const search = picker.querySelector('#pick-search'), list = picker.querySelector('#pick-list'), body = picker.querySelector('#pick-rows');
    const wrap = picker.querySelector('#pick-wrap'), empty = picker.querySelector('#pick-empty'), sum = picker.querySelector('#pick-sum');
    const whSelect = form.querySelector('[name=warehouse_id], [name=from_wh]'); // ô chọn (quản trị) hoặc kho cố định của tài khoản
    let shown = [], active = -1;

    const stockOf = (k) => {
      const wh = whSelect ? whSelect.value : '';
      return wh && stock[wh] ? stock[wh][k] ?? 0 : null; // null: chưa chọn kho
    };
    const lotTag = (i) => (i.lot ? ` <small class="tag tag-muted">Lô ${esc(i.lot)}${i.exp ? ' · HSD ' + esc(i.exp) : ''}</small>` : '');
    const stockText = (i) => { const s = stockOf(i.k); return s === null ? '' : `Tồn ${vn.format(s)} ${esc(i.unit)}`; };

    const open = (on) => { list.hidden = !on; search.setAttribute('aria-expanded', on ? 'true' : 'false'); };
    const render = () => {
      const q = search.value.trim();
      const all = q ? items.filter((i) => matches(i.hay, q)) : items;
      shown = all.slice(0, SUGGEST);
      if (active >= shown.length) active = shown.length - 1;
      list.innerHTML = shown.length
        ? shown.map((i, n) => `<li role="option" data-n="${n}" class="${n === active ? 'on' : ''}"><span><b>${esc(i.sku)}</b> ${esc(i.name)}${lotTag(i)}</span><small class="muted">${stockText(i)}</small></li>`).join('')
          + (all.length > shown.length ? `<li class="more">… còn ${all.length - shown.length} kết quả nữa — gõ thêm để thu hẹp</li>` : '')
        : '<li class="none">Không tìm thấy mặt hàng phù hợp</li>';
      open(true);
    };

    const refresh = () => {
      const trs = [...body.rows];
      let total = 0;
      trs.forEach((tr) => {
        const raw = tr.querySelector('input[name="qty[]"]').value.trim();
        const s = stockOf(tr.dataset.k), cell = tr.querySelector('.stock-cell'), err = tr.querySelector('.row-err');
        const qty = /^\d+$/.test(raw) ? parseInt(raw, 10) : 0;
        total += qty;
        cell.textContent = s === null ? '—' : vn.format(s);
        let msg = '';
        if (!/^\d+$/.test(raw) || qty < 1) msg = raw.startsWith('-') ? 'Không được nhập số âm' : 'Số lượng phải là số nguyên dương';
        else if (check && s !== null && qty > s) msg = s === 0 ? 'Kho đã hết hàng, không thể xuất' : `Vượt tồn kho — chỉ còn ${vn.format(s)}`;
        tr.classList.toggle('over', !!msg);
        tr.dataset.err = msg;
        err.textContent = msg;
        err.hidden = !msg;
        cell.title = msg;
      });
      wrap.hidden = trs.length === 0;
      empty.hidden = trs.length > 0;
      sum.hidden = trs.length === 0;
      sum.classList.remove('bad');
      sum.textContent = `${trs.length} dòng · tổng ${vn.format(total)} đơn vị`;
    };

    // Cộng/trừ số lượng; tăng quá tồn thì giữ nguyên và báo ngay
    const bump = (tr, d) => {
      const q = tr.querySelector('input[name="qty[]"]');
      const cur = /^\d+$/.test(q.value.trim()) ? parseInt(q.value, 10) : 0;
      const s = stockOf(tr.dataset.k);
      let next = Math.max(1, Math.min(1000000, cur + d));
      if (d > 0 && check && s !== null && next > s) {
        next = Math.max(1, Math.min(cur || 1, s) || 1);
        q.value = next; refresh();
        const err = tr.querySelector('.row-err');
        err.textContent = s === 0 ? 'Kho đã hết hàng, không thể xuất' : `Không được vượt tồn kho (chỉ còn ${vn.format(s)})`;
        err.hidden = false; tr.classList.add('over');
        return;
      }
      q.value = next; refresh();
    };

    const add = (i) => {
      let tr = [...body.rows].find((r) => r.dataset.k === i.k);
      if (tr) {
        bump(tr, 1);
      } else {
        tr = body.insertRow();
        tr.dataset.k = i.k;
        tr.innerHTML = `<td><input type="hidden" name="line[]" value="${esc(i.k)}"><b>${esc(i.sku)}</b> ${esc(i.name)}${lotTag(i)}</td>`
          + '<td class="num stock-cell"></td>'
          + `<td class="num"><div class="stepper"><button type="button" class="step" data-d="-1" aria-label="Giảm 1">−</button><input name="qty[]" type="number" min="1" max="1000000" value="1" inputmode="numeric" aria-label="Số lượng"><button type="button" class="step" data-d="1" aria-label="Tăng 1">+</button></div> <small class="muted">${esc(i.unit)}</small><div class="row-err" role="alert" hidden></div></td>`
          + '<td class="num"><button type="button" class="icon-btn remove-line" aria-label="Xoá dòng">✕</button></td>';
      }
      tr.classList.remove('flash-row'); void tr.offsetWidth; tr.classList.add('flash-row');
      search.value = ''; active = -1; open(false); refresh(); search.focus();
    };

    search.addEventListener('focus', render);
    search.addEventListener('click', render);
    search.addEventListener('input', () => { active = -1; render(); });
    search.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        if (list.hidden) return render();
        active = (active + (ev.key === 'ArrowDown' ? 1 : -1) + shown.length) % Math.max(1, shown.length);
        render();
        list.querySelector('.on')?.scrollIntoView({ block: 'nearest' });
      } else if (ev.key === 'Enter') {
        ev.preventDefault(); // Enter ở ô tìm kiếm không được gửi form
        const i = shown[active >= 0 ? active : 0];
        if (!list.hidden && i) add(i);
      } else if (ev.key === 'Escape') open(false);
    });
    list.addEventListener('mousedown', (ev) => {
      const li = ev.target.closest('li[data-n]');
      if (!li) return;
      ev.preventDefault(); // giữ focus ở ô tìm kiếm
      add(shown[+li.dataset.n]);
    });
    document.addEventListener('click', (ev) => { if (!picker.querySelector('.pick-box').contains(ev.target)) open(false); });

    body.addEventListener('click', (ev) => {
      const tr = ev.target.closest('tr');
      if (!tr) return;
      const step = ev.target.closest('.step');
      if (step) {
        bump(tr, +step.dataset.d);
      } else if (ev.target.closest('.remove-line')) { tr.remove(); refresh(); }
    });
    body.addEventListener('input', refresh);
    body.addEventListener('keydown', (ev) => { // ô số lượng chỉ nhận chữ số
      if (ev.target.matches('input[name="qty[]"]') && ['-', '+', '.', ',', 'e', 'E'].includes(ev.key)) {
        ev.preventDefault();
        const err = ev.target.closest('tr').querySelector('.row-err');
        err.textContent = ev.key === '-' ? 'Không được nhập số âm' : 'Chỉ nhập số nguyên dương';
        err.hidden = false;
      }
    });
    if (whSelect) whSelect.addEventListener('change', () => { refresh(); if (!list.hidden) render(); });

    // Phiếu không có dòng nào thì không gửi (bắt ở giai đoạn capture để chạy trước khoá chống bấm đúp)
    form.addEventListener('submit', (ev) => {
      refresh();
      const bad = [...body.rows].find((r) => r.dataset.err);
      if (body.rows.length && !bad) return;
      ev.preventDefault(); ev.stopImmediatePropagation();
      if (bad) {
        const inp = bad.querySelector('input[name="qty[]"]');
        inp.focus(); inp.select();
        sum.hidden = false; sum.classList.add('bad');
        sum.textContent = `Không thể lưu: ${bad.dataset.err.toLowerCase()} (${bad.querySelector('b').textContent}).`;
        return;
      }
      empty.classList.remove('shake'); void empty.offsetWidth; empty.classList.add('shake', 'warn');
      search.focus();
    }, true);
    refresh();
  }

  // Ô chọn có tìm kiếm cho mọi <select data-combo> (lọc theo mặt hàng, chọn mặt hàng để thêm lô…)
  document.querySelectorAll('select[data-combo]').forEach((sel) => {
    const first = sel.options[0];
    const hasBlank = !!first && first.value === '';
    const opts = [...sel.options].filter((o) => o.value !== '').map((o) => ({ v: o.value, t: o.textContent.trim(), hay: fold(o.textContent) }));
    const box = document.createElement('div');
    box.className = 'combo';
    const input = document.createElement('input');
    input.type = 'text'; input.autocomplete = 'off'; input.className = 'combo-input';
    input.placeholder = hasBlank ? first.textContent.trim() : 'Chọn…';
    input.setAttribute('role', 'combobox'); input.setAttribute('aria-label', sel.getAttribute('aria-label') || input.placeholder);
    const ul = document.createElement('ul');
    ul.hidden = true; ul.className = 'combo-list'; ul.setAttribute('role', 'listbox');
    sel.parentNode.insertBefore(box, sel);
    box.append(input, ul, sel);
    sel.classList.add('combo-native'); sel.tabIndex = -1;
    const current = () => opts.find((o) => o.v === sel.value);
    input.value = current()?.t ?? '';
    let shown = [], active = -1;
    const draw = (filter) => {
      const q = filter ? input.value.trim() : '';
      const all = q ? opts.filter((o) => matches(o.hay, q)) : opts;
      shown = all.slice(0, SUGGEST);
      ul.innerHTML = (hasBlank && !q ? `<li data-n="-1" class="${sel.value === '' ? 'on' : ''}"><span class="muted">${esc(first.textContent.trim())}</span></li>` : '')
        + (shown.length ? shown.map((o, n) => `<li data-n="${n}" class="${n === active ? 'on' : ''}">${esc(o.t)}</li>`).join('') : '<li class="none">Không tìm thấy</li>')
        + (all.length > shown.length ? `<li class="more">… còn ${all.length - shown.length} kết quả — gõ thêm để thu hẹp</li>` : '');
      ul.hidden = false;
    };
    const choose = (o) => {
      sel.value = o ? o.v : '';
      input.value = o ? o.t : '';
      ul.hidden = true;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    };
    input.addEventListener('focus', () => { input.select(); draw(false); });
    input.addEventListener('click', () => draw(false));
    input.addEventListener('input', () => { active = -1; draw(true); });
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); if (!ul.hidden && shown.length) choose(shown[active >= 0 ? active : 0]); }
      else if (ev.key === 'Escape') { ul.hidden = true; input.value = current()?.t ?? ''; }
      else if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault(); active = (active + (ev.key === 'ArrowDown' ? 1 : -1) + shown.length) % Math.max(1, shown.length); draw(true);
      }
    });
    ul.addEventListener('mousedown', (ev) => {
      const li = ev.target.closest('li[data-n]');
      if (!li) return;
      ev.preventDefault();
      choose(+li.dataset.n < 0 ? null : shown[+li.dataset.n]);
    });
    input.addEventListener('blur', () => { ul.hidden = true; input.value = current()?.t ?? ''; });
  });

  // Xác nhận thao tác nhạy cảm
  document.addEventListener('click', (ev) => {
    const el = ev.target.closest('[data-confirm]');
    if (el && !confirm(el.dataset.confirm)) ev.preventDefault();
  });

  // Chống bấm đúp: khoá nút sau lần gửi đầu (máy chủ vẫn chặn gửi lặp bằng khoá idempotency)
  document.querySelectorAll('form[data-once]').forEach((form) => {
    form.addEventListener('submit', (ev) => {
      if (form.dataset.sent) return ev.preventDefault();
      form.dataset.sent = '1';
      setTimeout(() => form.querySelectorAll('button').forEach((b) => (b.disabled = true)), 0);
    });
  });

  // Hiện địa chỉ kho ngay dưới ô chọn kho
  document.querySelectorAll('select[data-addr-for]').forEach((sel) => {
    const out = document.getElementById(sel.dataset.addrFor);
    if (!out) return;
    const show = () => { const o = sel.selectedOptions[0]; out.textContent = o && o.dataset.addr ? '📍 ' + o.dataset.addr : ''; };
    sel.addEventListener('change', show);
    show();
  });

  // Tài khoản quản trị không gắn kho: tắt ô chọn kho khi chọn vai trò đó
  document.querySelectorAll('select[data-role-select]').forEach((role) => {
    const kho = role.closest('form').querySelector('select[data-kho-select]');
    if (!kho) return;
    const sync = () => { const admin = role.value === 'ADMIN'; kho.disabled = admin; kho.required = !admin; if (admin) kho.value = ''; };
    role.addEventListener('change', sync);
    sync();
  });

  // Hộp thoại (<dialog>): nút [data-dialog-open] mở form; điền sẵn kho từ data-wh / data-wh-label
  document.addEventListener('click', (ev) => {
    const open = ev.target.closest('[data-dialog-open]');
    if (open) {
      const dlg = document.getElementById(open.dataset.dialogOpen);
      if (dlg && dlg.showModal) {
        const wh = dlg.querySelector('[name=warehouse_id]');
        if (wh) wh.value = open.dataset.wh || '';
        const label = dlg.querySelector('[data-dlg-wh]');
        if (label) label.textContent = open.dataset.whLabel || '';
        dlg.querySelector('form').reset();
        dlg.querySelectorAll('.combo-input').forEach((i) => (i.value = ''));
        dlg.querySelectorAll('select[data-role-select]').forEach((s) => s.dispatchEvent(new Event('change')));
        // data-fill='{"tên_ô":"giá trị"}': điền sẵn các ô của form; data-fill-label hiện ở phần tử [data-dlg-label]
        if (open.dataset.fill) {
          try { Object.entries(JSON.parse(open.dataset.fill)).forEach(([k, v]) => { const f = dlg.querySelector(`[name="${k}"]`); if (f) f.value = v; }); } catch (e) { /* bỏ qua */ }
        }
        const fl = dlg.querySelector('[data-dlg-label]');
        if (fl) fl.textContent = open.dataset.fillLabel || '';
        // sau khi điền: đồng bộ ô kho theo vai trò; [data-fill-text="khoá"] nhận chữ từ cùng JSON (VD: nhãn nút Khoá / Mở khoá)
        dlg.querySelectorAll('select[data-role-select]').forEach((s) => s.dispatchEvent(new Event('change')));
        try { const fd = JSON.parse(open.dataset.fill || '{}'); dlg.querySelectorAll('[data-fill-text]').forEach((el) => { if (fd[el.dataset.fillText] !== undefined) el.textContent = fd[el.dataset.fillText]; }); } catch (e) { /* bỏ qua */ }
        dlg.showModal();
        dlg.querySelector('input:not([type=hidden])')?.focus();
      }
      return;
    }
    if (ev.target.closest('[data-dialog-close]')) { ev.target.closest('dialog')?.close(); return; }
    if (ev.target instanceof HTMLDialogElement) ev.target.close(); // bấm ra ngoài hộp thoại
  });

  // ── Lọc tự động: chọn mục là lọc ngay, gõ tìm kiếm thì lọc dần (không cần bấm nút «Lọc») ──
  // Chỉ thay các vùng [data-live] (bảng kết quả, thanh tab) bằng bản mới từ máy chủ — ô lọc giữ nguyên nên không mất con trỏ khi đang gõ.
  // Ô có [data-live-reload] (đổi cả bộ lọc đi kèm, VD «Kiểu xem») thì tải lại trang.
  document.querySelectorAll('form.filters[method=get]').forEach((form) => {
    if (!document.querySelector('[data-live="results"]')) return;
    let timer = 0, seq = 0;
    const urlOf = () => {
      const p = new URLSearchParams();
      new FormData(form).forEach((v, k) => { const t = String(v).trim(); if (t !== '') p.append(k, t); });
      const qs = p.toString();
      return location.pathname + (qs ? '?' + qs : '');
    };
    let last = urlOf();
    const run = async (reload) => {
      const target = urlOf();
      if (target === last) return;
      last = target;
      if (reload) { location.href = target; return; }
      const mine = ++seq;
      document.body.classList.add('is-filtering');
      try {
        const res = await fetch(target, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
        if (mine !== seq) return;                                   // đã có lần lọc mới hơn
        if (!res.ok || new URL(res.url).pathname !== location.pathname) { location.href = target; return; }
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        let swapped = 0;
        document.querySelectorAll('[data-live]').forEach((el) => {
          const fresh = doc.querySelector('[data-live="' + el.dataset.live + '"]');
          if (fresh) { el.replaceWith(fresh); swapped++; }
        });
        if (!swapped) { location.href = target; return; }
        history.replaceState(null, '', target);                      // URL phản ánh bộ lọc: tải lại / chia sẻ vẫn đúng
      } catch (e) {
        location.href = target;
      } finally {
        if (mine === seq) document.body.classList.remove('is-filtering');
      }
    };
    form.addEventListener('change', (e) => { clearTimeout(timer); run(!!e.target.closest('[data-live-reload]')); });
    form.addEventListener('input', (e) => {
      if (e.target.matches('input[type=search], input[type=text][name]')) { clearTimeout(timer); timer = setTimeout(() => run(false), 300); }
    });
    form.addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(timer); run(false); });   // Enter: lọc ngay
  });
})();
