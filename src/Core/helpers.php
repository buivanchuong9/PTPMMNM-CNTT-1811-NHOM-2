<?php
declare(strict_types=1);

/**
 * Tên hệ thống hiển thị cho người dùng — đổi ở đây là đổi toàn bộ giao diện (thanh đầu, đăng nhập, tiêu đề tab, chân menu).
 * Từ đầu tiên viết thường, phần sau được tô màu nhấn trong logo chữ. Tên mã nguồn / lệnh `bin/stockflow` / CSDL `stockflow` giữ nguyên.
 */
const APP_NAME = 'Chương Đại Dương';

/** Tên hệ thống dạng logo chữ: «Chương <b>Đại Dương</b>». */
function app_brand(): string
{
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
        'transfer.create' => 'Lập phiếu điều chuyển', 'transfer.dispatch' => 'Xuất kho đi', 'transfer.vehicle' => 'Chọn xe & tài xế',
        'transfer.receive' => 'Nhận hàng', 'transfer.short' => 'Nhận thiếu hàng', 'transfer.missing' => 'Báo chưa nhận được hàng', 'transfer.reply' => 'Quản trị phản hồi', 'transfer.loss' => 'Xác nhận mất hàng', 'driver.penalty' => 'Trừ điểm, lương tài xế', 'driver.pay' => 'Sửa điểm, lương tài xế', 'transfer.discrepancy' => 'Báo sai lệch', 'transfer.correct' => 'Điều chỉnh điều chuyển',
    ];
    return $map[$action] ?? $action;
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
    return status_tag($s);
}

function type_chip(string $type): string
{
    [$label, $tone] = TYPE_LABELS[$type] ?? [$type, 'adj'];
    return '<span class="chip chip-' . $tone . '">' . e($label) . '</span>';
}

function icon(string $name): string
{
    static $paths = [
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
