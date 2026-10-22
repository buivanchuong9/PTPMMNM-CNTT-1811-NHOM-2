// Finova · Orders — hành vi trang: tải dữ liệu, tìm kiếm, lọc, sắp xếp, phân trang, xem/sửa/tạo/xoá đơn, xuất CSV, giao diện sáng/tối.
import {
  PAGE_SIZE, SAMPLE_TODAY, STATUSES, PAYMENTS, DATE_FILTERS, SORTS, SUMMARY, NOTIFICATIONS,
  isoAddDays, orderId, loadOrders,
} from './data.js';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ic = (name, cls = '') => `<svg class="fi ${cls}" aria-hidden="true" focusable="false"><use href="#fi-${name}"/></svg>`;
const int = (n) => Number(n).toLocaleString('en-US');
const money = (n) => '$' + int(n);
const fmtDate = (iso) => new Date(iso + 'T00:00:00Z').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
const itemsLabel = (n) => `${n} ${n === 1 ? "item" : "items"}`;
const tone = (status) => 'is-' + status.toLowerCase();

const state = { all: [], base: null, q: '', date: '', status: '', payment: '', sort: 'newest', page: 1, phase: 'loading', error: '' };

/* ───────── thông báo (toast) ───────── */
function toast(message, kind = 'success') {
  const el = document.createElement('div');
  el.className = 'fv-toast is-' + kind;
  el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  el.innerHTML = `${ic(kind === 'error' ? 'alert' : 'check-circle')}<span>${esc(message)}</span>`;
  $('#fv-toasts').append(el);
  setTimeout(() => { el.classList.add('is-out'); setTimeout(() => el.remove(), 200); }, 3600);
}

/* ───────── lọc · sắp xếp · phân trang ───────── */
function filteredRows() {
  const q = state.q.trim().toLowerCase();
  const df = DATE_FILTERS.find((d) => d.value === state.date);
  const min = df?.days ? isoAddDays(SAMPLE_TODAY, -df.days) : null;
  const rows = state.all.filter((o) =>
    (!q || o.id.toLowerCase().includes(q) || o.customer.toLowerCase().includes(q))
    && (!state.status || o.status === state.status)
    && (!state.payment || o.payment === state.payment)
    && (!min || o.date >= min)
    && (!df?.year || o.date.startsWith(String(df.year))));
  const byNewest = (a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : b.n - a.n);
  const sorters = {
    newest: byNewest,
    oldest: (a, b) => -byNewest(a, b),
    'amount-desc': (a, b) => b.amount - a.amount || byNewest(a, b),
    'amount-asc': (a, b) => a.amount - b.amount || byNewest(a, b),
    'customer-asc': (a, b) => a.customer.localeCompare(b.customer) || byNewest(a, b),
  };
  return rows.sort(sorters[state.sort] || byNewest);
}

const countBy = (rows, status) => rows.reduce((n, o) => n + (o.status === status ? 1 : 0), 0);

/* ───────── hiển thị ───────── */
function renderStats() {
  const box = $('#fv-stats');
  if (state.phase !== 'ready') { return; }
  box.setAttribute('aria-busy', 'false');
  box.innerHTML = SUMMARY.map((s) => {
    const delta = s.status ? countBy(state.all, s.status) - state.base[s.status] : state.all.length - state.base.total;
    const up = s.change >= 0;
    return `<article class="fv-stat">
      <div class="fv-stat-top"><span class="fv-stat-ic is-${s.tone}">${ic(s.icon)}</span><h3>${esc(s.label)}</h3>
        <button class="fv-icon-btn fv-stat-more" type="button" data-soon="${esc(s.label)} options" aria-label="${esc(s.label)} options">${ic('dots-h')}</button></div>
      <p class="fv-stat-num">${int(s.value + delta)}</p>
      <p class="fv-stat-foot"><span class="fv-delta ${up ? 'is-up' : 'is-down'}">${ic(up ? 'arrow-up' : 'arrow-down')}${Math.abs(s.change)}%</span><span>From Last weeks</span></p>
    </article>`;
  }).join('');
}

