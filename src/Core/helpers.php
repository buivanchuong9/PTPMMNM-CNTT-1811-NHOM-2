<?php
declare(strict_types=1);

/**
 * Tên hệ thống hiển thị cho người dùng — đổi ở đây là đổi toàn bộ giao diện (thanh đầu, đăng nhập, tiêu đề tab, chân menu).
 * Từ đầu tiên viết thường, phần sau được tô màu nhấn trong logo chữ. Tên mã nguồn / lệnh `bin/stockflow` / CSDL `stockflow` giữ nguyên.
 */
const APP_NAME = 'StockFlow';

/** Tên hệ thống dạng logo chữ: «Stock<b>Flow</b>» (tách ở chữ hoa thứ hai, hoặc ở dấu cách nếu tên có nhiều từ). */
function app_brand(): string
{
    if (preg_match('/^(\S+?)([A-Z][a-z]+)$/', APP_NAME, $m)) {
        return e($m[1]) . '<b>' . e($m[2]) . '</b>';
    }
    $parts = explode(' ', APP_NAME, 2);
    return e($parts[0]) . (isset($parts[1]) ? ' <b>' . e($parts[1]) . '</b>' : '');
}

/** Mốc lọc thời gian nhanh ở các trang danh sách (khoá → nhãn). */
const DATE_RANGES = ['today' => 'Hôm nay', 'yesterday' => 'Hôm qua', '7d' => '7 ngày qua', '1m' => '1 tháng qua', '3m' => '3 tháng qua'];

/**
 * Khoảng ngày [từ, đến] (theo giờ Việt Nam, UTC+7) của một mốc lọc nhanh; null nếu khoá không hợp lệ.
 * @return ?array{0:string,1:string}
 */
function date_range(string $key): ?array
{
    $today = gmdate('Y-m-d', time() + 7 * 3600);
    $ago = static fn(string $spec): string => gmdate('Y-m-d', strtotime("$today 00:00:00 UTC $spec"));
    return match ($key) {
        'today' => [$today, $today],
        'yesterday' => [$ago('-1 day'), $ago('-1 day')],
        '7d' => [$ago('-6 days'), $today],
        '1m' => [$ago('-1 month'), $today],
        '3m' => [$ago('-3 months'), $today],
        default => null,
    };
}

/** Bỏ dấu tiếng Việt và hạ chữ thường, giữ nguyên số ký tự (để dò vị trí khớp ở chuỗi gốc). */
function fold_vn(string $s): string
{
    static $map = null;
    if ($map === null) {
        $from = 'àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ';
        $to = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd';
        $map = array_combine(mb_str_split($from), mb_str_split($to));
    }
    return strtr(mb_strtolower($s), $map);
}

/**
 * Lọc danh sách đã tải (mảng) theo ô tìm kiếm: không phân biệt hoa thường và dấu, MỌI từ khoá đều phải khớp.
 * $hay(row) trả về chuỗi gộp các trường được tìm. Từ khoá rỗng ⇒ giữ nguyên danh sách.
 * @param list<array<string,mixed>> $rows @param callable(array):string $hay @return list<array<string,mixed>>
 */
function filter_rows(array $rows, string $q, callable $hay): array
{
    $words = array_filter(preg_split('/\s+/u', fold_vn(trim($q))) ?: [], fn($w) => $w !== '');
    if (!$words) {
        return $rows;
    }
    return array_values(array_filter($rows, function ($row) use ($words, $hay) {
        $text = fold_vn((string)$hay($row));
        foreach ($words as $w) {
            if (!str_contains($text, $w)) {
                return false;
            }
        }
        return true;
    }));
}

/**
 * Ô «Thời gian» dùng chung (partials/range_filter.php): mốc nhanh thắng; «Tuỳ chọn» hoặc có từ/đến thì dùng ngày tự chọn.
 * @return array{0:?string,1:?string,2:string} [từ ngày, đến ngày, mốc đang chọn ('' | khoá DATE_RANGES | 'custom')]
 */
