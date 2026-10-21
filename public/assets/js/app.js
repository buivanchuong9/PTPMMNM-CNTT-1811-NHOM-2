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

  // Menu trên mobile
  const toggle = document.querySelector('[data-menu-toggle]');
  if (toggle) toggle.addEventListener('click', () => document.getElementById('sidebar').classList.toggle('open'));

  // Dòng hàng trong phiếu: thêm / xoá
  const lines = document.getElementById('lines');
  if (lines) {
    document.getElementById('add-line').addEventListener('click', () => lines.insertAdjacentHTML('beforeend', lines.dataset.template));
    lines.addEventListener('click', (ev) => {
      const btn = ev.target.closest('.remove-line');
      if (btn && lines.children.length > 1) btn.closest('.line').remove();
    });
  }

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
        if (input.value === '') { cell.textContent = '—'; cell.className = 'num variance'; sel.hidden = note.hidden = true; return; }
        const d = +input.value - +tr.dataset.system;
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
    cf.addEventListener('input', refresh);
    refresh();
  }
})();
