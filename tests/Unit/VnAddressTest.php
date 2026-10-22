<?php
declare(strict_types=1);
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\VnAddress;

section('Danh mục hành chính');
same(count(VnAddress::provinces()), 34, '34 tỉnh, thành phố');
same(array_sum(array_map(fn($p) => count($p['w']), VnAddress::provinces())), 3321, '3.321 phường, xã');

section('Tìm tỉnh / phường, không phân biệt dấu và tiền tố');
same(VnAddress::findProvince('ha noi')['n'], 'Thành phố Hà Nội', '«ha noi» → Thành phố Hà Nội');
same(VnAddress::findProvince('THÀNH PHỐ HỒ CHÍ MINH')['n'], 'Thành phố Hồ Chí Minh', 'đủ tên, viết hoa');
same(VnAddress::findProvince('Atlantis'), null, 'tỉnh không có trong danh mục');
$hn = VnAddress::findProvince('Hà Nội');
same(VnAddress::findWard($hn, 'phuong ba dinh'), 'Phường Ba Đình', 'phường không dấu');
same(VnAddress::findWard($hn, 'Phường Không Có'), null, 'phường không thuộc tỉnh');
$dn = VnAddress::findProvince('Đà Nẵng');
same(VnAddress::findWard($dn, 'Phường Ba Đình'), null, 'phường của tỉnh khác bị từ chối');

section('Ghép và kiểm tra địa chỉ');
same(VnAddress::compose('Số 1 Lê Duẩn', 'ba dinh', 'ha noi'), 'Số 1 Lê Duẩn, Phường Ba Đình, Thành phố Hà Nội', 'ghép đúng dạng chuẩn, chuẩn hoá tên');
throws(fn() => VnAddress::compose('', 'Phường Ba Đình', 'Hà Nội'), DomainError::class, 'thiếu số nhà, đường');
throws(fn() => VnAddress::compose('Số 1', 'Phường Ba Đình', 'Atlantis'), DomainError::class, 'tỉnh không hợp lệ');
throws(fn() => VnAddress::compose('Số 1', 'Phường Hải Châu', 'Hà Nội'), DomainError::class, 'phường không thuộc tỉnh đã chọn');
throws(fn() => VnAddress::compose(str_repeat('x', 151), 'Phường Ba Đình', 'Hà Nội'), DomainError::class, 'số nhà, đường quá dài');
same(VnAddress::compose('', '', '', false), '', 'địa chỉ tuỳ chọn: bỏ trống cả ba ô được');
throws(fn() => VnAddress::compose('Số 1', '', '', false), DomainError::class, 'tuỳ chọn nhưng điền dở thì vẫn báo lỗi');

section('Tách địa chỉ đã lưu để điền sẵn form');
same(VnAddress::parse('Số 1 Lê Duẩn, Phường Ba Đình, Thành phố Hà Nội'), ['street' => 'Số 1 Lê Duẩn', 'ward' => 'Phường Ba Đình', 'province' => 'Thành phố Hà Nội'], 'địa chỉ chuẩn');
same(VnAddress::parse('KCN Thăng Long, Huyện Gia Lâm, Hà Nội'), ['street' => 'KCN Thăng Long, Huyện Gia Lâm', 'ward' => '', 'province' => 'Thành phố Hà Nội'], 'địa chỉ cũ (có huyện): giữ phần đầu, nhận tỉnh');
same(VnAddress::parse('Địa chỉ lạ không dấu phẩy'), ['street' => 'Địa chỉ lạ không dấu phẩy', 'ward' => '', 'province' => ''], 'không nhận ra: giữ nguyên trong ô số nhà, đường');
same(VnAddress::parse(null), ['street' => '', 'ward' => '', 'province' => ''], 'chưa có địa chỉ');
finish();
