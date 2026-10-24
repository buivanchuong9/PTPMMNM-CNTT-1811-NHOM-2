<?php
declare(strict_types=1);
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Core\Container;
use StockFlow\Core\Response;
use StockFlow\Core\Router;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\User;

section('Router');
$r = new Router();
$r->get('/vouchers/{id}', ['H', 'show']);
$r->post('/vouchers/{id}/confirm', ['H', 'confirm']);
same($r->match('GET', '/vouchers/42')['params'], ['id' => '42'], 'bắt tham số số nguyên');
same($r->match('GET', '/vouchers/abc'), null, 'tham số không phải số ⇒ không khớp');
same($r->match('GET', '/vouchers/42/confirm'), null, 'sai method ⇒ không khớp');
same($r->match('POST', '/vouchers/7/confirm')['handler'][1], 'confirm', 'POST confirm');
truthy($r->knowsPath('/vouchers/42/confirm'), 'biết đường dẫn tồn tại nhưng sai method (405)');

section('CSV an toàn');
$csv = Response::csv('x.csv', ['a'], [['=HYPERLINK("http://x")'], ['+1'], ['bình thường'], [5]])->body;
truthy(str_contains($csv, "'=HYPERLINK"), 'ô bắt đầu bằng = bị vô hiệu hoá (CSV injection)');
truthy(str_contains($csv, "'+1"), 'ô bắt đầu bằng + bị vô hiệu hoá');
truthy(str_contains($csv, 'bình thường'), 'ô thường giữ nguyên');
truthy(str_starts_with($csv, "\xEF\xBB\xBF"), 'có BOM UTF-8 cho Excel');

section('Container');
final class Leaf {}
final class Branch { public function __construct(public Leaf $leaf) {} }
$c = new Container();
same($c->get(Branch::class)->leaf === $c->get(Leaf::class), true, 'tự nối dependency, dùng chung một instance');

section('Quy đổi kiện (quy cách đóng gói)');
same(pack_label(130, 24), '5 kiện + 10 lẻ', '130 cái, 24/kiện → 5 kiện + 10 lẻ');
same(pack_label(48, 24), '2 kiện', 'chẵn kiện');
same(pack_label(7, 24), '7 lẻ', 'chưa đủ một kiện');
same(pack_label(50, 1), '', 'quy cách 1 ⇒ không quy đổi');
same(pack_label(0, 24), '', 'hết hàng ⇒ không quy đổi');

section('User');
same((new User(1, 'x', 'Hoàng Gia Khánh (giám sát)', AccountRole::Staff, 1))->initials(), 'K', 'chữ cái đầu bỏ phần trong ngoặc');
finish();
