<?php
declare(strict_types=1);
// Yêu cầu chuyển hàng: nhân viên lập → quản lý kho nhận xác nhận → quản lý kho cấp chấp nhận (mở phiếu điều chuyển) hoặc từ chối kèm lý do.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\TransferRequestService;

$f = Fx::make();
$f->receive($f->wa, 'x', 50);
$svc = $f->c->get(TransferRequestService::class);

section('Nhân viên kho B xin hàng từ kho A');
$res = $svc->create($f->keeperB, $f->wa, [$f->line('x', 20)], 'cần gấp', null);
$id = $res['id'];
same($svc->find($id)['trang_thai'], 'WAIT_OWN', 'nhân viên lập ⇒ chờ quản lý kho B xác nhận');
same($svc->pendingFor($f->approverB), 1, 'quản lý kho B có 1 việc chờ');
same($svc->pendingFor($f->approverA), 0, 'kho A chưa phải xử lý gì');
throws(fn() => $svc->accept($f->approverA, $id), DomainError::class, 'kho A chưa nhận được yêu cầu khi quản lý kho B chưa xác nhận');
throws(fn() => $svc->approveOwn($f->approverA, $id), Forbidden::class, 'quản lý kho khác không xác nhận hộ');
throws(fn() => $svc->create($f->keeperB, $f->wb, [$f->line('x', 1)], '', null), DomainError::class, 'không xin hàng từ chính kho mình');
throws(fn() => $svc->create($f->keeperB, $f->wa, [$f->line('x', 999)], '', null), DomainError::class, 'không xin vượt tồn kho cấp');

section('Quản lý B xác nhận ⇒ chuyển sang kho A');
$svc->approveOwn($f->approverB, $id);
same($svc->find($id)['trang_thai'], 'WAIT_SOURCE', 'chờ kho cấp chấp nhận');
same($svc->pendingFor($f->approverA), 1, 'quản lý kho A thấy 1 việc chờ');
throws(fn() => $svc->accept($f->keeperA, $id), Forbidden::class, 'nhân viên kho A không chấp nhận được');

section('Kho A chấp nhận ⇒ mở phiếu điều chuyển đi nháp A → B, tồn chưa đổi');
$vid = $svc->accept($f->approverA, $id);
$req = $svc->find($id);
same([$req['trang_thai'], (int)$req['phieu_id']], ['ACCEPTED', $vid], 'yêu cầu đã chấp nhận, gắn phiếu');
$v = $f->c->get(\StockFlow\Service\VoucherRepository::class)->find($vid);
same([$v['loai'], $v['trang_thai'], (int)$v['kho_id'], (int)$v['kho_doi_ung_id']], ['TRANSFER_OUT', 'DRAFT', $f->wa, $f->wb], 'phiếu DCX nháp tại kho A');
same($f->stock($f->wa, 'x'), 50, 'tồn kho A chưa đổi');
throws(fn() => $svc->accept($f->approverA, $id), DomainError::class, 'chấp nhận lần hai bị chặn, không ra phiếu thứ hai');
same((int)$f->db->value("SELECT COUNT(*) FROM phieu_nhap_xuat WHERE loai = 'TRANSFER_OUT' AND kho_id = ?", [$f->wa]), 1, 'chỉ có một phiếu điều chuyển đi');

section('Kho A từ chối ⇒ yêu cầu huỷ, quản lý B được báo lý do');
$id2 = $svc->create($f->approverB, $f->wa, [$f->line('x', 5)], '', null)['id'];
same($svc->find($id2)['trang_thai'], 'WAIT_SOURCE', 'quản lý lập thì gửi thẳng kho cấp');
throws(fn() => $svc->reject($f->approverA, $id2, '  '), DomainError::class, 'từ chối bắt buộc có lý do');
throws(fn() => $svc->reject($f->approverB, $id2, 'không phải kho cấp'), Forbidden::class, 'kho nhận không tự từ chối');
$svc->reject($f->approverA, $id2, 'Hàng đã giữ cho đơn khác');
$r2 = $svc->find($id2);
same([$r2['trang_thai'], $r2['ly_do']], ['REJECTED', 'Hàng đã giữ cho đơn khác'], 'bị từ chối kèm lý do');
same($svc->pendingFor($f->approverB), 1, 'quản lý kho B có thông báo từ chối chưa xem');
$svc->markSeen($f->approverB);
same($svc->pendingFor($f->approverB), 0, 'xem rồi thì hết số nhỏ');
throws(fn() => $svc->accept($f->approverA, $id2), DomainError::class, 'yêu cầu đã từ chối không chấp nhận lại được');

section('Huỷ yêu cầu');
$id3 = $svc->create($f->keeperB, $f->wa, [$f->line('x', 3)], '', null)['id'];
throws(fn() => $svc->cancel($f->keeperA, $id3, ''), Forbidden::class, 'người ngoài kho nhận không huỷ được');
$svc->cancel($f->keeperB, $id3, 'không cần nữa');
same($svc->find($id3)['trang_thai'], 'CANCELLED', 'người lập huỷ được');

finish();
