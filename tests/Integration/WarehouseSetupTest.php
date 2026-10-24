<?php
declare(strict_types=1);
// Thêm kho kèm tài khoản quản lý: một giao dịch (lỗi ở tài khoản thì không có kho), chỉ quản trị hệ thống làm được.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\CatalogService;

$f = Fx::make();
$cat = $f->c->get(CatalogService::class);
$pw = Fx::PASSWORD;
$tag = $f->tag;
$count = fn(string $sql, array $a = []) => (int)$f->db->value($sql, $a);

section('Thêm kho kèm quản lý');
$res = $cat->addWarehouseWithManager($f->admin, "N{$tag}", 'Kho mới', 'Địa chỉ mới', "ql.moi{$tag}", 'Quản Lý Mới', $pw);
$kho = $f->db->one('SELECT * FROM kho WHERE id = ?', [$res['warehouse_id']]);
same($kho['ma'], "N{$tag}", 'kho được tạo');
$u = $f->db->one('SELECT * FROM nguoi_dung WHERE id = ?', [$res['manager_id']]);
same([$u['vai_tro'], (int)$u['kho_id'], (int)$u['quan_ly_kho_id']], ['MANAGER', (int)$res['warehouse_id'], (int)$res['warehouse_id']], 'tài khoản là quản lý, gắn đúng kho mới');
truthy(password_verify($pw, $u['mat_khau_ma_hoa']), 'mật khẩu quản lý đăng nhập được');
$dir = array_values(array_filter($cat->warehouseDirectory(), fn($w) => (int)$w['id'] === (int)$res['warehouse_id']))[0];
same($dir['quan_ly']['ho_ten'] ?? null, 'Quản Lý Mới', 'trang Kho & nhân sự thấy ngay quản lý của kho mới');

section('Lỗi ở phần tài khoản thì không tạo kho (nguyên khối)');
$khoBefore = $count('SELECT COUNT(*) FROM kho');
$userBefore = $count('SELECT COUNT(*) FROM nguoi_dung');
throws(fn() => $cat->addWarehouseWithManager($f->admin, "M{$tag}", 'Kho lỗi', '', "ql.moi{$tag}", 'Trùng tên', $pw), DomainError::class, 'tên đăng nhập đã tồn tại bị từ chối');
throws(fn() => $cat->addWarehouseWithManager($f->admin, "M{$tag}", 'Kho lỗi', '', "ql.khac{$tag}", 'Mật khẩu ngắn', 'ngan'), DomainError::class, 'mật khẩu ngắn bị từ chối');
throws(fn() => $cat->addWarehouseWithManager($f->admin, "M{$tag}", 'Kho lỗi', '', '', '', $pw), DomainError::class, 'thiếu thông tin quản lý bị từ chối');
same([$count('SELECT COUNT(*) FROM kho'), $count('SELECT COUNT(*) FROM nguoi_dung')], [$khoBefore, $userBefore], 'không kho, không tài khoản nào được tạo');
same($count('SELECT COUNT(*) FROM kho WHERE ma = ?', ["M{$tag}"]), 0, 'kho «lỗi» không còn sót lại');

section('Phân quyền');
throws(fn() => $cat->addWarehouseWithManager($f->approverA, "P{$tag}", 'Kho', '', "ql.p{$tag}", 'X', $pw), Forbidden::class, 'quản lý kho không tạo được kho mới');
throws(fn() => $cat->addWarehouseWithManager($f->keeperA, "P{$tag}", 'Kho', '', "ql.p{$tag}", 'X', $pw), Forbidden::class, 'nhân viên không tạo được kho mới');

finish();
