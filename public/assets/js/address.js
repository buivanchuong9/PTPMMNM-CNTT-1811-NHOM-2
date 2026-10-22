// Ô nhập địa chỉ Việt Nam có gợi ý: Số nhà, đường · Tỉnh / Thành phố · Phường / Xã.
// Dữ liệu hành chính nạp từ /assets/data/vn-address.json (cùng nguồn với máy chủ, CSP chỉ cho 'self'). Gõ không dấu cũng tìm được.
// Máy chủ vẫn kiểm tra lại toàn bộ (VnAddress::compose), nên đây chỉ là phần tiện lợi + báo lỗi sớm.
(() => {
  const fold = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/\s+/g, ' ').trim();
  const core = (s) => fold(s).replace(/^(thanh pho|tinh|phuong|xa|dac khu|thi tran) /, '');
  const MAX = 40;

  let data = null, readyP = null;
  const loadData = () => readyP || (readyP = fetch('/assets/data/vn-address.json').then((r) => r.json()).then((d) => {
    data = d.map((p) => ({ n: p.n, f: fold(p.n), c: core(p.n), w: p.w.map((n) => ({ n, f: fold(n), c: core(n) })) }));
    document.dispatchEvent(new CustomEvent('vnaddr:ready'));
  }).catch(() => { data = []; }));

  const findProvince = (v) => { const f = fold(v); return f && data ? data.find((p) => p.f === f || p.c === f) || null : null; };
  const findWard = (p, v) => {
    const f = fold(v);
    if (!p || !f) return null;
    const exact = p.w.find((w) => w.f === f);
    if (exact) return exact;
    const hits = p.w.filter((w) => w.c === f);
    return hits.length === 1 ? hits[0] : null;
  };
  // Lọc + xếp hạng: khớp đầu tên trước, rồi khớp ở giữa
  const search = (items, q) => {
    const f = fold(q), toks = f.split(' ').filter(Boolean);
    const withType = /^(thanh|tinh|phuong|xa|dac|thi) /.test(f);   // gõ «thanh pho…», «phuong…» thì so cả loại đơn vị; ngược lại chỉ so tên (tránh «ha» khớp «Thành phố»)
    const hit = items.filter((i) => toks.every((t) => (' ' + (withType ? i.f : i.c)).includes(' ' + t)));
    return hit.sort((a, b) => (b.c.startsWith(f) - a.c.startsWith(f)) || (b.f.startsWith(f) - a.f.startsWith(f)) || a.n.localeCompare(b.n, 'vi'));
  };

  window.initVnAddr = () => document.querySelectorAll('[data-vn-addr]').forEach((root) => {
    if (root.dataset.vnInit) return;
    root.dataset.vnInit = '1';
    const ready = loadData();
    const street = root.querySelector('[name=street]');
    const prov = root.querySelector('[name=province]');
    const ward = root.querySelector('[name=ward]');
    const preview = root.querySelector('[data-addr-preview]');
    if (!street || !prov || !ward) return;

    const refresh = () => {
      const p = findProvince(prov.value), w = findWard(p, ward.value);
      prov.setCustomValidity(data && prov.value.trim() && !p ? 'Chọn Tỉnh / Thành phố từ danh sách gợi ý' : '');
      ward.setCustomValidity(data && ward.value.trim() && !w ? (p ? 'Phường / Xã không thuộc ' + p.n + ' — chọn từ danh sách gợi ý' : 'Chọn Tỉnh / Thành phố trước') : '');
      if (preview) {
        const parts = [street.value.trim(), w ? w.n : ward.value.trim(), p ? p.n : prov.value.trim()].filter(Boolean);
        preview.textContent = parts.length ? 'Địa chỉ sẽ lưu: ' + parts.join(', ') : '';
      }
    };

    // Hộp gợi ý dùng chung cho hai ô; getItems() trả về danh sách {n,f,c}; onPick(item) sau khi chọn
    const attach = (input, getItems, hint, onPick) => {
      const list = document.createElement('ul');
      list.className = 'combo-list addr-list';
      list.hidden = true;
      list.setAttribute('role', 'listbox');
      input.parentElement.appendChild(list);
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-expanded', 'false');
      let shown = [], on = -1;

      const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); on = -1; };
      const mark = () => [...list.children].forEach((li, i) => { li.classList.toggle('on', i === on); li.setAttribute('aria-selected', String(i === on)); if (i === on) li.scrollIntoView({ block: 'nearest' }); });
      const pick = (item) => { input.value = item.n; close(); onPick && onPick(item); refresh(); };
      const render = () => {
        const items = getItems(input.value);
        list.textContent = '';
        shown = [];
        if (items === null) {
          const li = document.createElement('li'); li.className = 'none'; li.textContent = hint; list.appendChild(li);
        } else if (!items.length) {
          const li = document.createElement('li'); li.className = 'none'; li.textContent = 'Không có kết quả phù hợp'; list.appendChild(li);
        } else {
          shown = items.slice(0, MAX);
          shown.forEach((it) => { const li = document.createElement('li'); li.setAttribute('role', 'option'); li.textContent = it.n; li.addEventListener('mousedown', (e) => { e.preventDefault(); pick(it); }); list.appendChild(li); });
          if (items.length > MAX) { const li = document.createElement('li'); li.className = 'more'; li.textContent = 'Còn ' + (items.length - MAX) + ' kết quả — gõ thêm để thu hẹp'; list.appendChild(li); }
        }
        on = shown.length && input.value.trim() ? 0 : -1;
        list.hidden = false; input.setAttribute('aria-expanded', 'true'); mark();
      };
      const open = () => ready.then(render);
      input.addEventListener('focus', open);
      input.addEventListener('click', open);
      input.addEventListener('input', () => { refresh(); open(); });
      input.addEventListener('keydown', (e) => {
        if (list.hidden && (e.key === 'ArrowDown')) { open(); e.preventDefault(); return; }
        if (list.hidden) return;
        if (e.key === 'ArrowDown') { on = Math.min(shown.length - 1, on + 1); mark(); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { on = Math.max(0, on - 1); mark(); e.preventDefault(); }
        else if (e.key === 'Enter' && on >= 0 && shown[on]) { pick(shown[on]); e.preventDefault(); }
        else if (e.key === 'Escape') { close(); e.stopPropagation(); }
      });
      input.addEventListener('blur', () => setTimeout(() => {
        close();
        const found = getItems(input.value);                       // gõ đủ tên (không dấu / thiếu «Phường», «Tỉnh») thì tự chuẩn hoá
        const exact = found && found.find((i) => i.f === fold(input.value) || i.c === fold(input.value));
        if (exact && input.value !== exact.n) { input.value = exact.n; onPick && onPick(exact); }
        refresh();
      }, 120));
    };

    attach(prov, (q) => search(data || [], q), '', () => { const p = findProvince(prov.value); if (ward.value && !findWard(p, ward.value)) ward.value = ''; refresh(); });
    attach(ward, (q) => { const p = findProvince(prov.value); return p ? search(p.w, q) : null; }, 'Chọn Tỉnh / Thành phố trước, rồi chọn Phường / Xã');
    street.addEventListener('input', refresh);
    root.closest('form')?.addEventListener('submit', (e) => { refresh(); if (!root.closest('form').checkValidity()) { e.preventDefault(); root.closest('form').reportValidity(); } });
    // ô được điền sẵn bằng mã (hộp thoại sửa địa chỉ) hoặc khi dữ liệu vừa nạp xong
    document.addEventListener('vnaddr:ready', refresh);
    root.closest('dialog')?.addEventListener('toggle', refresh);
    document.addEventListener('click', (e) => { if (e.target.closest('[data-dialog-open]')) setTimeout(refresh, 0); });
    ready.then(refresh);
  });
  window.initVnAddr();
})();
