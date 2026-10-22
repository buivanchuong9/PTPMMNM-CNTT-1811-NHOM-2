<?php
/** Trang Orders của Finova. Dữ liệu và hành vi nằm trong /assets/js/finova/*.js (dữ liệu mẫu ở data.js). */
require_once __DIR__ . '/partials/icons.php';   // nội dung được render trước khung nên tự nạp bộ icon
?>
<header class="fv-head">
  <div>
    <h1>Orders</h1>
    <p>Manage and track all customer orders</p>
  </div>
  <div class="fv-head-actions">
    <button class="fv-btn fv-btn-ghost" type="button" id="btn-export"><?= fi('download') ?>Export CSV</button>
    <button class="fv-btn fv-btn-primary" type="button" id="btn-new"><?= fi('plus') ?>New Order</button>
  </div>
</header>

<section class="fv-stats" id="fv-stats" aria-label="Order statistics" aria-busy="true">
  <?php for ($i = 0; $i < 4; $i++): ?><div class="fv-stat is-skeleton"><span class="sk sk-ic"></span><span class="sk sk-num"></span><span class="sk sk-line"></span></div><?php endfor; ?>
</section>

<form class="fv-filters" id="fv-filters" role="search" aria-label="Filter orders" autocomplete="off">
  <label class="fv-field fv-field-search"><?= fi('search') ?>
    <input type="search" id="f-search" name="q" placeholder="Search customer or..." aria-label="Search customer or order ID">
  </label>
  <label class="fv-field fv-select"><?= fi('calendar') ?>
    <select id="f-date" name="date" aria-label="Date"></select><?= fi('chevron-down', 'fv-caret') ?>
  </label>
  <label class="fv-field fv-select"><?= fi('filter') ?>
    <select id="f-status" name="status" aria-label="Status"></select><?= fi('chevron-down', 'fv-caret') ?>
  </label>
  <label class="fv-field fv-select"><?= fi('credit-card') ?>
    <select id="f-payment" name="payment" aria-label="Payment method"></select><?= fi('chevron-down', 'fv-caret') ?>
  </label>
  <label class="fv-field fv-select fv-sort"><?= fi('sort') ?>
    <select id="f-sort" name="sort" aria-label="Sort"></select><?= fi('chevron-down', 'fv-caret') ?>
  </label>
</form>

<section class="fv-card fv-orders" aria-labelledby="orders-title">
  <h2 id="orders-title">All Orders</h2>
  <div class="fv-table-wrap" id="fv-table-wrap">
    <table class="fv-table">
      <thead>
        <tr>
          <th scope="col">Order ID</th><th scope="col">Customer</th><th scope="col">Items</th><th scope="col">Amount</th>
          <th scope="col">Payment</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col" class="fv-th-actions">Actions</th>
        </tr>
      </thead>
      <tbody id="fv-tbody"></tbody>
    </table>
    <div class="fv-state" id="fv-state" hidden></div>
  </div>
  <footer class="fv-pager">
    <p class="fv-pager-info" id="fv-pager-info" aria-live="polite"></p>
    <nav class="fv-pager-nav" id="fv-pager-nav" aria-label="Pagination"></nav>
  </footer>
</section>

<div class="fv-menu" id="fv-rowmenu" role="menu" aria-label="Order actions" hidden></div>

<dialog class="fv-dlg" id="dlg-view" aria-labelledby="dlg-view-title">
  <div class="fv-dlg-box" id="dlg-view-body"></div>
</dialog>

<dialog class="fv-dlg" id="dlg-form" aria-labelledby="dlg-form-title">
  <form class="fv-dlg-box fv-form" id="order-form" novalidate>
    <div class="fv-dlg-head"><h2 id="dlg-form-title">Edit order</h2><button class="fv-icon-btn" type="button" data-dlg-close aria-label="Close"><?= fi('x') ?></button></div>
    <input type="hidden" name="id">
    <div class="fv-grid-2">
      <label>Order ID<input name="idView" disabled></label>
      <label>Date<input type="date" name="date" required></label>
    </div>
    <label>Customer<input name="customer" required maxlength="60" autocomplete="off"><small class="fv-err" data-err="customer"></small></label>
    <div class="fv-grid-3">
      <label>Items<input type="number" name="items" min="1" max="99" step="1" required><small class="fv-err" data-err="items"></small></label>
      <label>Amount ($)<input type="number" name="amount" min="0" max="1000000" step="1" required><small class="fv-err" data-err="amount"></small></label>
      <label>Payment<select name="payment"></select></label>
    </div>
    <label>Status<select name="status"></select></label>
    <div class="fv-dlg-actions">
      <button class="fv-btn fv-btn-ghost" type="button" data-dlg-close>Cancel</button>
      <button class="fv-btn fv-btn-primary" type="submit" id="form-submit">Save changes</button>
    </div>
  </form>
</dialog>

<dialog class="fv-dlg fv-dlg-sm" id="dlg-confirm" aria-labelledby="dlg-confirm-title">
  <div class="fv-dlg-box">
    <div class="fv-confirm-ic" id="confirm-ic"><?= fi('alert') ?></div>
    <h2 id="dlg-confirm-title">Are you sure?</h2>
    <p id="dlg-confirm-text" class="fv-muted"></p>
    <div class="fv-dlg-actions">
      <button class="fv-btn fv-btn-ghost" type="button" data-dlg-close>Keep order</button>
      <button class="fv-btn fv-btn-danger" type="button" id="confirm-ok">Confirm</button>
    </div>
  </div>
</dialog>