function rowHtml(o) {
  return `<tr data-id="${esc(o.id)}">
    <td data-label="Order ID"><button class="fv-idlink" type="button" data-act="view">${esc(o.id)}</button></td>
    <td data-label="Customer" class="fv-strong">${esc(o.customer)}</td>
    <td data-label="Items">${itemsLabel(o.items)}</td>
    <td data-label="Amount" class="fv-amount">${money(o.amount)}</td>
    <td data-label="Payment">${esc(o.payment)}</td>
    <td data-label="Date">${fmtDate(o.date)}</td>
    <td data-label="Status"><span class="fv-badge ${tone(o.status)}">${esc(o.status)}</span></td>
    <td data-label="Actions"><div class="fv-acts">
      <button class="fv-act is-view" type="button" data-act="view" data-tip="View" aria-label="View order ${esc(o.id)}">${ic('eye')}</button>
      <button class="fv-act is-edit" type="button" data-act="edit" data-tip="Edit" aria-label="Edit order ${esc(o.id)}">${ic('pencil')}</button>
      <button class="fv-act is-more" type="button" data-act="more" data-tip="More" aria-label="More actions for ${esc(o.id)}" aria-haspopup="menu" aria-expanded="false">${ic('dots-v')}</button>
    </div></td>
  </tr>`;
}

function pageList(page, pages) {
  if (pages <= 7) { return Array.from({ length: pages }, (_, i) => i + 1); }
  if (page <= 4) { return [1, 2, 3, 4, 5, '…', pages]; }
  if (page >= pages - 3) { return [1, '…', pages - 4, pages - 3, pages - 2, pages - 1, pages]; }
  return [1, '…', page - 1, page, page + 1, '…', pages];
}

function renderTable() {
  const tbody = $('#fv-tbody');
  const stateBox = $('#fv-state');
  const info = $('#fv-pager-info');
  const nav = $('#fv-pager-nav');
  stateBox.hidden = true;
  $('#fv-table-wrap').classList.remove('is-empty');

  if (state.phase === 'loading') {
    tbody.innerHTML = Array.from({ length: PAGE_SIZE }, () => `<tr class="is-skeleton">${'<td><span class="sk sk-cell"></span></td>'.repeat(8)}</tr>`).join('');
    info.textContent = '';
    nav.innerHTML = '';
    return;
  }
  if (state.phase === 'error') {
    tbody.innerHTML = '';
    stateBox.hidden = false;
    $('#fv-table-wrap').classList.add('is-empty');
    stateBox.innerHTML = `<div class="fv-state-ic is-err">${ic('alert')}</div><h3>Something went wrong</h3><p>${esc(state.error)}</p>
      <button class="fv-btn fv-btn-primary" type="button" id="btn-retry">${ic('refresh')}Try again</button>`;
    info.textContent = '';
    nav.innerHTML = '';
    return;
  }

  const rows = filteredRows();
  const pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  state.page = Math.min(Math.max(1, state.page), pages);
  const start = (state.page - 1) * PAGE_SIZE;
  const slice = rows.slice(start, start + PAGE_SIZE);

  if (!rows.length) {
    tbody.innerHTML = '';
    stateBox.hidden = false;
    $('#fv-table-wrap').classList.add('is-empty');
    stateBox.innerHTML = `<div class="fv-state-ic">${ic('inbox')}</div><h3>No orders found</h3><p>Try a different search or clear the filters.</p>
      <button class="fv-btn fv-btn-ghost" type="button" id="btn-clear">Clear filters</button>`;
    info.textContent = 'No orders to show';
    nav.innerHTML = '';
    return;
  }

  tbody.innerHTML = slice.map(rowHtml).join('');
  info.innerHTML = `Showing ${int(start + 1)}-${int(start + slice.length)} of ${int(rows.length)} orders`;
  const btn = (label, icon, page, disabled) => `<button class="fv-pg" type="button" data-page="${page}" aria-label="${label}"${disabled ? ' disabled' : ''}>${ic(icon)}</button>`;
  nav.innerHTML = btn('First page', 'chevrons-left', 1, state.page === 1) + btn('Previous page', 'chevron-left', state.page - 1, state.page === 1)
    + pageList(state.page, pages).map((p) => (p === '…'
      ? '<span class="fv-pg-gap" aria-hidden="true">…</span>'
      : `<button class="fv-pg${p === state.page ? ' is-on' : ''}" type="button" data-page="${p}" aria-label="Page ${p}"${p === state.page ? ' aria-current="page"' : ''}>${int(p)}</button>`)).join('')
    + btn('Next page', 'chevron-right', state.page + 1, state.page === pages) + btn('Last page', 'chevrons-right', pages, state.page === pages);
}

