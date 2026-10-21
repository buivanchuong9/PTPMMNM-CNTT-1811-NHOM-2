<?php
declare(strict_types=1);
// Phép thử 4 (qua HTTP thật): tài khoản chỉ phụ trách kho A KHÔNG lập được phiếu xuất kho B bằng yêu cầu trực tiếp
// (POST thủ công, bỏ qua giao diện), và mọi điểm vào đều kiểm tra đăng nhập + CSRF.
require __DIR__ . '/../Support/bootstrap.php';

$f = Fx::make();
$f->receive($f->wa, 'x', 30);
$f->receive($f->wb, 'x', 30);
$vb = $f->draftIssue($f->wb, 'x', 5);

$port = random_int(18000, 18999);
$sessions = sys_get_temp_dir() . '/sf-sess-' . bin2hex(random_bytes(4));
mkdir($sessions, 0700);
$server = proc_open([PHP_BINARY, '-d', "session.save_path=$sessions", '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2) . '/public', dirname(__DIR__, 2) . '/public/index.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($server, $sessions) {
    proc_terminate($server);
    foreach (glob("$sessions/*") ?: [] as $s) {
        @unlink($s);
    }
    @rmdir($sessions);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100_000);
}

/** Máy khách HTTP tối giản có cookie jar; không tự đi theo redirect. */
final class Client
{
    private string $cookie = '';
    public function __construct(private int $port) {}

    /** @return array{status:int,location:?string,body:string} */
    public function req(string $method, string $path, array $data = []): array
    {
        $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0,
            'header' => "Cookie: {$this->cookie}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)]]);
        $body = (string)file_get_contents("http://127.0.0.1:{$this->port}$path", false, $ctx);
        $status = 0;
        $location = null;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $status = (int)$m[1]; }
            if (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
            if (stripos($h, 'Set-Cookie: STOCKFLOW=') === 0) { $this->cookie = 'STOCKFLOW=' . explode(';', substr($h, 22))[0]; }
        }
        return ['status' => $status, 'location' => $location, 'body' => $body];
    }

    public function csrf(string $path = '/login'): string
    {
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $this->req('GET', $path)['body'], $m);
        return $m[1] ?? '';
    }

    public function login(string $user, string $pass): array
    {
        return $this->req('POST', '/login', ['csrf' => $this->csrf(), 'username' => $user, 'password' => $pass]);
    }
}

$count = fn(string $sql, array $p = []) => (int)$f->db->value($sql, $p);

section('Chưa đăng nhập');
$anon = new Client($port);
$r = $anon->req('GET', '/');
same([$r['status'], $r['location']], [303, '/login'], 'trang chủ chuyển về /login');
same($anon->req('GET', "/vouchers/{$vb['id']}")['status'], 303, 'xem chứng từ cần đăng nhập');
same($anon->req('GET', '/reports/stock.csv')['status'], 303, 'tải báo cáo cần đăng nhập');
same($anon->req('POST', '/vouchers', ['type' => 'ISSUE', 'warehouse_id' => $f->wb])['status'], 303, 'POST ẩn danh bị chuyển về đăng nhập');

section('Đăng nhập');
$bad = new Client($port);
same($bad->login($f->keeperA->username, 'sai-mat-khau')['status'], 401, 'sai mật khẩu ⇒ 401');
$a = new Client($port);
$r = $a->login($f->keeperA->username, Fx::PASSWORD);
same([$r['status'], $r['location']], [303, '/'], 'đúng mật khẩu ⇒ vào hệ thống');
same($a->req('POST', '/login', ['username' => 'x', 'password' => 'y'])['status'], 403, 'POST thiếu CSRF bị từ chối (403)');

section('Nhân viên kho A gửi yêu cầu trực tiếp lên kho B');
$csrf = $a->csrf('/vouchers/new?type=ISSUE');
$before = [$count('SELECT COUNT(*) FROM vouchers WHERE warehouse_id = ?', [$f->wb]), $f->movements($f->wb), $f->stock($f->wb, 'x')];
$r = $a->req('POST', '/vouchers', ['csrf' => $csrf, 'type' => 'ISSUE', 'warehouse_id' => $f->wb, 'idem' => bin2hex(random_bytes(16)),
    'line' => ["{$f->it['x']['item']}:{$f->it['x']['batch']}"], 'qty' => ['5'], 'partner' => 'tấn công']);
