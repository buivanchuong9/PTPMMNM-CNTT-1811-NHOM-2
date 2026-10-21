<?php
declare(strict_types=1);
// Phép thử 4 (tầng dịch vụ): tài khoản chỉ phụ trách kho A không thao tác được trên kho B.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\Forbidden;
use StockFlow\Domain\Role;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\Authorizer;
use StockFlow\Service\CatalogService;
use StockFlow\Service\StockCountService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$vs = $f->c->get(VoucherService::class);
$ts = $f->c->get(TransferService::class);
$cs = $f->c->get(StockCountService::class);
$auth = $f->c->get(Authorizer::class);
$q = $f->c->get(StockQuery::class);
$f->receive($f->wa, 'x', 20);
$f->receive($f->wb, 'x', 20);
$vb = $f->draftIssue($f->wb, 'x', 5);
$countB = $cs->open($f->keeperB, $f->wb, '');
$mB = $f->movements($f->wb);

section('Nhân viên kho A (chỉ quyền ở A) thao tác lên kho B bằng yêu cầu trực tiếp');
throws(fn() => $vs->createDraft($f->keeperA, VoucherType::Issue, $f->wb, [$f->line('x', 1)], '', '', null), Forbidden::class, 'lập phiếu XUẤT kho B bị từ chối');
throws(fn() => $vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wb, [$f->line('x', 1)], '', '', null), Forbidden::class, 'lập phiếu nhập kho B bị từ chối');
throws(fn() => $vs->confirm($f->approverA, (int)$vb['id']), Forbidden::class, 'người duyệt A không xác nhận được phiếu của B');
throws(fn() => $vs->cancel($f->keeperA, (int)$vb['id']), Forbidden::class, 'không huỷ được phiếu của B');
throws(fn() => $ts->createDraft($f->keeperA, $f->wb, $f->wa, [$f->line('x', 1)], '', null), Forbidden::class, 'không lập điều chuyển ĐI từ kho B (rút hàng khỏi B)');
throws(fn() => $cs->open($f->keeperA, $f->wb, ''), Forbidden::class, 'không mở kiểm kê kho B');
throws(fn() => $cs->save($f->keeperA, $countB, []), Forbidden::class, 'không nhập số đếm kho B');
throws(fn() => $cs->approve($f->approverA, $countB), Forbidden::class, 'không duyệt kiểm kê kho B');
throws(fn() => $auth->requireVisible($f->keeperA, $f->wb), Forbidden::class, 'không xem được kho B');
same($f->movements($f->wb), $mB, 'sổ phát sinh kho B không đổi sau mọi yêu cầu trên');
same($f->stock($f->wb, 'x'), 20, 'tồn kho B nguyên vẹn');

section('Phân tách quyền trong cùng kho');
throws(fn() => $vs->confirm($f->keeperA, (int)$f->draftIssue($f->wa, 'x', 1)['id']), Forbidden::class, 'nhân viên (không có quyền duyệt) không tự xác nhận phiếu');
throws(fn() => $vs->createDraft($f->approverA, VoucherType::Issue, $f->wa, [$f->line('x', 1)], '', '', null), Forbidden::class, 'người duyệt thuần không lập phiếu');
throws(fn() => $cs->open($f->approverA, $f->wa, ''), Forbidden::class, 'người duyệt thuần không mở kiểm kê (thiếu quyền kiểm kê)');

section('Đường điều chỉnh không phải cửa hậu');
$t = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 4)], '', null)['voucher'];
$ts->confirm($f->approverA, (int)$t['id']);
throws(fn() => $vs->correct($f->approverA, (int)$t['id'], 'thử đảo điều chuyển'), Forbidden::class, 'người duyệt chỉ ở A không đảo được điều chuyển chạm kho B');
same($f->stock($f->wb, 'x'), 24, 'kho B không bị rút hàng qua đường đảo');
$vb2 = $f->receive($f->wb, 'y', 5);
throws(fn() => $vs->correct($f->approverA, (int)$vb2['id'], 'x'), Forbidden::class, 'không điều chỉnh chứng từ của kho B');

section('Quyền xem (đọc) cũng theo kho');
$visA = $auth->visibleWarehouseIds($f->keeperA);
same($visA, [$f->wa], 'chỉ thấy kho A');
$hist = $q->history($visA);
truthy($hist !== [] && count(array_unique(array_column($hist, 'warehouse_id'))) === 1 && (int)$hist[0]['warehouse_id'] === $f->wa, 'lịch sử chỉ gồm phát sinh kho A');
same(array_unique(array_map(fn($r) => (int)$r['warehouse_id'], $q->onHand($visA))), [$f->wa], 'tồn kho chỉ gồm kho A');
same($q->onHand([]), [], 'không có kho nào ⇒ không có dữ liệu');
truthy($auth->can($f->both, $f->wb, Role::Approver) && $auth->can($f->admin, $f->wb, Role::Approver), 'người có quyền cả hai kho/admin thì được');

section('Quản trị danh mục chỉ dành cho admin');
$cat = $f->c->get(CatalogService::class);
throws(fn() => $cat->addWarehouse($f->keeperA, 'ZZ9', 'x', ''), Forbidden::class, 'nhân viên kho không tạo được kho');
throws(fn() => $cat->grant($f->keeperA, $f->keeperA->id, $f->wb, Role::Approver), Forbidden::class, 'không tự cấp quyền cho mình');
throws(fn() => $cat->addUser($f->approverA, 'hacker_' . $f->tag, 'x', 'password1', true), Forbidden::class, 'không tạo được tài khoản');
finish();
