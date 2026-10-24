<?php
declare(strict_types=1);
// «Thay đổi mới» theo chức năng: số +N cạnh mục menu, bôi đậm cái mới khi mở chức năng, xem rồi thì thôi; chỉ tính việc của NGƯỜI KHÁC trong phạm vi kho.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Core\Session;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\Authorizer;
use StockFlow\Service\CatalogService;
use StockFlow\Service\ChangeTracker;
use StockFlow\Service\VoucherService;

$_SESSION = [];
$f = Fx::make();
$auth = $f->c->get(Authorizer::class);
$session = $f->c->get(Session::class);
/** Mỗi lần kiểm tra dùng một bộ theo dõi mới (bộ nhớ đệm trong một yêu cầu không lẫn sang yêu cầu sau). */
$tr = fn() => new ChangeTracker($f->db, $auth, $session);
$count = fn(\StockFlow\Domain\User $u, string $s) => $tr()->counts($u)[$s] ?? 0;

section('Lần đầu dùng: không đổ dồn lịch sử cũ thành «mới»');
$f->receive($f->wa, 'x', 50);   // việc có từ trước khi các tài khoản dùng tính năng
foreach ([$f->admin, $f->keeperA, $f->approverA, $f->approverB] as $u) {
    $tr()->counts($u);   // đặt mốc
}
same([$count($f->admin, 'vouchers'), $count($f->approverA, 'vouchers'), $count($f->approverA, 'history')], [0, 0, 0], 'lịch sử cũ không bị tính là thay đổi mới');

section('Người khác làm ⇒ hiện +N; việc của chính mình không tính');
$v1 = $f->receive($f->wa, 'x', 5);   // nhân viên A lập, quản lý A xác nhận
same($count($f->admin, 'vouchers'), 1, 'admin thấy 1 phiếu mới');
same($count($f->approverA, 'vouchers'), 1, 'quản lý A thấy phiếu mà nhân viên A vừa lập');
same($count($f->keeperA, 'vouchers'), 1, 'nhân viên A thấy phiếu mà quản lý A vừa xác nhận');
same($count($f->approverB, 'vouchers'), 0, 'quản lý kho B không thấy việc của kho A (theo phạm vi kho)');
$svc = $f->c->get(VoucherService::class);
$own = $svc->createDraft($f->approverA, VoucherType::Receipt, $f->wa, [$f->line('x', 2)], 'NCC', '', null)['voucher'];
same([$count($f->approverA, 'vouchers'), $count($f->admin, 'vouchers')], [1, 2], 'quản lý A tự lập phiếu: không tự báo mình; admin thấy thêm 1');
same($count($f->approverA, 'history') >= 1 && $count($f->approverB, 'history') === 0, true, 'sổ phát sinh mới cũng báo (đúng phạm vi kho)');

section('Mở chức năng: bôi đậm cái mới rồi dời mốc — xem rồi thì thôi');
$first = $tr()->visit($f->admin, 'vouchers');
same(array_keys($first['ids']) === [] ? [] : array_map('intval', array_keys($first['ids'])), array_map('intval', array_keys($first['ids'])), 'lần mở đầu có danh sách cần bôi đậm');
truthy(isset($first['ids'][(int)$v1['id']]) && isset($first['ids'][(int)$own['id']]), 'cả hai phiếu mới đều được bôi đậm');
same($count($f->admin, 'vouchers'), 0, 'mở rồi thì số +N trên menu về 0');
$again = $tr()->visit($f->admin, 'vouchers');
same($again['ids'], [], 'mở lần nữa (từ trang khác vào): không còn bôi đậm');
$_SESSION = [];
$tr()->visit($f->admin, 'vouchers');   // lần mở này không có gì mới
$f->receive($f->wa, 'x', 3);
$open = $tr()->visit($f->admin, 'vouchers');
$cont = $tr()->visit($f->admin, 'vouchers', true);
same(array_keys($cont['ids']), array_keys($open['ids']), 'lọc / phân trang ngay trong trang đó: giữ nguyên phần bôi đậm của lần mở');
same($count($f->admin, 'vouchers'), 0, 'và không tính lại thành «mới»');

section('Lịch sử phát sinh theo id dòng sổ');
$h1 = $tr()->visit($f->approverA, 'history');
truthy($h1['upto'] > $h1['after'] && is_new_ledger($h1, $h1['upto']) && !is_new_ledger($h1, $h1['after']), 'dòng sổ mới nằm trong khoảng được bôi đậm, dòng cũ thì không');
same($count($f->approverA, 'history'), 0, 'mở rồi thì hết báo');

section('Danh mục: việc của admin báo cho người khác');
$cat = $f->c->get(CatalogService::class);
$tr()->visit($f->approverA, 'inventory');
$cat->updateItem($f->admin, $f->it['x']['item'], 5, 9);
same([$count($f->approverA, 'inventory'), $count($f->admin, 'inventory')], [1, 0], 'quản lý thấy mặt hàng admin vừa sửa; admin không tự báo mình');
$inv = $tr()->visit($f->approverA, 'inventory');
truthy(isset($inv['ids'][$f->it['x']['item']]), 'mặt hàng đó được bôi đậm ở trang Hàng hoá');

section('Chức năng không có quyền thì không báo');
same($tr()->counts($f->keeperA)['users'] ?? 0, 0, 'nhân viên không có số cho trang Tài khoản');
same($tr()->visit($f->keeperA, 'users')['ids'], [], 'và mở trang đó cũng không dời mốc / không có gì bôi đậm');

finish();
