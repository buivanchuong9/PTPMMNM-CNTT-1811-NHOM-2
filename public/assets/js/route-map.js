// Bản đồ lộ trình điều chuyển: xe chạy theo đường bộ từ kho đi tới kho đến trong 5 giây (minh hoạ, không phải GPS).
// Chế độ giao hàng (mode = delivery): từ kho tới địa chỉ khách, xe máy / ô tô nhỏ / xe tải; vị trí khách tìm theo địa chỉ trên OpenStreetMap, không tìm được thì lấy gần đúng theo tỉnh.
// Nhiều xe cùng đợt nối đuôi nhau; xe hỏng dừng giữa đường kèm cảnh báo; xe đã giao đứng ở kho đến; xe bị thu hồi chạy ngược về kho đi.
(function () {
  // Leaflet chỉ nạp khi có bản đồ trên trang — trang khác không tốn thêm dung lượng
  var leaflet = null;
  function loadLeaflet() {
    if (window.L) { return Promise.resolve(); }
    return leaflet || (leaflet = new Promise(function (ok, fail) {
      var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = '/assets/vendor/leaflet/leaflet.css'; document.head.appendChild(css);
      var js = document.createElement('script'); js.src = '/assets/vendor/leaflet/leaflet.js'; js.onload = ok; js.onerror = fail; document.head.appendChild(js);
    }));
  }

  // gọi lúc tải trang và sau mỗi lần đổi trang không reload (app.js → initPage)
  window.initRouteMap = function () {
    var el = document.getElementById('route-map');
    if (!el || el.dataset.ready) { return; }
    el.dataset.ready = '1';
    loadLeaflet().then(function () { build(el); }, function () { delete el.dataset.ready; });
  };

  var TRUCK = '<svg viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg>';
  var MOTO = '<svg viewBox="0 0 24 24"><circle cx="5.5" cy="16.5" r="3"/><circle cx="18.5" cy="16.5" r="3"/><path d="M5.5 16.5l3.5-7h4.5l2.5 4.5M9 9.5L7.5 6.5H5M13.5 9.5l1.5-3h3"/></svg>';
  var CAR = '<svg viewBox="0 0 24 24"><path d="M3 16v-3.5L5 8h14l2 4.5V16z"/><path d="M3 12.5h18"/><circle cx="7.5" cy="17" r="1.8"/><circle cx="16.5" cy="17" r="1.8"/></svg>';
  var ICONS = { MAY: MOTO, OTO_NHO: CAR, TAI: TRUCK };
  var WARN = '<svg viewBox="0 0 24 24"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/></svg>';
  var GAP = 0.09;   // khoảng cách giữa hai xe nối đuôi (tính theo phần lộ trình)

  function build(el) {
  if (!document.body.contains(el)) { return; }   // đã chuyển sang trang khác trong lúc nạp Leaflet
  var r = JSON.parse(el.dataset.route), DURATION = 5000, delivery = r.mode === 'delivery';
  var a = [r.from.lat, r.from.lng], b = [r.to.lat, r.to.lng];

  var map = L.map(el, { scrollWheelZoom: false });
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, referrerPolicy: 'origin', attribution: '&copy; OpenStreetMap' }).addTo(map);
  if (delivery) {
    map.fitBounds(L.latLngBounds([a, b]).pad(0.35));   // giao hàng nội thành / liên tỉnh: khung vừa quãng đường, cho phóng to xem đường
  } else {
    // chỉ hiện lãnh thổ Việt Nam: khung nhìn cố định, không kéo ra ngoài hay thu nhỏ hơn mức vừa khung
    var vn = L.latLngBounds([8.2, 102.1], [23.5, 109.6]);
    map.fitBounds(vn);
    map.setMinZoom(map.getZoom());
    map.setMaxBounds(vn.pad(0.1));
  }

  // mọi kho trong hệ thống; kho trùng toạ độ (cùng tỉnh) được xếp lệch nhau một chút để không chồng lên nhau
  var seen = {};
  r.all.forEach(function (w) {
    var key = w.lat + ',' + w.lng, n = seen[key] = (seen[key] || 0) + 1, off = (n - 1) * 0.12;
    var on = w.code === r.from.code || w.code === r.to.code, cls = 'route-dot' + (w.code === r.from.code ? ' on' : w.code === r.to.code ? ' dest' : '');
    L.marker([w.lat + off, w.lng + off], { icon: L.divIcon({ className: '', html: '<div class="' + cls + '"></div>', iconSize: [16, 16], iconAnchor: [8, 8] }), zIndexOffset: on ? 500 : 0 })
      .bindTooltip(w.code + ' · ' + w.name, { permanent: on, direction: 'right', offset: [8, 0] }).addTo(map);
  });

  // điểm giao hàng của khách (chế độ giao hàng)
  var destMarker = null;
  if (delivery) {
    destMarker = L.marker(b, { icon: L.divIcon({ className: '', html: '<div class="route-dot dest"></div>', iconSize: [16, 16], iconAnchor: [8, 8] }), zIndexOffset: 500 })
      .bindTooltip((r.to.name || 'Khách') + (r.to.addr ? ' · ' + r.to.addr : ''), { permanent: true, direction: 'right', offset: [8, 0] }).addTo(map);
  }

  // mỗi xe một biểu tượng (kèm biển số) và một vệt đường phía sau; xe của phiếu đang xem được tô đậm
  var trucks = (r.trucks || []).map(function (t) {
    var cls = 'route-truck' + (t.me ? ' me' : '') + (t.state === 'broken' ? ' broken' : '') + (t.state === 'returning' ? ' returning' : '');
    var marker = L.marker(a, { interactive: false, zIndexOffset: t.me ? 1100 : 1000, icon: L.divIcon({
      className: '', iconSize: [34, 34], iconAnchor: [17, 17],
      html: '<div class="' + cls + '">' + (t.state === 'broken' ? WARN : (ICONS[t.kind] || TRUCK)) + '</div><span class="route-plate">' + t.plate + '</span>' }) }).addTo(map);
    if (t.state === 'broken') { marker.options.interactive = true; marker.bindTooltip('Xe ' + t.plate + ' hỏng: ' + t.why, { direction: 'top', offset: [0, -16] }); }
    if (t.state === 'returning') { marker.options.interactive = true; marker.bindTooltip('Xe ' + t.plate + ' bị thu hồi — quay về kho đi', { direction: 'top', offset: [0, -16] }); }
    var trail = L.polyline([], { color: t.state === 'broken' ? '#dc2626' : t.state === 'returning' ? '#f59e0b' : t.me ? '#2563eb' : '#60a5fa', weight: t.me ? 5 : 4, dashArray: t.state === 'returning' ? '2 8' : null }).addTo(map);
    return { t: t, marker: marker, trail: trail };
  });

  var line = null;

  // các thành phố dọc quốc lộ 1 giữa hai kho: ép tuyến đi theo trục Bắc – Nam trong nước, không để dịch vụ chỉ đường tự cắt qua Lào / Campuchia
  var SPINE = [[21.03, 105.85], [20.25, 105.97], [19.81, 105.78], [18.68, 105.68], [18.34, 105.91], [16.82, 107.10], [16.46, 107.59], [16.05, 108.21], [15.12, 108.80], [13.78, 109.22], [12.24, 109.19], [10.78, 106.70]];
  function near(p) { var k = 0, d = 1e9; SPINE.forEach(function (s, i) { var x = Math.hypot(s[0] - p[0], s[1] - p[1]); if (x < d) { d = x; k = i; } }); return k; }
  var via = [];
  function plan() {   // các điểm ghé dọc quốc lộ 1 giữa hai đầu (chỉ có tác dụng với tuyến Bắc – Nam dài; tuyến ngắn thì chỉ hai đầu)
    var i = near(a), j = near(b), mid = i <= j ? SPINE.slice(i, j + 1) : SPINE.slice(j, i + 1).reverse();
    via = [a].concat(mid.filter(function (m) { return Math.hypot(m[0] - a[0], m[1] - a[1]) > 0.4 && Math.hypot(m[0] - b[0], m[1] - b[1]) > 0.4; }), [b]);
  }
  plan();
  function fallback() { return via; }

  // đường đi mượt: lấy mẫu lại theo quãng đường để xe chạy đều tay
  function prepare(pts) {
    var cum = [0];
    for (var i = 1; i < pts.length; i++) { cum.push(cum[i - 1] + Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1])); }
    return { pts: pts, cum: cum, total: cum[cum.length - 1] };
  }
  function at(route, f) {   // f ∈ [0,1] → toạ độ và số điểm đã đi qua
    var d = f * route.total, lo = 0, hi = route.cum.length - 1;
    while (hi - lo > 1) { var m = (lo + hi) >> 1; if (route.cum[m] <= d) { lo = m; } else { hi = m; } }
    var seg = route.cum[hi] - route.cum[lo] || 1, w = (d - route.cum[lo]) / seg, p = route.pts[lo], q = route.pts[hi];
    return { p: [p[0] + (q[0] - p[0]) * w, p[1] + (q[1] - p[1]) * w], upto: lo };
  }

  var route = null, raf = 0;
  // xe thứ i xuất phát chậm hơn xe dẫn đầu i × GAP; xe hỏng dừng tại vị trí đã báo; xe đã giao nằm sẵn ở kho đến
  function place(k, e) {
    var span = 1 + GAP * (trucks.length - 1), m = e * span;
    trucks.forEach(function (tr, n) {
      var go = Math.max(0, Math.min(1, m - GAP * n));
      var f = tr.t.state === 'arrived' ? 1 : tr.t.state === 'returning' ? 1 - go : go;   // xe thu hồi: xuất phát từ phía kho đến, chạy ngược về kho đi
      if (tr.t.state === 'broken') { f = Math.min(f, tr.t.at); }
      var s = at(route, f);
      tr.marker.setLatLng(s.p);
      if (tr.t.state === 'returning') { tr.trail.setLatLngs(go > 0 ? [s.p].concat(route.pts.slice(s.upto + 1)) : []); }   // vệt đường đã chạy ngược
      else { tr.trail.setLatLngs(f > 0 ? route.pts.slice(0, s.upto + 1).concat([s.p]) : []); }
    });
  }
  function run() {
    if (!route) { return; }
    cancelAnimationFrame(raf);
    var start = performance.now();
    (function step(now) {
      var k = Math.min(1, (now - start) / DURATION), e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;   // chạy êm đầu và cuối
      place(k, e);
      if (k < 1) { raf = requestAnimationFrame(step); }
    })(start);
  }
  function show(pts) {
    route = prepare(pts);
    if (line) { map.removeLayer(line); }
    line = L.polyline(pts, { color: '#94a3b8', weight: 4, dashArray: '6 8' }).addTo(map);
    trucks.forEach(function (tr) { tr.trail.bringToFront(); });
    if (delivery) { map.fitBounds(line.getBounds().pad(0.2)); }
    if (delivery && r.arrived) { place(1, 1); } else { run(); }   // đã giao đến nơi: xe nằm sẵn ở đích, «Chạy lại» để xem lại
  }

  document.getElementById('route-replay').addEventListener('click', run);

  // chỉ đường theo đường bộ thật (OSRM); lỗi thì vẽ đường dự phòng
  function start() {
    plan();
    var ctl = new AbortController(), timer = setTimeout(function () { ctl.abort(); }, 8000);
    fetch('https://router.project-osrm.org/route/v1/driving/' + via.map(function (p) { return p[1] + ',' + p[0]; }).join(';') + '?overview=full&geometries=geojson', { signal: ctl.signal })
      .then(function (res) { return res.json(); })
      .then(function (d) {
        if (!d.routes || !d.routes[0]) { throw 0; }
        var c = d.routes[0].geometry.coordinates, step = Math.max(1, Math.floor(c.length / 800)), pts = [];
        for (var i = 0; i < c.length; i += step) { pts.push([c[i][1], c[i][0]]); }
        pts.push([c[c.length - 1][1], c[c.length - 1][0]]);
        show(pts);
      })
      .catch(function () { show(fallback()); })
      .then(function () { clearTimeout(timer); });
  }

  // số ngẫu nhiên cố định theo địa chỉ (xem lại cùng phiếu luôn ra cùng một điểm)
  function rng(seed) { var t = seed >>> 0; return function () { t += 0x6D2B79F5; var x = Math.imul(t ^ t >>> 15, 1 | t); x ^= x + Math.imul(x ^ x >>> 7, 61 | x); return ((x ^ x >>> 14) >>> 0) / 4294967296; }; }

  // giao hàng: tìm phường / xã của địa chỉ trên Nominatim (OpenStreetMap) rồi chọn NGẪU NHIÊN một điểm giao trong phường đó (demo); không tìm được thì dùng vị trí gần đúng theo tỉnh do máy chủ gửi
  if (delivery && (r.to.wardQuery || r.to.query)) {
    var gctl = new AbortController(), gtimer = setTimeout(function () { gctl.abort(); }, 6000);
    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=vn&q=' + encodeURIComponent(r.to.wardQuery || r.to.query), { signal: gctl.signal })
      .then(function (res) { return res.json(); })
      .then(function (d) {
        if (!d || !d[0]) { return; }
        var la = parseFloat(d[0].lat), lo = parseFloat(d[0].lon), bb = (d[0].boundingbox || []).map(parseFloat), rnd = rng(r.to.seed || 1);
        if (!(la > 8 && la < 24 && lo > 102 && lo < 110)) { return; }
        var s0 = la - 0.008, n0 = la + 0.008, w0 = lo - 0.008, e0 = lo + 0.008;   // mặc định quanh tâm ±~0,9 km
        if (bb.length === 4 && bb[1] - bb[0] > 0.002 && bb[1] - bb[0] < 0.1) { s0 = bb[0]; n0 = bb[1]; w0 = bb[2]; e0 = bb[3]; }   // có khung phường thì chọn trong khung
        b = [s0 + (n0 - s0) * (0.2 + 0.6 * rnd()), w0 + (e0 - w0) * (0.2 + 0.6 * rnd())];
        destMarker.setLatLng(b);
        map.fitBounds(L.latLngBounds([a, b]).pad(0.35));
      })
      .catch(function () {})
      .then(function () { clearTimeout(gtimer); start(); });
  } else {
    start();
  }
  }

  window.initRouteMap();
})();
