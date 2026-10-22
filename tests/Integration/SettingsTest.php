<?php
declare(strict_types=1);
// Cài đặt theo vai trò: nhân viên chỉ cá nhân; quản lý chỉ kho/nhân viên của mình; quản trị chỉnh tham số hệ thống.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Core\Paginator;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\CatalogService;
use StockFlow\Service\SettingsService;

$f = Fx::make();
$st = $f->c->get(SettingsService::class);
$cat = $f->c->get(CatalogService::class);
$pw = Fx::PASSWORD;

section('Tuỳ chọn cá nhân');
same($st->preferences($f->keeperA)['rows'], 20, 'mặc định 20 dòng / trang');
$st->savePreferences($f->keeperA, 50, true);
same($st->preferences($f->keeperA), ['rows' => 50, 'rows_choice' => 50, 'compact' => true], 'lưu và đọc lại tuỳ chọn');
same($st->preferences($f->keeperB)['rows'], 20, 'tuỳ chọn của người này không ảnh hưởng người khác');
throws(fn() => $st->savePreferences($f->keeperA, 7, false), DomainError::class, 'số dòng ngoài danh sách bị từ chối');
Paginator::$default = 50;
same((new Paginator(120, 1))->per, 50, 'Paginator dùng số dòng theo cài đặt');
Paginator::$default = 20;

section('Hồ sơ và mật khẩu');
$st->updateProfile($f->keeperA, '  Tên Mới  ');
same($f->db->value('SELECT ho_ten FROM nguoi_dung WHERE id = ?', [$f->keeperA->id]), 'Tên Mới', 'đổi họ tên (cắt khoảng trắng)');
throws(fn() => $st->updateProfile($f->keeperA, '   '), DomainError::class, 'họ tên rỗng bị từ chối');
throws(fn() => $st->changePassword($f->keeperA, 'sai-mat-khau', 'mat-khau-moi-1', 'mat-khau-moi-1'), DomainError::class, 'sai mật khẩu hiện tại');
throws(fn() => $st->changePassword($f->keeperA, $pw, 'mat-khau-moi-1', 'khac-hoan-toan'), DomainError::class, 'nhập lại không khớp');
throws(fn() => $st->changePassword($f->keeperA, $pw, 'ngan', 'ngan'), DomainError::class, 'ngắn hơn mức tối thiểu');
$st->changePassword($f->keeperA, $pw, 'mat-khau-moi-1', 'mat-khau-moi-1');
truthy(password_verify('mat-khau-moi-1', (string)$f->db->value('SELECT mat_khau_ma_hoa FROM nguoi_dung WHERE id = ?', [$f->keeperA->id])), 'mật khẩu mới có hiệu lực');

section('Quản lý kho: chỉ kho và nhân viên của mình');
$st->updateWarehouse($f->approverA, 'Kho A đổi tên', 'Số 1 đường X');
same($f->db->value('SELECT ten FROM kho WHERE id = ?', [$f->wa]), 'Kho A đổi tên', 'quản lý sửa được kho của mình');
throws(fn() => $st->updateWarehouse($f->keeperA, 'x', 'y'), Forbidden::class, 'nhân viên không sửa được thông tin kho');
throws(fn() => $st->updateWarehouse($f->admin, 'x', 'y'), Forbidden::class, 'admin dùng trang quản trị riêng, không sửa qua trang cài đặt kho');
same(count($st->staffOf($f->approverA)), 1, 'quản lý A thấy đúng nhân viên kho A');
$st->resetStaffPassword($f->approverA, $f->keeperA->id, 'dat-lai-mat-khau');
truthy(password_verify('dat-lai-mat-khau', (string)$f->db->value('SELECT mat_khau_ma_hoa FROM nguoi_dung WHERE id = ?', [$f->keeperA->id])), 'đặt lại mật khẩu nhân viên kho mình');
throws(fn() => $st->resetStaffPassword($f->approverA, $f->keeperB->id, 'dat-lai-mat-khau'), Forbidden::class, 'không đụng được nhân viên kho khác');
throws(fn() => $st->setStaffActive($f->approverA, $f->approverB->id, false), Forbidden::class, 'không khoá được quản lý/nhân viên kho khác');
throws(fn() => $st->setStaffActive($f->keeperA, $f->keeperA->id, false), Forbidden::class, 'nhân viên không khoá được ai');
$st->setStaffActive($f->approverA, $f->keeperA->id, false);
same((int)$f->db->value('SELECT hoat_dong FROM nguoi_dung WHERE id = ?', [$f->keeperA->id]), 0, 'khoá nhân viên kho mình');

section('Đổi địa chỉ kho');
$newAddr = 'Số 9 Lê Lợi, Phường Ba Đình, Thành phố Hà Nội';
$cat->setWarehouseAddress($f->approverA, $f->wa, $newAddr);
same($f->db->value('SELECT dia_chi FROM kho WHERE id = ?', [$f->wa]), $newAddr, 'quản lý đổi địa chỉ kho của mình');
same($f->c->get(\StockFlow\Service\Authorizer::class)->warehouseOf($f->keeperA)['dia_chi'], $newAddr, 'nhân viên cùng kho thấy địa chỉ mới');
throws(fn() => $cat->setWarehouseAddress($f->approverA, $f->wb, $newAddr), Forbidden::class, 'quản lý không đổi được địa chỉ kho khác');
throws(fn() => $cat->setWarehouseAddress($f->keeperA, $f->wa, $newAddr), Forbidden::class, 'nhân viên không đổi được địa chỉ kho');
$cat->setWarehouseAddress($f->admin, $f->wb, 'Số 1 Bạch Đằng, Phường Hải Châu, Thành phố Đà Nẵng');
same($f->db->value('SELECT dia_chi FROM kho WHERE id = ?', [$f->wb]), 'Số 1 Bạch Đằng, Phường Hải Châu, Thành phố Đà Nẵng', 'admin đổi địa chỉ mọi kho');
truthy((int)$f->db->value("SELECT COUNT(*) FROM nhat_ky WHERE hanh_dong = 'warehouse.address' AND doi_tuong_id = ?", [$f->wa]) >= 1, 'có ghi nhật ký thao tác');