const renderAll = () => { renderStats(); renderTable(); };

/* ───────── hộp thoại ───────── */
function openDialog(dlg) { if (!dlg.open) { dlg.showModal(); } }

function timeline(o) {
  const steps = { Pending: ['Order placed'], Shipped: ['Order placed', 'Packed', 'Shipped'], Delivered: ['Order placed', 'Packed', 'Shipped', 'Delivered'],
    Returned: ['Order placed', 'Packed', 'Shipped', 'Delivered', 'Returned'], Cancelled: ['Order placed', 'Cancelled'] }[o.status] || ['Order placed'];
  return `<ol class="fv-tl">${steps.map((s, i) => `<li class="${i === steps.length - 1 ? 'is-now' : ''}"><i></i><span>${esc(s)}</span></li>`).join('')}</ol>`;
}

function showView(o) {
  $('#dlg-view-body').innerHTML = `
    <div class="fv-dlg-head"><div><h2 id="dlg-view-title">${esc(o.id)}</h2><span class="fv-badge ${tone(o.status)}">${esc(o.status)}</span></div>
      <button class="fv-icon-btn" type="button" data-dlg-close aria-label="Close">${ic('x')}</button></div>
    <dl class="fv-dl">
      <div><dt>Customer</dt><dd>${esc(o.customer)}</dd></div><div><dt>Date</dt><dd>${fmtDate(o.date)}</dd></div>
      <div><dt>Items</dt><dd>${itemsLabel(o.items)}</dd></div><div><dt>Amount</dt><dd>${money(o.amount)}</dd></div>
      <div><dt>Payment</dt><dd>${esc(o.payment)}</dd></div><div><dt>Average per item</dt><dd>${money(Math.round(o.amount / o.items))}</dd></div>
    </dl>
    <h3 class="fv-sub">Progress</h3>${timeline(o)}
    <div class="fv-dlg-actions"><button class="fv-btn fv-btn-ghost" type="button" data-dlg-close>Close</button>
      <button class="fv-btn fv-btn-primary" type="button" data-edit-from-view="${esc(o.id)}">${ic('pencil')}Edit order</button></div>`;
  openDialog($('#dlg-view'));
}

const fillSelect = (sel, options, value) => { sel.innerHTML = options.map((v) => `<option${v === value ? ' selected' : ''}>${esc(v)}</option>`).join(''); };

let formMode = 'edit';
function showForm(o) {
  formMode = o ? 'edit' : 'new';
  const form = $('#order-form');
  const data = o || { id: '(auto)', customer: '', items: 1, amount: '', payment: PAYMENTS[0], status: 'Pending', date: SAMPLE_TODAY };
  $('#dlg-form-title').textContent = o ? `Edit ${o.id}` : 'New order';
  $('#form-submit').textContent = o ? 'Save changes' : 'Create order';
  form.elements.id.value = o ? o.id : '';
  form.elements.idView.value = data.id;
  form.elements.customer.value = data.customer;
  form.elements.items.value = data.items;
  form.elements.amount.value = data.amount;
  form.elements.date.value = data.date;
  fillSelect(form.elements.payment, PAYMENTS, data.payment);
  fillSelect(form.elements.status, STATUSES, data.status);
  $$('.fv-err', form).forEach((e) => { e.textContent = ''; });
  $$('[aria-invalid]', form).forEach((e) => e.removeAttribute('aria-invalid'));
  openDialog($('#dlg-form'));
  form.elements.customer.focus();
}

