// StockFlow WMS — tiện ích giao diện (không phụ thuộc thư viện).
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Màn chào sau đăng nhập (xe tải chạy ngang màn hình): gỡ khỏi trang khi chạy xong, hoặc khi bấm / nhấn phím để bỏ qua.
  const welcome = document.getElementById('welcome');
  if (welcome) {
    const done = () => welcome.remove();
    welcome.addEventListener('animationend', (e) => { if (e.target === welcome) done(); });
    welcome.addEventListener('click', done);
    document.addEventListener('keydown', done, { once: true });
    setTimeout(done, 4000);
  }

  // Ảnh đại diện (Cài đặt): xem trước ngay, báo lỗi sớm (loại file, dung lượng). Máy chủ vẫn kiểm tra lại toàn bộ nội dung file.
  const initAvatar = () => {
    const form = document.querySelector('.avatar-form');
    if (!form) return;
    const input = form.querySelector('[data-avatar-input]'), submit = form.querySelector('[data-avatar-submit]');
    const nameEl = form.querySelector('[data-avatar-name]'), errEl = form.querySelector('[data-avatar-error]');
    const preview = form.querySelector('[data-avatar-preview]'), original = preview.innerHTML;
    const MAX = 2 * 1024 * 1024;
    const fail = (msg) => { errEl.textContent = msg; errEl.hidden = !msg; submit.disabled = true; };
    input.addEventListener('change', () => {
      const f = input.files[0];
      errEl.hidden = true;
      if (!f) { submit.disabled = true; preview.innerHTML = original; return; }
      nameEl.textContent = f.name + ' · ' + (f.size / 1024).toFixed(0) + ' KB';
      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) return fail('Chỉ nhận ảnh JPG, PNG hoặc WebP');
      if (f.size > MAX) return fail('Ảnh quá lớn — tối đa 2 MB');
      // Đọc file thành data: URL (CSP của trang chỉ cho ảnh từ 'self' và data:, không cho blob:)
      const reader = new FileReader();
      reader.onerror = () => fail('Không đọc được file ảnh này');
      reader.onload = () => {
        const img = new Image();
        img.onload = () => {
          if (img.naturalWidth < 64 || img.naturalHeight < 64) return fail('Ảnh quá nhỏ — tối thiểu 64×64 điểm ảnh');
          preview.innerHTML = '<span class="avatar xl"><img alt=""></span>';
          preview.querySelector('img').src = reader.result;
          submit.disabled = false;
        };
        img.onerror = () => fail('Không đọc được file ảnh này');
        img.src = reader.result;
      };
      reader.readAsDataURL(f);
    });
  };

  // Trang Phân quyền: các quyền phụ thuộc nhau (data-requires = quyền xem cần có). Bật chức năng ⇒ tự bật quyền xem; tắt quyền xem ⇒ tự tắt chức năng đi kèm.
  const initRoles = () => {
    const table = document.querySelector('table.matrix');
    if (!table) return;
    const flash = (box) => { const row = box.closest('tr'); row.classList.remove('dep-flash'); void row.offsetWidth; row.classList.add('dep-flash'); };
    table.addEventListener('change', (e) => {
      const box = e.target;
      if (!(box instanceof HTMLInputElement) || box.type !== 'checkbox') return;
      const col = box.name;                                                        // cùng cột = cùng vai trò (quyen[ROLE][])
      const sameCol = [...table.querySelectorAll('input[type=checkbox]')].filter((x) => x.name === col);
      if (box.checked && box.dataset.requires) {
        const need = sameCol.find((x) => x.dataset.perm === box.dataset.requires);
        if (need && !need.checked) { need.checked = true; flash(need); }
      } else if (!box.checked && box.dataset.perm) {
        sameCol.filter((x) => x.dataset.requires === box.dataset.perm && x.checked).forEach((x) => { x.checked = false; flash(x); });
      }
    });
  };

  const initCounters = () => {
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
  };

  // Biểu đồ (Chart.js nạp cục bộ từ /assets/vendor)
  const initCharts = () => {
    if (!window.Chart) return;
    const palette = ['#d12f3f', '#2b2f3a', '#f59e0b', '#2563eb', '#16a34a', '#7a8190', '#0ea5e9'];
    Chart.defaults.font.family = '"Be Vietnam Pro", system-ui, sans-serif';
    const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    const paintChartTheme = () => { Chart.defaults.color = css('--muted'); Chart.defaults.borderColor = css('--line'); };
    paintChartTheme();
    if (!initCharts.bound) { initCharts.bound = true; document.addEventListener('themechange', () => { paintChartTheme(); Object.values(Chart.instances).forEach((c) => c.update('none')); }); }
    document.querySelectorAll('canvas[data-chart]').forEach((cv) => {
      const labels = JSON.parse(cv.dataset.labels || '[]');
      if (cv.dataset.chart === 'doughnut') {
        const fixed = JSON.parse(cv.dataset.colors || 'null');   // màu cố định theo từng phần (vd. trạng thái); không có thì dùng bảng màu chung
        new Chart(cv, { type: 'doughnut',
          data: { labels, datasets: [{ data: JSON.parse(cv.dataset.values || '[]'), backgroundColor: (c) => (fixed ? fixed[c.dataIndex] : c.dataIndex === 1 && document.documentElement.dataset.theme === 'dark' ? '#8b95b3' : palette[c.dataIndex % palette.length]), borderWidth: 2, borderColor: () => css('--surface'), hoverOffset: 6 }] },
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

  // Ô tìm kiếm có data-search-clean: chỉ nhận chữ, số, khoảng trắng và - . /; tự chuyển thành chữ thường (máy chủ cũng lọc lại)
  document.addEventListener('input', (e) => {
    const t = e.target;
    if (!(t instanceof HTMLInputElement) || !t.hasAttribute('data-search-clean')) return;
    const v = t.value.toLowerCase().replace(/[^\p{L}\p{N}\s./-]/gu, '').slice(0, 50);
    if (v !== t.value) t.value = v;
  });

  // Menu trên mobile
  const toggle = document.querySelector('[data-menu-toggle]');
  if (toggle) toggle.addEventListener('click', () => document.getElementById('sidebar').classList.toggle('open'));

  // ── Tìm kiếm: chữ thường, bỏ dấu (đ → d), mọi từ khoá đều phải khớp ──
  const fold = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const matches = (hay, q) => fold(q).split(/\s+/).filter(Boolean).every((w) => hay.includes(w));
  const vn = new Intl.NumberFormat('vi-VN');
  const SUGGEST = 100; // số gợi ý hiển thị mỗi lần (danh sách tự cuộn, bấm vào là thấy gần như đủ)

  const initPickers = () => {
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
    // Phiếu nhập: chỉ hiện mặt hàng của nhà cung cấp đã chọn
    const supSel = form.querySelector('#supplier-select');
    const supId = () => (supSel && supSel.selectedOptions[0] ? supSel.selectedOptions[0].dataset.sup || '' : '');
    const catSel = picker.querySelector('#pick-cat'), supFilter = picker.querySelector('#pick-sup');   // ô lọc danh mục / nhà cung cấp (phiếu xuất, điều chuyển…)
    const pool = () => {
      let base = supSel ? (supId() ? items.filter((i) => (i.sup || []).map(String).includes(supId())) : []) : items;
      if (catSel && catSel.value) base = base.filter((i) => String(i.cat) === catSel.value);
      if (supFilter && supFilter.value) base = base.filter((i) => (i.sup || []).map(String).includes(supFilter.value));
      return base;
    };

    const stockOf = (k) => {
      const wh = whSelect ? whSelect.value : '';
      return wh && stock[wh] ? stock[wh][k] ?? 0 : null; // null: chưa chọn kho
    };
    const lotTag = (i) => (i.lot ? ` <small class="tag tag-muted">Lô ${esc(i.lot)}${i.exp ? ' · HSD ' + esc(i.exp) : ''}</small>` : '');
    const stockText = (i) => { const s = stockOf(i.k); return s === null ? '' : `Tồn ${vn.format(s)} ${esc(i.unit)}`; };

    const open = (on) => { list.hidden = !on; search.setAttribute('aria-expanded', on ? 'true' : 'false'); };
    const render = () => {
      const q = search.value.trim();
      const base = pool();
      const all = q ? base.filter((i) => matches(i.hay, q)) : base;
      shown = all.slice(0, SUGGEST);
      if (active >= shown.length) active = shown.length - 1;
      list.innerHTML = shown.length
        ? shown.map((i, n) => `<li role="option" data-n="${n}" class="${n === active ? 'on' : ''}"><span><b>${esc(i.sku)}</b> ${esc(i.name)}${lotTag(i)}</span><small class="muted">${stockText(i)}</small></li>`).join('')
          + (all.length > shown.length ? `<li class="more">… còn ${all.length - shown.length} kết quả nữa — gõ thêm để thu hẹp</li>` : '')
        : `<li class="none">${supSel && !supId() ? 'Hãy chọn nhà cung cấp trước' : supSel && !base.length ? 'Nhà cung cấp này chưa được gắn mặt hàng nào' : 'Không tìm thấy mặt hàng phù hợp'}</li>`;
      open(true);
      fit();
    };

    // Danh sách gợi ý luôn nằm gọn trong màn hình và tự cuộn: nếu ô tìm sát đáy thì cuộn trang lên một chút, rồi giới hạn chiều cao theo chỗ còn trống
    const fit = () => {
      const box = search.getBoundingClientRect();
      if (window.innerHeight - box.bottom < 260) window.scrollBy({ top: Math.min(box.top - 90, 260 - (window.innerHeight - box.bottom)), behavior: 'auto' });
      const room = window.innerHeight - search.getBoundingClientRect().bottom - 20;
      list.style.maxHeight = Math.max(180, Math.min(340, room)) + 'px';
    };
    window.addEventListener('resize', () => { if (!list.hidden) fit(); });

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
        else if (qty > 1000000) msg = 'Tối đa 1.000.000 mỗi dòng';
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
    document.addEventListener('click', (ev) => { if (picker.isConnected && !picker.querySelector('.pick-box').contains(ev.target)) open(false); });

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
    [catSel, supFilter].forEach((sel) => { if (sel) sel.addEventListener('change', () => { render(); search.focus(); }); });
    if (supSel) supSel.addEventListener('change', () => {
      const ok = new Set(pool().map((i) => i.k));
      [...body.rows].forEach((tr) => { if (!ok.has(tr.dataset.k)) tr.remove(); });   // dòng của nhà cung cấp trước bị bỏ
      refresh(); if (!list.hidden) render();
      search.placeholder = supId() ? 'Bấm để chọn, hoặc gõ tên / SKU để tìm trong hàng của nhà cung cấp…' : 'Hãy chọn nhà cung cấp trước…';
    });
    if (supSel) search.placeholder = 'Hãy chọn nhà cung cấp trước…';

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
      shown = all; // danh sách <select> vốn hữu hạn: hiện đủ, thanh cuộn của .combo-list lo phần dài
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
    input.addEventListener('focus', () => {
      if (input.dataset.quiet) { delete input.dataset.quiet; return; }
      input.select(); draw(false);
    });
    input.addEventListener('click', () => draw(false));
    input.addEventListener('input', () => { active = -1; draw(true); });
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); if (!ul.hidden && shown.length) choose(shown[active >= 0 ? active : 0]); }
      else if (ev.key === 'Escape') { ul.hidden = true; input.value = current()?.t ?? ''; }
      else if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault(); active = (active + (ev.key === 'ArrowDown' ? 1 : -1) + shown.length) % Math.max(1, shown.length); draw(true);
      }
    });
    // Chọn ở «click» (không phải «mousedown»): danh sách nằm trong luồng form nên thu lại ngay khi chọn sẽ làm nội dung dịch lên dưới con trỏ,
    // cú click lúc nhả chuột rơi vào chỗ khác — vào <label> (chuyển thành click vào ô nhập ⇒ danh sách mở lại, nháy lặp) hoặc vào nền hộp thoại (đóng cả hộp thoại).
    ul.addEventListener('mousedown', (ev) => { if (ev.target.closest('li[data-n]')) ev.preventDefault(); });   // giữ focus ở ô nhập
    ul.addEventListener('click', (ev) => {
      const li = ev.target.closest('li[data-n]');
      if (!li) return;
      ev.preventDefault();    // chặn <label> chuyển cú click này vào ô nhập
      choose(+li.dataset.n < 0 ? null : shown[+li.dataset.n]);
    });
    input.addEventListener('blur', () => { ul.hidden = true; input.value = current()?.t ?? ''; });
  });
  };

  const initNotice = () => {
  // Thông báo sau thao tác (flash): hiện popup giữa trang; thành công tự đóng sau vài giây, lỗi chờ người dùng bấm «Đã hiểu».
  const notice = document.getElementById('notice-dlg');
  if (notice && notice.showModal) {
    notice.showModal();
    const ms = +notice.dataset.auto;
    if (ms > 0) {
      notice.style.setProperty('--nt-ms', ms + 'ms');
      setTimeout(() => { if (notice.open) notice.close(); }, ms);
    }
  }
  };

  // Xác nhận thao tác nhạy cảm: hộp thoại giữa trang có hiệu ứng (thay cho confirm() của trình duyệt).
  // Phần tử có data-confirm="câu hỏi" (tuỳ chọn data-confirm-title, data-confirm-ok); nút .btn-danger ⇒ giao diện cảnh báo đỏ.
  let confirmDlg = null;
  const askConfirm = (el) => new Promise((resolve) => {
    if (!confirmDlg) {
      confirmDlg = document.createElement('dialog');
      confirmDlg.className = 'dlg dlg-confirm';
      confirmDlg.setAttribute('aria-labelledby', 'cf-title');
      confirmDlg.innerHTML = '<div class="cf-icon" aria-hidden="true">!</div><h2 id="cf-title"></h2><p class="cf-msg"></p>'
        + '<div class="actions cf-actions"><button type="button" class="btn-ghost" data-cf="no">Huỷ bỏ</button><button type="button" data-cf="yes">Xác nhận</button></div>';
      document.body.appendChild(confirmDlg);
    }
    const danger = el.classList.contains('btn-danger');
    const dlg = confirmDlg;
    dlg.classList.toggle('is-danger', danger);
    dlg.querySelector('#cf-title').textContent = el.dataset.confirmTitle || (danger ? 'Bạn chắc chắn chứ?' : 'Xác nhận thao tác');
    dlg.querySelector('.cf-msg').textContent = el.dataset.confirm;
    const yes = dlg.querySelector('[data-cf=yes]'), no = dlg.querySelector('[data-cf=no]');
    yes.textContent = el.dataset.confirmOk || 'Xác nhận';
    yes.className = danger ? 'btn-danger-solid' : '';
    let answer = false;
    const onClick = (e) => { const b = e.target.closest('[data-cf]'); if (b) { answer = b.dataset.cf === 'yes'; dlg.close(); } else if (e.target === dlg) dlg.close(); };
    const onClose = () => { dlg.removeEventListener('click', onClick); dlg.removeEventListener('close', onClose); resolve(answer); };
    dlg.addEventListener('click', onClick);
    dlg.addEventListener('close', onClose);
    dlg.showModal();
    no.focus();   // mặc định focus «Huỷ bỏ» để Enter không vô tình xác nhận thao tác nguy hiểm
  });
  document.addEventListener('click', (ev) => {
    const el = ev.target.closest('[data-confirm]');
    if (!el) return;
    if (el.dataset.confirmed) { delete el.dataset.confirmed; return; }
    ev.preventDefault();
    askConfirm(el).then((ok) => { if (ok) { el.dataset.confirmed = '1'; el.click(); } });
  });

  const initForms = () => {
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
    const PIN = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg>';
    const show = () => { const o = sel.selectedOptions[0]; out.innerHTML = o && o.dataset.addr ? PIN + '<span>' + esc(o.dataset.addr) + '</span>' : ''; };
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
  };

  // Hộp thoại (<dialog>): nút [data-dialog-open] mở form; điền sẵn kho từ data-wh / data-wh-label
  document.addEventListener('click', (ev) => {
    const open = ev.target.closest('[data-dialog-open]');
    if (open) {
      const dlg = document.getElementById(open.dataset.dialogOpen);
      if (dlg && dlg.showModal) {
        const wh = dlg.querySelector('[name=warehouse_id]');
        if (wh && open.dataset.wh !== undefined) wh.value = open.dataset.wh;   // nút không khai báo data-wh (VD «Mở đợt kiểm kê») giữ nguyên kho cố định của tài khoản
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
        try { const ids = String(JSON.parse(open.dataset.fill || '{}').supplier_ids ?? '').split(','); dlg.querySelectorAll('[data-sup-check]').forEach((c) => { c.checked = ids.includes(c.value); }); } catch (e) { /* bỏ qua */ }
        // sau khi điền: đồng bộ ô kho theo vai trò; [data-fill-text="khoá"] nhận chữ từ cùng JSON (VD: nhãn nút Khoá / Mở khoá)
        dlg.querySelectorAll('select[data-role-select]').forEach((s) => s.dispatchEvent(new Event('change')));
        try { const fd = JSON.parse(open.dataset.fill || '{}'); dlg.querySelectorAll('[data-fill-text]').forEach((el) => { if (fd[el.dataset.fillText] !== undefined) el.textContent = fd[el.dataset.fillText]; }); } catch (e) { /* bỏ qua */ }
        dlg.showModal();
        const first = dlg.querySelector('input:not([type=hidden])');
        if (first?.closest('.combo')) first.dataset.quiet = '1'; // focus tự động không xổ danh sách
        first?.focus();
      }
      return;
    }
    if (ev.target.closest('[data-dialog-close]')) { ev.target.closest('dialog')?.close(); return; }
    if (ev.target instanceof HTMLDialogElement) ev.target.close(); // bấm ra ngoài hộp thoại
  });

  const initCounting = () => {
  // Kiểm kê: tính chênh lệch ngay khi gõ, chỉ hiện ô lý do khi có lệch
  const cf = document.querySelector('[data-count-form]');
  if (cf) {
    const rows = [...cf.querySelectorAll('tr[data-system]')];
    const refresh = () => {
      if (!cf.querySelector('.count-input')) return; // đợt đã nộp/đóng: giữ số liệu do máy chủ tính
      let net = 0, n = 0;
      rows.forEach((tr) => {
        const input = tr.querySelector('.count-input');
        if (!input) return;
        const cell = tr.querySelector('.variance');
        const sel = tr.querySelector('.reason-select'), note = tr.querySelector('.reason-note');
        const warn = tr.querySelector('.count-warn');
        const sys = +tr.dataset.system;
        const fmt = (v) => v.toLocaleString('vi-VN');
        // Cảnh báo số đếm bất thường (không chặn): gấp đôi sổ sách trở lên, chỉ còn dưới nửa, hoặc sổ sách = 0 mà đếm ra hàng
        const suspicion = (v) => {
          if (v > 1000000) return ['err', 'Tối đa 1.000.000'];
          if (sys === 0) return v > 0 ? ['warn', `Sổ sách không có hàng mà đếm được ${fmt(v)} — kiểm tra lại?`] : null;
          if (v >= sys * 2 && v - sys >= 5) return ['warn', `Gấp ${(v / sys).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} lần sổ sách (${fmt(sys)}) — gõ thừa số? Kiểm tra lại.`];
          if (v === 0 && sys >= 5) return ['warn', `Đếm được 0 trong khi sổ sách có ${fmt(sys)} — hết sạch thật không?`];
          if (v <= sys / 2 && sys - v >= 5) return ['warn', `Chỉ bằng ${Math.round(v / sys * 100)}% sổ sách (${fmt(sys)}) — gõ thiếu số? Kiểm tra lại.`];
          return null;
        };
        const showWarn = (w) => {
          warn.hidden = !w;
          warn.textContent = w ? w[1] : '';
          warn.className = 'count-warn' + (w ? ' ' + w[0] : '');
          input.classList.toggle('is-warn', !!w && w[0] === 'warn');
          input.setCustomValidity(w && w[0] === 'err' ? w[1] : '');
        };
        if (input.value === '') { showWarn(null); cell.textContent = 'khớp'; cell.className = 'num variance muted'; sel.required = false; sel.hidden = note.hidden = true; return; }
        showWarn(suspicion(+input.value));
        const d = +input.value - sys;
        cell.textContent = d === 0 ? '✓' : (d > 0 ? '+' : '') + d;
        cell.className = 'num variance ' + (d === 0 ? '' : d > 0 ? 'pos' : 'neg');
        sel.hidden = note.hidden = d === 0;
        sel.required = d !== 0;
        if (d !== 0) { net += d; n++; }
      });
      const fmt = (v) => (v > 0 ? '+' : '') + v;
      const netEl = cf.querySelector('[data-var-net]');
      cf.querySelector('[data-var-lines]').textContent = n;
      netEl.textContent = fmt(net);
      netEl.className = net > 0 ? 'pos' : net < 0 ? 'neg' : '';
    };
    // Ô thực đếm chỉ nhận chữ số: chặn phím ký tự / dấu âm / dấu chấm, và lọc cả khi dán
    cf.addEventListener('keydown', (e) => {
      if (!e.target.classList.contains('count-input') || e.ctrlKey || e.metaKey || e.altKey || e.key.length > 1) return;
      if (!/^[0-9]$/.test(e.key)) e.preventDefault();
    });
    cf.addEventListener('input', (e) => {
      if (e.target.classList.contains('count-input')) {
        const clean = e.target.value.replace(/\D+/g, '').replace(/^0+(?=\d)/, '');
        if (clean !== e.target.value) e.target.value = clean;
      }
      refresh();
    });
    refresh();
  }

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
      if (reload) { window.spaGo ? window.spaGo(target) : (location.href = target); return; }
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
  };

  // ── Điều hướng không tải lại trang ──
  // Thanh đầu và thanh chức năng đứng yên; bấm liên kết / gửi form chỉ đổi vùng nội dung bên phải (<main>).
  // Trang không thuộc khung này (đăng nhập, trang lỗi, tải CSV…) vẫn chuyển trang bình thường.
  const initPage = (swapped) => { initRoles(); initAvatar(); initCounters(); initPickers(); initNotice(); initForms(); initCounting(); if (swapped) initCharts(); window.initVnAddr && window.initVnAddr(); };
  initPage();

  const SPA_OK = document.querySelector('meta[name=spa-layout]')?.content === 'app' && window.fetch && history.pushState;
  if (SPA_OK) {
    const skipUrl = (u) => u.origin !== location.origin || /^\/(login|logout|assets)(\/|$)/.test(u.pathname) || /\.(csv|pdf|xlsx?)$/i.test(u.pathname);
    const bar = document.createElement('div');
    bar.className = 'spa-bar';
    bar.setAttribute('aria-hidden', 'true');
    document.body.appendChild(bar);
    let seq = 0, here = location.pathname + location.search;

    const swapIn = (doc, url, push, fx) => {
      if (window.Chart) Object.values(Chart.instances).forEach((c) => c.destroy());
      const cur = document.getElementById('main');
      cur.innerHTML = doc.getElementById('main').innerHTML;
      document.title = doc.title;
      // Thanh đầu / thanh menu: chỉ đụng tới phần tử khi nội dung thật sự đổi — thay lại y nguyên sẽ làm chúng nháy.
      const nav = document.querySelector('.sidebar nav'), freshNav = doc.querySelector('.sidebar nav');
      if (nav && freshNav) {
        const mine = [...nav.querySelectorAll('a')], theirs = [...freshNav.querySelectorAll('a')];
        if (mine.length === theirs.length && mine.every((a, i) => a.getAttribute('href') === theirs[i].getAttribute('href'))) {
          mine.forEach((a, i) => {                                                    // cùng danh sách mục: chỉ chuyển dấu «đang mở»
            const on = theirs[i].classList.contains('active');
            if (a.classList.contains('active') !== on) a.classList.toggle('active', on);
            if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
          });
        } else nav.innerHTML = freshNav.innerHTML;                                   // quyền đổi nên menu đổi: thay cả khối
      }
      ['.topuser .who', '.topuser .avatar'].forEach((q) => { const a = document.querySelector(q), b = doc.querySelector(q); if (a && b && a.outerHTML !== b.outerHTML) a.replaceWith(document.importNode(b, true)); });
      document.body.classList.toggle('compact', doc.body.classList.contains('compact'));
      document.getElementById('notice-dlg')?.remove();                               // thông báo kết quả thao tác nằm ngoài <main>
      const nd = doc.getElementById('notice-dlg');
      if (nd) document.body.appendChild(document.importNode(nd, true));
      if (push) history.pushState({ spa: 1 }, '', url);
      here = location.pathname + location.search;
      document.getElementById('sidebar')?.classList.remove('open');
      cur.scrollTop = 0;
      cur.classList.remove('vx-out');
      cur.style.visibility = '';
      if (fx && !reducedMotion.matches) {                     // chỉ khi bấm ở thanh menu: các khối hiện lần lượt
        [...cur.children].forEach((el, i) => el.style.setProperty('--i', Math.min(i, 8)));   // kiểu «Mượt»: hiện lần lượt, tối đa 9 nhịp
        void cur.offsetWidth;
        cur.classList.add('vx-in');
        const done = (e) => { if (!e || e.target === cur || (e.animationName === 'fx-up' && e.target === cur.lastElementChild)) { cur.classList.remove('vx-in'); cur.removeEventListener('animationend', done); } };
        cur.addEventListener('animationend', done);
        setTimeout(done, Math.max((parseFloat(getComputedStyle(cur).animationDuration) || 0) * 1000, 1000) + 600);
      }
      cur.focus({ preventScroll: true });
      initPage(true);
    };

    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');

    // Hiệu ứng «mượt»: nội dung cũ mờ đi rất nhanh. Chạy song song với việc tải trang mới.
    const playExit = () => new Promise((resolve) => {
      const m = document.getElementById('main');
      if (reducedMotion.matches) return resolve();
      m.classList.remove('vx-in');
      void m.offsetWidth;
      m.classList.add('vx-out');
      let finished = false;
      const end = () => { if (finished) return; finished = true; m.removeEventListener('animationend', onEnd); resolve(); };
      const onEnd = (e) => { if (e.target === m && /^fx-out$/.test(e.animationName)) end(); };
      m.addEventListener('animationend', onEnd);
      setTimeout(end, (parseFloat(getComputedStyle(m).animationDuration) || 0.3) * 1000 + 400);   // dự phòng nếu animationend không bắn
    });

    const load = async (url, opts = {}) => {
      const mine = ++seq, method = opts.method || 'GET';
      document.body.classList.add('spa-busy');
      const exit = opts.fx ? playExit() : Promise.resolve();                // chỉ menu bên trái mới có hiệu ứng chuyển
      if (!opts.fx) {                                                                  // bấm trong nội dung: đổi ngay, đồng thời dọn hiệu ứng còn dở (nếu có)
        const m0 = document.getElementById('main');
        m0.classList.remove('vx-out', 'vx-in'); m0.style.visibility = '';
      }
      let leaving = false, swapped = false;
      try {
        const res = await fetch(url, { method, body: opts.body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
        if (mine !== seq) return;                                                    // đã có lần bấm mới hơn
        const html = (res.headers.get('content-type') || '').includes('text/html') ? await res.text() : null;
        const doc = html === null ? null : new DOMParser().parseFromString(html, 'text/html');
        if (doc && doc.querySelector('meta[name=spa-layout]')?.content === 'app' && doc.getElementById('main')) {
          await exit;                                                                // đợi nội dung cũ «hút vào» xong rồi mới bung nội dung mới
          if (mine !== seq) return;
          swapIn(doc, res.url, opts.push !== false, !!opts.fx);
          swapped = true;
          return;
        }
        leaving = true;
        if (method === 'GET') { location.href = res.url || url; return; }            // khung khác (đăng nhập, trang lỗi…): tải trang thường
        document.open(); document.write(html ?? ''); document.close(); history.pushState(null, '', res.url);   // POST đã xử lý xong, không gửi lại lần hai
      } catch (e) {
        if (method === 'GET') { leaving = true; location.href = url; } else alert('Mất kết nối tới máy chủ. Hãy thử lại.');
      } finally {
        if (mine === seq) {
          document.body.classList.remove('spa-busy');
          if (!leaving && !swapped) { const mm = document.getElementById('main'); mm?.classList.remove('vx-out'); if (mm) mm.style.visibility = ''; }   // không đổi được trang (lỗi mạng…): trả nội dung về
        }
      }
    };
    window.spaGo = (url) => load(url);

    document.addEventListener('click', (e) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = e.target.closest('a[href]');
      if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-no-spa')) return;
      const u = new URL(a.href, location.href);
      if (skipUrl(u) || (u.pathname + u.search === here && u.hash)) return;
      e.preventDefault();
      load(u.pathname + u.search, { fx: !!a.closest('.sidebar') });
    });

    document.addEventListener('submit', (e) => {
      const f = e.target;
      if (e.defaultPrevented || !(f instanceof HTMLFormElement) || f.target || f.hasAttribute('data-no-spa')) return;
      const sub = e.submitter;
      const u = new URL(sub?.getAttribute('formaction') || f.getAttribute('action') || location.href, location.href);
      if (skipUrl(u)) return;
      e.preventDefault();
      const fd = new FormData(f, sub);
      if ((sub?.getAttribute('formmethod') || f.method || 'get').toUpperCase() === 'GET') {
        const q = new URLSearchParams();
        fd.forEach((v, k) => { if (typeof v === 'string' && v.trim() !== '') q.append(k, v); });
        load(u.pathname + (q.toString() ? '?' + q : ''));
      } else {
        load(u.pathname + u.search, { method: 'POST', body: f.enctype === 'multipart/form-data' ? fd : new URLSearchParams(fd) });   // form tải file gửi nguyên FormData
      }
    });

    addEventListener('popstate', () => { if (location.pathname + location.search !== here) load(location.pathname + location.search, { push: false }); });
  }

  // ── Thanh cuộn xe tải: thay thanh cuộn mặc định của vùng nội dung (<main>) ──
  const wrap = document.querySelector('.main-wrap'), scroller = document.getElementById('main');
  if (wrap && scroller) {
    const bar = document.createElement('div');
    bar.className = 'truck-bar';
    bar.setAttribute('aria-hidden', 'true');
    bar.innerHTML = '<span class="truck-road"></span><div class="truck-thumb"><svg viewBox="0 0 36 20" fill="none">'
      + '<rect x="1" y="2.5" width="21" height="11.5" rx="2" fill="var(--brand)"/><rect x="4" y="5" width="15" height="1.6" rx=".8" fill="#fff" opacity=".4"/><rect x="4" y="8.4" width="15" height="1.6" rx=".8" fill="#fff" opacity=".4"/>'
      + '<path d="M22.6 6h6.5l4.9 5v3H22.6z" fill="var(--brand-600, #b32433)"/><path d="M24.6 7.4h3.9l2.7 2.8h-6.6z" fill="#fff" opacity=".85"/>'
      + '<g class="truck-wh"><circle cx="8" cy="15.6" r="3.1" fill="#2b2f3a"/><path d="M8 13.4v4.4M5.8 15.6h4.4" stroke="#cbd2de" stroke-width=".9"/></g>'
      + '<g class="truck-wh"><circle cx="28.5" cy="15.6" r="3.1" fill="#2b2f3a"/><path d="M28.5 13.4v4.4M26.3 15.6h4.4" stroke="#cbd2de" stroke-width=".9"/></g></svg></div>';
    wrap.appendChild(bar);
    const thumb = bar.querySelector('.truck-thumb');
    let last = 0, movingTimer = 0, raf = 0, drag = null;
    const metrics = () => ({ max: scroller.scrollHeight - scroller.clientHeight, free: bar.clientHeight - thumb.offsetHeight });
    const place = () => {
      raf = 0;
      const { max, free } = metrics();
      const on = max > 2 && free > 0;
      bar.classList.toggle('on', on);
      if (on) thumb.style.transform = 'translateY(' + (scroller.scrollTop / max * free) + 'px)';
    };
    const queue = () => { if (!raf) raf = requestAnimationFrame(place); };
    scroller.addEventListener('scroll', () => {
      const d = scroller.scrollTop - last;
      if (d) bar.classList.toggle('up', d < 0);
      last = scroller.scrollTop;
      bar.classList.add('moving');
      clearTimeout(movingTimer);
      movingTimer = setTimeout(() => bar.classList.remove('moving'), 160);
      queue();
    }, { passive: true });
    addEventListener('resize', queue);
    new ResizeObserver(queue).observe(scroller);
    new MutationObserver(queue).observe(scroller, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'class', 'style', 'open'] });
    thumb.addEventListener('pointerdown', (e) => {
      const m = metrics();
      drag = { y: e.clientY, top: scroller.scrollTop, max: m.max, free: m.free };
      thumb.setPointerCapture(e.pointerId);
      bar.classList.add('drag', 'moving');
      e.preventDefault();
    });
    thumb.addEventListener('pointermove', (e) => { if (drag && drag.free > 0) scroller.scrollTop = drag.top + (e.clientY - drag.y) / drag.free * drag.max; });
    const stop = () => { drag = null; bar.classList.remove('drag'); };
    thumb.addEventListener('pointerup', stop);
    thumb.addEventListener('pointercancel', stop);
    bar.addEventListener('pointerdown', (e) => {                                   // bấm lên đường chạy: xe chạy tới chỗ đó
      if (e.target.closest('.truck-thumb')) return;
      const { max, free } = metrics(), r = bar.getBoundingClientRect();
      if (free > 0) scroller.scrollTo({ top: Math.min(1, Math.max(0, (e.clientY - r.top - thumb.offsetHeight / 2) / free)) * max, behavior: 'smooth' });
    });
    place();
  }
})();

