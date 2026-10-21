<?php
declare(strict_types=1);
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\StockRules as R;
use StockFlow\Domain\VoucherType as T;

section('apply — tồn không bao giờ âm');
same(R::apply(10, 5), 15, 'cộng tồn');
same(R::apply(10, -10), 0, 'xuất hết về 0');
throws(fn() => R::apply(3, -4), DomainError::class, 'xuất quá tồn bị chặn');

section('delta — dấu theo loại chứng từ');
same(R::delta(T::Receipt, 5), 5, 'nhập: +');
same(R::delta(T::Issue, 5), -5, 'xuất: −');
same(R::delta(T::TransferOut, 5), -5, 'điều chuyển đi: −');
same(R::delta(T::TransferIn, 5), 5, 'điều chuyển đến: +');
same(R::delta(T::Adjustment, -3), -3, 'điều chỉnh mang dấu riêng');
throws(fn() => R::delta(T::Issue, -1), DomainError::class, 'phiếu xuất không nhận số lượng âm');
throws(fn() => R::delta(T::Receipt, 0), DomainError::class, 'số lượng 0 bị từ chối');
same(R::reversalQty(T::Receipt, 7), -7, 'đảo phiếu nhập = −7');
same(R::reversalQty(T::Issue, 7), 7, 'đảo phiếu xuất = +7');

section('parseQty');
same(R::parseQty('12'), 12, 'số hợp lệ');
same(R::parseQty(' 7 '), 7, 'cắt khoảng trắng');
foreach (['-1', '1.5', '1e3', '', '0x10', '１２'] as $bad) {
    same(R::parseQty($bad), null, 'từ chối ' . var_export($bad, true));
}
same(R::parseQty(['1']), null, 'mảng bị từ chối');
same(R::parseQty('1000001'), null, 'vượt trần');

section('normalizeLines');
same(R::normalizeLines(['1:2', '', '3:4'], ['5', '', '6']), [['item_id' => 1, 'batch_id' => 2, 'qty' => 5], ['item_id' => 3, 'batch_id' => 4, 'qty' => 6]], 'bỏ dòng trống');
same(R::normalizeLines(['1:2', '1:2'], ['2', '3']), [['item_id' => 1, 'batch_id' => 2, 'qty' => 5]], 'gộp dòng trùng hàng+lô');
same(R::normalizeLines(['1:2', '1:3'], ['2', '3']) === [] , false, 'cùng hàng khác lô là hai dòng');
throws(fn() => R::normalizeLines(['1:2'], ['0']), DomainError::class, 'SL = 0');
throws(fn() => R::normalizeLines(['abc'], ['1']), DomainError::class, 'khoá hàng/lô sai');
throws(fn() => R::normalizeLines([''], ['5']), DomainError::class, 'có SL nhưng chưa chọn hàng');
throws(fn() => R::normalizeLines(['1:2'], ['']), DomainError::class, 'có hàng nhưng chưa nhập SL');

section('variance');
same(R::variance(10, 8), -2, 'thiếu 2');
same(R::variance(10, 13), 3, 'thừa 3');
same(R::variance(10, null), null, 'chưa đếm');
finish();
