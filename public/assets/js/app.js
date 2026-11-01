// StockFlow WMS — tiện ích giao diện (không phụ thuộc thư viện).
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Nhận hàng điều chuyển: gõ số thực nhận thì hiện ngay «thiếu N»; vượt số trên phiếu thì báo đỏ
  document.querySelectorAll('input[data-recv]').forEach((inp) => {
    const note = inp.parentNode.querySelector('.recv-short');
    const update = () => {
      const expected = parseInt(inp.dataset.recv, 10);
      const raw = inp.value.trim();
      const got = raw === '' ? expected : parseInt(raw, 10);
      const miss = expected - (Number.isNaN(got) ? expected : got);
      inp.classList.toggle('over', got > expected || got < 0);
      if (note) { note.textContent = miss > 0 ? 'thiếu ' + miss : (got > expected ? 'vượt phiếu' : ''); note.classList.toggle('neg', miss > 0 || got > expected); }
    };
    inp.addEventListener('input', update);
    update();
  });

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
    const palette = ['#3a6bb8', '#0d9488', '#7c3aed', '#c2610c', '#be185d', '#15803d', '#64748b'];
    const fmt = (v) => Number(v).toLocaleString('vi-VN');
    const money = (v) => (v >= 1e9 ? (v / 1e9).toLocaleString('vi-VN', { maximumFractionDigits: 2 }) + ' tỷ' : v >= 1e6 ? (v / 1e6).toLocaleString('vi-VN', { maximumFractionDigits: 1 }) + ' triệu' : fmt(v)) + ' đ';
    const axisY = { beginAtZero: true, ticks: { precision: 0, callback: (v) => fmt(v) }, grid: { color: () => css('--line') }, border: { display: false }, afterFit: (a) => { a.width = Math.max(a.width + 10, 56); } };
    const legend = { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } };
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
      } else if (cv.dataset.chart === 'trend') {
        const nhap = JSON.parse(cv.dataset.nhap || '[]'), xuat = JSON.parse(cv.dataset.xuat || '[]');
        new Chart(cv, { type: 'bar', data: { labels, datasets: [
            { type: 'bar', label: 'Nhập', data: nhap, backgroundColor: '#15803d', borderRadius: 4, maxBarThickness: 26, order: 2 },
            { type: 'bar', label: 'Xuất', data: xuat, backgroundColor: '#c2610c', borderRadius: 4, maxBarThickness: 26, order: 2 },
            { type: 'line', label: 'Chênh lệch (nhập − xuất)', data: nhap.map((v, i) => v - xuat[i]), borderColor: '#3a6bb8', backgroundColor: '#3a6bb8', borderWidth: 2, tension: 0, pointRadius: 2, order: 1 } ] },
          options: { maintainAspectRatio: false, animation: reduce ? false : { duration: 700 }, interaction: { mode: 'index', intersect: false },
            scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } }, y: { ...axisY, beginAtZero: false } },
            plugins: { legend, tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + fmt(c.parsed.y) } } } } });
      } else if (cv.dataset.chart === 'catbar') {
        new Chart(cv, { type: 'bar', data: { labels, datasets: [
            { label: 'Nhập', data: JSON.parse(cv.dataset.nhap || '[]'), backgroundColor: '#15803d', borderRadius: 4, maxBarThickness: 18 },
            { label: 'Xuất', data: JSON.parse(cv.dataset.xuat || '[]'), backgroundColor: '#c2610c', borderRadius: 4, maxBarThickness: 18 } ] },
          options: { indexAxis: 'y', maintainAspectRatio: false, animation: reduce ? false : { duration: 700 },
            scales: { x: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 6, callback: (v) => fmt(v) }, grid: { color: () => css('--line') }, border: { display: false } }, y: { grid: { display: false } } },
            plugins: { legend, tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + fmt(c.parsed.x) } } } } });
      } else if (cv.dataset.chart === 'minstock') {
        const q = JSON.parse(cv.dataset.q || '[]'), dm = JSON.parse(cv.dataset.dm || '[]'), names = JSON.parse(cv.dataset.names || '[]');
        new Chart(cv, { type: 'bar', data: { labels, datasets: [
            { type: 'bar', label: 'Tồn hiện có', data: q, backgroundColor: (c) => (q[c.dataIndex] <= dm[c.dataIndex] ? '#dc2626' : '#3a6bb8'), borderRadius: 4, maxBarThickness: 30, order: 2 },
            { type: 'line', label: 'Định mức tối thiểu', data: dm, borderColor: '#111827', borderWidth: 0, showLine: false, pointStyle: 'rectRot', pointRadius: 6, pointBackgroundColor: '#111827', pointBorderColor: '#fff', order: 1 } ] },
          options: { maintainAspectRatio: false, animation: reduce ? false : { duration: 700 }, interaction: { mode: 'index', intersect: false },
            scales: { x: { grid: { display: false }, ticks: { maxRotation: 0 } }, y: axisY },
            plugins: { legend, tooltip: { callbacks: { title: (it) => labels[it[0].dataIndex] + ' — ' + (names[it[0].dataIndex] || ''), label: (c) => c.dataset.label + ': ' + fmt(c.parsed.y) } } } } });
      } else if (cv.dataset.chart === 'moneydonut') {
        new Chart(cv, { type: 'doughnut',
          data: { labels, datasets: [{ data: JSON.parse(cv.dataset.values || '[]'), backgroundColor: (c) => palette[c.dataIndex % palette.length], borderWidth: 2, borderColor: () => css('--surface'), hoverOffset: 6 }] },
          options: { cutout: '62%', maintainAspectRatio: false, animation: reduce ? false : { duration: 700 },
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 8, font: { size: 11 } } }, tooltip: { callbacks: { label: (c) => ' ' + c.label + ': ' + money(c.parsed) } } } } });
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
            { label: 'Số mã hàng', data: JSON.parse(cv.dataset.skus || '[]'), backgroundColor: '#2b5797', borderRadius: 8, maxBarThickness: 38 },
            { label: 'Phiếu chờ xác nhận', data: JSON.parse(cv.dataset.drafts || '[]'), backgroundColor: '#2b2f3a', borderRadius: 8, maxBarThickness: 38 } ] },
          options: { maintainAspectRatio: false, animation: reduce ? false : { duration: 900 },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: () => css('--line') }, border: { display: false } } },
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } } } } });
      }
    });
  };
  if (document.readyState === 'complete') initCharts(); else addEventListener('load', initCharts);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { if (window.Chart) Object.values(Chart.instances).forEach((c) => c.resize()); });

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
  // khung ứng dụng (thanh trên + menu trái) đứng yên: nếu trình duyệt tự cuộn ngang / dọc cả khung (vd. khi bấm vào ô nằm ngoài tầm nhìn) thì trả về 0
  document.addEventListener('scroll', (e) => {
    const t = e.target === document ? document.scrollingElement : e.target;
    if (t && (t === document.scrollingElement || t === document.body || (t.matches && t.matches('.app-shell .shell, .app-shell .main-wrap'))) && (t.scrollLeft || t.scrollTop)) { t.scrollLeft = 0; t.scrollTop = 0; }
  }, true);

  // ── Tìm kiếm: chữ thường, bỏ dấu (đ → d), mọi từ khoá đều phải khớp ──
  const fold = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const matches = (hay, q) => fold(q).split(/\s+/).filter(Boolean).every((w) => hay.includes(w));
  const vn = new Intl.NumberFormat('vi-VN');
  const SUGGEST = 100; // số gợi ý hiển thị mỗi lần (danh sách tự cuộn, bấm vào là thấy gần như đủ)

  // «Các lô và hạn dùng» của một mặt hàng (bấm tên hàng ở trang Hàng hoá): hạn gần nhất lên đầu, kèm tình trạng hạn và tồn từng kho
  const LOT_TAG = {
    expired: (d) => `<span class="tag tag-danger">Hết hạn ${vn.format(Math.abs(d))} ngày</span>`,
    soon: (d) => `<span class="tag tag-warn">Còn ${vn.format(d)} ngày</span>`,
    ok: () => '<span class="tag tag-ok">Còn hạn</span>',
    none: () => '<span class="muted">Không ghi hạn</span>',
  };
  const renderLots = (dlg, data) => {
    const box = dlg.querySelector('[data-lots-body]');
    let whs = [];
    try { whs = JSON.parse(dlg.dataset.whs || '[]'); } catch (e) { /* bỏ qua */ }
    if (!data.tracked) { box.innerHTML = '<p class="empty">Mặt hàng này không quản lý theo lô nên không có hạn dùng.</p>'; return; }
    if (!data.lots.length) { box.innerHTML = '<p class="empty">Chưa có lô nào còn tồn.</p>'; return; }
    const fmt = (d) => (d ? d.split('-').reverse().join('/') : '—');
    box.innerHTML = '<table class="dense"><thead><tr><th>Lô</th><th>Hạn dùng</th><th>Bảo hành</th><th>Tình trạng</th>'
      + whs.map((w) => `<th class="num">${esc(w.ma)}</th>`).join('') + '<th class="num">Tổng</th></tr></thead><tbody>'
      + data.lots.map((l, i) => `<tr><td><b>${esc(l.lot)}</b>${i === 0 && l.state.status !== 'expired' ? ' <span class="tag tag-info">Xuất trước</span>' : ''}</td><td>${fmt(l.exp)}</td><td>${l.bh ? '<b>' + l.bh + '</b> tháng' : '<span class="muted">—</span>'}</td><td>${(LOT_TAG[l.state.status] || LOT_TAG.none)(l.state.days)}</td>`
        + whs.map((w) => `<td class="num${l.by[w.id] ? '' : ' muted'}">${vn.format(l.by[w.id] || 0)}</td>`).join('')
        + `<td class="num"><b>${vn.format(l.total)}</b> <small class="muted">${esc(data.unit)}</small></td></tr>`).join('')
      + '</tbody></table>';
  };

  // Lịch sử giá của mặt hàng trong hộp thoại «Giá bán và thuế»
  const PRICE_LABEL = { GIA_BAN: 'Giá bán', THUE_SUAT: 'Thuế suất', GIA_VON: 'Giá gốc' };
  const renderPriceHist = (dlg, rows) => {
    const box = dlg.querySelector('[data-price-hist]');
    if (!box) return;
    box.innerHTML = rows.length
      ? '<table class="dense"><thead><tr><th>Lúc</th><th>Thay đổi</th><th>Cũ → mới</th><th>Lý do</th><th>Người đổi</th></tr></thead><tbody>'
        + rows.map((h) => { const pct = h.loai === 'THUE_SUAT'; const f = (v) => (pct ? v + '%' : vn.format(v) + ' đ');
          return `<tr><td class="muted nw">${esc(h.luc)}</td><td>${esc(PRICE_LABEL[h.loai] || h.loai)}</td><td class="money">${f(h.cu)} → <b>${f(h.moi)}</b></td><td>${esc(h.ly_do)}</td><td>${esc(h.ai)}</td></tr>`; }).join('')
        + '</tbody></table>'
      : '<p class="muted small">Chưa có lần đổi giá nào.</p>';
  };

  // Hộp thoại giá: vừa gõ giá vốn / giá bán vừa thấy lãi gộp trên mỗi đơn vị
  document.addEventListener('input', (ev) => {
    if (!ev.target.matches('[data-price-calc]')) return;
    const form = ev.target.closest('form');
    const num = (el) => parseInt(String(el.value).replace(/[\s.,]/g, ''), 10) || 0;
    const cost = num(form.querySelector('[name=cost], [name=cost_show]')), price = num(form.querySelector('[name=price]'));
    const out = form.querySelector('[data-margin]');
    if (!out) return;
    if (!cost || !price) { out.textContent = ''; return; }
    const gain = price - cost;
    out.innerHTML = `Lãi gộp mỗi đơn vị: <b class="${gain >= 0 ? 'pos' : 'neg'}">${vn.format(gain)} đ</b> (${Math.round(gain / price * 100)}% giá bán)${gain < 0 ? ' — giá bán đang thấp hơn giá vốn' : ''}`;
  });

  // Đơn đặt hàng: chọn nhà cung cấp thì hiện liên hệ (người liên hệ, điện thoại, email, địa chỉ) để biết gửi đơn cho ai
  const initSupplierCard = () => {
    const sel = document.getElementById('supplier-select'), box = document.getElementById('supplier-card');
    if (!sel || !box) return;
    const draw = () => {
      const o = sel.selectedOptions[0], d = o ? o.dataset : {};
      const rows = [['Người liên hệ', d.contact], ['Điện thoại', d.phone], ['Email', d.email], ['Địa chỉ', d.addr]].filter((r) => r[1]);
      box.hidden = !o || !o.value;
      box.innerHTML = rows.length ? rows.map((r) => `<span><small>${r[0]}</small>${esc(r[1])}</span>`).join('') : '<span class="muted">Nhà cung cấp này chưa có thông tin liên hệ. Bổ sung ở «Đối tác & nhà cung cấp».</span>';
    };
    sel.addEventListener('change', draw);
    draw();
  };
  // Kho huỷ: tích nhiều lô, chọn MỘT xe cho cả đợt. Tải của các lô đã tích do máy chủ tính (cùng quy tắc với lúc xuất) rồi đánh dấu xe nào chở nổi.
  const disposeBoxes = () => [...document.querySelectorAll('input[data-pick-dispose]')];
  const refreshDispatch = async () => {
    const form = document.getElementById('dispatch-form');
    if (!form) return;
    const sum = form.querySelector('[data-dispatch-summary]'), veh = form.querySelector('select[name=vehicle_id]'), btn = form.querySelector('[data-dispatch-submit]');
    const all = document.querySelector('[data-pick-all]'), boxes = disposeBoxes(), ids = boxes.filter((b) => b.checked).map((b) => b.value);
    form.querySelector('[data-dispatch-count]').textContent = ids.length;
    btn.disabled = ids.length === 0;
    if (all) { all.checked = ids.length > 0 && ids.length === boxes.length; all.indeterminate = ids.length > 0 && ids.length < boxes.length; }
    const mine = (refreshDispatch.seq = (refreshDispatch.seq || 0) + 1), keep = veh.value;
    if (!ids.length) { sum.innerHTML = '<p class="muted small" style="margin:0">Chưa tích lô nào.</p>'; return; }
    try {
      const res = await fetch('/disposals/fit?' + ids.map((i) => 'ids[]=' + encodeURIComponent(i)).join('&'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!res.ok || mine !== refreshDispatch.seq) return;
      const j = await res.json();
      if (mine !== refreshDispatch.seq) return;
      sum.innerHTML = j.summary;
      veh.innerHTML = j.options;
      if ([...veh.options].some((o) => o.value === keep && !o.disabled)) veh.value = keep;   // giữ xe đã chọn nếu vẫn chở được
      if (window.crewSync) window.crewSync(form);
    } catch (e) { /* mất mạng: giữ nguyên danh sách xe, máy chủ vẫn kiểm lại khi xuất */ }
  };
  if (!document.body.dataset.disposeBound) {
    document.body.dataset.disposeBound = '1';
    document.addEventListener('change', (ev) => {
      const t = ev.target;
      if (t.matches && t.matches('[data-pick-all]')) { disposeBoxes().forEach((b) => { b.checked = t.checked; }); refreshDispatch(); }
      else if (t.matches && t.matches('input[data-pick-dispose]')) refreshDispatch();
    });
  }

  const initPickers = () => {
  // Ô chọn hàng của phiếu: bấm vào là gợi ý vài mặt hàng đầu danh sách, gõ để tìm, chọn xong mới sinh dòng có +/−
  const picker = document.getElementById('picker');
  if (picker) {
    const items = JSON.parse(picker.dataset.items || '[]').map((i) => ({ ...i, hay: fold([i.sku, i.name, i.lot].join(' ')) }));
    const stock = JSON.parse(picker.dataset.stock || '{}');
    const check = !!picker.dataset.check;
    const orderMap = JSON.parse(picker.dataset.order || 'null');   // nhận hàng theo đơn đặt: {mã hàng: {ord, got, left}}
    const orderMode = !!orderMap;
    const form = picker.closest('form');
    const search = picker.querySelector('#pick-search'), list = picker.querySelector('#pick-list'), body = picker.querySelector('#pick-rows');
    const wrap = picker.querySelector('#pick-wrap'), empty = picker.querySelector('#pick-empty'), sum = picker.querySelector('#pick-sum');
    const whSelect = form.querySelector('[name=warehouse_id], [name=from_wh]'); // ô chọn (quản trị) hoặc kho cố định của tài khoản
    let shown = [], active = -1;
    // Phiếu nhập: chỉ hiện mặt hàng của nhà cung cấp đã chọn
    // Phiếu xuất: chọn nhà cung cấp TRƯỚC (ô #pick-sup), rồi mới hiện danh mục của nhà đó và danh sách hàng
    const supFirst = !!picker.dataset.supFirst;
    const supSel = supFirst ? picker.querySelector('#pick-sup') : form.querySelector('#supplier-select');
    const supId = () => (supFirst ? supSel.value : (supSel && supSel.selectedOptions[0] ? supSel.selectedOptions[0].dataset.sup || '' : ''));
    const catSel = picker.querySelector('#pick-cat'), supFilter = supFirst ? null : picker.querySelector('#pick-sup');   // ô lọc danh mục / nhà cung cấp (điều chuyển…)
    const ofSupplier = (i, id) => (id === 'none' ? !(i.sup || []).length : (i.sup || []).map(String).includes(id));
    const pool = () => {
      let base = supSel ? (supId() ? items.filter((i) => ofSupplier(i, supId())) : []) : items;
      if (catSel && catSel.value) base = base.filter((i) => String(i.cat) === catSel.value);
      if (supFilter && supFilter.value) base = base.filter((i) => ofSupplier(i, supFilter.value));
      return base;
    };
    // Danh mục chỉ liệt kê loại có hàng của nhà cung cấp đang chọn; chưa chọn nhà cung cấp thì ẩn cả ô
    const syncCats = () => {
      if (!supFirst || !catSel) return;
      const label = picker.querySelector('#pick-cat-label'), has = new Set(items.filter((i) => supId() && ofSupplier(i, supId())).map((i) => String(i.cat)));
      [...catSel.options].forEach((o) => { o.hidden = o.disabled = !!o.value && !has.has(o.value); });
      if (catSel.value && !has.has(catSel.value)) catSel.value = '';
      label.hidden = !supId() || has.size < 2;   // chỉ một loại thì không cần lọc
    };

    const todayIso = () => new Date(Date.now() + 7 * 3600e3).toISOString().slice(0, 10);   // hôm nay theo giờ Việt Nam
    const autoItem = (k) => (picker.dataset.auto && /:auto$/.test(k) ? items.find((x) => x.k === k) : null);
    // Dòng «tự chia lô»: tồn = tổng các lô chưa quá hạn tại kho đang chọn; xem trước lô nào lấy bao nhiêu (hết hạn trước, lấy trước)
    const autoStock = (it, wh) => (it.lots && it.lots.length
      ? it.lots.reduce((s, l) => s + (l.iso && l.iso < todayIso() ? 0 : (stock[wh][l.k] || 0)), 0)
      : (stock[wh] ? Object.entries(stock[wh]).reduce((s, [k, v]) => s + (k.startsWith(it.k.split(':')[0] + ':') ? v : 0), 0) : 0));
    // Hàng theo lô khi xuất / điều chuyển đi: các ô lô hiện sẵn (hết hạn trước đứng đầu). Chọn bao nhiêu lô thì số lượng tự ra
    // (đầy đủ tồn của các lô đó); hoặc gõ số lượng thì hệ thống tự tích các lô theo thứ tự hết hạn trước. Mỗi lô được chọn thành một dòng phiếu.
    const lotStock = (l, wh) => (stock[wh] || {})[l.k] || 0;
    const lotExpired = (l) => !!(l.iso && l.iso < todayIso());
    const usableLots = (it, wh) => (it.lots || []).filter((l) => !lotExpired(l) && lotStock(l, wh) > 0);
    const rowItem = (tr) => items.find((x) => x.k === tr.dataset.k);
    const whVal = () => (whSelect ? whSelect.value : '');
    const writeOut = (tr) => {
      tr.querySelector('.auto-out').innerHTML = Object.entries(tr._alloc || {}).filter(([, n]) => n > 0)
        .map(([k, n]) => `<input type="hidden" name="line[]" value="${esc(k)}"><input type="hidden" name="qty[]" value="${n}">`).join('');
    };
    const drawChips = (tr) => {
      const it = rowItem(tr), wh = whVal();
      tr.querySelector('.lot-chips').innerHTML = (it.lots || []).filter((l) => !wh || lotStock(l, wh) > 0).map((l) => {
        const have = wh ? lotStock(l, wh) : 0, take = (tr._alloc || {})[l.k] || 0, off = !wh || lotExpired(l) || have <= 0;
        const stockNote = !wh ? 'chọn kho trước' : lotExpired(l) ? '<em class="bad-text">đã hết hạn</em>' : `tồn <b>${vn.format(have)}</b>${take && take < have ? ' · lấy <b>' + vn.format(take) + '</b>' : ''}`;
        return `<label class="lot-chip${take ? ' on' : ''}${off ? ' off' : ''}"><input type="checkbox" data-lot="${esc(l.k)}"${take ? ' checked' : ''}${off ? ' disabled' : ''}>`
          + `<span><b>Lô ${esc(l.lot)}</b><small>${l.exp ? 'HSD ' + esc(l.exp) + ' · ' : ''}${stockNote}</small></span></label>`;
      }).join('') || '<small class="muted">Kho này chưa có lô nào còn hàng.</small>';
    };
    // gõ / bấm +− số lượng ⇒ chia lô hết hạn trước lấy trước rồi tích các ô tương ứng
    const syncLots = (tr) => {
      const it = rowItem(tr), wh = whVal(), raw = tr.querySelector('input[data-qty]').value.trim();
      let left = /^\d+$/.test(raw) ? parseInt(raw, 10) : 0;
      tr._alloc = {};
      for (const l of usableLots(it, wh)) {
        const take = Math.min(left, lotStock(l, wh));
        if (take > 0) { tr._alloc[l.k] = take; left -= take; }
      }
      drawChips(tr); writeOut(tr);
    };
    // tích ô lô ⇒ lấy hết lô đó và mọi lô hết hạn trước nó; bỏ tích ⇒ bỏ lô đó và các lô sau; số lượng tự ra
    const pickLots = (tr, cb) => {
      const it = rowItem(tr), wh = whVal(), usable = usableLots(it, wh), idx = usable.findIndex((l) => l.k === cb.dataset.lot);
      const upto = cb.checked ? idx : idx - 1;
      tr._alloc = {};
      usable.forEach((l, n) => { if (n <= upto) tr._alloc[l.k] = lotStock(l, wh); });
      const total = Object.values(tr._alloc).reduce((a, b) => a + b, 0);
      tr.querySelector('input[data-qty]').value = total || '';
      drawChips(tr); writeOut(tr);
    };
    const stockOf = (k) => {
      const wh = whSelect ? whSelect.value : '';
      const auto = autoItem(k);
      if (auto) return wh && stock[wh] ? autoStock(auto, wh) : (wh ? 0 : null);
      return wh && stock[wh] ? stock[wh][k] ?? 0 : null; // null: chưa chọn kho
    };
    const lotTag = (i) => (i.lot ? ` <small class="tag tag-muted">Lô ${esc(i.lot)}${i.exp ? ' · HSD ' + esc(i.exp) : ''}</small>` : '');
    const stockText = (i) => {
      if (i.k.endsWith(':new')) {   // phiếu nhập hàng theo lô: cộng mọi lô của mặt hàng
        const wh = whSelect ? whSelect.value : '';
        if (!wh) return '';
        const m = stock[wh] || {}, item = i.k.split(':')[0] + ':';
        const n = Object.entries(m).reduce((a, [k, v]) => (k.startsWith(item) ? a + v : a), 0);
        return `Tồn ${vn.format(n)} ${esc(i.unit)} · ${(i.lots || []).length} lô`;
      }
      if (i.ord) return `Tồn ${vn.format(i.ord.have)} ${esc(i.unit)}${i.ord.min ? ' · tối thiểu ' + vn.format(i.ord.min) : ''}`;   // đơn đặt hàng: tồn cả kho, không theo lô
      const s = stockOf(i.k);
      return s === null ? '' : `Tồn ${vn.format(s)} ${esc(i.unit)}`;
    };

    const open = (on) => { list.hidden = !on; search.setAttribute('aria-expanded', on ? 'true' : 'false'); };
    // Phiếu nhập: chọn nhà cung cấp xong là hiện sẵn 5 mặt hàng của nhà đó (nút «Xem thêm» mở hết); ô tìm chỉ lọc trong hàng của nhà cung cấp
    const INLINE = 5;
    let expanded = false, sug = null;
    if (supSel) {
      sug = document.createElement('div');
      sug.id = 'pick-suggest';
      sug.className = 'pick-suggest';
      picker.querySelector('.pick-box').after(sug);
    }
    const renderInline = () => {
      if (!sug) return;
      const q = search.value.trim();
      if (!supId()) { shown = []; sug.hidden = true; sug.innerHTML = ''; return; }
      const base = pool(), all = q ? base.filter((i) => matches(i.hay, q)) : base;
      shown = expanded || q ? all : all.slice(0, INLINE);
      if (active >= shown.length) active = shown.length - 1;
      sug.hidden = false;
      sug.innerHTML = shown.length
        ? shown.map((i, n) => `<button type="button" class="pick-item${n === active ? ' on' : ''}" data-n="${n}"><span><b>${esc(i.sku)}</b> ${esc(i.name)}${lotTag(i)}</span><small class="muted">${stockText(i)}</small><i aria-hidden="true">+</i></button>`).join('')
          + (!q && all.length > INLINE ? `<button type="button" class="pick-more" data-more>${expanded ? 'Thu gọn' : `Xem thêm ${all.length - INLINE} mặt hàng`}</button>` : '')
        : `<p class="muted">${q ? 'Không tìm thấy mặt hàng phù hợp của nhà cung cấp này' : 'Nhà cung cấp này chưa được gắn mặt hàng nào'}</p>`;
    };
    const render = () => {
      if (supSel) { renderInline(); return; }
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

    // Phiếu nhập hàng theo lô: dòng có khoá «hàng:new» còn tồn lưu theo «hàng:lô» ⇒ tra theo lô đang chọn / đang gõ.
    // Lô đã có ⇒ tồn của lô đó; lô mới ⇒ 0; chưa ghi lô ⇒ tổng tồn mọi lô của mặt hàng.
    const rowStock = (tr) => {
      if (!tr.dataset.k.endsWith(':new')) return stockOf(tr.dataset.k);
      const wh = whSelect ? whSelect.value : '';
      if (!wh) return null;
      const m = stock[wh] || {}, item = tr.dataset.k.split(':')[0] + ':';
      const lotNo = (tr.querySelector('input[name="lot_no[]"]')?.value || '').trim().toUpperCase();
      const opt = [...(tr.querySelector('.lot-existing')?.options || [])].find((o) => o.value && o.value.toUpperCase() === lotNo);
      if (opt) return m[opt.dataset.k] ?? 0;
      if (lotNo) return 0;
      return itemTotal(tr);
    };
    const itemTotal = (tr) => {   // tổng tồn mọi lô của mặt hàng ở kho đang chọn
      const m = stock[whSelect ? whSelect.value : ''] || {}, item = tr.dataset.k.split(':')[0] + ':';
      return Object.entries(m).reduce((a, [k, v]) => (k.startsWith(item) ? a + v : a), 0);
    };
    // Dòng nhập hàng theo lô: ghi «còn N» vào từng lô trong ô chọn, cột tồn hiện số của lô đang chọn + tổng mọi lô
    const showLotStock = (tr, s, cell) => {
      const wh = whSelect ? whSelect.value : '';
      const m = stock[wh] || {}, total = itemTotal(tr), sel = tr.querySelector('.lot-existing');
      if (sel) {
        [...sel.options].forEach((o) => { o.textContent = o.value ? `${o.dataset.label} · còn ${vn.format(m[o.dataset.k] ?? 0)}` : (wh ? `Chọn lô có sẵn… (tổng ${vn.format(total)})` : 'Chọn lô có sẵn…'); });
      }
      const lotNo = (tr.querySelector('input[name="lot_no[]"]')?.value || '').trim();
      if (s === null) cell.textContent = '—';
      else if (!lotNo) cell.innerHTML = `<b>${vn.format(total)}</b><small class="stock-sub">tổng các lô</small>`;
      else cell.innerHTML = `<b>${vn.format(s)}</b><small class="stock-sub">${s === 0 && !sel?.value ? 'lô mới' : 'lô ' + esc(lotNo)}</small><small class="stock-sub">tổng các lô ${vn.format(total)}</small>`;
    };
    const refresh = () => {
      body.querySelectorAll('[data-zdis]').forEach((i) => { i.disabled = false; delete i.dataset.zdis; });   // phiếu bị chặn lại: mở khoá các dòng chưa đếm
      const trs = [...body.rows];
      let total = 0, grams = 0, money = 0;
      const gotBy = {};   // nhận hàng theo đơn: số đang đếm của từng mặt hàng (cộng các dòng lô)
      if (orderMode) trs.forEach((tr) => { const id = tr.dataset.k.split(':')[0], v = parseInt(tr.querySelector('input[data-qty]').value, 10); gotBy[id] = (gotBy[id] || 0) + (v > 0 ? v : 0); });
      trs.forEach((tr) => {
        const raw = tr.querySelector('input[data-qty]').value.trim();
        const s = rowStock(tr), cell = tr.querySelector('.stock-cell'), err = tr.querySelector('.row-err');
        const qty = /^\d+$/.test(raw) ? parseInt(raw, 10) : 0;
        total += qty;
        const itx = rowItem(tr), pinfo = tr.querySelector('.pack-info');
        if (itx) grams += weightOf(itx, qty);
        if (pinfo) pinfo.textContent = packInfo(itx, qty) + (itx && itx.ord && itx.cost > 0 && qty > 0 ? ' · ≈ ' + vn.format(itx.cost * qty) + ' đ' : '');
        if (itx && itx.ord) {   // đơn đặt hàng: tồn hiện có, định mức tối thiểu, số đang đặt chưa về
          const o = itx.ord, low = o.min > 0 && o.have <= o.min;
          cell.innerHTML = `<b${low ? ' class="neg"' : ''}>${vn.format(o.have)}</b>` + (o.min ? `<small class="stock-sub">tối thiểu ${vn.format(o.min)}${low ? ' · sắp hết' : ''}</small>` : '') + (o.open ? `<small class="stock-sub">đang đặt chưa về ${vn.format(o.open)}</small>` : '');
          money += itx.cost * qty;
        }
        else if (tr.dataset.k.endsWith(':new')) showLotStock(tr, s, cell);
        else cell.textContent = s === null ? '—' : vn.format(s);
        let msg = '';
        if (orderMode) {   // chưa đếm (0) là hợp lệ; dòng chưa đếm không bắt buộc số lô / hạn dùng
          tr.querySelectorAll('input[name="lot_no[]"], input[name="exp[]"]').forEach((i) => { i.required = qty > 0; });
          const oc = tr.querySelector('.order-cell'), id = tr.dataset.k.split(':')[0], info = orderMap[id];
          if (oc) {
            const have = gotBy[id] || 0;
            if (!info) oc.innerHTML = '<span class="tag tag-warn" title="Mặt hàng này không có trong đơn đặt hàng">Ngoài đơn</span>';
            else {
              const d = have - info.left;
              oc.innerHTML = `<small class="muted">Đặt <b>${vn.format(info.ord)}</b>${info.got ? ' · đã nhận ' + vn.format(info.got) : ''}</small><br>Còn thiếu <b>${vn.format(info.left)}</b><br>`
                + (have === 0 ? '<span class="tag">Chưa đếm</span>' : d === 0 ? '<span class="tag tag-ok">Đủ</span>' : d < 0 ? `<span class="tag tag-warn">Thiếu ${vn.format(-d)}</span>` : `<span class="tag tag-info">Thừa ${vn.format(d)}</span>`);
            }
          }
        }
        if (raw === '' && tr._alloc && !picker._submitting) msg = '';   // dòng chọn lô còn trống: nhắc nhẹ, không tô đỏ
        else if (orderMode && raw === '0') msg = '';
        else if (!/^\d+$/.test(raw) || qty < 1) msg = raw.startsWith('-') ? 'Không được nhập số âm' : 'Số lượng phải là số nguyên dương';
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
      sum.textContent = `${trs.length} dòng · tổng ${vn.format(total)} đơn vị${grams > 0 ? ' · nặng ≈ ' + kgText(grams) : ''}${money > 0 ? ' · tạm tính ≈ ' + vn.format(money) + ' đ' : ''}`;
      if (orderMode) {   // tóm tắt so với đơn
        const need = Object.values(orderMap).reduce((a, o) => a + o.left, 0), counted = Object.entries(gotBy).reduce((a, [id, v]) => a + (orderMap[id] ? Math.min(v, orderMap[id].left) : 0), 0);
        const short = Object.entries(orderMap).filter(([id, o]) => (gotBy[id] || 0) < o.left).length, over = Object.entries(orderMap).filter(([id, o]) => (gotBy[id] || 0) > o.left).length;
        sum.textContent += ` · so với đơn: đã đếm ${vn.format(counted)}/${vn.format(need)}${short ? ' · còn thiếu ' + short + ' mặt hàng' : ' · giao đủ'}${over ? ' · thừa ' + over + ' mặt hàng' : ''}`;
      }
    };

    // Cộng/trừ số lượng; tăng quá tồn thì giữ nguyên và báo ngay
    // Mặt hàng đóng kiện (thùng): +/− nhảy cả kiện, dòng mới mặc định 1 kiện — kho xuất theo thùng chứ không theo từng cái; tick «lẻ» để +/− từng cái
    const stepSize = (tr) => { const it = rowItem(tr); return it && it.pack > 1 && !tr.querySelector('.loose')?.checked ? it.pack : 1; };
    const weightOf = (it, qty) => Math.floor(qty / (it.pack || 1)) * (it.kg || 0) + (qty % (it.pack || 1)) * (it.g || 0);   // gram
    const volumeOf = (it, qty) => Math.floor(qty / (it.pack || 1)) * (it.kv || 0) + (qty % (it.pack || 1)) * (it.v || 0);   // m³
    const kgText = (g) => (g >= 1000 ? vn.format(Math.round(g / 10) / 100) + ' kg' : vn.format(Math.round(g)) + ' g');
    const packInfo = (it, qty) => {
      if (!it || qty < 1) return '';
      const pack = it.pack || 1, full = Math.floor(qty / pack), rest = qty % pack, out = [];
      if (pack > 1) out.push('= ' + (full ? vn.format(full) + ' kiện' : '') + (rest ? (full ? ' + ' : '') + vn.format(rest) + ' lẻ' : ''));
      const g = weightOf(it, qty), m3 = volumeOf(it, qty);
      if (g > 0) out.push(kgText(g));
      if (m3 > 0) out.push(vn.format(Math.round(m3 * 1000) / 1000) + ' m³');
      if (g <= 0) out.push('chưa khai cân nặng');
      return out.join(' · ');
    };
    const bump = (tr, d) => {
      const q = tr.querySelector('input[data-qty]');
      const cur = /^\d+$/.test(q.value.trim()) ? parseInt(q.value, 10) : 0;
      const s = stockOf(tr.dataset.k);
      let next = Math.max(orderMode ? 0 : 1, Math.min(1000000, cur + d));
      if (!orderMode && Math.abs(d) > 1 && next < Math.abs(d)) next = Math.abs(d);   // bước theo kiện: tối thiểu 1 kiện (đếm theo đơn thì được về 0)
      if (d > 0 && check && s !== null && next > s) {
        next = Math.max(1, Math.min(cur || 1, s) || 1);
        q.value = next; if (tr._alloc) syncLots(tr); refresh();
        const err = tr.querySelector('.row-err');
        err.textContent = s === 0 ? 'Kho đã hết hàng, không thể xuất' : `Không được vượt tồn kho (chỉ còn ${vn.format(s)})`;
        err.hidden = false; tr.classList.add('over');
        return;
      }
      q.value = next; if (tr._alloc) syncLots(tr); refresh();
    };

    const add = (i) => {
      const newLot = !!picker.dataset.newlots && i.tracked;   // phiếu nhập hàng theo lô: mỗi lần chọn là một dòng mới có ô số lô + hạn dùng
      const lotRow = !!picker.dataset.auto && !!(i.lots && i.lots.length);   // xuất / điều chuyển hàng theo lô: ô chọn lô hiện sẵn
      let tr = newLot ? null : [...body.rows].find((r) => r.dataset.k === i.k);
      if (tr) {
        bump(tr, stepSize(tr));
      } else {
        tr = body.insertRow();
        tr.dataset.k = i.k;
        const lotCells = !picker.dataset.newlots ? '' : (newLot
          ? '<div class="lot-in">'
            + ((i.lots || []).length ? '<label><small>Lô có sẵn</small><select class="lot-existing" aria-label="Chọn lô có sẵn"><option value="">Chọn lô có sẵn…</option>'
              + i.lots.map((l) => { const lb = esc(l.lot) + (l.exp ? ' · HSD ' + esc(l.exp) : ''); return `<option value="${esc(l.lot)}" data-k="${esc(l.k || '')}" data-iso="${esc(l.iso || '')}" data-label="${lb}">${lb}</option>`; }).join('') + '</select></label>' : '')
            + '<label><small>Số lô</small><input type="text" name="lot_no[]" required maxlength="40" pattern="[A-Za-z0-9._\\-]{1,40}" placeholder="VD: L2611A" autocomplete="off" aria-label="Số lô"></label>'
            + '<label><small>Hạn dùng (ghi trên hàng)</small><input type="date" name="exp[]" required aria-label="Hạn dùng"></label></div>'
          : '<input type="hidden" name="lot_no[]" value=""><input type="hidden" name="exp[]" value="">');
        const addLot = newLot ? '<div><button type="button" class="btn-ghost btn-sm add-lot">+ Thêm lô khác của hàng này</button></div>' : '';
        tr.innerHTML = `<td>${lotRow ? '<span class="auto-out"></span>' : `<input type="hidden" name="line[]" value="${esc(i.k)}">`}<b>${esc(i.sku)}</b> ${esc(i.name)}${lotTag(i)}${lotCells}${addLot}</td>`
          + '<td class="num stock-cell"></td>'
          + (orderMode ? '<td class="num order-cell"></td>' : '')
          + `<td class="num${lotRow ? ' qty-cell lot-cell' : ''}"><div class="qty-line">${lotRow ? '<div class="lot-chips"></div>' : ''}<div class="qty-box"><div class="stepper"><button type="button" class="step" data-d="-1" aria-label="Giảm 1">−</button><input${lotRow ? '' : ' name="qty[]"'} data-qty type="number" min="${orderMode ? 0 : 1}" max="1000000" value="${(i.pack || 1) > 1 ? i.pack : 1}" inputmode="numeric" aria-label="Số lượng"${lotRow ? ' placeholder="Chọn lô"' : ''}><button type="button" class="step" data-d="1" aria-label="Tăng 1">+</button></div> <small class="muted">${esc(i.unit)}</small></div></div><div class="pack-line">${(i.pack || 1) > 1 ? '<label class="loose-opt"><input type="checkbox" class="loose"> lẻ (bước 1 cái)</label>' : ''}<span class="pack-info muted"></span></div><div class="row-err" role="alert" hidden></div></td>`
          + (picker.dataset.newlots ? `<td class="bad-cell"><input name="bad[]" class="bad-qty" type="number" min="0" max="1000000" value="0" inputmode="numeric" aria-label="Số lượng không đạt chất lượng"><select name="bad_reason[]" class="bad-reason" aria-label="Tình trạng hàng không đạt" hidden><option value="">Tình trạng…</option>${(picker.dataset.badReasons ? JSON.parse(picker.dataset.badReasons) : []).map(([k, l]) => `<option value="${esc(k)}">${esc(l)}</option>`).join('')}</select><input name="bad_note[]" class="bad-note" maxlength="200" placeholder="Ghi chú" hidden></td>` : '')
          + '<td class="num"><button type="button" class="icon-btn remove-line" aria-label="Xoá dòng">✕</button></td>';
      }
      if (lotRow && !tr._alloc) { tr._alloc = {}; syncLots(tr); }
      tr.classList.remove('flash-row'); void tr.offsetWidth; tr.classList.add('flash-row');
      search.value = ''; active = -1; open(false); refresh(); renderInline(); search.focus();
    };

    search.addEventListener('focus', render);
    search.addEventListener('click', render);
    search.addEventListener('input', () => { active = -1; render(); });
    search.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        if (list.hidden && !supSel) return render();
        active = (active + (ev.key === 'ArrowDown' ? 1 : -1) + shown.length) % Math.max(1, shown.length);
        render();
        (supSel ? sug : list).querySelector('.on')?.scrollIntoView({ block: 'nearest' });
      } else if (ev.key === 'Enter') {
        ev.preventDefault(); // Enter ở ô tìm kiếm không được gửi form
        const i = shown[active >= 0 ? active : 0];
        if ((supSel || !list.hidden) && i) add(i);
      } else if (ev.key === 'Escape') open(false);
    });
    list.addEventListener('mousedown', (ev) => {
      const li = ev.target.closest('li[data-n]');
      if (!li) return;
      ev.preventDefault(); // giữ focus ở ô tìm kiếm
      add(shown[+li.dataset.n]);
    });
    if (sug) sug.addEventListener('click', (ev) => {
      if (ev.target.closest('[data-more]')) { expanded = !expanded; renderInline(); return; }
      const b = ev.target.closest('.pick-item');
      if (b) add(shown[+b.dataset.n]);
    });
    document.addEventListener('click', (ev) => { if (picker.isConnected && !picker.querySelector('.pick-box').contains(ev.target)) open(false); });

    body.addEventListener('click', (ev) => {
      const tr = ev.target.closest('tr');
      if (!tr) return;
      const step = ev.target.closest('.step');
      if (ev.target.closest('.add-lot')) {
        const it = rowItem(tr);
        if (it) add(it);
      } else if (step) {
        bump(tr, +step.dataset.d * stepSize(tr));
      } else if (ev.target.closest('.remove-line')) { tr.remove(); refresh(); }
    });
    body.addEventListener('input', (ev) => {
      const tr = ev.target.closest('tr');
      if (tr && tr._alloc && ev.target.matches('input[data-qty]')) syncLots(tr);
      if (tr && picker.dataset.newlots) {   // phiếu nhập: kiểm tra chất lượng — không đạt không vượt số nhận; có số thì phải chọn tình trạng
        const q = tr.querySelector('input[data-qty]'), b = tr.querySelector('.bad-qty');
        if (q && b) {
          const bad = +b.value || 0;
          b.setCustomValidity(bad > (+q.value || 0) ? 'Số không đạt vượt số lượng nhận' : '');
          const on = bad > 0, rs = tr.querySelector('.bad-reason'), nt = tr.querySelector('.bad-note');
          rs.hidden = nt.hidden = !on; rs.required = on;
        }
      }
      refresh();
    });
    body.addEventListener('change', (ev) => {
      const pre = ev.target.closest('.lot-existing');
      if (pre) {   // chọn lô đã có ⇒ số lô + hạn dùng tự điền, chỉ còn nhập số lượng
        const tr = pre.closest('tr'), opt = pre.selectedOptions[0];
        tr.querySelector('input[name="lot_no[]"]').value = pre.value;
        tr.querySelector('input[name="exp[]"]').value = pre.value ? (opt.dataset.iso || '') : '';
        refresh();   // cột «Tồn tại kho» đổi theo lô vừa chọn
        return;
      }
      const cb = ev.target.closest('.lot-chip input');
      if (!cb) return;
      pickLots(cb.closest('tr'), cb);
      refresh();
    });
    body.addEventListener('keydown', (ev) => { // ô số lượng chỉ nhận chữ số
      if (ev.target.matches('input[data-qty]') && ['-', '+', '.', ',', 'e', 'E'].includes(ev.key)) {
        ev.preventDefault();
        const err = ev.target.closest('tr').querySelector('.row-err');
        err.textContent = ev.key === '-' ? 'Không được nhập số âm' : 'Chỉ nhập số nguyên dương';
        err.hidden = false;
      }
    });
    if (whSelect) whSelect.addEventListener('change', () => { [...body.rows].forEach((tr) => { if (tr._alloc) syncLots(tr); }); refresh(); if (supSel || !list.hidden) render(); });
    [catSel, supFilter].forEach((sel) => { if (sel) sel.addEventListener('change', () => { render(); search.focus(); }); });
    if (supSel) supSel.addEventListener('change', () => {
      syncCats();
      const ok = new Set(pool().map((i) => i.k));
      if (!supFirst) [...body.rows].forEach((tr) => { if (!ok.has(tr.dataset.k)) tr.remove(); });   // phiếu nhập: dòng của nhà cung cấp trước bị bỏ (phiếu xuất giữ lại, có thể bán hàng nhiều nhà)
      expanded = false;
      refresh(); render();
      setPlaceholder();
    });
    const setPlaceholder = () => { search.placeholder = supId() ? 'Gõ tên / SKU để tìm trong hàng của nhà cung cấp…' : 'Hãy chọn nhà cung cấp trước…'; };
    if (supSel) { syncCats(); setPlaceholder(); renderInline(); }

    // Điền lại các dòng hàng đã chọn sau khi lưu phiếu bị từ chối
    picker.restoreLines = (keys, qtys, lotNos, exps) => {
      const grouped = new Set();
      keys.forEach((k, n) => {
        let it = items.find((x) => x.k === k);
        if (!it && picker.dataset.auto) it = items.find((x) => (x.lots || []).some((l) => l.k === k));   // lô cụ thể ⇒ dòng hàng chứa lô đó
        if (!it) return;
        if (picker.dataset.auto && it.lots && it.lots.length) {
          if (grouped.has(it.k)) return;
          grouped.add(it.k);
          add(it);
          const row = [...body.rows].find((r) => r.dataset.k === it.k);
          row._alloc = {};
          keys.forEach((kk, m) => { if (it.lots.some((l) => l.k === kk)) row._alloc[kk] = (row._alloc[kk] || 0) + (parseInt(qtys[m], 10) || 0); });
          row.querySelector('input[data-qty]').value = Object.values(row._alloc).reduce((a, b) => a + b, 0) || 1;
          drawChips(row); writeOut(row);
          return;
        }
        add(it);
        const tr = picker.dataset.newlots && it.tracked ? body.rows[body.rows.length - 1] : [...body.rows].find((r) => r.dataset.k === k);
        if (tr) tr.querySelector('input[data-qty]').value = qtys[n] ?? 1;
        const lotIn = tr && tr.querySelector('input[type=text][name="lot_no[]"]');
        if (lotIn) { lotIn.value = (lotNos && lotNos[n]) || ''; tr.querySelector('input[type=date][name="exp[]"]').value = (exps && exps[n]) || ''; }
      });
      search.value = ''; open(false); refresh();
    };

    // Nhận hàng theo đơn: quét mã (máy quét USB gõ mã + Enter, hoặc camera) ⇒ số «Thực nhận» của mặt hàng đó tăng 1; mặt hàng ngoài đơn thì báo, không tự thêm
    const countIn = picker.querySelector('#count-code'), countMsg = picker.querySelector('#count-msg');
    const countShow = (r) => { if (countMsg && r.text) { countMsg.textContent = r.text; countMsg.classList.toggle('bad', !r.ok); countMsg.classList.toggle('ok', r.ok); } };
    const countCode = (raw) => {
      const code = String(raw || '').trim(), low = code.toLowerCase();
      if (!code) return { ok: false, text: '' };
      const it = items.find((i) => (i.bc && i.bc === code) || i.sku.toLowerCase() === low);
      if (!it) return { ok: false, text: `Mã «${code}» không thuộc mặt hàng nào của nhà cung cấp này — kiểm tra lại hàng giao.` };
      const id = it.k.split(':')[0], info = orderMap && orderMap[id];
      if (!info) return { ok: false, text: `${it.sku} — ${it.name} KHÔNG nằm trong đơn đặt hàng này (không tự thêm vào phiếu). Kiểm tra lại hàng giao.` };
      let tr = [...body.rows].find((r) => r.dataset.k.split(':')[0] === id);
      if (!tr) { add(it); tr = [...body.rows].find((r) => r.dataset.k.split(':')[0] === id); tr.querySelector('input[data-qty]').value = 0; }
      const q = tr.querySelector('input[data-qty]'), step = stepSize(tr);   // mỗi lần quét = 1 kiện (hàng đóng kiện), hoặc 1 cái nếu hàng lẻ / đã tích «lẻ»
      q.value = (parseInt(q.value, 10) || 0) + step;
      refresh();
      tr.classList.remove('flash-row'); void tr.offsetWidth; tr.classList.add('flash-row');
      const have = [...body.rows].filter((r) => r.dataset.k.split(':')[0] === id).reduce((a, r) => a + (parseInt(r.querySelector('input[data-qty]').value, 10) || 0), 0);
      return { ok: true, text: `${it.sku}: +${vn.format(step)}${step > 1 ? ' (1 kiện)' : ''} → đã đếm ${vn.format(have)} / còn thiếu ${vn.format(info.left)}${have > info.left ? ' — VƯỢT ĐƠN ' + vn.format(have - info.left) : have === info.left ? ' — đủ' : ''}` };
    };
    if (orderMode) {
      window.countScan = (code) => { const r = countCode(code); countShow(r); return r; };
      if (countIn) {
        countIn.addEventListener('keydown', (ev) => { if (ev.key !== 'Enter') return; ev.preventDefault(); const r = countCode(countIn.value); countIn.value = ''; countShow(r); });
        countIn.focus();
      }
    }

    // Hàng chọn sẵn từ nút «Yêu cầu chuyển hàng» (data-prefill = [["mã hàng:lô", số lượng], …])
    try {
      const pre = JSON.parse(picker.dataset.prefill || '[]');
      if (pre.length) picker.restoreLines(pre.map((x) => x[0]), pre.map((x) => x[1]));
    } catch (e) { /* bỏ qua */ }

    // Phiếu không có dòng nào thì không gửi (bắt ở giai đoạn capture để chạy trước khoá chống bấm đúp)
    form.addEventListener('submit', (ev) => {
      picker._submitting = true;
      refresh();
      picker._submitting = false;
      const bad = [...body.rows].find((r) => r.dataset.err);
      if (orderMode && body.rows.length && !bad) {
        const counted = [...body.rows].filter((r) => (parseInt(r.querySelector('input[data-qty]').value, 10) || 0) > 0);
        if (!counted.length) {
          ev.preventDefault(); ev.stopImmediatePropagation();
          sum.hidden = false; sum.classList.add('bad');
          sum.textContent = 'Chưa đếm được mặt hàng nào. Quét mã hoặc nhập số thực nhận trước khi lưu phiếu.';
          return;
        }
        [...body.rows].forEach((r) => { if (!counted.includes(r)) r.querySelectorAll('input, select').forEach((i) => { i.disabled = true; i.dataset.zdis = '1'; }); });   // dòng chưa đếm không vào phiếu
        return;
      }
      if (body.rows.length && !bad) return;
      ev.preventDefault(); ev.stopImmediatePropagation();
      if (bad) {
        const inp = bad.querySelector('input[data-qty]');
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
    input.type = 'text'; input.autocomplete = 'off'; input.spellcheck = false; input.className = 'combo-input';
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
  document.addEventListener('click', (ev) => { if (ev.target.closest('[data-print]')) window.print(); });
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
  // Giữ phần đang nhập dở: lỡ bấm ra ngoài / Esc / ✕ chỉ ẩn hộp; mở lại đúng hộp đó (cùng nút, cùng dòng) thì còn nguyên.
  // Chỉ nút «Huỷ» hoặc gửi form mới xoá; mở cho dòng khác thì điền mới như thường.
  const draftKey = (open) => JSON.stringify(open.dataset);   // mọi data-* của nút mở (fill, lots, hist…) ⇒ khác dòng là khác khoá
  document.addEventListener('submit', (ev) => { const dlg = ev.target.closest('dialog'); if (dlg) dlg._draft = null; }, true);
  document.addEventListener('click', (ev) => {
    const open = ev.target.closest('[data-dialog-open]');
    if (open) {
      const dlg = document.getElementById(open.dataset.dialogOpen);
      if (dlg && dlg.showModal && dlg._draft && dlg._draft === draftKey(open)) { dlg.showModal(); return; }
      if (dlg && dlg.showModal) {
        dlg._draft = draftKey(open);
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
        if (open.dataset.lots) renderLots(dlg, JSON.parse(open.dataset.lots));
        if (open.dataset.hist !== undefined) renderPriceHist(dlg, JSON.parse(open.dataset.hist || '[]'));
        if (open.dataset.fillAction) dlg.querySelector('form').action = open.dataset.fillAction;   // địa chỉ gửi theo từng dòng (VD: /requests/12/reject)
        dlg.querySelectorAll('.newitem-row').forEach((x) => x.remove());   // hộp mở lại thì bỏ các dòng hàng mới nhập dở
        try { const ids = String(JSON.parse(open.dataset.fill || '{}').supplier_ids ?? '').split(','); dlg.querySelectorAll('[data-sup-check]').forEach((c) => { c.checked = ids.includes(c.value); }); const its = String(JSON.parse(open.dataset.fill || '{}').item_ids ?? '').split(','); dlg.querySelectorAll('[data-item-check]').forEach((c) => { c.checked = its.includes(c.value); }); } catch (e) { /* bỏ qua */ }
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
    const shut = ev.target.closest('[data-dialog-close]');
    if (shut) {
      const dlg = shut.closest('dialog');
      if (dlg && shut.getAttribute('aria-label') !== 'Đóng') dlg._draft = null;   // ✕ (aria-label «Đóng») chỉ ẩn; «Huỷ» thì bỏ phần nhập dở
      dlg?.close();
      return;
    }
    if (ev.target instanceof HTMLDialogElement && !ev.target.hasAttribute('data-lock')) ev.target.close(); // bấm ra ngoài hộp thoại (trừ hộp thoại khoá: phải chọn một nút)
  });

  // Xuất kho điều chuyển: chọn xe của kho hoặc thuê ngoài; tiền công gợi ý = km × đơn giá/km của kho đi, sửa được
  const initPay = () => {
    document.querySelectorAll('[data-pay]').forEach((box) => {
      if (box._payInit) return;
      box._payInit = true;
      const q = (s) => box.querySelector(s);
      const km = q('[name=km]'), money = q('[name=tien]'), hint = q('[data-pay-hint]');
      const own = q('[data-pay-own]'), ext = q('[data-pay-ext]'), fields = q('[data-pay-fields]');
      const rates = { NOI_BO: +box.dataset.rateOwn || 0, THUE_NGOAI: +box.dataset.rateHired || 0 };
      let dirty = false;
      const mode = () => (q('[name=hinh_thuc]:checked') || {}).value || 'NOI_BO';
      const payWrap = box.previousElementSibling && box.previousElementSibling.classList.contains('pay-money') ? box.previousElementSibling : null;
      if (payWrap) {   // khách đã thanh toán ⇒ chọn tiền mặt / chuyển khoản
        const sync = () => { const paid = (payWrap.querySelector('[name=thanh_toan]:checked') || {}).value === 'DA_TT', m = payWrap.querySelector('[data-paid-method]'); m.hidden = !paid; m.querySelector('select').required = paid; };
        payWrap.addEventListener('change', sync); sync();
      }
      const fmt = (n) => n.toLocaleString('vi-VN');
      const refresh = (fromMode) => {
        const m = mode(), ex = m === 'THUE_NGOAI', tl = m === 'TU_LAY';   // TU_LAY: khách tự đến lấy (phiếu xuất) — không cần xe, km, tiền
        if (own) { own.hidden = ex || tl; own.querySelectorAll('select').forEach((e) => { e.required = !ex && !tl; }); }
        if (ext) { ext.hidden = !ex; ext.querySelectorAll('input[data-req],select[data-req]').forEach((e) => { e.required = ex; }); }
        if (fields) { fields.hidden = tl; fields.querySelectorAll('[data-need]').forEach((e) => { e.required = !tl; }); }
        else { km.required = money.required = true; }
        const rate = rates[m] || 0, k = +km.value || 0, sug = k * rate;
        hint.textContent = tl ? '' : (k ? `Gợi ý: ${fmt(k)} km × ${fmt(rate)} đ/km = ${fmt(sug)} đ` : 'Nhập quãng đường để có gợi ý tiền.');
        if (!tl && (!dirty || fromMode) && k) { money.value = String(sug); dirty = false; }
      };
      km.addEventListener('input', () => { km.value = km.value.replace(/\D+/g, ''); refresh(false); });
      money.addEventListener('input', () => { dirty = true; money.value = money.value.replace(/[^\d]/g, ''); });
      box.addEventListener('change', (e) => { if (e.target.name === 'hinh_thuc') refresh(true); });
      refresh(true);
    });
  };

  // ── Rời trang khi còn thay đổi chưa lưu: hỏi «Lưu / Không lưu / Ở lại trang» ──
  // form[data-guard="submit"]: «Lưu» = gửi form (lưu phiếu nháp). form[data-guard="local"]: «Lưu» = nhớ tạm các ô đã nhập trên trình duyệt này, lần sau mở lại được điền sẵn.
  const dirtyForms = new Set();
  let leaveDlg = null, guardBound = false, sentinel = false;
  const askLeave = (form) => new Promise((resolve) => {
    if (!leaveDlg) {
      leaveDlg = document.createElement('dialog');
      leaveDlg.className = 'dlg dlg-confirm';
      leaveDlg.setAttribute('data-lock', '');   // không đóng khi bấm ra ngoài: bắt buộc chọn Lưu / Không lưu / Ở lại
      leaveDlg.setAttribute('aria-labelledby', 'lv-title');
      leaveDlg.innerHTML = '<div class="cf-icon" aria-hidden="true">!</div><h2 id="lv-title">Bạn có thay đổi chưa lưu</h2><p class="cf-msg">Rời trang này, các thông tin vừa nhập có thể bị mất.</p>'
        + '<div class="actions cf-actions"><button type="button" data-lv="save"></button><button type="button" class="btn-ghost" data-lv="discard">Không lưu</button><button type="button" class="btn-ghost" data-lv="stay">Ở lại trang</button></div>';
      document.body.appendChild(leaveDlg);
    }
    const dlg = leaveDlg;
    const isDraft = form.hasAttribute('data-guard-draft');   // phiếu nháp đã có trong hệ thống: lưu nháp để xác nhận sau, hoặc huỷ phiếu nháp
    const dirty = !!form._dirty, canSave = isDraft || (dirty && (form.dataset.guard === 'submit' || form.dataset.guard === 'local'));
    const local = document.querySelector('form[data-guard=local]');
    dlg.querySelector('#lv-title').textContent = isDraft ? 'Phiếu đang ở bản nháp' : 'Bạn có thay đổi chưa lưu';
    dlg.querySelector('.cf-msg').textContent = isDraft
      ? 'Phiếu chưa được xác nhận nên chưa ghi sổ. Bạn có muốn lưu nháp để xác nhận sau không?' + (local && local._dirty ? ' Thông tin giao hàng vừa nhập cũng được lưu tạm.' : '')
      : 'Rời trang này, các thông tin vừa nhập có thể bị mất.';
    dlg.querySelector('[data-lv=save]').textContent = isDraft ? 'Lưu nháp, xác nhận sau' : (form.dataset.guardSave || 'Lưu');
    dlg.querySelector('[data-lv=save]').hidden = !canSave;
    dlg.querySelector('[data-lv=discard]').textContent = isDraft ? 'Huỷ phiếu nháp' : 'Không lưu';
    let answer = 'stay';
    const onClick = (e) => { const b = e.target.closest('[data-lv]'); if (b) { answer = b.dataset.lv; dlg.close(); } };
    const onClose = () => { dlg.removeEventListener('click', onClick); dlg.removeEventListener('close', onClose); resolve(answer); };
    dlg.addEventListener('click', onClick);
    dlg.addEventListener('close', onClose);
    dlg.showModal();
    dlg.querySelector(canSave ? '[data-lv=save]' : '[data-lv=stay]').focus();   // mặc định «Ở lại» để Enter không vô tình rời trang
  });
  const guardKey = () => 'sf-draft:' + location.pathname;
  const guardFields = (form) => [...form.elements].filter((el) => el.name && !['csrf', 'goto', 'idem'].includes(el.name) && el.type !== 'file' && el.type !== 'submit' && el.type !== 'button');
  const guardSaveLocal = (form) => {
    const data = guardFields(form).map((el) => ({ n: el.name, t: el.type, v: el.value, c: el.checked }));
    try { localStorage.setItem(guardKey(), JSON.stringify(data)); } catch (e) { /* trình duyệt chặn lưu trữ: bỏ qua */ }
  };
  const guardRestore = (form) => {
    let data = null;
    try { data = JSON.parse(localStorage.getItem(guardKey()) || 'null'); } catch (e) { /* bỏ qua */ }
    if (!Array.isArray(data) || !data.length) return;
    const els = guardFields(form);
    data.forEach((d) => {
      const el = els.find((x) => x.name === d.n && (x.type === 'radio' ? x.value === d.v : true));
      if (!el) return;
      if (el.type === 'radio' || el.type === 'checkbox') el.checked = !!d.c; else el.value = d.v;
    });
    els.forEach((el) => el.dispatchEvent(new Event(el.type === 'radio' || el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })));
    const note = document.createElement('p');
    note.className = 'muted small guard-note';
    note.innerHTML = 'Đã điền lại thông tin bạn lưu tạm lần trước. <button type="button" class="link-btn">Xoá thông tin lưu tạm</button>';
    note.querySelector('button').addEventListener('click', () => { try { localStorage.removeItem(guardKey()); } catch (e) { /* bỏ qua */ } form.reset(); els.forEach((el) => el.dispatchEvent(new Event('change', { bubbles: true }))); note.remove(); });
    form.insertBefore(note, form.firstChild);
  };
  const guardClear = () => { dirtyForms.clear(); };
  const guardPrune = () => { dirtyForms.forEach((f) => { if (!document.contains(f)) dirtyForms.delete(f); }); if (!dirtyForms.size) sentinel = false; };
  /** Xử lý lựa chọn: true = được phép rời trang. */
  const guardDecide = async () => {
    const draft = [...dirtyForms].find((f) => document.contains(f) && f.hasAttribute('data-guard-draft'));
    const form = draft || [...dirtyForms].find((f) => document.contains(f) && f._dirty) || [...dirtyForms].find((f) => document.contains(f));
    if (!form) { guardClear(); return true; }
    const ans = await askLeave(form);
    if (ans === 'stay') return false;
    if (draft) {
      const local = document.querySelector('form[data-guard=local]');
      if (ans === 'save') { if (local && local._dirty) guardSaveLocal(local); }   // lưu nháp: phiếu đã nằm sẵn trong danh sách, chỉ nhớ thêm các ô giao hàng đã nhập
      else {                                                                        // huỷ phiếu nháp (phiếu vẫn còn trong lịch sử với trạng thái «Đã huỷ»)
        try { await fetch(draft.dataset.cancel, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ csrf: document.querySelector('input[name=csrf]')?.value || '' }) }); } catch (e) { /* bỏ qua */ }
        if (local) { try { localStorage.removeItem(guardKey()); } catch (e) { /* bỏ qua */ } }
      }
      guardClear();
      return true;
    }
    if (ans === 'save' && form._dirty) {
      if (form.dataset.guard === 'local') { guardSaveLocal(form); } else { form.requestSubmit(); return false; }   // lưu phiếu nháp: gửi form, trang tự chuyển sau khi lưu
    } else if (form.dataset.guard === 'local' && form._dirty) { try { localStorage.removeItem(guardKey()); } catch (e) { /* bỏ qua */ } }
    guardClear();
    return true;
  };
  const initGuard = () => {
    document.querySelectorAll('form[data-guard]').forEach((form) => {
      if (form._guard) return;
      form._guard = true;
      if (form.dataset.guard === 'local') guardRestore(form);
      let armed = false;
      setTimeout(() => { armed = true; }, 500);   // bỏ qua các thay đổi do chính trang sinh ra lúc mở (điền sẵn, dòng chọn sẵn…)
      const mark = () => {
        if (!armed || form._dirty) return;
        form._dirty = true;
        dirtyForms.add(form);
        if (!sentinel) { sentinel = true; history.pushState({ guard: 1 }, '', location.href); }   // để nút Quay lại của trình duyệt cũng được hỏi
      };
      form.addEventListener('input', (e) => { if (!e.target.closest('[data-guard-ignore], #pick-search, [type=search]')) mark(); });
      form.addEventListener('change', (e) => { if (!e.target.closest('[data-guard-ignore], #pick-search, [type=search]')) mark(); });
      const rows = form.querySelector('#pick-rows');
      if (rows) new MutationObserver(mark).observe(rows, { childList: true });
      form.addEventListener('submit', () => { form._dirty = false; dirtyForms.delete(form); if (form.dataset.guard === 'local') { try { localStorage.removeItem(guardKey()); } catch (e) { /* bỏ qua */ } } });
    });
    // Phiếu nháp: luôn hỏi khi rời trang (bấm nhầm Quay lại) dù chưa nhập gì
    document.querySelectorAll('[data-guard-draft]').forEach((el) => {
      if (el._guard) return;
      el._guard = true;
      dirtyForms.add(el);
      if (!sentinel) { sentinel = true; history.pushState({ guard: 1 }, '', location.href); }
    });
    if (guardBound) return;
    guardBound = true;
    window.addEventListener('beforeunload', (e) => { guardPrune(); if ([...dirtyForms].some((f) => f._dirty)) { e.preventDefault(); e.returnValue = ''; } });   // đóng tab / tải lại: hộp thoại của trình duyệt
    window.addEventListener('click', async (ev) => {
      guardPrune();
      if (!dirtyForms.size || ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
      const a = ev.target.closest('a[href]');
      if (!a || a.target === '_blank' || a.hasAttribute('download') || a.dataset.noGuard !== undefined) return;
      const u = new URL(a.href, location.href);
      if (u.protocol !== 'http:' && u.protocol !== 'https:') return;
      if (u.pathname === location.pathname && u.search === location.search && u.hash) return;
      ev.preventDefault();
      ev.stopImmediatePropagation();
      if (await guardDecide()) a.click();
    }, true);
    window.addEventListener('popstate', async (ev) => {
      guardPrune();
      if (!dirtyForms.size) return;
      ev.stopImmediatePropagation();
      history.pushState({ guard: 1 }, '', location.href);   // ở lại trang trong lúc chờ trả lời
      if (await guardDecide()) { sentinel = false; history.go(-2); }
    }, true);
  };

  const initCounting = () => {
  // Kiểm kê: tính chênh lệch ngay khi gõ, chỉ hiện ô lý do khi có lệch
  const cf = document.querySelector('[data-count-form]');
  if (cf) {
    const rows = [...cf.querySelectorAll('tr[data-system]')];
    const refresh = () => {
      if (!cf.querySelector('.count-input')) return; // đợt đã nộp/đóng: giữ số liệu do máy chủ tính
      let net = +cf.dataset.baseNet || 0, n = +cf.dataset.baseVar || 0, badSum = +cf.dataset.baseBad || 0;   // phần ở các trang khác + phần trang này
      rows.forEach((tr) => {
        const input = tr.querySelector('.count-input');
        if (!input) return;
        // Chất lượng: số không đạt nằm trong số thực đếm (để trống thực đếm = khớp sổ sách); có số thì bắt chọn tình trạng
        const bq = tr.querySelector('.bad-input'), br = tr.querySelector('.bad-reason'), bn = tr.querySelector('.bad-note'), good = tr.querySelector('.good-qty');
        const have = input.value === '' ? +tr.dataset.system : +input.value, bad = bq && bq.value !== '' ? +bq.value : 0;
        if (bq) {
          bq.setCustomValidity(bad > have ? `Không đạt (${bad}) vượt số thực đếm (${have})` : '');
          br.hidden = bn.hidden = !(bad > 0); br.required = bad > 0;
          good.textContent = Math.max(0, have - bad).toLocaleString('vi-VN');
          badSum += bad;
        }
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
      const badEl = cf.querySelector('[data-bad-total]');
      if (badEl) { badEl.textContent = badSum.toLocaleString('vi-VN'); badEl.className = badSum ? 'neg' : ''; }
    };
    // Ô thực đếm chỉ nhận chữ số: chặn phím ký tự / dấu âm / dấu chấm, và lọc cả khi dán
    cf.addEventListener('keydown', (e) => {
      if (!(e.target.classList.contains('count-input') || e.target.classList.contains('bad-input')) || e.ctrlKey || e.metaKey || e.altKey || e.key.length > 1) return;
      if (!/^[0-9]$/.test(e.key)) e.preventDefault();
    });
    cf.addEventListener('input', (e) => {
      if (e.target.classList.contains('count-input') || e.target.classList.contains('bad-input')) {
        const clean = e.target.value.replace(/\D+/g, '').replace(/^0+(?=\d)/, '');
        if (clean !== e.target.value) e.target.value = clean;
      }
      refresh();
    });
    refresh();
    // Đang nhập mà chuyển trang / tìm kiếm: lưu số đã nhập trước rồi mới đi, để không mất dữ liệu
    if (cf.dataset.save) {
      const saveThen = (url) => { cf.querySelector('[name=goto]').value = url; cf.action = cf.dataset.save; cf.requestSubmit(); };
      cf.addEventListener('click', (e) => {
        const a = e.target.closest('.pager a');
        if (!a) return;
        e.preventDefault();
        saveThen(a.getAttribute('href'));
      });
      const sf = document.querySelector('[data-count-search]');
      sf?.addEventListener('submit', (e) => {
        e.preventDefault();
        const q = sf.querySelector('[name=q]').value.trim();
        saveThen(location.pathname + (q ? '?q=' + encodeURIComponent(q) : ''));
      });
      const only = rows.length === 1 && new URLSearchParams(location.search).get('q');
      if (only) rows[0].querySelector('.count-input')?.focus();   // quét mã vạch ra đúng một dòng: nhảy thẳng vào ô thực đếm
    }
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
  // Số nhỏ cạnh mục menu (VD: phiếu chờ xác nhận): cập nhật tại chỗ, không chạm tới phần còn lại của menu
  // Hai loại số cạnh mục menu: «nav-new» (+N thay đổi mới, xanh) và «nav-badge» (cần xử lý, đỏ), cùng nằm trong khối .nav-pills
  const setNavPill = (link, cls, n) => {
    let box = link.querySelector('.nav-pills'), b = box?.querySelector('.' + cls);
    const text = !n || n < 1 ? '' : (cls === 'nav-new' ? '+' + (n > 99 ? 99 : n) : String(n));
    if (!text) { b?.remove(); if (box && !box.children.length) box.remove(); return; }
    if (!box) { box = document.createElement('span'); box.className = 'nav-pills'; link.insertBefore(box, link.querySelector('.nav-caret')); }   // số đứng trước mũi tên ▾ (nếu có)
    if (!b) { b = document.createElement('b'); b.className = cls; b.title = cls === 'nav-new' ? 'Thay đổi mới' : 'Cần xử lý'; cls === 'nav-new' ? box.prepend(b) : box.appendChild(b); }
    if (b.textContent !== text) { b.textContent = text; b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump'); }
  };
  const setNavBadge = (link, n, news) => { setNavPill(link, 'nav-badge', n); setNavPill(link, 'nav-new', news); };
  // Chuông thông báo: bấm để mở danh sách, bấm một dòng để sang đúng trang cần làm; danh sách do /api/badges làm tươi.
  // «Hoạt động mới» (quản trị: mọi kho; tài khoản kho: kho mình): đóng chuông ⇒ coi như đã xem, số trên chuông về 0.
  const notifRoot = () => document.querySelector('[data-notif]');
  let notifNew = 0;   // số hoạt động mới chưa xem
  const renderNotifs = (data) => {
    const root = notifRoot();
    if (!root || !data) return;
    const tasks = data.tasks || [], act = data.activity || { new: 0, list: [] };
    notifNew = act.new || 0;
    const total = tasks.reduce((s, x) => s + (x.count || 0), 0) + notifNew;
    const badge = root.querySelector('[data-notif-count]');
    const text = total > 99 ? '99+' : String(total);
    badge.hidden = total === 0;
    if (badge.textContent !== text) { badge.textContent = text; badge.classList.remove('bump'); void badge.offsetWidth; badge.classList.add('bump'); }
    // hai cột: trái «Hoạt động mới», phải «Việc cần làm» (việc gấp đã được xếp lên trước)
    const acts = act.list || [];
    root.querySelector('[data-notif-activity]').innerHTML = acts.map((x) => `<li><a href="${esc(x.href)}" class="notif-item${x.fresh ? ' fresh' : ''}"><i class="notif-dot" aria-hidden="true"></i><span><b>${esc(x.who)}</b><small>${esc(x.text)}</small></span><em class="when">${esc(x.at)}</em></a></li>`).join('');
    root.querySelector('[data-notif-list]').innerHTML = tasks.map((x) => `<li><a href="${esc(x.href)}" class="notif-item tone-${esc(x.tone)}"><i class="notif-dot" aria-hidden="true"></i><span><b>${esc(x.title)}</b><small>${esc(x.note)}</small></span><em>${vn.format(x.count)}</em></a></li>`).join('');
    const head = (k, n) => { const el = root.querySelector(`[data-notif-n="${k}"]`); el.hidden = !n; el.textContent = vn.format(n); };
    head('act', notifNew);
    root.querySelector('[data-notif-readall]').disabled = notifNew === 0;
    head('tasks', tasks.reduce((s, x) => s + (x.count || 0), 0));
    root.querySelector('[data-notif-empty="act"]').hidden = acts.length > 0;
    root.querySelector('[data-notif-empty="tasks"]').hidden = tasks.length > 0;
  };
  const markNotifsSeen = () => {   // dời mốc «đã xem» của hoạt động mới
    if (notifNew <= 0 || !window.fetch) return;
    const fd = new FormData();
    fd.set('csrf', document.querySelector('input[name="csrf"]')?.value || '');
    fetch('/api/notifications/seen', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).then(() => window.refreshBadges && window.refreshBadges(true)).catch(() => {});
  };
  const closeNotif = () => {
    const root = notifRoot();
    if (!root) return;
    const pop = root.querySelector('[data-notif-pop]');
    if (pop.hidden) return;
    pop.hidden = true;
    root.querySelector('[data-notif-toggle]').setAttribute('aria-expanded', 'false');
    markNotifsSeen();
  };
  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-notif-readall]')) {   // đánh dấu đã đọc ngay, vẫn mở khung
      if (notifNew <= 0) return;
      markNotifsSeen();
      notifNew = 0;
      const root = notifRoot();
      root.querySelectorAll('.notif-item.fresh').forEach((a) => a.classList.remove('fresh'));
      root.querySelector('[data-notif-readall]').disabled = true;
      const n = root.querySelector('[data-notif-n="act"]'); n.hidden = true;
      const badge = root.querySelector('[data-notif-count]');
      const left = root.querySelectorAll('[data-notif-list] .notif-item em');
      const tasks = [...left].reduce((s, e) => s + (parseInt(e.textContent.replace(/\D/g, ''), 10) || 0), 0);
      badge.hidden = tasks === 0; badge.textContent = tasks > 99 ? '99+' : String(tasks);
      return;
    }
    const toggle = ev.target.closest('[data-notif-toggle]');
    if (toggle) {
      const pop = toggle.closest('[data-notif]').querySelector('[data-notif-pop]');
      if (pop.hidden) {
        pop.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        if (window.refreshBadges) window.refreshBadges(true);
      } else {
        closeNotif();
      }
    } else if (!ev.target.closest('[data-notif-pop]') || ev.target.closest('a')) {
      closeNotif();
    }
  });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') closeNotif(); });
  // Menu có mục con: bấm mũi tên ▾ chỉ gập / mở nhánh, không chuyển trang
  document.addEventListener('click', (ev) => {
    const caret = ev.target.closest('[data-nav-caret], a[data-nav-toggle]');   // mục cha không có trang: bấm đâu cũng chỉ xổ / gập
    if (!caret) return;
    ev.preventDefault();
    ev.stopPropagation();
    caret.closest('[data-nav-tree]').classList.toggle('open');
  }, true);
  // Dòng có phần «Chi tiết» (vd. Đối chiếu tồn): bấm nút mở / thu gọn dòng ngay bên dưới
  document.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-row-toggle]');
    const detail = b && b.closest('tr')?.nextElementSibling;
    if (!detail || !detail.classList.contains('detail-row')) return;
    const open = detail.hidden;
    detail.hidden = !open;
    b.setAttribute('aria-expanded', open ? 'true' : 'false');
    b.textContent = open ? 'Thu gọn' : 'Chi tiết';
  });
  // Khung tài khoản: bấm ảnh đại diện để mở / đóng; bấm ra ngoài hoặc Esc thì đóng
  const setAcct = (open) => {
    const root = document.querySelector('[data-acct]');
    if (!root) return;
    root.querySelector('[data-acct-pop]').hidden = !open;
    root.querySelector('[data-acct-toggle]').setAttribute('aria-expanded', String(open));
  };
  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-acct-toggle]')) setAcct(document.querySelector('[data-acct-pop]').hidden);
    else if (!ev.target.closest('[data-acct-pop]') || ev.target.closest('a')) setAcct(false);
  });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') setAcct(false); });

  // Tự làm tươi số nhỏ: mỗi 30 giây và mỗi lần quay lại thẻ trình duyệt — thấy ngay khi người khác lập / xác nhận / huỷ phiếu
  if (document.querySelector('.sidebar nav a[data-nav]') && window.fetch) {
    let busy = false;
    const refreshBadges = async (force) => {
      if (busy || (document.hidden && force !== true)) return;
      busy = true;
      try {
        const res = await fetch('/api/badges', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
        if (!res.ok || !(res.headers.get('content-type') || '').includes('json')) return;
        const { badges, news, notifications } = await res.json();
        renderNotifs(notifications);
        document.querySelectorAll('.sidebar nav a[data-nav]').forEach((a) => setNavBadge(a, badges[a.dataset.nav] || 0, (news || {})[a.dataset.nav] || 0));
      } catch (e) { /* mạng chập chờn: lần sau thử lại */ } finally { busy = false; }
    };
    setInterval(refreshBadges, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshBadges(); });
    window.refreshBadges = refreshBadges;
  }

  // Bảng rộng hơn màn hình: lăn chuột dọc khi trỏ trong bảng ⇒ bảng trượt ngang; hết mép trái/phải thì trả lại cho trang cuộn dọc
  const initHScroll = () => {
    document.querySelectorAll('.table-wrap').forEach((wrap) => {
      if (wrap.dataset.hs || wrap.closest('dialog')) return;
      wrap.dataset.hs = '1';
      wrap.addEventListener('wheel', (e) => {
        if (e.ctrlKey || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;   // phóng to, hoặc touchpad đã cuộn ngang sẵn
        // bảng có cột ghim (.sticky-2: SKU, Tên hàng): trỏ trên 2 cột ghim thì lăn dọc như bình thường, trỏ sang các cột còn lại mới trượt ngang
        const cell = e.target.closest ? e.target.closest('td, th') : null;
        if (cell && cell.closest('table.sticky-2') && cell.cellIndex < 2) return;
        const max = wrap.scrollWidth - wrap.clientWidth;
        if (max <= 4 || (e.deltaY < 0 && wrap.scrollLeft <= 0) || (e.deltaY > 0 && wrap.scrollLeft >= max - 1)) return;
        e.preventDefault();
        wrap.scrollLeft += e.deltaMode === 1 ? e.deltaY * 40 : e.deltaY;   // deltaMode 1: Firefox tính theo dòng
      }, { passive: false });
    });
  };
  // Thanh danh mục dài: hai mũi tên nằm TRONG thanh, cùng hàng với các tab (trái ‹ · phải ›); mỗi lần bấm trượt khoảng 75% chiều rộng
  const initTabScroll = () => {
    document.querySelectorAll('.ts-btn').forEach((b) => { if (!b._nav || !b._nav.isConnected) b.remove(); });   // thanh đã bị thay mới (lọc, làm tươi) ⇒ bỏ nút cũ
    document.querySelectorAll('.cat-bar nav.tabs').forEach((nav) => {
      if (nav.dataset.ts || !nav.parentElement) return;
      nav.dataset.ts = '1';
      const mk = (cls, label, txt) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'ts-btn ' + cls; b.setAttribute('aria-label', label); b.title = label; b.textContent = txt; b._nav = nav; return b; };
      const left = mk('ts-left', 'Trượt thanh sang trái', '‹'), right = mk('ts-right', 'Trượt thanh sang phải', '›');
      nav.before(left);
      nav.after(right);
      const sync = () => {
        const max = nav.scrollWidth - nav.clientWidth;
        left.hidden = nav.scrollLeft <= 4 || max <= 4;
        right.hidden = nav.scrollLeft >= max - 4 || max <= 4;
      };
      const go = (dir) => nav.scrollBy({ left: dir * Math.max(160, nav.clientWidth * 0.75), behavior: 'smooth' });
      left.addEventListener('click', () => go(-1));
      right.addEventListener('click', () => go(1));
      nav.addEventListener('scroll', sync, { passive: true });
      window.addEventListener('resize', sync);
      new ResizeObserver(sync).observe(nav);
      sync();
    });
  };
  // Cuộn xuống quá đầu bảng: một bản sao hàng tên cột ghim ngay dưới thanh trên cùng (như Freeze Panes của Excel).
  // Không dùng position: sticky được vì .table-wrap cuộn ngang nên sticky chỉ bám trong khung bảng.
  const heads = new Set();
  let headRaf = 0;
  const placeHeads = () => { headRaf = 0; heads.forEach((f) => f()); };
  const queueHeads = () => { if (!headRaf) headRaf = requestAnimationFrame(placeHeads); };
  const initStickyHead = () => {
    document.querySelectorAll('.table-wrap').forEach((wrap) => {
      const table = wrap.querySelector(':scope > table');
      if (wrap.dataset.sh || wrap.closest('dialog') || !table || !table.tHead) return;
      wrap.dataset.sh = '1';
      const box = document.createElement('div');
      box.className = 'float-head';
      box.hidden = true;
      box.inert = true;
      box.setAttribute('aria-hidden', 'true');
      const clone = table.cloneNode(false);
      clone.removeAttribute('id');
      box.appendChild(clone);
      document.body.appendChild(box);
      let sized = 0;   // độ rộng bảng lần đo cột gần nhất; đổi thì đo lại
      const place = () => {
        if (!wrap.isConnected) { box.remove(); heads.delete(place); return; }
        const bar = document.querySelector('.topbar');
        const top = Math.max(bar ? bar.getBoundingClientRect().bottom : 0, 0);
        const r = wrap.getBoundingClientRect(), hh = table.tHead.offsetHeight;
        const show = wrap.offsetParent !== null && r.top < top && r.bottom - hh * 2 > top;
        box.hidden = !show;
        if (!show) return;
        if (sized !== table.offsetWidth || !clone.tHead) {   // chép lại hàng tiêu đề + độ rộng từng cột
          sized = table.offsetWidth;
          clone.replaceChildren(table.tHead.cloneNode(true));
          clone.style.width = sized + 'px';
          const src = table.tHead.querySelectorAll('th, td'), dst = clone.tHead.querySelectorAll('th, td');
          src.forEach((c, i) => { const w = c.getBoundingClientRect().width + 'px'; Object.assign(dst[i].style, { width: w, minWidth: w, maxWidth: w }); });
        }
        Object.assign(box.style, { top: top + 'px', left: r.left + 'px', width: wrap.clientWidth + 'px' });
        box.scrollLeft = wrap.scrollLeft;
      };
      heads.add(place);
      wrap.addEventListener('scroll', queueHeads, { passive: true });
      new ResizeObserver(() => { sized = 0; queueHeads(); }).observe(table);
      queueHeads();
    });
  };
  document.addEventListener('scroll', queueHeads, { capture: true, passive: true });   // capture: bắt cả khi <main> là khung cuộn
  window.addEventListener('resize', queueHeads);

  new MutationObserver(() => { initHScroll(); initTabScroll(); initStickyHead(); queueHeads(); }).observe(document.body, { childList: true, subtree: true });

  const initPage = (swapped) => { initHScroll(); initTabScroll(); initStickyHead(); initRoles(); initAvatar(); initCounters(); initPickers(); initSupplierCard(); refreshDispatch(); window.paintCols && window.paintCols(); initNotice(); initForms(); initCounting(); initPay(); initGuard(); if (swapped) initCharts(); window.initVnAddr && window.initVnAddr(); window.initRouteMap && window.initRouteMap(); window.initConvoy && window.initConvoy(); window.initCrew && window.initCrew(); window.initDeliveryKm && window.initDeliveryKm(); window.initCreturn && window.initCreturn(); };
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
          mine.forEach((a, i) => {                                                    // cùng danh sách mục: chuyển dấu «đang mở» và đồng bộ số nhỏ
            setNavBadge(a, parseInt(theirs[i].querySelector('.nav-badge')?.textContent || '0', 10), parseInt((theirs[i].querySelector('.nav-new')?.textContent || '0').replace('+', ''), 10));
            const on = theirs[i].classList.contains('active');
            if (a.classList.contains('active') !== on) a.classList.toggle('active', on);
            if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
          });
          const trees = nav.querySelectorAll('[data-nav-tree]'), fresh = freshNav.querySelectorAll('[data-nav-tree]');
          trees.forEach((t, i) => { if (fresh[i]?.classList.contains('open')) t.classList.add('open'); });   // sang trang con / trang cha ⇒ mở nhánh (không tự gập)
        } else nav.innerHTML = freshNav.innerHTML;                                   // quyền đổi nên menu đổi: thay cả khối
      }
      ['.topuser [data-acct]'].forEach((q) => { const a = document.querySelector(q), b = doc.querySelector(q); if (a && b && a.outerHTML !== b.outerHTML) a.replaceWith(document.importNode(b, true)); });
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
      load(u.pathname + u.search, { fx: false });   // bỏ hiệu ứng trôi / mờ khi bấm menu: đổi trang tức thì (muốn bật lại: fx: !!a.closest('.sidebar'))
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
    bar.innerHTML = '<span class="truck-road"></span><span class="truck-fill"></span>'
      + '<button type="button" class="truck-flag" tabindex="-1" title="Xuống cuối trang"><svg viewBox="0 0 12 16"><path d="M2 15V1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M2.6 2h8L8.6 5l2 3h-8z" fill="currentColor"/></svg></button>'
      + '<div class="truck-thumb" title="Bấm để về đầu trang"><svg viewBox="0 0 36 20" fill="none">'
      + '<rect x="1" y="2.5" width="21" height="11.5" rx="2" fill="var(--brand)"/><rect x="4" y="5" width="15" height="1.6" rx=".8" fill="#fff" opacity=".4"/><rect x="4" y="8.4" width="15" height="1.6" rx=".8" fill="#fff" opacity=".4"/>'
      + '<path d="M22.6 6h6.5l4.9 5v3H22.6z" fill="var(--brand-600, #b32433)"/><path d="M24.6 7.4h3.9l2.7 2.8h-6.6z" fill="#fff" opacity=".85"/>'
      + '<g class="truck-wh"><circle cx="8" cy="15.6" r="3.1" fill="#2b2f3a"/><path d="M8 13.4v4.4M5.8 15.6h4.4" stroke="#cbd2de" stroke-width=".9"/></g>'
      + '<g class="truck-wh"><circle cx="28.5" cy="15.6" r="3.1" fill="#2b2f3a"/><path d="M28.5 13.4v4.4M26.3 15.6h4.4" stroke="#cbd2de" stroke-width=".9"/></g></svg></div>';
    wrap.appendChild(bar);
    const thumb = bar.querySelector('.truck-thumb'), fill = bar.querySelector('.truck-fill'), flag = bar.querySelector('.truck-flag');
    let last = 0, movingTimer = 0, awakeTimer = 0, raf = 0, drag = null;
    const metrics = () => ({ max: scroller.scrollHeight - scroller.clientHeight, free: bar.clientHeight - flag.offsetHeight - thumb.offsetHeight });
    const wake = () => { bar.classList.add('awake'); clearTimeout(awakeTimer); awakeTimer = setTimeout(() => bar.classList.remove('awake'), 1400); };   // thanh mờ đi khi không dùng
    const place = () => {
      raf = 0;
      const { max, free } = metrics();
      const on = max > 2 && free > 0;
      bar.classList.toggle('on', on);
      if (!on) return;
      const y = scroller.scrollTop / max * free;
      thumb.style.transform = 'translateY(' + y + 'px)';
      fill.style.height = (y + thumb.offsetHeight / 2) + 'px';
      bar.classList.toggle('end', scroller.scrollTop >= max - 2);
    };
    const queue = () => { if (!raf) raf = requestAnimationFrame(place); };
    scroller.addEventListener('scroll', () => {
      const d = scroller.scrollTop - last;
      if (d) bar.classList.toggle('up', d < 0);
      last = scroller.scrollTop;
      bar.classList.add('moving');
      clearTimeout(movingTimer);
      movingTimer = setTimeout(() => bar.classList.remove('moving'), 160);
      wake();
      queue();
    }, { passive: true });
    addEventListener('resize', queue);
    new ResizeObserver(queue).observe(scroller);
    new MutationObserver(queue).observe(scroller, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'class', 'style', 'open'] });
    thumb.addEventListener('pointerdown', (e) => {
      const m = metrics();
      drag = { y: e.clientY, top: scroller.scrollTop, max: m.max, free: m.free, moved: false };
      thumb.setPointerCapture(e.pointerId);
      bar.classList.add('drag', 'moving');
      e.preventDefault();
    });
    thumb.addEventListener('pointermove', (e) => {
      if (!drag || drag.free <= 0) return;
      if (Math.abs(e.clientY - drag.y) > 4) drag.moved = true;
      scroller.scrollTop = drag.top + (e.clientY - drag.y) / drag.free * drag.max;
    });
    const stop = () => { drag = null; bar.classList.remove('drag'); };
    thumb.addEventListener('pointerup', () => {                                    // bấm xe (không kéo): về đầu trang
      if (drag && !drag.moved) scroller.scrollTo({ top: 0, behavior: 'smooth' });
      stop();
    });
    thumb.addEventListener('pointercancel', stop);
    flag.addEventListener('click', () => scroller.scrollTo({ top: scroller.scrollHeight, behavior: 'smooth' }));
    bar.addEventListener('pointerdown', (e) => {                                   // bấm lên đường chạy: xe chạy tới chỗ đó
      if (e.target.closest('.truck-thumb, .truck-flag')) return;
      const { max, free } = metrics(), r = bar.getBoundingClientRect();
      if (free > 0) scroller.scrollTo({ top: Math.min(1, Math.max(0, (e.clientY - r.top - thumb.offsetHeight / 2) / free)) * max, behavior: 'smooth' });
    });
    place();
  }
})();

