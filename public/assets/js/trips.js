// Tab «Điểm & thu nhập»: bấm «N chuyến» để mở / đóng danh sách chuyến thật của tài xế ngay dưới dòng đó.
// Nhiều chuyến thì chia trang (5 chuyến một trang) để bảng không dài ngoằng; tổng tiền ở chân bảng vẫn tính cả tháng.
(function () {
  var PAGE = 5;

  // chỉ các dòng chuyến của bảng bên trong (không lấy dòng tổng ở chân bảng, không lấy dòng của bảng ngoài)
  function tripRows(box) { return [].slice.call(box.querySelector('table').tBodies[0].rows); }

  function show(box, page) {
    var rows = tripRows(box), pages = Math.max(1, Math.ceil(rows.length / PAGE));
    page = Math.min(Math.max(1, page), pages);
    rows.forEach(function (r, i) { r.hidden = i < (page - 1) * PAGE || i >= page * PAGE; });
    var nav = box.querySelector('.trips-pager');
    if (!nav) { return; }
    var from = (page - 1) * PAGE + 1, to = Math.min(rows.length, page * PAGE);
    nav.querySelector('.trips-info').textContent = 'Chuyến ' + from + '–' + to + ' / ' + rows.length + ' · trang ' + page + '/' + pages;
    nav.querySelector('[data-trips-prev]').disabled = page <= 1;
    nav.querySelector('[data-trips-next]').disabled = page >= pages;
    box.dataset.page = String(page);
  }

  function init(box) {
    if (box.dataset.ready) { return; }
    box.dataset.ready = '1';
    var count = tripRows(box).length;
    if (count > PAGE) {
      var nav = document.createElement('div');
      nav.className = 'trips-pager';
      nav.innerHTML = '<button type="button" class="btn-ghost btn-sm" data-trips-prev>‹ Trước</button><span class="trips-info muted small"></span><button type="button" class="btn-ghost btn-sm" data-trips-next>Sau ›</button>';
      box.appendChild(nav);
    }
    show(box, 1);
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    var btn = t.closest && t.closest('[data-trips-toggle]');
    if (btn) {
      var row = document.getElementById('trips-' + btn.dataset.tripsToggle);
      if (!row) { return; }
      var open = row.hidden;
      row.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.closest('tr').classList.toggle('has-trips-open', open);
      if (open) { init(row.querySelector('.trips-box')); }
      return;
    }
    var step = t.closest && (t.closest('[data-trips-prev]') ? -1 : t.closest('[data-trips-next]') ? 1 : 0);
    if (step) {
      var box = t.closest('.trips-box');
      show(box, (parseInt(box.dataset.page, 10) || 1) + step);
    }
  });
})();
