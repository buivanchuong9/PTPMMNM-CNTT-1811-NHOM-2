// Giao hàng cho khách: chọn xong tỉnh / phường thì tự tính quãng đường (km) từ kho tới địa chỉ — cố định theo địa chỉ, không phải nhập tay.
// Gõ tay vào ô km thì giữ số đã gõ, không tự ghi đè nữa. Máy chủ chỉ trả km, tiền gợi ý do khối «vận chuyển & tiền công» tính tiếp.
(function () {
  var timers = new WeakMap();

  function refresh(pay) {
    var url = pay.dataset.kmUrl, root = pay.querySelector('[data-vn-addr]'), km = pay.querySelector('input[name=km]');
    if (!url || !root || !km || km.dataset.manual) { return; }
    var mode = (pay.querySelector('[name=hinh_thuc]:checked') || {}).value;
    if (mode === 'TU_LAY') { return; }
    var q = ['street', 'ward', 'province'].map(function (n) { return n + '=' + encodeURIComponent((root.querySelector('[name=' + n + ']') || {}).value || ''); }).join('&');
    fetch(url + '?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var note = pay.querySelector('[data-km-note]');
      if (!note) { note = document.createElement('small'); note.className = 'muted'; note.setAttribute('data-km-note', ''); km.closest('label').appendChild(note); }
      if (d && d.km) {
        km.value = String(d.km);
        km.dispatchEvent(new Event('input', { bubbles: true }));   // để khối tiền công tính lại gợi ý
        note.textContent = 'Tự tính từ kho tới địa chỉ giao (cố định theo địa chỉ) — sửa được nếu thực tế khác';
        if (d.nearer) {   // khách ở xa kho này, có kho khác gần hơn nhiều
          note.textContent += ' · ⚠ Khách ở xa kho này (' + d.km + ' km), kho ' + d.nearer.ma + ' chỉ cách ' + d.nearer.km + ' km — nên xuất từ kho đó cho rẻ.';
          note.classList.add('neg');
        } else { note.classList.remove('neg'); }
      } else {
        note.textContent = 'Chọn đủ tỉnh / thành và phường / xã để tự tính km';
      }
    }).catch(function () {});
  }

  function later(pay) {
    clearTimeout(timers.get(pay));
    timers.set(pay, setTimeout(function () { refresh(pay); }, 350));
  }

  window.initDeliveryKm = function () {
    document.querySelectorAll('[data-pay][data-km-url]').forEach(function (pay) {
      if (pay.dataset.kmReady) { return; }
      pay.dataset.kmReady = '1';
      var km = pay.querySelector('input[name=km]');
      if (km) { km.addEventListener('input', function (e) { if (e.isTrusted) { km.dataset.manual = '1'; } }); }   // người dùng tự gõ km
      pay.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('[data-vn-addr]')) { later(pay); } });
      pay.addEventListener('change', function (e) { if (e.target.closest && (e.target.closest('[data-vn-addr]') || e.target.name === 'hinh_thuc')) { later(pay); } });
      document.addEventListener('vnaddr:ready', function () { later(pay); });
      later(pay);   // địa chỉ khách đã lưu được điền sẵn ⇒ tính ngay
    });
  };
  window.initDeliveryKm();
})();
