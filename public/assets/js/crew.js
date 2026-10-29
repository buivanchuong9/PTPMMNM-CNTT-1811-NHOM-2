// Chọn xe và tài xế riêng (không gắn cố định): danh sách tài xế tự lọc theo xe đã chọn — chỉ tài xế có bằng lái ĐÚNG HẠNG cho xe đó mới chọn được;
// bằng hết hạn, chưa đủ tuổi… (hồ sơ không hợp lệ) và tài xế đã chọn cho xe khác trong cùng đợt bị khoá kèm lý do. Máy chủ vẫn kiểm lại khi xuất kho.
(function () {
  // $covers: hạng bằng cần có của xe ⇒ các hạng bằng được lái (TransportRules::coverTable)
  function annotate(veh, drv, covers, taken) {
    if (!veh || !drv) { return; }
    var opt = veh.selectedOptions && veh.selectedOptions[0], need = veh.value && opt ? (opt.dataset.need || '') : '';
    [].slice.call(drv.options).forEach(function (o) {
      if (!o.value) { return; }
      if (!o.dataset.base) { o.dataset.base = o.textContent; }
      var cls = o.dataset.class || '', why = o.dataset.bad || '';
      if (!why && taken.indexOf(o.value) >= 0) { why = 'đã chọn lái xe khác trong đợt này'; }
      if (!why && need && cls && (covers[need] || []).indexOf(cls) < 0) { why = 'bằng ' + cls + ' không lái được xe này (cần hạng ' + need + ' trở lên)'; }
      o.disabled = !!why;
      o.textContent = o.dataset.base + (why ? ' — ✘ ' + why : '');
    });
    if (drv.selectedOptions[0] && drv.selectedOptions[0].disabled) { drv.value = ''; }   // đổi xe làm tài xế đang chọn hết hợp lệ ⇒ bỏ chọn
  }

  function sync(box) {
    var covers = {};
    try { covers = JSON.parse(box.dataset.crew || '{}'); } catch (e) { covers = {}; }
    var mainVeh = box.querySelector('select[name=vehicle_id]'), mainDrv = box.querySelector('select[name=driver_id]');
    var rows = [].slice.call(box.querySelectorAll('.convoy-row')).map(function (r) { return { veh: r.querySelector('[data-convoy-sel]'), drv: r.querySelector('[data-convoy-drv]') }; });
    rows.forEach(function (r) { if (r.drv && r.veh) { r.drv.name = r.veh.value ? 'extra_driver[' + r.veh.value + ']' : ''; } });   // tài xế của xe phụ gửi kèm id xe
    var pairs = [{ veh: mainVeh, drv: mainDrv }].concat(rows);
    var chosen = pairs.map(function (p) { return p.drv ? p.drv.value : ''; }).filter(Boolean);
    pairs.forEach(function (p) {
      if (!p.drv) { return; }
      annotate(p.veh, p.drv, covers, chosen.filter(function (v) { return v !== p.drv.value; }));
    });
  }

  window.crewSync = sync;
  window.initCrew = function () {
    document.querySelectorAll('[data-crew]').forEach(function (box) {
      if (box.dataset.crewReady) { return; }
      box.dataset.crewReady = '1';
      box.addEventListener('change', function () { sync(box); });
      sync(box);
    });
  };
  window.initCrew();
})();