// Menu trái: kéo mép phải để nới rộng trong khoảng giới hạn (đọc rõ tên mục dài); bấm đúp để về mặc định; nhớ độ rộng
// Độ rộng menu trái cố định (không còn kéo mép để chỉnh); xoá độ rộng cũ đã lưu ở trình duyệt.
try { localStorage.removeItem('sidebar-w'); } catch (e) {}

// Bảng Hàng hoá: «Hiện thêm cột» bật / tắt các cột ít dùng (nhớ lựa chọn). Lớp đặt ở <html> để vẫn giữ khi bảng được tải lại theo bộ lọc.
(() => {
  const KEY = 'inv-cols-all';
  try { if (localStorage.getItem(KEY) === '1') document.documentElement.classList.add('cols-all'); } catch (e) {}
  const paint = () => document.querySelectorAll('[data-toggle-cols]').forEach((b) => {
    const on = document.documentElement.classList.contains('cols-all');
    b.textContent = on ? 'Ẩn bớt cột' : 'Hiện thêm cột';
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest || !e.target.closest('[data-toggle-cols]')) return;
    const on = document.documentElement.classList.toggle('cols-all');
    try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (err) {}
    paint();
  });
  window.paintCols = paint;
  paint();
  document.addEventListener('DOMContentLoaded', paint);
  window.addEventListener('load', paint);
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

// Thanh danh mục: nút «Khác» xổ danh sách có ô tìm. Bắt sự kiện ở document vì vùng thanh được thay mới khi lọc.
// Phím: «/» mở + gõ tìm · ↑↓ chọn · Enter mở danh mục đầu tiên khớp · Esc đóng.
(() => {
  const fold = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLowerCase();
  const links = (m) => [...m.querySelectorAll('.cat-more-pop li:not([hidden]) a')];
  const filter = (m, q) => {
    const f = fold(q.trim());
    m.querySelectorAll('.cat-more-pop li').forEach((li) => { li.hidden = !!f && !fold(li.textContent).includes(f); });
    m.querySelector('.cat-more-empty').hidden = links(m).length > 0;
  };
  const setOpen = (m, on) => {
    m.classList.toggle('open', on);
    m.querySelector('.cat-more-pop').hidden = !on;
    m.querySelector('.cat-more-btn').setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) { const q = m.querySelector('.cat-more-q'); q.value = ''; filter(m, ''); q.focus(); }
  };
  const closeAll = (except) => document.querySelectorAll('.cat-more.open').forEach((m) => { if (m !== except) setOpen(m, false); });
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.cat-more-btn');
    if (btn) { const m = btn.closest('.cat-more'); setOpen(m, !m.classList.contains('open')); return; }
    if (!e.target.closest('.cat-more')) closeAll();
  });
  document.addEventListener('input', (e) => { if (e.target.matches('.cat-more-q')) filter(e.target.closest('.cat-more'), e.target.value); });
  document.addEventListener('keydown', (e) => {
    const m = e.target.closest && e.target.closest('.cat-more');
    if (m) {
      const list = links(m), i = list.indexOf(document.activeElement);
      if (e.key === 'Escape') { setOpen(m, false); m.querySelector('.cat-more-btn').focus(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); (list[i + 1] || list[0] || m.querySelector('.cat-more-q')).focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); (i > 0 ? list[i - 1] : m.querySelector('.cat-more-q')).focus(); }
      else if (e.key === 'Enter' && e.target.matches('.cat-more-q') && list[0]) { e.preventDefault(); list[0].click(); }
      return;
    }
    if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) && !e.target.isContentEditable) {
      const more = document.querySelector('.cat-more');
      if (more) { e.preventDefault(); setOpen(more, true); }
    }
  });
})();