section('Sửa tên đăng nhập / mật khẩu tài khoản kho');
$tag = $f->tag;
$st->editStaff($f->approverA, $f->keeperA->id, 'Moi.Nv.' . $tag, 'mat-khau-moi-2');
same($f->db->value('SELECT ten_dang_nhap FROM nguoi_dung WHERE id = ?', [$f->keeperA->id]), strtolower('Moi.Nv.' . $tag), 'quản lý đổi tên đăng nhập nhân viên kho mình (chuẩn hoá chữ thường)');
truthy(password_verify('mat-khau-moi-2', (string)$f->db->value('SELECT mat_khau_ma_hoa FROM nguoi_dung WHERE id = ?', [$f->keeperA->id])), 'kèm đặt mật khẩu mới');
$hashBefore = (string)$f->db->value('SELECT mat_khau_ma_hoa FROM nguoi_dung WHERE id = ?', [$f->keeperA->id]);
$st->editStaff($f->approverA, $f->keeperA->id, 'Moi.Nv.' . $tag, '');
same((string)$f->db->value('SELECT mat_khau_ma_hoa FROM nguoi_dung WHERE id = ?', [$f->keeperA->id]), $hashBefore, 'mật khẩu để trống = giữ nguyên');
throws(fn() => $st->editStaff($f->approverA, $f->keeperA->id, $f->approverB->username, ''), DomainError::class, 'trùng tên đăng nhập bị từ chối');
throws(fn() => $st->editStaff($f->approverA, $f->keeperA->id, 'a b', ''), DomainError::class, 'tên đăng nhập sai định dạng');
throws(fn() => $st->editStaff($f->approverA, $f->keeperB->id, 'hack' . $tag, ''), Forbidden::class, 'không sửa được nhân viên kho khác');
throws(fn() => $st->editStaff($f->approverA, $f->approverA->id, 'hack2' . $tag, ''), Forbidden::class, 'không sửa được chính tài khoản quản lý qua đường này');
throws(fn() => $st->editStaff($f->keeperA, $f->keeperA->id, 'hack3' . $tag, ''), Forbidden::class, 'nhân viên không tự đổi tên đăng nhập');
$cat->editAccount($f->admin, $f->keeperB->id, 'Admin.Doi.' . $tag, \StockFlow\Domain\AccountRole::Staff, $f->wb, 'admin-dat-mk-1');
same($f->db->value('SELECT ten_dang_nhap FROM nguoi_dung WHERE id = ?', [$f->keeperB->id]), strtolower('Admin.Doi.' . $tag), 'admin đổi tên đăng nhập tài khoản kho bất kỳ');
truthy(password_verify('admin-dat-mk-1', (string)$f->db->value('SELECT mat_khau_ma_hoa FROM nguoi_dung WHERE id = ?', [$f->keeperB->id])), 'admin đặt mật khẩu mới trong cùng lần lưu');
$cat->editAccount($f->admin, $f->keeperB->id, 'Admin.Doi.' . $tag, \StockFlow\Domain\AccountRole::Staff, $f->wa, '');
same((int)$f->db->value('SELECT kho_id FROM nguoi_dung WHERE id = ?', [$f->keeperB->id]), $f->wa, 'admin chuyển nhân viên sang kho khác trong cùng lần lưu');
throws(fn() => $cat->editAccount($f->admin, $f->admin->id, 'doi.admin' . $tag, \StockFlow\Domain\AccountRole::Admin, null, ''), DomainError::class, 'không sửa tài khoản quản trị cấp cao');
throws(fn() => $cat->editAccount($f->approverA, $f->keeperA->id, 'x.y.z' . $tag, \StockFlow\Domain\AccountRole::Staff, $f->wa, ''), Forbidden::class, 'quản lý không dùng được chức năng quản trị tài khoản');

section('Quản trị: tham số hệ thống');
throws(fn() => $st->saveSystem($f->approverA, 50, 10, 0), Forbidden::class, 'quản lý không chỉnh được cài đặt hệ thống');
throws(fn() => $st->recentAudit($f->keeperA), Forbidden::class, 'nhân viên không xem được nhật ký');
throws(fn() => $st->saveSystem($f->admin, 33, 10, 0), DomainError::class, 'số dòng ngoài danh sách bị từ chối');
$st->saveSystem($f->admin, 50, 10, 60);
same($st->system(), ['rows_default' => 50, 'min_password' => 10, 'idle_minutes' => 60], 'lưu tham số hệ thống');
same($st->preferences($f->keeperB)['rows'], 50, 'tài khoản chưa tự chọn thì theo mặc định hệ thống');
throws(fn() => $cat->createStaff($f->approverB, 'newstaff' . $f->tag, 'Nhân viên mới', 'chin-ky-t', $f->wb), DomainError::class, 'tạo tài khoản theo độ dài mật khẩu tối thiểu mới');
truthy(count($st->recentAudit($f->admin)) > 0, 'admin xem được nhật ký thao tác');
$st->saveSystem($f->admin, 20, 8, 0);
finish();