function range_params(\StockFlow\Core\Request $r): array
{
    if ($preset = date_range($r->str('range'))) {
        return [$preset[0], $preset[1], $r->str('range')];
    }
    $valid = function (string $k) use ($r): ?string {
        $v = $r->str($k);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null;
    };
    [$from, $to] = [$valid('from'), $valid('to')];
    if ($from && $to && $from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to, ($r->str('range') === 'custom' || $from || $to) ? 'custom' : ''];
}

/** Thời điểm lưu trong CSDL (UTC) có rơi vào khoảng ngày theo giờ Việt Nam không. Không chọn khoảng ⇒ luôn đúng. */
function in_vn_range(?string $ts, ?string $from, ?string $to): bool
{
    if (!$from && !$to) {
        return true;
    }
    if (!$ts) {
        return false;
    }
    $d = gmdate('Y-m-d', strtotime($ts . ' UTC') + 7 * 3600);
    return (!$from || $d >= $from) && (!$to || $d <= $to);
}

/** Lựa chọn [giá trị => nhãn] lấy từ chính các dòng đang có (không có mục rỗng), sắp theo nhãn. */
function options_of(array $rows, string $valueKey, string|callable $label): array
{
    $out = [];
    foreach ($rows as $row) {
        $v = $row[$valueKey] ?? null;
        if ($v !== null && $v !== '') {
            $out[(string)$v] = is_callable($label) ? $label($row) : (string)$row[$label];
        }
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/** <option> cho ô lọc: mục đầu là «tất cả» (giá trị rỗng). */
function filter_options(array $options, string|int|null $selected, string $placeholder): string
{
    $html = '<option value="">' . e($placeholder) . '</option>';
    foreach ($options as $v => $label) {
        $html .= '<option value="' . e($v) . '"' . ((string)$selected === (string)$v ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

/** Tham số lọc đang dùng (giữ lại khi chuyển tab, bỏ giá trị rỗng / mặc định). */
function keep_params(array $params): array
{
    return array_filter($params, fn($v) => $v !== null && $v !== '' && $v !== 0);
}

/** Escape rồi tô đậm phần khớp với từ khoá (không phân biệt hoa thường và dấu, giống cách tìm ở CSDL): «<mark class="hl">…</mark>». */
function hl(?string $text, string $needle): string
{
    $text = (string)$text;
    $needle = trim($needle);
    if ($needle === '' || $text === '') {
        return e($text);
    }
    $hay = fold_vn($text);
    $key = fold_vn($needle);
    if (mb_strlen($hay) !== mb_strlen($text) || $key === '') {
        return e($text);   // ký tự lạ làm lệch độ dài: bỏ tô để không cắt sai
    }
    $out = '';
    $pos = 0;
    $len = mb_strlen($key);
    while (($i = mb_strpos($hay, $key, $pos)) !== false) {
        $out .= e(mb_substr($text, $pos, $i - $pos)) . '<mark class="hl">' . e(mb_substr($text, $i, $len)) . '</mark>';
        $pos = $i + $len;
    }
    return $out . e(mb_substr($text, $pos));
}

/** Tên thao tác trong nhật ký (hanh_dong) bằng tiếng Việt; chưa có trong bảng thì giữ nguyên mã. */
function audit_label(string $action): string
{
    static $map = [
        'voucher.create' => 'Lập phiếu', 'voucher.confirm' => 'Xác nhận & ghi sổ', 'voucher.cancel' => 'Huỷ phiếu', 'voucher.correct' => 'Lập phiếu điều chỉnh',
        'voucher.delivery' => 'Ghi nhận giao hàng', 'voucher.delivered' => 'Tài xế đã giao đến nơi',
        'transfer.create' => 'Lập phiếu điều chuyển', 'transfer.confirm' => 'Xác nhận điều chuyển', 'transfer.dispatch' => 'Xuất kho đi', 'transfer.vehicle' => 'Chọn xe & tài xế',
        'transfer.receive' => 'Nhận hàng', 'transfer.short' => 'Nhận thiếu hàng', 'transfer.missing' => 'Báo chưa nhận được hàng', 'transfer.reply' => 'Quản trị phản hồi',
        'transfer.loss' => 'Xác nhận mất hàng', 'transfer.recall' => 'Thu hồi chuyến', 'transfer.split' => 'Chia hàng nhiều xe', 'transfer.discrepancy' => 'Báo sai lệch', 'transfer.correct' => 'Điều chỉnh điều chuyển',
        'request.create' => 'Gửi yêu cầu chuyển hàng', 'request.approve' => 'Duyệt yêu cầu chuyển hàng', 'request.accept' => 'Nhận yêu cầu chuyển hàng', 'request.cancel' => 'Huỷ yêu cầu chuyển hàng',
        'return.create' => 'Lập phiếu trả nhà cung cấp',
        'item.create' => 'Thêm mặt hàng', 'item.update' => 'Sửa mặt hàng', 'item.price' => 'Đặt giá', 'item.suppliers' => 'Gắn nhà cung cấp cho hàng', 'batch.create' => 'Thêm lô hàng',
        'category.create' => 'Thêm danh mục', 'proposal.create' => 'Đề xuất hàng mới', 'proposal.reject' => 'Từ chối đề xuất hàng',
        'partner.create' => 'Thêm đối tác', 'partner.update' => 'Sửa đối tác', 'partner.approve' => 'Duyệt đối tác', 'partner.reject' => 'Từ chối đối tác', 'partner.items' => 'Gắn hàng cho nhà cung cấp', 'partner.auto' => 'Tự gắn nhà cung cấp', 'partner.enable' => 'Dùng lại đối tác', 'partner.disable' => 'Ngừng dùng đối tác',
        'warranty.receive' => 'Nhận hàng bảo hành', 'warranty.da_sua' => 'Bảo hành: đã sửa xong', 'warranty.doi_moi' => 'Bảo hành: đổi mới', 'warranty.tu_choi' => 'Bảo hành: từ chối', 'warranty.return' => 'Trả hàng bảo hành cho khách',
        'disposal.propose' => 'Đề xuất huỷ hàng', 'disposal.approve' => 'Duyệt huỷ hàng', 'disposal.load' => 'Xếp hàng huỷ lên xe', 'disposal.destroy' => 'Tiêu huỷ hàng', 'disposal.undo' => 'Hoàn tác huỷ hàng',
        'vehicle.create' => 'Thêm xe', 'vehicle.update' => 'Sửa xe', 'vehicle.relocate' => 'Đặt vị trí xe', 'driver.create' => 'Thêm tài xế', 'driver.update' => 'Sửa tài xế',
        'driver.penalty' => 'Trừ điểm, lương tài xế', 'driver.pay' => 'Sửa điểm tài xế', 'fleet.distance' => 'Cập nhật quãng đường',
        'count.open' => 'Mở đợt kiểm kê', 'count.submit' => 'Nộp kết quả kiểm kê', 'count.approve' => 'Duyệt kiểm kê', 'count.cancel' => 'Huỷ đợt kiểm kê',
        'system.backup' => 'Sao lưu dữ liệu', 'creturn.create' => 'Lập phiếu khách trả hàng', 'creturn.confirm' => 'Xác nhận khách trả hàng', 'creturn.cancel' => 'Huỷ phiếu khách trả hàng', 'order.create' => 'Lập đơn đặt hàng', 'order.send' => 'Gửi đơn cho nhà cung cấp', 'order.close' => 'Đóng đơn đặt hàng', 'order.cancel' => 'Huỷ đơn đặt hàng', 'user.create' => 'Tạo tài khoản', 'user.profile' => 'Sửa hồ sơ', 'user.avatar' => 'Đổi ảnh đại diện', 'user.avatar.remove' => 'Xoá ảnh đại diện', 'role.grant' => 'Cấp / thu quyền', 'warehouse.create' => 'Thêm kho',
    ];
    return $map[$action] ?? $action;
}

/** Chi tiết một dòng nhật ký, dễ đọc: mã phiếu + ghi chú (bỏ mã loại thô như ISSUE / RECEIPT khi đã có mã phiếu). */
function audit_detail(array $a): string
{
    $d = (string)($a['chi_tiet'] ?? '');
    $ma = (string)($a['phieu_ma'] ?? '');
    if ($ma === '') {
        return $d;
    }
    $kinds = ['ISSUE' => 'phiếu xuất', 'RECEIPT' => 'phiếu nhập', 'ADJUSTMENT' => 'phiếu điều chỉnh', 'TRANSFER_OUT' => 'điều chuyển đi', 'TRANSFER_IN' => 'điều chuyển đến'];
    $d = $kinds[$d] ?? $d;
    return $d === '' || $d === $ma ? $ma : (str_contains($d, $ma) ? $d : $ma . ' · ' . $d);
}

/** Nhóm của một hành động trong nhật ký (để lọc). */
const AUDIT_GROUPS = [
    'voucher' => 'Phiếu & chuyển kho', 'goods' => 'Hàng hoá & đối tác', 'warranty' => 'Bảo hành', 'disposal' => 'Kho huỷ',
    'fleet' => 'Đội xe', 'count' => 'Kiểm kê', 'account' => 'Tài khoản & kho',
];
function audit_group(string $action): string
{
    $head = explode('.', $action)[0];
    return match ($head) {
        'voucher', 'transfer', 'request', 'return' => 'voucher',
        'item', 'batch', 'category', 'proposal', 'partner' => 'goods',
        'warranty' => 'warranty', 'disposal' => 'disposal', 'vehicle', 'driver', 'fleet' => 'fleet', 'count' => 'count',
        default => 'account',
    };
}

/** Đối tượng này có nằm trong phần «thay đổi mới» của trang đang mở không (người khác vừa tạo / sửa, chưa xem). */
function is_new(?array $changes, int|string|null $id): bool
{
    return $changes !== null && $id !== null && isset($changes['ids'][(int)$id]);
}

/** Nhãn «Mới» cạnh đối tượng vừa thay đổi. */
function new_tag(?array $changes, int|string|null $id): string
{
    return is_new($changes, $id) ? ' <span class="new-tag">Mới</span>' : '';
}

/** Dòng sổ phát sinh mới (id nằm trong khoảng vừa xuất hiện kể từ lần xem trước). */
function is_new_ledger(?array $changes, int $id): bool
{
    return $changes !== null && ($changes['section'] ?? '') === 'history' && $id > (int)$changes['after'] && $id <= (int)$changes['upto'];
}

/** Escape HTML đầu ra. */
function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Số nguyên kiểu Việt Nam: 1.234. */
function n(int|string|null $v): string
{
    return number_format((int)$v, 0, ',', '.');
}

/** Số tiền gọn cho ô thống kê: 4.521.300.000 → «4,52 tỷ», 38.500.000 → «38,5 triệu», số nhỏ giữ nguyên. */
function vnd_short(int $v): string
{
    if ($v >= 1_000_000_000) {
        return rtrim(rtrim(number_format($v / 1_000_000_000, 2, ',', '.'), '0'), ',') . ' tỷ';
    }
    if ($v >= 1_000_000) {
        return rtrim(rtrim(number_format($v / 1_000_000, 1, ',', '.'), '0'), ',') . ' triệu';
    }
    return n($v);
}

/** Quy đổi số lượng ra kiện (thùng đóng sẵn) theo quy cách đóng gói: 130 với 24/kiện → "5 kiện + 10 lẻ". Quy cách 1 (bán lẻ) → chuỗi rỗng. */
function pack_label(int $qty, int $pack): string
{
    if ($pack <= 1 || $qty <= 0) {
        return '';
    }
    $full = intdiv($qty, $pack);
    $rest = $qty % $pack;
    return trim(($full > 0 ? n($full) . ' kiện' : '') . ($rest > 0 ? ($full > 0 ? ' + ' : '') . n($rest) . ' lẻ' : ''));
}

/** Đường dẫn file tĩnh kèm số phiên bản theo thời điểm sửa file: sửa CSS / JS là trình duyệt tự nạp lại, không phải Ctrl+F5. */
function asset_url(string $path): string
{
    $file = \StockFlow\Core\Config::root() . '/public' . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Gram ⇒ chữ dễ đọc: 350 ⇒ «350 g», 17500 ⇒ «17,5 kg». 0 ⇒ chuỗi rỗng. */
function weight_label(int $grams): string
{
    if ($grams <= 0) {
        return '';
    }
    return $grams < 1000 ? number_format($grams, 0, ',', '.') . ' g' : rtrim(rtrim(number_format($grams / 1000, 2, ',', '.'), '0'), ',') . ' kg';
}

/** Ba cạnh mm ⇒ «18 × 9,5 × 5,5 cm». Thiếu cạnh nào thì rỗng. */
function size_label(int $l, int $w, int $h): string
{
    if ($l <= 0 || $w <= 0 || $h <= 0) {
        return '';
    }
    $cm = fn(int $mm) => rtrim(rtrim(number_format($mm / 10, 1, ',', '.'), '0'), ',');
    return $cm($l) . ' × ' . $cm($w) . ' × ' . $cm($h) . ' cm';
}

/**
 * Cân nặng (gram) của $qty đơn vị một mặt hàng: kiện nguyên tính theo cân cả kiện (đã khai, hoặc tự tính = số đơn vị × cân đơn vị), phần lẻ theo cân đơn vị.
 * @param array<string,mixed> $item dòng mat_hang (quy_cach_lo, khoi_luong_g, kien_g)
 */
function stock_weight_g(array $item, int $qty): int
{
    $pack = max(1, (int)($item['quy_cach_lo'] ?? 1));
    $unit = (int)($item['khoi_luong_g'] ?? 0);
    $box = (int)($item['kien_g'] ?? 0) > 0 ? (int)$item['kien_g'] : $pack * $unit;
    return intdiv($qty, $pack) * $box + ($qty % $pack) * $unit;
}

/** Số tiền bằng chữ cho chứng từ in: 4428000 → «Bốn triệu bốn trăm hai mươi tám nghìn đồng». */
function vnd_words(int $v): string
{
    if ($v <= 0) {
        return 'Không đồng';
    }
    $d = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
    $three = function (int $x, bool $full) use ($d): string {   // $full: còn nhóm lớn hơn phía trước ⇒ đọc đủ «không trăm»
        [$h, $t, $u] = [intdiv($x, 100), intdiv($x % 100, 10), $x % 10];
        $out = [];
        if ($h > 0 || $full) {
            $out[] = $d[$h] . ' trăm';
        }
        if ($t > 1) {
            $out[] = $d[$t] . ' mươi';
        } elseif ($t === 1) {
            $out[] = 'mười';
        } elseif ($u > 0 && ($h > 0 || $full)) {
            $out[] = 'lẻ';
        }
        if ($u > 0) {
            $out[] = $u === 1 && $t > 1 ? 'mốt' : ($u === 5 && $t > 0 ? 'lăm' : $d[$u]);
        }
        return implode(' ', $out);
    };
    $units = ['', ' nghìn', ' triệu', ' tỷ'];
    $groups = [];
    for ($i = 0; $v > 0; $i++, $v = intdiv($v, 1000)) {
        $groups[] = $v % 1000;
    }
    $parts = [];
    foreach (array_reverse($groups, true) as $i => $g) {
        if ($g > 0) {
            $parts[] = $three($g, $i < count($groups) - 1) . $units[$i % 4];
        }
    }
    $text = implode(' ', $parts) . ' đồng';
    return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
}

/** Số có dấu: +5 / −3. */
function signed(int $v): string
{
    return ($v > 0 ? '+' : '') . n($v);
}

/** Giờ Việt Nam (UTC+7) từ timestamp UTC trong CSDL. */
function dt(?string $v, bool $time = true): string
{
    if (!$v) {
        return '—';
    }
    return gmdate($time ? 'd/m/Y H:i' : 'd/m/Y', strtotime($v . ' UTC') + 7 * 3600);
}

/** Nhãn hạn dùng của một lô (kết quả StockQuery::expiryState): hết hạn · sắp hết hạn (còn N ngày) · còn hạn. */
function expiry_tag(array $s): string
{
    return match ($s['status']) {
        'expired' => '<span class="tag tag-danger">Hết hạn ' . n(abs((int)$s['days'])) . ' ngày</span>',
        'soon' => '<span class="tag tag-warn">Còn ' . n((int)$s['days']) . ' ngày</span>',
        'ok' => '<span class="tag tag-ok">Còn hạn</span>',
        default => '<span class="muted">Không ghi hạn</span>',
    };
}

const STATUS_LABELS = [
    'DRAFT' => ['Chờ xác nhận', 'warn'], 'CONFIRMED' => ['Đã xác nhận', 'ok'], 'CANCELLED' => ['Đã huỷ', 'danger'],
    'OPEN' => ['Đang kiểm đếm', 'info'], 'REVIEW' => ['Chờ duyệt chênh lệch', 'warn'], 'CLOSED' => ['Đã đóng', 'ok'],
];

const TYPE_LABELS = [
    'RECEIPT' => ['Phiếu nhập', 'in'], 'ISSUE' => ['Phiếu xuất', 'out'], 'TRANSFER_OUT' => ['Điều chuyển đi', 'xfer'],
    'TRANSFER_IN' => ['Điều chuyển đến', 'xfer'], 'ADJUSTMENT' => ['Điều chỉnh', 'adj'],
];

/** Tông màu hàng danh sách theo trạng thái (dùng cho thuộc tính data-tone). */
function status_tone(string $status): string
{
    return ['DRAFT' => 'warn', 'REVIEW' => 'warn', 'CONFIRMED' => 'ok', 'CLOSED' => 'ok', 'CANCELLED' => 'bad', 'OPEN' => 'info'][$status] ?? 'all';
}

/** Nhãn trạng thái dạng "tag" kiểu cổng vận đơn. */
function status_tag(string $status): string
{
    [$label, $tone] = STATUS_LABELS[$status] ?? [$status, 'muted'];
    return '<span class="tag tag-' . $tone . '">' . e($label) . '</span>';
}

/** Trạng thái phiếu có tính đến hành trình điều chuyển: đang vận chuyển / đã nhận. */
function voucher_status_tag(array $v): string
{
    $s = $v['trang_thai'];
    if ($v['loai'] === 'TRANSFER_OUT' && $s === 'CONFIRMED') {
        return ($v['pair_status'] ?? '') === 'DRAFT'
            ? '<span class="tag tag-info">Đang vận chuyển</span>' : '<span class="tag tag-ok">Đã đến kho</span>';
    }
    if ($v['loai'] === 'TRANSFER_IN') {
        return $s === 'DRAFT' ? '<span class="tag tag-info">Hàng đang tới — chờ nhận</span>' : ($s === 'CONFIRMED' ? '<span class="tag tag-ok">Đã nhận hàng</span>' : status_tag($s));
    }
    if ($v['loai'] === 'ISSUE' && $s === 'CONFIRMED' && (int)($v['da_tra'] ?? 0) > 0) {   // khách đã trả hàng (phiếu trả đã xác nhận)
        return (int)$v['da_tra'] >= (int)$v['da_ban'] ? '<span class="tag tag-warn">Hoàn trả</span>' : '<span class="tag tag-info">Hoàn trả một phần</span>';
    }
    if ($v['loai'] === 'ISSUE' && $s === 'CONFIRMED' && !empty($v['dang_giao'])) {   // đã ghi sổ nhưng hàng chưa tới tay khách (tài xế chưa báo giao đến nơi)
        return '<span class="tag tag-info">Đang giao hàng</span>';
    }
    return status_tag($s);
}

/** Màu dòng của phiếu trong danh sách: phiếu xuất đang giao hàng cho khách thì màu «đang xử lý» thay vì «đã xong». */
function voucher_tone(array $v): string
{
    if ($v['loai'] === 'ISSUE' && $v['trang_thai'] === 'CONFIRMED' && (int)($v['da_tra'] ?? 0) > 0) {
        return 'warn';
    }
    return $v['loai'] === 'ISSUE' && $v['trang_thai'] === 'CONFIRMED' && !empty($v['dang_giao']) ? 'info' : status_tone($v['trang_thai']);
}

function type_chip(string $type): string
{
    [$label, $tone] = TYPE_LABELS[$type] ?? [$type, 'adj'];
    return '<span class="chip chip-' . $tone . '">' . e($label) . '</span>';
}

function icon(string $name): string
{
    static $paths = [
        'scan' => '<path d="M4 8V5a1 1 0 011-1h3M16 4h3a1 1 0 011 1v3M20 16v3a1 1 0 01-1 1h-3M8 20H5a1 1 0 01-1-1v-3"/><path d="M8 9v6M12 9v6M16 9v6"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'home' =>'<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'box' => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'layers' => '<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>',
        'truck' => '<path d="M2 6h11v10H2z"/><path d="M13 9h5l3 3v4h-8"/><circle cx="7" cy="17.5" r="1.7"/><circle cx="17" cy="17.5" r="1.7"/>',
        'pin' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'in' => '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 20h16"/>',
        'out' => '<path d="M12 15V3"/><path d="M7 8l5-5 5 5"/><path d="M4 20h16"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 13l2 2 4-4"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'scale' => '<path d="M12 4v16"/><path d="M5 8h14"/><path d="M5 8l-3 7a3 3 0 006 0L5 8z"/><path d="M19 8l-3 7a3 3 0 006 0l-3-7z"/>',
        'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
        'warehouse' => '<path d="M3 21V9l9-6 9 6v12"/><path d="M7 21v-7h10v7"/><path d="M7 17h10"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 5.5a3 3 0 010 5.6M18 14.4c1.8.8 3 2.6 3 5.6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'printer' => '<path d="M7 9V3h10v6"/><path d="M7 17H5a1 1 0 01-1-1v-5a2 2 0 012-2h12a2 2 0 012 2v5a1 1 0 01-1 1h-2"/><path d="M7 14h10v7H7z"/>',
        'download' => '<path d="M12 4v11"/><path d="M7 10l5 5 5-5"/><path d="M4 20h16"/>',
        'alert' => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18v.5"/>',
        'check' => '<path d="M5 12l4 4 10-10"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4.2M6.6 6.6A17 17 0 002 12s3.5 7 10 7c1.8 0 3.4-.5 4.8-1.2"/><path d="M9.9 9.9a3 3 0 004.2 4.2"/>',
        'logout' => '<path d="M9 4H5v16h4"/><path d="M16 8l4 4-4 4"/><path d="M20 12H9"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** <option> cho danh sách hàng/lô: value = "item:batch". */
function pick_options(array $pick, ?string $selected = null): string
{
    $html = '<option value="">Chọn mặt hàng / lô…</option>';
    foreach ($pick as $p) {
        $lot = $p['quan_ly_lo'] ? ' · lô ' . $p['so_lo'] . ($p['han_dung'] ? ' (HSD ' . dt($p['han_dung'], false) . ')' : '') : '';
        $v = $p['mat_hang_id'] . ':' . $p['lo_hang_id'];
        $html .= '<option value="' . e($v) . '"' . ($v === $selected ? ' selected' : '') . '>' . e($p['ma_hang'] . ' — ' . $p['ten'] . $lot . ' [' . $p['unit'] . ']') . '</option>';
    }
    return $html;
}

function wh_options(array $rows, ?int $selected = null, string $placeholder = ''): string
{
    $html = $placeholder !== '' ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($rows as $r) {
        $html .= '<option value="' . (int)$r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '')
            . (isset($r['dia_chi']) ? ' data-addr="' . e($r['dia_chi']) . '"' : '') . '>' . e($r['ma'] . ' — ' . $r['ten']) . '</option>';
    }
    return $html;
}

/** Kho cố định của tài khoản quản lý / nhân viên: hiển thị mã, tên, địa chỉ và gửi kèm kho_id ẩn — không cho chọn kho khác. */
function wh_fixed(array $wh, string $label, string $field): string
{
    return '<div class="wh-fixed"><span class="wh-fixed-label">' . e($label) . '</span>'
        . '<b><span class="wh-code">' . e($wh['ma']) . '</span> ' . e($wh['ten']) . '</b>'
        . '<small class="muted">' . icon('pin') . e($wh['dia_chi'] ?: 'Chưa có địa chỉ') . '</small>'
        . '<input type="hidden" name="' . e($field) . '" value="' . (int)$wh['id'] . '"></div>';
}

/** Ảnh đại diện tròn (hoặc chữ cái đầu của tên khi chưa có ảnh). $cls: sm | xl. */
function avatar_html(int $id, ?string $file, string $name, string $cls = ''): string
{
    if ($file) {
        return '<span class="avatar ' . e($cls) . '"><img src="/avatar/' . $id . '?v=' . substr(md5($file), 0, 8) . '" alt="" loading="lazy"></span>';
    }
    $parts = preg_split('/\s+/u', trim((string)preg_replace('/\(.*?\)/u', '', $name))) ?: [];
    return '<span class="avatar ' . e($cls) . '" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string)(end($parts) ?: '?'), 0, 1))) . '</span>';
}

/** Giờ ngắn gọn cho bảng dày: 21/10 15:00. */
function dt_short(?string $v): string
{
    return $v ? gmdate('d/m H:i', strtotime($v . ' UTC') + 7 * 3600) : '—';
}

/** Thanh phân trang (giữ nguyên các tham số lọc hiện có trên URL). Không hiển thị gì nếu chỉ có một trang. */
function pager(\StockFlow\Core\Paginator $pg): string
{
    if ($pg->total === 0) {
        return '';
    }
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $link = function (int $page, string $label, string $class = '', string $aria = '') use ($pg, $path): string {
        $q = $_GET;
        $page > 1 ? $q[$pg->param] = $page : $q[$pg->param] = null;
        $qs = http_build_query(array_filter($q, fn($v) => $v !== null && $v !== ''));
        return '<a class="' . $class . '" href="' . e($path . ($qs !== '' ? '?' . $qs : '')) . '"' . ($aria ? ' aria-label="' . e($aria) . '"' : '') . '>' . $label . '</a>';
    };
    $info = '<span class="pager-info">Hiển thị <b>' . n($pg->from()) . '–' . n($pg->to()) . '</b> / ' . n($pg->total) . '</span>';
    if ($pg->pages === 1) {
        return '<div class="pager">' . $info . '</div>';
    }
    $pages = array_unique(array_filter([1, $pg->pages, ...range(max(1, $pg->page - 2), min($pg->pages, $pg->page + 2))], fn($p) => $p >= 1 && $p <= $pg->pages));
    sort($pages);
    $nav = '';
    $nav .= $pg->page > 1 ? $link($pg->page - 1, '‹', 'pg', 'Trang trước') : '<span class="pg off">‹</span>';
    $prev = 0;
    foreach ($pages as $p) {
        if ($prev && $p - $prev > 1) {
            $nav .= '<span class="pg gap">…</span>';
        }
        $nav .= $p === $pg->page ? '<span class="pg on" aria-current="page">' . $p . '</span>' : $link($p, (string)$p, 'pg');
        $prev = $p;
    }
    $nav .= $pg->page < $pg->pages ? $link($pg->page + 1, '›', 'pg', 'Trang sau') : '<span class="pg off">›</span>';
    return '<nav class="pager" aria-label="Phân trang">' . $info . '<span class="pager-nav">' . $nav . '</span></nav>';
}
