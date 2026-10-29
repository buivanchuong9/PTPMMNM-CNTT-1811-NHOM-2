// Form hàng hoá: gõ cân nặng + dài/rộng/cao thì hiện ngay thể tích và trọng lượng quy đổi của 1 đơn vị (cách tính cước đường bộ: 1 m³ ≈ 333 kg).
(function () {
  var FACTOR = 333;
  var num = function (v) { var n = parseFloat(String(v || '').replace(',', '.')); return isFinite(n) && n > 0 ? n : 0; };
  var fmt = function (n, d) { return n.toLocaleString('vi-VN', { maximumFractionDigits: d }); };
  function refresh(box) {
    var out = box.querySelector('[data-logi-sum]');
    if (!out) { return; }
    if (!out.dataset.base) { out.dataset.base = out.textContent; }
    var w = num(box.querySelector('[data-logi-w]').value), d = [].slice.call(box.querySelectorAll('[data-logi-d]')).map(function (i) { return num(i.value); });
    if (d.every(Boolean)) {
      var m3 = d[0] * d[1] * d[2] / 1e6, conv = m3 * FACTOR;
      out.textContent = 'Thể tích ≈ ' + fmt(m3, 4) + ' m³ · quy đổi ≈ ' + fmt(conv, 2) + ' kg (1 m³ = ' + FACTOR + ' kg)' + (w ? ' · tính cước theo ' + fmt(Math.max(w, conv), 2) + ' kg (số lớn hơn)' : '');
    } else {
      out.textContent = out.dataset.base;
    }
    // 1 kiện (thùng đóng sẵn): thể tích và cân nặng (không nhập cân kiện thì tính = số đơn vị × cân nặng 1 đơn vị)
    var kd = [].slice.call(box.querySelectorAll('[data-logi-kd]')).map(function (i) { return num(i.value); }), form = box.closest('form'), packEl = form && form.querySelector('[name=pack_size]');
    var pack = packEl ? parseInt(packEl.value, 10) || 1 : 1, kw = num((box.querySelector('[data-logi-kw]') || {}).value), parts = [];
    if (kd.every(Boolean)) { parts.push('thể tích ≈ ' + fmt(kd[0] * kd[1] * kd[2] / 1e6, 4) + ' m³'); }
    if (kw || (w && pack > 1)) { parts.push('nặng ≈ ' + fmt(kw || w * pack, 2) + ' kg' + (kw ? '' : ' (tự tính ' + pack + ' × ' + fmt(w, 2) + ')')); }
    if (parts.length && pack > 1) { out.textContent += ' · 1 kiện ' + parts.join(' · '); }
  }
  // Form xe: nhập GVW (và rơ moóc) thì cho biết hạng bằng lái tối thiểu (Luật TTATGTĐB 2024: ≤ 3,5 tấn B · đến 7,5 tấn C1 · trên 7,5 tấn C; rơ moóc trên 0,75 tấn thêm E)
  function need(box) {
    var out = box.querySelector('[data-spec-need]'), g = box.querySelector('[data-spec-gvw]'), t = box.querySelector('[data-spec-trailer]');
    if (!out || !g) { return; }
    if (!out.dataset.base) { out.dataset.base = out.textContent; }
    var kind = box.querySelector('[data-spec-kind]'), ccEl = box.querySelector('[data-spec-cc]');
    if (kind && kind.value === 'MAY') {   // xe máy: hạng bằng theo dung tích xi-lanh (A1 đến 175 cm³, A trên 175 cm³)
      var cc = ccEl ? parseInt(ccEl.value, 10) || 0 : 0;
      out.textContent = cc ? 'Tài xế cần bằng lái hạng ' + (cc <= 175 ? 'A1' : 'A') + ' (xe máy ' + cc + ' cm³). Xe máy chỉ giao hàng cho khách, không chạy điều chuyển.' : 'Xe máy: nhập dung tích xi-lanh để biết hạng bằng (đến 175 cm³ hạng A1, trên đó hạng A).';
      return;
    }
    var gvw = num(g.value), tr = t ? num(t.value) : 0;
    if (!gvw) { out.textContent = out.dataset.base; return; }
    var cls = gvw <= 3.5 ? 'B' : gvw <= 7.5 ? 'C1' : 'C';
    if (tr > 0.75) { cls += 'E'; }
    out.textContent = 'Tài xế cần bằng lái hạng ' + cls + ' trở lên (GVW ' + fmt(gvw, 2) + ' tấn' + (tr > 0.75 ? ', kéo rơ moóc ' + fmt(tr, 2) + ' tấn' : '') + ').';
  }
  // Ngày sinh ⇒ tuổi hiện nay, hiện ngay cạnh ô nhập
  function age(inp) {
    var out = inp.closest('label').querySelector('[data-age]'), v = inp.value;
    if (!out) { return; }
    if (!v) { out.textContent = ''; return; }
    var b = new Date(v), n = new Date(), a = n.getFullYear() - b.getFullYear();
    if (n.getMonth() < b.getMonth() || (n.getMonth() === b.getMonth() && n.getDate() < b.getDate())) { a--; }
    out.textContent = isFinite(a) && a >= 0 && a < 120 ? '— ' + a + ' tuổi' : '';
  }
  document.addEventListener('input', function (e) {
    if (e.target.matches && e.target.matches('[data-birth]')) { age(e.target); }
    var s = e.target.closest && e.target.closest('[data-spec]'); if (s) { need(s); }
    var b = e.target.closest && e.target.closest('[data-logi]'); if (b) { refresh(b); }
  });
  document.addEventListener('click', function (e) {   // mở hộp thoại sửa: giá trị được điền sẵn nên tính lại sau khi điền
    if (e.target.closest && e.target.closest('[data-dialog-open]')) { setTimeout(function () { document.querySelectorAll('[data-logi]').forEach(refresh); document.querySelectorAll('[data-spec]').forEach(need); document.querySelectorAll('[data-birth]').forEach(age); }, 80); }
  });
})();
