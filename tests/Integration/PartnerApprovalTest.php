<?php
declare(strict_types=1);
// Đối tác do quản lý kho thêm phải chờ quản trị duyệt
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\PartnerService;

$f = Fx::make();
$partners = $f->c->get(PartnerService::class);
$db = $f->c->get(StockFlow\Core\Db::class);
$state = fn(int $id) => $db->one('SELECT duyet, hoat_dong FROM doi_tac WHERE id = ?', [$id]);

section('Quản lý kho thêm đối tác ⇒ chờ quản trị duyệt');
$id = $partners->add($f->approverA, 'PA' . $f->tag, 'NCC chờ duyệt ' . $f->tag, 'SUPPLIER', '', '', '', '', '');
same($state($id)['duyet'], 'CHO_DUYET', 'quản lý thêm ⇒ chờ duyệt');
same((int)$state($id)['hoat_dong'], 0, 'chờ duyệt thì chưa hoạt động (không hiện trong phiếu)');
same($partners->pendingCount() >= 1, true, 'đếm được đối tác chờ duyệt');
throws(fn() => $partners->setActive($f->approverA, $id, true), Forbidden::class, 'quản lý không tự bật đối tác chờ duyệt');
throws(fn() => $partners->approve($f->approverA, $id), Forbidden::class, 'quản lý không tự duyệt');
throws(fn() => $partners->reject($f->approverA, $id), Forbidden::class, 'quản lý không tự từ chối');

section('Quản trị duyệt / từ chối');
$partners->approve($f->admin, $id);
same($state($id)['duyet'], 'DA_DUYET', 'đã duyệt');
same((int)$state($id)['hoat_dong'], 1, 'duyệt xong thì hoạt động');
throws(fn() => $partners->approve($f->admin, $id), DomainError::class, 'không duyệt lần hai');
$id2 = $partners->add($f->approverA, 'PB' . $f->tag, 'NCC bị từ chối ' . $f->tag, 'SUPPLIER', '', '', '', '', '');
$partners->reject($f->admin, $id2);
same($state($id2), null, 'từ chối ⇒ xoá đối tác chờ duyệt');
$id3 = $partners->add($f->admin, 'PC' . $f->tag, 'NCC của quản trị ' . $f->tag, 'SUPPLIER', '', '', '', '', '');
same($state($id3)['duyet'], 'DA_DUYET', 'quản trị thêm ⇒ dùng được ngay');

finish();
