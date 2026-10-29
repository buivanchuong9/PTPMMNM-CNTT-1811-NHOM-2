// Bản đồ lộ trình điều chuyển: xe chạy theo đường bộ từ kho đi tới kho đến trong 5 giây (minh hoạ, không phải GPS).
(function () {
  var el = document.getElementById('route-map');
  if (!el || !window.L) { return; }
  var r = JSON.parse(el.dataset.route), DURATION = 5000;
  var a = [r.from.lat, r.from.lng], b = [r.to.lat, r.to.lng];

  var map = L.map(el, { scrollWheelZoom: false });
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, referrerPolicy: 'origin', attribution: '&copy; OpenStreetMap' }).addTo(map);
  // chỉ hiện lãnh thổ Việt Nam: khung nhìn cố định, không kéo ra ngoài hay thu nhỏ hơn mức vừa khung
  var vn = L.latLngBounds([8.2, 102.1], [23.5, 109.6]);
  map.fitBounds(vn);
  map.setMinZoom(map.getZoom());
  map.setMaxBounds(vn.pad(0.1));

  // mọi kho trong hệ thống; kho trùng toạ độ (cùng tỉnh) được xếp lệch nhau một chút để không chồng lên nhau
  var seen = {};
  r.all.forEach(function (w) {
    var key = w.lat + ',' + w.lng, n = seen[key] = (seen[key] || 0) + 1, off = (n - 1) * 0.12;
    var on = w.code === r.from.code || w.code === r.to.code, cls = 'route-dot' + (w.code === r.from.code ? ' on' : w.code === r.to.code ? ' dest' : '');
    L.marker([w.lat + off, w.lng + off], { icon: L.divIcon({ className: '', html: '<div class="' + cls + '"></div>', iconSize: [16, 16], iconAnchor: [8, 8] }), zIndexOffset: on ? 500 : 0 })
      .bindTooltip(w.code + ' · ' + w.name, { permanent: on, direction: 'right', offset: [8, 0] }).addTo(map);
  });

  var line = null, trail = L.polyline([], { color: '#2563eb', weight: 5 }).addTo(map);
  var truck = L.marker(a, { interactive: false, zIndexOffset: 1000, icon: L.divIcon({
    className: '', iconSize: [34, 34], iconAnchor: [17, 17],
    html: '<div class="route-truck"><svg viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg></div>' }) }).addTo(map);

  // các thành phố dọc quốc lộ 1 giữa hai kho: ép tuyến đi theo trục Bắc – Nam trong nước, không để dịch vụ chỉ đường tự cắt qua Lào / Campuchia
  var SPINE = [[21.03, 105.85], [20.25, 105.97], [19.81, 105.78], [18.68, 105.68], [18.34, 105.91], [16.82, 107.10], [16.46, 107.59], [16.05, 108.21], [15.12, 108.80], [13.78, 109.22], [12.24, 109.19], [10.78, 106.70]];
  function near(p) { var k = 0, d = 1e9; SPINE.forEach(function (s, i) { var x = Math.hypot(s[0] - p[0], s[1] - p[1]); if (x < d) { d = x; k = i; } }); return k; }
  var i = near(a), j = near(b), mid = i <= j ? SPINE.slice(i, j + 1) : SPINE.slice(j, i + 1).reverse();
  var via = [a].concat(mid.filter(function (m) { return Math.hypot(m[0] - a[0], m[1] - a[1]) > 0.4 && Math.hypot(m[0] - b[0], m[1] - b[1]) > 0.4; }), [b]);
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
  function run() {
    if (!route) { return; }
    cancelAnimationFrame(raf);
    var start = performance.now();
    (function step(now) {
      var k = Math.min(1, (now - start) / DURATION), e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;   // chạy êm đầu và cuối
      var s = at(route, e);
      truck.setLatLng(s.p);
      trail.setLatLngs(route.pts.slice(0, s.upto + 1).concat([s.p]));
      if (k < 1) { raf = requestAnimationFrame(step); }
    })(start);
  }
  function show(pts) {
    route = prepare(pts);
    if (line) { map.removeLayer(line); }
    line = L.polyline(pts, { color: '#94a3b8', weight: 4, dashArray: '6 8' }).addTo(map);
    trail.bringToFront();
    run();
  }

  document.getElementById('route-replay').addEventListener('click', run);
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
})();