function validateForm(form) {
  const f = form.elements;
  const errors = {};
  if (!f.customer.value.trim()) { errors.customer = 'Customer name is required.'; }
  const items = Number(f.items.value);
  if (!Number.isInteger(items) || items < 1 || items > 99) { errors.items = 'Enter 1–99.'; }
  const amount = Number(f.amount.value);
  if (f.amount.value === '' || !(amount >= 0) || amount > 1000000) { errors.amount = 'Enter a valid amount.'; }
  $$('.fv-err', form).forEach((e) => { e.textContent = errors[e.dataset.err] || ''; });
  ['customer', 'items', 'amount'].forEach((k) => { if (errors[k]) { f[k].setAttribute('aria-invalid', 'true'); } else { f[k].removeAttribute('aria-invalid'); } });
  return { errors, items, amount };
}

function submitForm(ev) {
  ev.preventDefault();
  const form = ev.currentTarget;
  const { errors, items, amount } = validateForm(form);
  const bad = Object.keys(errors)[0];
  if (bad) { form.elements[bad].focus(); toast('Please fix the highlighted fields.', 'error'); return; }
  const f = form.elements;
  const patch = { customer: f.customer.value.trim(), items, amount: Math.round(amount), payment: f.payment.value, status: f.status.value, date: f.date.value || SAMPLE_TODAY };
  if (formMode === 'new') {
    const n = state.all.reduce((m, o) => Math.max(m, o.n), 0) + 1;
    state.all.unshift({ ...patch, n, id: orderId(n) });
    state.page = 1;
    toast(`Order ${orderId(n)} created.`);
  } else {
    const o = state.all.find((x) => x.id === f.id.value);
    if (!o) { toast('That order no longer exists.', 'error'); return; }
    Object.assign(o, patch);
    toast(`Order ${o.id} updated.`);
  }
  $('#dlg-form').close();
  renderAll();
}

let confirmAction = null;
function confirmDialog({ title, text, ok, danger = true, onOk }) {
  $('#dlg-confirm-title').textContent = title;
  $('#dlg-confirm-text').textContent = text;
  const btn = $('#confirm-ok');
  btn.textContent = ok;
  btn.className = 'fv-btn ' + (danger ? 'fv-btn-danger' : 'fv-btn-primary');
  confirmAction = onOk;
  openDialog($('#dlg-confirm'));
}

/* ───────── menu hành động của từng dòng ───────── */
const menu = $('#fv-rowmenu');
let menuFor = null;
function closeRowMenu() {
  if (menuFor) { $$('[data-act="more"][aria-expanded="true"]').forEach((b) => b.setAttribute('aria-expanded', 'false')); }
  menu.hidden = true;
  menuFor = null;
}
function openRowMenu(btn, order) {
  closeRowMenu();
  const open = order.status === 'Pending' || order.status === 'Shipped';
  menu.innerHTML = `<button type="button" role="menuitem" data-m="duplicate">${ic('copy')}Duplicate order</button>`
    + (open ? `<button type="button" role="menuitem" data-m="deliver">${ic('check-circle')}Mark as delivered</button>` : '')
    + (open ? `<button type="button" role="menuitem" data-m="cancel" class="is-danger">${ic('x')}Cancel order</button>` : '')
    + `<button type="button" role="menuitem" data-m="delete" class="is-danger">${ic('trash')}Delete order</button>`;
  menu.hidden = false;
  const r = btn.getBoundingClientRect();
  const w = menu.offsetWidth;
  const h = menu.offsetHeight;
  menu.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)) + 'px';
  menu.style.top = (r.bottom + h + 12 > window.innerHeight ? Math.max(8, r.top - h - 6) : r.bottom + 6) + 'px';
  btn.setAttribute('aria-expanded', 'true');
  menuFor = order.id;
  $('button', menu).focus();
}

