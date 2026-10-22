// Giao diện sáng / tối dùng chung cho mọi trang (StockFlow, đăng nhập, Finova).
// Nạp đồng bộ ở <head> để đặt data-theme trước khi trang vẽ lần đầu (không nháy). CSP chỉ cho script từ 'self' nên không dùng inline.
// Lựa chọn lưu ở localStorage (khoá sf-theme): 'light' | 'dark'; không có khoá = theo hệ điều hành.
(function () {
  var KEY = 'sf-theme';
  var root = document.documentElement;
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var timer = null;

  function stored() {
    try {
      var t = localStorage.getItem(KEY) || localStorage.getItem('finova-theme');   // finova-theme: khoá cũ của trang Finova
      return t === 'dark' || t === 'light' ? t : null;
    } catch (e) { return null; }                                                    // localStorage có thể bị chặn
  }
  function resolve() { return stored() || (mq && mq.matches ? 'dark' : 'light'); }

  function mark() {
    var cur = root.getAttribute('data-theme'), saved = stored();
    var set = document.querySelectorAll('[data-theme-set]');
    for (var i = 0; i < set.length; i++) {
      var v = set[i].getAttribute('data-theme-set');
      set[i].setAttribute('aria-pressed', String(v === 'system' ? saved === null : v === cur && saved !== null));
    }
    var tg = document.querySelectorAll('[data-theme-toggle]');
    for (var j = 0; j < tg.length; j++) {
      tg[j].setAttribute('aria-pressed', String(cur === 'dark'));
      tg[j].setAttribute('title', cur === 'dark' ? 'Chuyển sang giao diện sáng' : 'Chuyển sang giao diện tối');
    }
  }

  function reduced() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }

  function commit(theme) {
    root.setAttribute('data-theme', theme);
    mark();
    document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
  }

  // Mờ chéo: View Transitions chụp trang cũ/mới rồi hoà trộn một lần (mọi thành phần đổi màu cùng lúc, không giật).
  function apply(theme, animate) {
    if (!animate || reduced()) { commit(theme); return; }
    if (document.startViewTransition) {
      document.startViewTransition(function () { commit(theme); });
      return;
    }
    root.classList.add('theme-anim');                      // trình duyệt cũ: chuyển màu từng phần tử trong ~0,5 giây
    clearTimeout(timer);
    timer = setTimeout(function () { root.classList.remove('theme-anim'); }, 500);
    commit(theme);
  }

  function set(mode) {                                       // 'light' | 'dark' | 'system'
    try {
      if (mode === 'system') { localStorage.removeItem(KEY); localStorage.removeItem('finova-theme'); }
      else { localStorage.setItem(KEY, mode); }
    } catch (e) { /* vẫn đổi cho phiên hiện tại */ }
    var next = mode === 'system' ? (mq && mq.matches ? 'dark' : 'light') : mode;
    apply(next, true);
  }

  root.setAttribute('data-theme', resolve());

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-theme-toggle], [data-theme-set]') : null;
    if (!t) { return; }
    if (t.hasAttribute('data-theme-toggle')) { set(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); }
    else { set(t.getAttribute('data-theme-set')); }
  });
  if (mq && mq.addEventListener) {
    mq.addEventListener('change', function () { if (!stored()) { apply(resolve(), true); } });
  }
  window.addEventListener('storage', function (e) { if (e.key === KEY) { apply(resolve(), true); } });   // đồng bộ giữa các tab
  document.addEventListener('DOMContentLoaded', mark);
  window.sfTheme = { set: set, get: function () { return root.getAttribute('data-theme'); } };
})();
