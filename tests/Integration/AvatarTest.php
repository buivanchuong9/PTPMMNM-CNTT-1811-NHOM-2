<?php
declare(strict_types=1);
// Ảnh đại diện: mỗi tài khoản tự đổi ảnh của mình; kiểm tra nội dung thật của file; tên file do hệ thống sinh; lưu thư mục storage + tên file trong CSDL.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Core\Config;
use StockFlow\Domain\DomainError;
use StockFlow\Service\AuthService;
use StockFlow\Service\SettingsService;

// Ảnh kiểm thử ghi vào thư mục riêng, không đụng storage/avatars thật.
$dir = Config::root() . '/.local/test-storage-' . bin2hex(random_bytes(3));
putenv('STOCKFLOW_STORAGE=' . $dir);

/** PNG hợp lệ w×h (tự dựng, không cần thư viện ảnh). */
function png(int $w, int $h): string
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\0" . str_repeat("\xD1\x2F\x3F", $w);
    }
    $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
}
function tmp(string $bytes): string
{
    $p = tempnam(sys_get_temp_dir(), 'av');
    file_put_contents($p, $bytes);
    return $p;
}
$files = fn() => array_values(array_diff(is_dir($dir . '/avatars') ? scandir($dir . '/avatars') : [], ['.', '..']));

$f = Fx::make();
$st = $f->c->get(SettingsService::class);
$auth = $f->c->get(AuthService::class);
$u = $f->keeperA;

section('Lưu ảnh hợp lệ');
$p1 = tmp(png(128, 128));
$name = $st->saveAvatar($u, $p1, filesize($p1));
truthy(preg_match('/^u' . $u->id . '_[a-f0-9]{12}\.png$/', $name) === 1, 'tên file do hệ thống sinh (không dùng tên người dùng gửi): ' . $name);
same($files(), [$name], 'file nằm trong thư mục lưu ảnh đại diện');
same($f->db->value('SELECT anh_dai_dien FROM nguoi_dung WHERE id = ?', [$u->id]), $name, 'tên file được lưu trong CSDL');
$av = $st->avatarFile($u->id);
same([$av['mime'] ?? null, is_file($av['path'] ?? '')], ['image/png', true], 'đọc lại ra đúng loại ảnh và file tồn tại');
same($auth->find($u->id)->avatar, $name, 'nạp tài khoản có kèm ảnh đại diện');
truthy(str_starts_with((string)$auth->find($u->id)->avatarUrl(), '/avatar/' . $u->id . '?v='), 'địa chỉ ảnh có mã phiên bản để làm mới bộ nhớ đệm');

section('Đổi ảnh: ảnh cũ bị xoá, chỉ còn một file');
$p2 = tmp(png(96, 96));
$name2 = $st->saveAvatar($u, $p2, filesize($p2));
truthy($name2 !== $name, 'ảnh mới có tên mới');
same($files(), [$name2], 'ảnh cũ đã bị xoá khỏi thư mục');

section('Từ chối file không hợp lệ');
$bad = [
    'file văn bản giả đuôi ảnh' => tmp("<?php echo 'hack'; ?>"),
    'SVG (có thể chứa script)' => tmp('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
    'ảnh quá nhỏ 32×32' => tmp(png(32, 32)),
    'file rỗng' => tmp(''),
];
foreach ($bad as $label => $path) {
    throws(fn() => $st->saveAvatar($u, $path, (int)filesize($path)), DomainError::class, "từ chối: $label");
}
throws(fn() => $st->saveAvatar($u, $p1, SettingsService::AVATAR_MAX_BYTES + 1), DomainError::class, 'từ chối: quá 2 MB');
same($files(), [$name2], 'sau các lần bị từ chối, ảnh hiện tại không đổi và không có file rác');
same($f->db->value('SELECT anh_dai_dien FROM nguoi_dung WHERE id = ?', [$u->id]), $name2, 'CSDL vẫn trỏ tới ảnh hiện tại');

section('Mỗi tài khoản chỉ đổi ảnh của chính mình');
same($f->db->value('SELECT anh_dai_dien FROM nguoi_dung WHERE id = ?', [$f->keeperB->id]), null, 'tài khoản khác không bị ảnh hưởng');
$p3 = tmp(png(80, 80));
$nameB = $st->saveAvatar($f->keeperB, $p3, filesize($p3));
same($f->db->value('SELECT anh_dai_dien FROM nguoi_dung WHERE id = ?', [$u->id]), $name2, 'lưu ảnh cho B không đụng tới ảnh của A');
same(count($files()), 2, 'mỗi tài khoản một file riêng');

section('Chống đọc file ngoài thư mục ảnh');
$f->db->exec('UPDATE nguoi_dung SET anh_dai_dien = ? WHERE id = ?', ['../../src/bootstrap.php', $f->approverA->id]);
same($st->avatarFile($f->approverA->id), null, 'tên file kiểu ../ không bao giờ được phục vụ');
$f->db->exec('UPDATE nguoi_dung SET anh_dai_dien = NULL WHERE id = ?', [$f->approverA->id]);
same($st->avatarFile($f->approverB->id), null, 'tài khoản chưa có ảnh ⇒ không có file');

section('Xoá ảnh');
$st->removeAvatar($u);
same($f->db->value('SELECT anh_dai_dien FROM nguoi_dung WHERE id = ?', [$u->id]), null, 'CSDL không còn tên file');
same($files(), [$nameB], 'file của tài khoản đã xoá bị gỡ khỏi thư mục');
same($st->avatarFile($u->id), null, 'không còn phục vụ ảnh');
$st->removeAvatar($u);
truthy(true, 'xoá lần nữa khi đã không có ảnh: không lỗi');

// dọn thư mục kiểm thử
foreach ($files() as $x) {
    @unlink("$dir/avatars/$x");
}
@rmdir("$dir/avatars");
@rmdir($dir);
finish();