function runMenu(action) {
  const o = state.all.find((x) => x.id === menuFor);
  closeRowMenu();
  if (!o) { return; }
  if (action === 'duplicate') {
    const n = state.all.reduce((m, x) => Math.max(m, x.n), 0) + 1;
    state.all.unshift({ ...o, n, id: orderId(n), status: 'Pending', date: SAMPLE_TODAY });
    state.page = 1;
    renderAll();
    toast(`Order duplicated as ${orderId(n)}.`);
  } else if (action === 'deliver') {
    o.status = 'Delivered';
    renderAll();
    toast(`Order ${o.id} marked as delivered.`);
  } else if (action === 'cancel') {
    confirmDialog({ title: `Cancel ${o.id}?`, text: `The order from ${o.customer} will be marked as Cancelled.`, ok: 'Cancel order',
      onOk: () => { o.status = 'Cancelled'; renderAll(); toast(`Order ${o.id} cancelled.`); } });
  } else if (action === 'delete') {
    confirmDialog({ title: `Delete ${o.id}?`, text: 'This removes the order from the list and cannot be undone.', ok: 'Delete order',
      onOk: () => { state.all.splice(state.all.indexOf(o), 1); renderAll(); toast(`Order ${o.id} deleted.`); } });
  }
}

/* ───────── xuất CSV ───────── */
function exportCsv() {
  if (state.phase !== 'ready') { toast('Orders are still loading.', 'error'); return; }
  const rows = filteredRows();
  if (!rows.length) { toast('No orders to export.', 'error'); return; }
  const cell = (v) => `"${String(v).replace(/"/g, '""')}"`;
  const lines = [['Order ID', 'Customer', 'Items', 'Amount', 'Payment', 'Date', 'Status'].map(cell).join(',')]
    .concat(rows.map((o) => [o.id, o.customer, o.items, o.amount, o.payment, o.date, o.status].map(cell).join(',')));
  const url = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
  const a = Object.assign(document.createElement('a'), { href: url, download: `orders-${SAMPLE_TODAY}.csv` });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  toast(`Exported ${int(rows.length)} order${rows.length === 1 ? '' : 's'} to CSV.`);
}

/* ───────── bộ lọc ───────── */
function setupFilters() {
  const opts = (sel, list) => { sel.innerHTML = list.map(({ value, label }) => `<option value="${esc(value)}">${esc(label)}</option>`).join(''); };
  opts($('#f-date'), DATE_FILTERS);
  opts($('#f-status'), [{ value: '', label: 'Status' }, ...STATUSES.map((s) => ({ value: s, label: s }))]);
  opts($('#f-payment'), [{ value: '', label: 'Payment Method' }, ...PAYMENTS.map((s) => ({ value: s, label: s }))]);
  opts($('#f-sort'), SORTS);
  const apply = () => { state.page = 1; if (state.phase === 'ready') { renderTable(); } };
  $('#f-date').addEventListener('change', (e) => { state.date = e.target.value; apply(); });
  $('#f-status').addEventListener('change', (e) => { state.status = e.target.value; apply(); });
  $('#f-payment').addEventListener('change', (e) => { state.payment = e.target.value; apply(); });
  $('#f-sort').addEventListener('change', (e) => { state.sort = e.target.value; apply(); });
  let timer = 0;
  const onSearch = (value, other) => {
    other.value = value;
    clearTimeout(timer);
    timer = setTimeout(() => { state.q = value; apply(); }, 120);
  };
  $('#f-search').addEventListener('input', (e) => onSearch(e.target.value, $('#fv-global-search')));
  $('#fv-global-search').addEventListener('input', (e) => onSearch(e.target.value, $('#f-search')));
  $('#fv-filters').addEventListener('submit', (e) => e.preventDefault());
  $('#fv-global-form').addEventListener('submit', (e) => e.preventDefault());
}

function clearFilters() {
  Object.assign(state, { q: '', date: '', status: '', payment: '', sort: 'newest', page: 1 });
  $('#f-search').value = '';
  $('#fv-global-search').value = '';
  ['date', 'status', 'payment', 'sort'].forEach((k) => { $('#f-' + k).selectedIndex = 0; });
  renderTable();
}