// Thêm lô: chọn loại hàng hoá trước thì danh sách mặt hàng chỉ còn hàng thuộc loại đó
document.addEventListener('change', (ev) => {
  if (!ev.target || ev.target.id !== 'batch-cat') return;
  const item = document.getElementById('batch-item');
  if (!item) return;
  [...item.options].forEach((o) => { if (o.value) { const off = ev.target.value && o.dataset.cat !== ev.target.value; o.hidden = off; o.disabled = off; } });
  item.value = '';
});

// Thanh danh mục: lăn chuột = trượt ngang, cuộn tab đang chọn vào giữa, ô tìm lọc danh mục theo tên (không phân biệt hoa/thường, dấu)
(() => {
  const bar = document.getElementById('cat-tabs'), box = document.getElementById('cat-search');
  if (!bar) return;
  const fold = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLowerCase();
  const act = bar.querySelector('a.active');
  if (act) bar.scrollLeft = Math.max(0, act.offsetLeft - bar.clientWidth / 2 + act.clientWidth / 2);
  bar.addEventListener('wheel', (e) => {
    if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;   // cuộn ngang sẵn (touchpad) thì để trình duyệt tự xử lý
    const max = bar.scrollWidth - bar.clientWidth;
    if (max <= 0 || (e.deltaY < 0 && bar.scrollLeft <= 0) || (e.deltaY > 0 && bar.scrollLeft >= max - 1)) return;   // hết đầu/cuối thì cho trang cuộn dọc
    e.preventDefault();
    bar.scrollLeft += e.deltaY;
  }, { passive: false });
  if (box) box.addEventListener('input', () => {
    const q = fold(box.value.trim());
    bar.querySelectorAll('a').forEach((a) => { a.hidden = !!q && !a.classList.contains('active') && !fold(a.textContent).includes(q); });
    bar.scrollLeft = 0;
  });
})();
