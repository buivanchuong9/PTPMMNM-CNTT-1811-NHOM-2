// Thêm xe chở cùng khi xuất kho điều chuyển: «＋ Thêm xe» ra một ô chọn xe phụ, nhập số lượng từng dòng; xe chính (ô «Xe chở hàng») chở phần còn lại.
(function () {
  window.initConvoy = function () {
    document.querySelectorAll('[data-convoy]').forEach(function (box) {
      if (box.dataset.ready) { return; }
      box.dataset.ready = '1';
      var form = box.closest('form'), main = form && form.querySelector('select[name=vehicle_id]');
      var rowsBox = box.querySelector('[data-convoy-rows]'), tpl = box.querySelector('[data-convoy-tpl]'), addBtn = box.querySelector('[data-convoy-add]');
      var table = box.querySelector('[data-convoy-table]'), hint = box.querySelector('[data-convoy-hint]'), even = box.querySelector('[data-convoy-even]');
      var plate = box.querySelector('[data-convoy-main-plate]'), lineRows = [].slice.call(box.querySelectorAll('[data-line]'));
      var sels = function () { return [].slice.call(rowsBox.querySelectorAll('[data-convoy-sel]')); };
      var inputsOf = function (id) { return [].slice.call(box.querySelectorAll('td[data-car="' + id + '"] input')); };

      var lastSig = '';
      function refresh() {
        var mainId = main ? main.value : '', picked = [];
        sels().forEach(function (s) {
          if (s.value && s.value === mainId) { s.value = ''; }                   // xe chính đổi trúng xe phụ ⇒ bỏ chọn ô phụ
          if (s.value) { picked.push(s.value); }
        });
        sels().forEach(function (s) {                                             // mỗi xe chỉ chọn một lần: ẩn xe chính và xe đã chọn ở ô khác
          [].slice.call(s.options).forEach(function (o) {
            o.disabled = !!o.value && (o.value === mainId || (o.value !== s.value && picked.indexOf(o.value) >= 0));
          });
        });
        var taken = picked.length + (mainId ? 1 : 0), total = tpl.content.querySelectorAll('option:not([value=""])').length;   // tổng số xe sẵn sàng
        addBtn.disabled = taken >= total || sels().some(function (s) { return !s.value; });   // còn xe để thêm, và không có ô đang để trống
        box.querySelectorAll('th[data-car], td[data-car]').forEach(function (cell) { cell.hidden = picked.indexOf(cell.dataset.car) < 0; });
        box.querySelectorAll('td[data-car] input').forEach(function (i) { i.disabled = picked.indexOf(i.closest('td').dataset.car) < 0; });
        table.hidden = !picked.length;
        even.hidden = !picked.length;
        plate.textContent = main && main.value ? '(' + main.selectedOptions[0].textContent.split(' — ')[0] + ')' : '';

        var bad = '', mainTotal = 0;
        lineRows.forEach(function (tr) {
          var t = +tr.dataset.total, used = 0;
          tr.querySelectorAll('td[data-car] input:not(:disabled)').forEach(function (i) { used += Math.max(0, parseInt(i.value, 10) || 0); });
          var left = t - used, cell = tr.querySelector('[data-main]');
          cell.textContent = left;
          cell.classList.toggle('neg', left < 0);
          if (left < 0) { bad = 'Các xe chở nhiều hơn số lượng trên phiếu.'; }
          mainTotal += Math.max(0, left);
        });
        if (picked.length && !bad && !mainId) { bad = 'Chọn xe chính ở ô «Xe chở hàng» phía trên.'; }
        if (picked.length && !bad && mainTotal < 1) { bad = 'Xe chính không còn hàng nào để chở — giảm bớt số lượng các xe phụ.'; }
        picked.forEach(function (id) {
          var sum = 0; inputsOf(id).forEach(function (i) { sum += parseInt(i.value, 10) || 0; });
          if (!bad && sum < 1) { bad = 'Mỗi xe phụ phải chở ít nhất một mặt hàng — nhập số lượng ở bảng bên dưới.'; }
        });
        if (!bad && sels().some(function (s) { return !s.value; })) { bad = 'Có ô xe chở cùng chưa chọn xe — chọn xe hoặc bấm ✕ để bỏ.'; }
        if (!bad && [].slice.call(rowsBox.querySelectorAll('[data-convoy-drv]')).some(function (d) { return !d.value; })) { bad = 'Chọn tài xế lái cho từng xe chở cùng.'; }
        hint.textContent = bad || (picked.length ? 'Tổng cộng ' + (picked.length + 1) + ' xe cùng chở, mỗi xe một phiếu riêng.' : '');
        hint.classList.toggle('neg', !!bad);
        box.dataset.invalid = bad ? '1' : '';
        var crew = box.closest('[data-crew]'); if (crew && window.crewSync) { window.crewSync(crew); }   // lọc lại danh sách tài xế theo xe vừa chọn
        var sig = picked.join(',') + '|' + mainId;
        if (sig !== lastSig) { lastSig = sig; if (picked.length) { fill(picked); refresh(); } }   // thêm / bớt / đổi xe ⇒ chia đều lại, sau đó vẫn tự sửa số được
      }

      // chia đều từng dòng: mỗi xe phụ nhận phần nguyên, xe chính giữ phần còn lại (kể cả số dư)
      function fill(picked) {
        var n = picked.length + 1;
        lineRows.forEach(function (tr) {
          var share = Math.floor(+tr.dataset.total / n), pack = +tr.dataset.pack || 1;
          if (pack > 1 && share >= pack) { share = Math.floor(share / pack) * pack; }   // hàng đóng kiện: chia theo kiện nguyên, phần dư về xe chính
          picked.forEach(function (id) { var i = tr.querySelector('td[data-car="' + id + '"] input'); if (i) { i.value = share; } });
        });
      }

      addBtn.addEventListener('click', function () {
        rowsBox.appendChild(tpl.content.cloneNode(true));
        refresh();
        var last = sels().pop(); if (last) { last.focus(); }
      });
      rowsBox.addEventListener('click', function (e) {
        var del = e.target.closest('[data-convoy-del]');
        if (!del) { return; }
        del.closest('.convoy-row').remove();
        refresh();
      });
      rowsBox.addEventListener('change', refresh);
      if (main) { main.addEventListener('change', refresh); }
      table.addEventListener('input', refresh);
      even.addEventListener('click', function () {
        fill(sels().map(function (s) { return s.value; }).filter(Boolean));
        refresh();
      });
      form.addEventListener('submit', function (e) {
        var ownHidden = !!box.closest('[data-pay-own]') && box.closest('[data-pay-own]').hidden;
        if (ownHidden) { box.querySelectorAll('input').forEach(function (i) { i.disabled = true; }); return; }   // đổi sang thuê ngoài: bỏ phần chia xe
        if (box.dataset.invalid) { e.preventDefault(); e.stopImmediatePropagation(); hint.scrollIntoView({ block: 'center' }); }
      }, true);
      refresh();
    });
  };
  window.initConvoy();
})();