/* ───────── popover tiêu đề, giao diện, drawer ───────── */
function setupChrome() {
  const pops = $$('.fv-pop');
  const closePops = () => { pops.forEach((p) => { p.hidden = true; }); $$('[data-pop]').forEach((b) => b.setAttribute('aria-expanded', 'false')); };
  $('#pop-notif').innerHTML = `<h3>Notifications</h3><ul class="fv-notes">${NOTIFICATIONS.map((n) => `<li><b>${esc(n.title)}</b><span>${esc(n.text)}</span><small>${esc(n.time)}</small></li>`).join('')}</ul>`;
  $('#pop-msg').innerHTML = `<h3>Messages</h3><div class="fv-pop-empty">${ic('message')}<p>No new messages</p></div>`;
  document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-pop]');
    if (toggle) {
      const target = $('#' + toggle.dataset.pop);
      const willOpen = target.hidden;
      closePops();
      closeRowMenu();
      target.hidden = !willOpen;
      toggle.setAttribute('aria-expanded', String(willOpen));
      return;
    }
    if (!e.target.closest('.fv-pop')) { closePops(); }
  });

  // Chế độ sáng/tối do /assets/js/theme.js xử lý (dùng chung toàn hệ thống).

  const shell = $('#fv-shell');
  const overlay = $('.fv-overlay');
  const setDrawer = (open) => { shell.classList.toggle('is-drawer-open', open); overlay.hidden = !open; };
  $('[data-drawer-open]').addEventListener('click', () => setDrawer(true));
  $$('[data-drawer-close]').forEach((b) => b.addEventListener('click', () => setDrawer(false)));
  $$('.fv-link').forEach((l) => l.addEventListener('click', () => setDrawer(false)));

  $$('[data-group-toggle]').forEach((b) => b.addEventListener('click', () => {
    const open = b.getAttribute('aria-expanded') !== 'true';
    b.setAttribute('aria-expanded', String(open));
    $('#' + b.getAttribute('aria-controls')).hidden = !open;
  }));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closePops(); closeRowMenu(); setDrawer(false); }
  });
  window.addEventListener('resize', closeRowMenu);
  window.addEventListener('scroll', closeRowMenu, true);
}

/* ───────── khởi động ───────── */
function bindEvents() {
  document.addEventListener('click', (e) => {
    const t = e.target;
    const soon = t.closest('[data-soon]');
    if (soon) { toast(`${soon.dataset.soon} isn't part of this demo yet.`, 'info'); return; }
    if (t.closest('[data-dlg-close]')) { t.closest('dialog').close(); return; }
    if (t instanceof HTMLDialogElement) { t.close(); return; }
    const pg = t.closest('[data-page]');
    if (pg && !pg.disabled) { state.page = Number(pg.dataset.page); renderTable(); return; }
    if (t.closest('#btn-retry')) { start(); return; }
    if (t.closest('#btn-clear')) { clearFilters(); return; }
    const editFromView = t.closest('[data-edit-from-view]');
    if (editFromView) { $('#dlg-view').close(); showForm(state.all.find((o) => o.id === editFromView.dataset.editFromView)); return; }
    const mi = t.closest('#fv-rowmenu [data-m]');
    if (mi) { runMenu(mi.dataset.m); return; }
    const act = t.closest('[data-act]');
    if (act) {
      const o = state.all.find((x) => x.id === act.closest('tr')?.dataset.id);
      if (!o) { return; }
      if (act.dataset.act === 'view') { showView(o); } else if (act.dataset.act === 'edit') { showForm(o); } else { openRowMenu(act, o); }
      return;
    }
    if (!t.closest('#fv-rowmenu')) { closeRowMenu(); }
  });
  $('#btn-new').addEventListener('click', () => (state.phase === 'ready' ? showForm(null) : toast('Orders are still loading.', 'error')));
  $('#btn-export').addEventListener('click', exportCsv);
  $('#order-form').addEventListener('submit', submitForm);
  $('#confirm-ok').addEventListener('click', () => { $('#dlg-confirm').close(); const fn = confirmAction; confirmAction = null; if (fn) { fn(); } });
}

function start() {
  state.phase = 'loading';
  $('#fv-stats').hidden = false;
  renderTable();
  loadOrders().then((rows) => {
    state.all = rows;
    state.base = { total: rows.length, ...Object.fromEntries(STATUSES.map((s) => [s, countBy(rows, s)])) };
    state.phase = 'ready';
    renderAll();
  }).catch((err) => {
    state.phase = 'error';
    $('#fv-stats').hidden = true;
    state.error = err?.message || 'Could not load orders. Please try again.';
    renderTable();
    toast(state.error, 'error');
  });
}

setupFilters();
setupChrome();
bindEvents();
start();