same($r['status'], 403, 'POST lập phiếu XUẤT kho B ⇒ 403');
same($count('SELECT COUNT(*) FROM vouchers WHERE warehouse_id = ?', [$f->wb]), $before[0], 'không có chứng từ mới ở kho B');
same($a->req('POST', "/vouchers/{$vb['id']}/confirm", ['csrf' => $csrf])['status'], 403, 'POST xác nhận phiếu của kho B ⇒ 403');
same($a->req('POST', "/vouchers/{$vb['id']}/cancel", ['csrf' => $csrf])['status'], 403, 'POST huỷ phiếu của kho B ⇒ 403');
same($a->req('GET', "/vouchers/{$vb['id']}")['status'], 403, 'GET xem phiếu kho B ⇒ 403');
same($a->req('POST', '/transfers', ['csrf' => $csrf, 'from_wh' => $f->wb, 'to_wh' => $f->wa, 'idem' => bin2hex(random_bytes(16)),
    'line' => ["{$f->it['x']['item']}:{$f->it['x']['batch']}"], 'qty' => ['5']])['status'], 403, 'POST điều chuyển ĐI từ kho B ⇒ 403');
same($a->req('POST', '/counts', ['csrf' => $csrf, 'warehouse_id' => $f->wb])['status'], 403, 'POST mở kiểm kê kho B ⇒ 403');
same($a->req('GET', '/users')['status'], 403, 'trang quản trị tài khoản ⇒ 403');
same($a->req('POST', '/warehouses', ['csrf' => $csrf, 'code' => 'HACK', 'name' => 'x'])['status'], 403, 'tạo kho mới ⇒ 403');
same([$count('SELECT COUNT(*) FROM vouchers WHERE warehouse_id = ?', [$f->wb]), $f->movements($f->wb), $f->stock($f->wb, 'x')], $before, 'kho B: chứng từ, sổ, tồn đều nguyên vẹn');
truthy(!str_contains($a->req('GET', '/inventory')['body'], "B{$f->tag}"), 'danh sách tồn không lộ tên kho B');
truthy(!str_contains($a->req('GET', '/history')['body'], $vb['code']), 'lịch sử không lộ chứng từ kho B');
truthy(!str_contains($a->req('GET', '/reports/stock.csv')['body'], "B{$f->tag}"), 'báo cáo CSV không có dữ liệu kho B');

section('Cùng tài khoản thao tác đúng kho của mình: hoạt động và idempotent');
$idem = bin2hex(random_bytes(16));
$post = ['csrf' => $csrf, 'type' => 'ISSUE', 'warehouse_id' => $f->wa, 'idem' => $idem, 'line' => ["{$f->it['x']['item']}:{$f->it['x']['batch']}"], 'qty' => ['5'], 'partner' => 'Khách'];
$r1 = $a->req('POST', '/vouchers', $post);
$r2 = $a->req('POST', '/vouchers', $post);     // gửi lại y hệt (bấm đúp / F5)
same($r1['status'], 303, 'lập phiếu xuất kho A thành công');
same($r2['location'], $r1['location'], 'gửi lại ⇒ cùng một chứng từ');
same($count('SELECT COUNT(*) FROM vouchers WHERE idempotency_key = ?', [$idem]), 1, 'chỉ một chứng từ trong CSDL');
same($a->req('POST', $r1['location'] . '/confirm', ['csrf' => $csrf])['status'], 403, 'nhưng nhân viên (không có quyền duyệt) không tự xác nhận được');

section('Người duyệt A: xác nhận, rồi gửi lại yêu cầu xác nhận');
$ap = new Client($port);
$ap->login($f->approverA->username, Fx::PASSWORD);
$csrf2 = $ap->csrf("{$r1['location']}");
$m0 = $f->movements($f->wa);
same($ap->req('POST', $r1['location'] . '/confirm', ['csrf' => $csrf2])['status'], 303, 'xác nhận lần 1');
$m1 = $f->movements($f->wa);
same($ap->req('POST', $r1['location'] . '/confirm', ['csrf' => $csrf2])['status'], 303, 'xác nhận lần 2 (gửi lại)');
same([$m1 - $m0, $f->movements($f->wa) - $m1, $f->stock($f->wa, 'x')], [1, 0, 25], 'chỉ ghi sổ một lần; tồn = 30 − 5 = 25');
same($ap->req('POST', "/vouchers/{$vb['id']}/confirm", ['csrf' => $csrf2])['status'], 403, 'người duyệt A không xác nhận được phiếu kho B');
finish();