// Form nhận bảo hành: nhập đủ «mã lô hàng» + «mã món hàng» (gõ tay hoặc máy quét USB) ⇒ tra ra mặt hàng, ngày mua, hạn bảo hành, hoá đơn.
// Tra được thì mặt hàng / mã phiếu mua không còn bắt buộc (hệ thống lấy theo mã).
(() => {
  let timer = 0, seq = 0;
  const fmt = (d) => (d || '').split('-').reverse().join('/');
  const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const run = async (form) => {
    const lot = form.querySelector('[data-sale-lot]'), item = form.querySelector('[data-sale-item]'), info = form.querySelector('[data-sale-info]');
    if (!lot || !item || !info) return;
    const needs = form.querySelectorAll('[data-sale-need]');
    const setNeed = (on) => needs.forEach((n) => { if (n.dataset.wasRequired === undefined) n.dataset.wasRequired = n.required ? '1' : ''; n.required = on && n.dataset.wasRequired === '1'; });
    const l = lot.value.trim(), i = item.value.trim();
    if (!l && !i) { info.className = 'sale-lookup muted small'; info.textContent = info.dataset.hint || info.textContent; setNeed(true); return; }
    if (info.dataset.hint === undefined) info.dataset.hint = info.textContent;
    const mine = ++seq;
    info.className = 'sale-lookup muted small'; info.textContent = 'Đang tra mã…';
    try {
      const res = await fetch('/warranty/lookup?lot=' + encodeURIComponent(l) + '&item=' + encodeURIComponent(i), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      const d = await res.json();
      if (mine !== seq) return;
      if (!d.found) { setNeed(true); info.className = 'sale-lookup neg small'; info.textContent = i ? 'Không tìm thấy chiếc nào khớp mã này — kiểm tra lại mã trên phiếu bán.' : 'Không tìm thấy lô này ở kho — hoặc mọi chiếc trong lô đang có phiếu bảo hành.'; return; }
      setNeed(false);
      const state = d.void ? '<b class="neg">Phiếu bán đã bị đảo — mã không còn hiệu lực</b>' : d.open ? '<b class="neg">Đang có phiếu bảo hành ' + esc(d.open) + '</b>' : d.valid ? '<b class="pos">Còn bảo hành</b>' : '<b class="neg">Đã hết hạn bảo hành</b> (vẫn nhận được, không đổi mới miễn phí)';
      info.className = 'sale-lookup small';
      info.innerHTML = (d.whole ? '<b>Bảo hành cả lô ' + esc(l) + ' — ' + d.count + ' chiếc</b><br>' : '<b>Bảo hành 1 chiếc</b> · ' + esc(d.code) + '<br>') + esc(d.item) + (d.lot ? ' · lô ' + esc(d.lot) : '') + ' · kho ' + esc(d.wh) + '<br>Hoá đơn ' + esc(d.voucher) + (d.customer ? ' · ' + esc(d.customer) : '') +
        ' · mua ' + fmt(d.bought) + ' · bảo hành ' + d.months + ' tháng, đến ' + fmt(d.expiry) + '<br>' + state;
    } catch (e) { if (mine === seq) { info.className = 'sale-lookup muted small'; info.textContent = 'Không tra được lúc này — thử lại.'; } }
  };
  document.addEventListener('change', (e) => {   // hẹn trả không được trước ngày nhận
    if (!e.target.matches || !e.target.matches('[data-warranty-from]')) return;
    const due = e.target.closest('form').querySelector('[data-warranty-due]');
    if (due && e.target.value) { due.min = e.target.value; if (due.value && due.value < e.target.value) due.value = e.target.value; }
  });
  document.addEventListener('input', (e) => {
    if (!e.target.matches || !e.target.matches('[data-sale-lot], [data-sale-item]')) return;
    e.target.value = e.target.value.toUpperCase().replace(/\s+/g, '');
    clearTimeout(timer);
    const form = e.target.closest('form');
    timer = setTimeout(() => run(form), 250);
  });

  // Gợi ý mã: gõ / bấm vào ô mã lô ⇒ liệt kê lô (rồi chiếc trong lô đó); gõ ô mã món ⇒ liệt kê chiếc kèm mã lô, chọn là điền cả hai ô.
  const SEL = '[data-sale-lot], [data-sale-item]';
  let pop = null, rows = [], act = -1, sugTimer = 0, sugSeq = 0;
  const closePop = () => { if (pop) { pop.remove(); pop = null; } rows = []; act = -1; };
  const choose = (row) => {
    const form = pop && pop.closest('form');
    if (!form || !row) return;
    const lot = form.querySelector('[data-sale-lot]'), item = form.querySelector('[data-sale-item]');
    lot.value = row.lot;
    if (row.code) { item.value = row.code; closePop(); run(form); item.focus(); return; }
    closePop();
    item.value = '';
    run(form);      // chỉ có mã lô ⇒ nhận bảo hành cả lô
    item.focus();   // muốn một chiếc thôi: ô mã món hàng tự gợi ý các chiếc trong lô
  };
  const paint = () => [...pop.children].forEach((li, i) => li.classList.toggle('on', i === act));
  const suggest = async (input) => {
    const form = input.closest('form'), label = input.closest('label'), mine = ++sugSeq;
    const lot = form.querySelector('[data-sale-lot]'), item = form.querySelector('[data-sale-item]');
    const field = input === item ? 'item' : 'lot';
    try {
      const res = await fetch('/warranty/suggest?field=' + field + '&lot=' + encodeURIComponent(lot.value) + '&item=' + encodeURIComponent(item.value), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      const d = await res.json();
      if (mine !== sugSeq || document.activeElement !== input) return;
      closePop();
      rows = d.rows.map((r) => ({ ...r, units: d.mode === 'units' }));
      if (!rows.length) return;
      pop = document.createElement('ul');
      pop.className = 'sale-suggest';
      pop.setAttribute('role', 'listbox');
      pop.innerHTML = rows.map((r, i) => '<li role="option" data-i="' + i + '">' + (r.units
        ? '<b class="code">' + esc(field === 'item' ? r.code : r.lot) + '</b><span>' + esc(field === 'item' ? r.lot : r.code) + '</span><small>' + esc(r.sku + ' — ' + r.name) + '</small>'
        : '<b class="code">' + esc(r.lot) + '</b><span>' + esc(r.n) + ' chiếc</span><small>' + esc(r.sku + ' — ' + r.name + ' · ' + r.voucher) + '</small>') + '</li>').join('');
      label.classList.add('has-suggest');
      label.appendChild(pop);
    } catch (e) { /* không gợi ý được thì thôi, vẫn gõ tay bình thường */ }
  };
  document.addEventListener('focusin', (e) => {
    if (!e.target.matches || !e.target.matches(SEL)) return;
    clearTimeout(sugTimer);
    sugTimer = setTimeout(() => suggest(e.target), 120);
  });
  document.addEventListener('input', (e) => {
    if (!e.target.matches || !e.target.matches(SEL)) return;
    clearTimeout(sugTimer);
    sugTimer = setTimeout(() => suggest(e.target), 150);
  });
  document.addEventListener('focusout', (e) => { if (e.target.matches && e.target.matches(SEL)) setTimeout(() => { if (!document.activeElement || !document.activeElement.matches(SEL)) closePop(); }, 120); });
  document.addEventListener('mousedown', (e) => {   // mousedown (không phải click) để ô nhập chưa kịp mất focus
    const li = e.target.closest && e.target.closest('.sale-suggest li');
    if (!li) return;
    e.preventDefault();
    choose(rows[+li.dataset.i]);
  });
  document.addEventListener('keydown', (e) => {
    if (!pop || !e.target.matches || !e.target.matches(SEL)) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      act = (act + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length;
      paint();
    } else if (e.key === 'Enter' && act >= 0) {
      e.preventDefault();
      choose(rows[act]);
    } else if (e.key === 'Escape') {
      e.stopPropagation();   // chỉ đóng gợi ý, không đóng hộp thoại
      closePop();
    }
  }, true);
})();

// Lọc ngay trên trình duyệt cho trang ít dữ liệu, không phân trang (kho & nhân sự, phân quyền).
// [data-filter-box] = khung lọc, giá trị là bộ chọn các phần tử được lọc; bên trong: ô [data-filter-q] (từ khoá) và tuỳ chọn [data-filter-group] (nhóm).
// [data-filter-groups] = dòng tiêu đề nhóm: ẩn khi cả nhóm không còn dòng nào; chọn nhóm thì chỉ giữ các dòng thuộc nhóm đó.
const runListFilter = (box) => {
  const fold = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
  const qEl = box.querySelector('[data-filter-q]'), gEl = box.querySelector('[data-filter-group]');
  const words = fold(qEl ? qEl.value : '').split(/\s+/).filter(Boolean), group = gEl ? gEl.value : '';
  const items = [...document.querySelectorAll(box.dataset.filterBox)];
  const heads = box.dataset.filterGroups ? [...document.querySelectorAll(box.dataset.filterGroups)] : [];
  const groupOf = (el) => { let n = el.previousElementSibling; while (n && !heads.includes(n)) n = n.previousElementSibling; return n ? n.textContent.trim() : ''; };
  items.forEach((el) => { const t = fold(el.textContent); el.hidden = !words.every((w) => t.includes(w)) || (group !== '' && groupOf(el) !== group); });
  heads.forEach((g) => { let n = g.nextElementSibling, any = false; while (n && !heads.includes(n)) { if (!n.hidden) any = true; n = n.nextElementSibling; } g.hidden = !any; });
  qEl && qEl.classList.toggle('on', words.length > 0);
  gEl && gEl.classList.toggle('on', group !== '');
  let empty = box.querySelector('.filter-empty');
  if (!empty) { empty = document.createElement('p'); empty.className = 'filter-empty muted small'; empty.textContent = 'Không có kết quả phù hợp.'; box.append(empty); }
  empty.hidden = !(words.length || group) || items.some((el) => !el.hidden);
};
['input', 'change'].forEach((t) => document.addEventListener(t, (e) => { const box = e.target.closest && e.target.closest('[data-filter-box]'); if (box) runListFilter(box); }));

// Form đối tác: thêm / bỏ dòng «hàng mới do nhà cung cấp cung cấp»
document.addEventListener('click', (ev) => {
  const add = ev.target.closest('[data-add-newitem]'), del = ev.target.closest('[data-remove-newitem]');
  if (del) { del.closest('.newitem-row').remove(); return; }
  if (!add) return;
  const box = add.closest('[data-newitems]'), tpl = box.querySelector('[data-newitem-tpl]');
  const row = tpl.content.firstElementChild.cloneNode(true);
  box.querySelector('.newitem-list').appendChild(row);
  row.querySelector('input').focus();
});

// Form bị từ chối ⇒ điền lại đúng những gì đã nhập (và mở lại hộp thoại nếu form nằm trong hộp thoại) để khỏi nhập từ đầu
(() => {
  const holder = document.getElementById('old-input');
  if (!holder) return;
  let data, action = holder.dataset.action;
  try { data = JSON.parse(holder.dataset.old || '{}'); } catch (e) { return; }
  const forms = [...document.forms].filter((f) => f.method.toLowerCase() === 'post' && new URL(f.action, location.href).pathname === action);
  if (!forms.length) return;
  // nhiều form cùng đích (VD nút «Sửa» của từng dòng): chọn form có các ô ẩn định danh (partner_id, item_id…) khớp
  const score = (f) => [...f.querySelectorAll('input[type=hidden]')].filter((h) => !['csrf', 'idem'].includes(h.name) && data[h.name] !== undefined && String(data[h.name]) === h.value).length;
  const form = forms.reduce((a, b) => (score(b) > score(a) ? b : a));
  const fire = (el) => { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
  const fields = (name) => [...form.elements].filter((el) => el.name === name);
  // 1) hộp thoại trước, để thông báo lỗi (mở sau cùng) nằm trên cùng
  const dlg = form.closest('dialog');
  if (dlg && dlg.showModal && !dlg.open) dlg.showModal();
  // 2) ô đơn
  Object.entries(data).forEach(([k, v]) => {
    if (Array.isArray(v) || k === 'line' || k === 'qty') return;
    fields(k).forEach((el) => { if (el.type === 'checkbox' || el.type === 'radio') el.checked = el.value === String(v); else if (el.type !== 'file') el.value = v; fire(el); });
  });
  // 3) nhóm ô cùng tên (tick nhiều ô / các dòng hàng mới của nhà cung cấp)
  Object.entries(data).forEach(([k, v]) => {
    if (!Array.isArray(v) || k === 'line' || k === 'qty') return;
    const els = fields(k + '[]');
    if (els.length && els.every((el) => el.type === 'checkbox')) { els.forEach((el) => { el.checked = v.map(String).includes(el.value); }); return; }
    const add = form.querySelector('[data-add-newitem]');
    if (add && k.startsWith('new_')) {
      const rows = () => form.querySelectorAll('.newitem-row').length;
      while (rows() < v.length) add.click();
    }
    fields(k + '[]').forEach((el, i) => { if (v[i] !== undefined) el.value = v[i]; });
  });
  // 4) dòng hàng của phiếu
  const picker = document.getElementById('picker');
  if (picker && picker.restoreLines && Array.isArray(data.line)) picker.restoreLines(data.line, Array.isArray(data.qty) ? data.qty : [], Array.isArray(data.lot_no) ? data.lot_no : [], Array.isArray(data.exp) ? data.exp : []);
  // 5) đưa thông báo lỗi lên trên hộp thoại vừa mở lại
  const notice = document.getElementById('notice-dlg');
  if (dlg && notice && notice.open) { notice.close(); notice.showModal(); }
})();

// Phiếu khách trả hàng: ô «người chịu» đổi theo nguyên nhân lỗi, và tiền hoàn cho khách tính ngay khi nhập. Chạy lại mỗi lần đổi trang kiểu SPA.
window.initCreturn = () => {
  const form = document.getElementById('creturn-form');
  if (!form || form._creturn) return;
  form._creturn = true;
  const total = document.getElementById('refund-total');
  const fmt = (n) => Math.round(n).toLocaleString('vi-VN');
  const sync = () => {
    let sum = 0;
    form.querySelectorAll('tr[data-unit]').forEach((tr) => {
      const unit = Number(tr.dataset.unit) || 0;
      const qty = Number(tr.querySelector('[name="qty[]"]')?.value) || 0;
      const bad = Math.min(qty, Number(tr.querySelector('[name="bad[]"]')?.value) || 0);
      const cause = bad > 0 ? tr.querySelector('[name="why[]"]')?.value || '' : '';
      tr.querySelectorAll('[data-for]').forEach((el) => { el.hidden = el.dataset.for !== cause; });
      let deduct = 0;
      if (cause === 'KHACH') {
        const typed = tr.querySelector('[name="deduct[]"]')?.value.trim() ?? '';
        deduct = typed === '' ? bad * unit : Math.min(bad * unit, Number(typed) || 0);
      }
      sum += qty * unit - deduct;
    });
    if (total) total.textContent = fmt(sum);
  };
  form.addEventListener('input', sync);
  form.addEventListener('change', sync);
  sync();
};
window.initCreturn();
