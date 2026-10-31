// Quét mã vạch: camera / ảnh tải lên / ảnh dán (Ctrl+V) / máy quét USB (gõ vào ô rồi Enter).
// Ảnh chỉ được giải mã ngay trên trình duyệt (thư viện ZXing); gửi lên máy chủ duy nhất dãy số mã vạch — không bao giờ gửi hay lưu ảnh.
(function () {
  const dlg = document.getElementById('scan-dlg');
  if (!dlg) return;
  const $ = (s) => dlg.querySelector(s);
  const view = $('[data-scan-view]'), video = view.querySelector('video'), msg = $('[data-scan-msg]'), out = $('[data-scan-result]');
  const title = $('[data-scan-title]'), sub = $('[data-scan-sub]'), manual = $('[data-scan-manual]'), drop = $('[data-scan-drop]'), camBtn = $('[data-scan-cam]');
  const defaultSub = sub.textContent;
  let target = null;          // chế độ gán mã: {id, label, code}
  let reader = null, camOn = false, zxLoad = null;

  const el = (tag, attrs = {}, ...kids) => {
    const n = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => (k === 'class' ? (n.className = v) : k === 'text' ? (n.textContent = v) : n.setAttribute(k, v)));
    kids.forEach((c) => c != null && n.append(c));
    return n;
  };
  const say = (text, bad) => { msg.hidden = !text; msg.textContent = text || ''; msg.classList.toggle('bad', !!bad); };
  const money = (n) => Number(n).toLocaleString('vi-VN');

  const loadZx = () => zxLoad || (zxLoad = new Promise((ok, fail) => {
    if (window.ZXing) return ok(window.ZXing);
    const s = document.createElement('script');
    s.src = '/assets/vendor/zxing.min.js';
    s.onload = () => ok(window.ZXing);
    s.onerror = () => { zxLoad = null; fail(new Error('Không tải được bộ đọc mã vạch')); };
    document.head.append(s);
  }));
  const makeReader = async () => {
    const Z = await loadZx();
    const hints = new Map();
    hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.EAN_8, Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.ITF, Z.BarcodeFormat.CODABAR, Z.BarcodeFormat.QR_CODE, Z.BarcodeFormat.DATA_MATRIX]);
    hints.set(Z.DecodeHintType.TRY_HARDER, true);
    return new Z.BrowserMultiFormatReader(hints);
  };

  let stream = null, loopTimer = 0, detector = null, lastRead = '';
  // Mã số 8 / 12 / 13 chữ số (EAN-8, UPC-A, EAN-13) phải đúng số kiểm tra — khung hình mờ hay bị đọc sai một vài vạch.
  const checkDigitOk = (t) => {
    if (!/^\d+$/.test(t) || ![8, 12, 13].includes(t.length)) return true;
    const d = t.split('').map(Number), last = d.pop();
    const sum = d.reverse().reduce((a, x, i) => a + x * (i % 2 ? 1 : 3), 0);
    return (10 - (sum % 10)) % 10 === last;
  };
  const stopCam = () => {
    camOn = false; clearTimeout(loopTimer);
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
    video.srcObject = null; view.hidden = true; camBtn.lastChild.textContent = 'Bật camera';
  };

  // Đọc mã từ một ảnh (File / Blob) — giải mã tại chỗ rồi bỏ ảnh, không gửi đi đâu.
  // Dùng data URL (không dùng blob:) vì chính sách bảo mật của trang chỉ cho ảnh từ 'self' và data:.
  const readDataUrl = (blob) => new Promise((ok, fail) => {
    const fr = new FileReader();
    fr.onload = () => ok(fr.result);
    fr.onerror = () => fail(fr.error);
    fr.readAsDataURL(blob);
  });
  const decodeImage = async (blob) => {
    say('Đang đọc mã vạch…');
    out.replaceChildren();
    try {
      const [r, url] = await Promise.all([makeReader(), readDataUrl(blob)]);
      const res = await Promise.race([r.decodeFromImageUrl(url), new Promise((_, no) => setTimeout(() => no(new Error('timeout')), 10000))]);
      r.reset();
      lookup(res.getText());
    } catch (e) {
      say('Không đọc được mã vạch trong ảnh. Thử ảnh rõ nét hơn, cắt sát mã, hoặc gõ số mã vạch vào ô bên dưới.', true);
    }
  };

  // Giải mã một khung hình: thử trình đọc có sẵn của trình duyệt (nếu có), rồi ZXing trên toàn khung và trên dải giữa phóng to
  // (webcam thường mờ, mã nhỏ nên phóng to dải giữa giúp đọc được nhiều hơn).
  const canvas = document.createElement('canvas');
  const zxTry = (Z, r, w, h, sx, sy, sw, sh) => {
    canvas.width = w; canvas.height = h;
    const g = canvas.getContext('2d', { willReadFrequently: true });
    g.imageSmoothingQuality = 'high';
    g.drawImage(video, sx, sy, sw, sh, 0, 0, w, h);
    try { return r.decodeBitmap(new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(canvas)))).getText(); } catch (e) { return null; }
  };
  const decodeFrame = async (Z, r) => {
    const vw = video.videoWidth, vh = video.videoHeight;
    if (!vw || !vh) return null;
    if (detector) {
      try { const f = await detector.detect(video); if (f.length) return f[0].rawValue; } catch (e) { detector = null; }
    }
    const k = Math.min(1, 1280 / vw);
    let t = zxTry(Z, r, Math.round(vw * k), Math.round(vh * k), 0, 0, vw, vh);
    if (t) return t;
    const bw = Math.round(vw * 0.8), bh = Math.round(vh * 0.5);
    const up = Math.max(1, Math.min(3, 1600 / bw));
    return zxTry(Z, r, Math.round(bw * up), Math.round(bh * up), Math.round(vw * 0.1), Math.round(vh * 0.25), bw, bh);
  };

  const startCam = async () => {
    if (camOn) { stopCam(); say(''); return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say('Trình duyệt này không mở được camera (camera chỉ dùng được trên HTTPS hoặc localhost). Hãy tải ảnh lên hoặc gõ mã.', true); return; }
    try {
      const Z = await loadZx();
      reader = await makeReader();
      if ('BarcodeDetector' in window) { try { detector = new window.BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar', 'qr_code'] }); } catch (e) { detector = null; } }
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } } });
      video.srcObject = stream;
      await video.play();
      lastRead = '';
      view.hidden = false; camOn = true; camBtn.lastChild.textContent = 'Tắt camera';
      say('Đưa mã vạch vào giữa khung, cách camera khoảng 15–25 cm, đủ sáng. Màn hình điện thoại thì để sáng tối đa.');
      const tick = async () => {
        if (!camOn) return;
        const t = await decodeFrame(Z, reader);
        if (!camOn) return;
        if (t && checkDigitOk(t)) {
          if (t === lastRead) {   // phải đọc trùng 2 lần liên tiếp mới nhận, tránh đọc nhầm
            if (target && target.count) { lookup(t); lastRead = ''; loopTimer = setTimeout(tick, 1500); return; }   // đếm hàng: camera vẫn chạy, nghỉ một nhịp để không đếm trùng cùng một mã
            stopCam(); lookup(t); return;
          }
          lastRead = t;
        } else if (t === null) lastRead = '';
        loopTimer = setTimeout(tick, 120);
      };
      tick();
    } catch (e) {
      stopCam();
      say(e && e.name === 'NotAllowedError' ? 'Bạn chưa cho phép dùng camera. Hãy cấp quyền camera cho trang này, hoặc tải ảnh lên.' : 'Không mở được camera. Hãy tải ảnh lên hoặc gõ mã.', true);
    }
  };

  // ── tra mã ──
  const lookup = async (raw) => {
    const code = String(raw || '').trim();
    if (!code) return;
    if (target && target.count) {   // đếm hàng nhận theo đơn: trang tự cộng số thực nhận, hộp thoại giữ mở để quét tiếp
      const r = window.countScan ? window.countScan(code) : { ok: false, text: 'Trang này không hỗ trợ đếm bằng quét mã.' };
      say(r.text, !r.ok);
      manual.reset(); manual.code.focus();
      return;
    }
    out.replaceChildren();
    say('Đang tra mã ' + code + '…');
    let data;
    try {
      const res = await fetch('/scan/lookup?code=' + encodeURIComponent(code), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      data = await res.json();
      if (!res.ok) { say(data.error || 'Không tra được mã này.', true); return; }
    } catch (e) { say('Không kết nối được máy chủ.', true); return; }
    say('');
    if (target && target.fill) {   // quét để điền vào ô tìm kiếm của trang (kiểm kê): điền mã, đóng hộp thoại, tìm luôn
      const input = document.querySelector(target.fill);
      if (input) { input.value = data.code || code; dlg.close(); input.form.requestSubmit(); return; }
    }
    if (target) showAssign(data); else if (data.found) showItem(data); else showNew(data);
  };

  const postForm = (action, fields, btnText, cls) => {
    const f = el('form', { method: 'post', action });
    f.append(el('input', { type: 'hidden', name: 'csrf', value: dlg.dataset.csrf }));
    Object.entries(fields).forEach(([k, v]) => f.append(el('input', { type: 'hidden', name: k, value: v })));
    f.append(el('button', { type: 'submit', class: cls || '', text: btnText }));
    return f;
  };

  // Mã đã có ⇒ thẻ mặt hàng + việc muốn làm
  const showItem = (d) => {
    const it = d.item;
    const card = el('div', { class: 'scan-card' },
      el('h3', { text: it.sku + ' — ' + it.name }),
      el('span', { class: 'scan-code', text: 'Mã vạch ' + d.code + (it.category ? ' · ' + it.category : '') }),
      el('div', { class: 'scan-stock' },
        d.stockMine !== null ? el('span', {}, 'Tồn kho của bạn: ', el('b', { text: money(d.stockMine) + ' ' + it.unit })) : null,
        el('span', {}, 'Tổng các kho: ', el('b', { text: money(d.stockTotal) + ' ' + it.unit }))));
    const acts = el('div', { class: 'scan-actions' });
    if (d.actions.length) {
      card.append(el('span', { class: 'muted', text: 'Bạn muốn làm gì với mặt hàng này?' }));
      d.actions.forEach((a, i) => acts.append(el('a', { class: i ? 'btn btn-ghost' : 'btn', href: a.href, text: a.label })));
    }
    acts.append(el('a', { class: 'btn btn-ghost', href: d.viewHref, text: 'Xem trong Hàng hoá' }));
    card.append(acts);
    out.replaceChildren(card);
    acts.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => dlg.close()));
  };

  // Mã chưa có ⇒ quản lý gửi đề xuất mặt hàng mới cho quản trị duyệt
  const showNew = (d) => {
    if (d.pending) {
      out.replaceChildren(el('div', { class: 'scan-card' }, el('h3', { text: 'Đã có đề xuất cho mã này' }), el('span', { class: 'scan-code', text: d.code }),
        el('span', { text: d.pending.sku + ' — ' + d.pending.name + ' (kho ' + d.pending.kho + ') đang chờ quản trị duyệt.' })));
      return;
    }
    if (!d.canCreate) {
      out.replaceChildren(el('div', { class: 'scan-card' }, el('h3', { text: 'Mã vạch chưa có trong hệ thống' }), el('span', { class: 'scan-code', text: d.code }),
        el('span', { class: 'muted', text: 'Mã này chưa có trong hệ thống. Hãy báo quản lý kho của bạn để gửi đề xuất mặt hàng mới.' })));
      return;
    }
    const f = el('form', { method: 'post', action: '/proposals', class: 'form scan-new' });
    f.append(el('input', { type: 'hidden', name: 'csrf', value: dlg.dataset.csrf }), el('input', { type: 'hidden', name: 'code', value: d.code }));
    const field = (label, input, full) => el('label', { class: full ? 'full' : '' }, label, input);
    const sel = (name, rows, text, ph) => {
      const s = el('select', { name, required: '' });
      s.append(el('option', { value: '', text: ph }));
      rows.forEach((r) => s.append(el('option', { value: r.id, text: text(r) })));
      return s;
    };
    f.append(
      el('p', { class: 'full' }, el('b', { text: 'Mã vạch ' + d.code }), ' chưa có trong hệ thống. Điền thông tin và gửi đề xuất cho quản trị duyệt; được chấp nhận thì bạn lập phiếu nhập:'),
      field('SKU (mã hàng)', el('input', { name: 'sku', required: '', maxlength: '40', placeholder: 'VD: MLK-1L', autocomplete: 'off' })),
      field('Tên mặt hàng', el('input', { name: 'name', required: '', maxlength: '160', autocomplete: 'off' })),
      field('Đơn vị tính', sel('unit_id', d.units, (r) => r.ma + ' — ' + r.ten, 'Chọn đơn vị…')),
      field('Danh mục', sel('category_id', d.categories, (r) => r.ten, 'Chọn danh mục…')),
      field('Nhà cung cấp', sel('supplier_id', d.suppliers, (r) => r.ten + ' (' + r.ma + ')', d.suppliers.length ? 'Chọn nhà cung cấp…' : 'Chưa có nhà cung cấp'), true),
      el('label', { class: 'check full' }, el('input', { type: 'checkbox', name: 'track_batch', value: '1' }), ' Quản lý theo lô / hạn dùng'),
      el('div', { class: 'actions full' }, el('button', { type: 'submit', text: 'Gửi đề xuất cho quản trị' })));
    out.replaceChildren(f);
    f.querySelector('input[name=sku]').focus();
  };

  // Chế độ gán mã cho một mặt hàng có sẵn
  const showAssign = (d) => {
    const card = el('div', { class: 'scan-card' }, el('h3', { text: 'Mã vạch ' + d.code }));
    if (d.found && String(d.item.id) === String(target.id)) {
      card.append(el('span', { class: 'muted', text: 'Mã này đã gắn cho mặt hàng này rồi.' }));
    } else if (d.found) {
      card.append(el('span', { class: 'scan-code', text: 'Đã gắn cho ' + d.item.sku + ' — ' + d.item.name }), el('span', { class: 'muted', text: 'Mỗi mã vạch chỉ gắn cho một mặt hàng. Gỡ mã khỏi mặt hàng kia trước nếu muốn chuyển sang đây.' }));
    } else {
      card.append(el('span', { text: 'Chưa gắn cho mặt hàng nào. Lưu mã này cho ' + target.label + '?' }), postForm('/items/barcode', { item_id: target.id, code: d.code }, 'Lưu mã vạch'));
    }
    out.replaceChildren(card);
  };

  // ── mở hộp thoại ──
  const open = (tgt) => {
    target = tgt || null;
    out.replaceChildren(); say(''); manual.reset();
    title.textContent = target && target.count ? 'Quét để đếm hàng nhận' : target && !target.fill ? 'Gán mã vạch' : 'Quét mã vạch';
    sub.replaceChildren(document.createTextNode(target && target.count ? 'Quét lần lượt từng thùng / món: mỗi mã quét được tăng số «Thực nhận» lên 1. Camera giữ mở để quét tiếp.' : target && target.fill ? 'Quét mã để tìm đúng dòng hàng cần đếm.' : target ? target.label + '. Quét hoặc nhập mã để gán cho mặt hàng này.' : defaultSub));
    if (target && target.code) sub.append(document.createTextNode(' Mã hiện tại: ' + target.code + ' '), postForm('/items/barcode', { item_id: target.id, code: '' }, 'Gỡ mã', 'btn-ghost'));
    dlg.showModal();
    manual.code.focus();
  };

  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-scan-open]')) { open(null); return; }
    if (ev.target.closest('[data-scan-count]')) { open({ count: true }); return; }
    const f = ev.target.closest('[data-scan-fill]');
    if (f) { open({ fill: f.dataset.scanFill }); return; }
    const a = ev.target.closest('[data-scan-assign]');
    if (a) open({ id: a.dataset.scanAssign, label: a.dataset.scanLabel || '', code: a.dataset.scanCode || '' });
  });
  dlg.addEventListener('close', stopCam);
  camBtn.addEventListener('click', startCam);
  manual.addEventListener('submit', (ev) => { ev.preventDefault(); lookup(manual.code.value); });
  $('[data-scan-file]').addEventListener('change', (ev) => { const f = ev.target.files[0]; ev.target.value = ''; if (f) decodeImage(f); });

  // Dán ảnh (Ctrl+V) và kéo thả ảnh
  document.addEventListener('paste', (ev) => {
    if (!dlg.open) return;
    const f = [...((ev.clipboardData && ev.clipboardData.files) || [])].find((x) => x.type.startsWith('image/'));
    if (f) { ev.preventDefault(); decodeImage(f); }
  });
  ['dragover', 'dragenter'].forEach((t) => drop.addEventListener(t, (ev) => { ev.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, () => drop.classList.remove('over')));
  drop.addEventListener('drop', (ev) => { ev.preventDefault(); const f = [...ev.dataTransfer.files].find((x) => x.type.startsWith('image/')); if (f) decodeImage(f); });
})();
