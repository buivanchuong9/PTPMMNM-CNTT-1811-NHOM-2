<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;

/**
 * Nhập hàng loạt từ file CSV (UTF-8, dấu phẩy hoặc chấm phẩy). Hai loại:
 *  - danh mục mặt hàng (quản trị): mỗi dòng một mặt hàng mới, đi qua CatalogService::addItem nên đúng mọi quy tắc cũ;
 *  - tồn đầu kỳ (quản lý / nhân viên kho): gom thành MỘT phiếu nhập NHÁP của kho mình, kiểm tra rồi xác nhận như phiếu thường.
 * Luôn xem trước từng dòng (hợp lệ / lỗi) rồi mới ghi; dòng lỗi bị bỏ qua, không làm hỏng cả file.
 */
final class ImportService
{
    public const MAX_ROWS = 2000;
    public const ITEM_HEADERS = ['SKU', 'Tên hàng', 'Đơn vị tính', 'Loại hàng', 'Theo lô (có/không)', 'Tồn tối thiểu', 'Quy cách (đơn vị/kiện)', 'Bảo hành (tháng)', 'Mã vạch'];
    public const STOCK_HEADERS = ['SKU', 'Số lô', 'Hạn dùng (dd/mm/yyyy)', 'Số lượng'];

    public function __construct(private Db $db, private CatalogService $catalog, private VoucherService $vouchers) {}

    /** Chuẩn hoá tên cột: bỏ dấu, chữ thường, bỏ phần trong ngoặc. */
    private static function key(string $h): string
    {
        $h = preg_replace('/\([^)]*\)/u', '', $h) ?? $h;
        return trim(preg_replace('/\s+/', ' ', fold_vn(trim($h, " \t\n\r\0\x0B\xEF\xBB\xBF"))) ?? '');
    }

    /**
     * Đọc file CSV thành các dòng {tên cột chuẩn ⇒ giá trị}. @return list<array<string,string>> khoá thêm '_line' = số dòng trong file
     * @param array<string,list<string>> $aliases cột chuẩn ⇒ các tên cột chấp nhận (đã chuẩn hoá)
     */
    public function read(string $path, array $aliases): array
    {
        $text = (string)file_get_contents($path);
        if ($text === '') {
            throw new DomainError('File rỗng');
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new DomainError('File phải lưu dạng CSV UTF-8 (Excel: Save As → CSV UTF-8)');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $first = strtok($text, "\n") ?: '';
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $h = fopen('php://memory', 'r+');
        fwrite($h, $text);
        rewind($h);
        $head = fgetcsv($h, 0, $sep, '"', '');
        if (!$head) {
            throw new DomainError('Không đọc được dòng tiêu đề');
        }
        $map = [];
        foreach ($head as $i => $name) {
            $k = self::key((string)$name);
            foreach ($aliases as $std => $names) {
                if (in_array($k, $names, true)) {
                    $map[$i] = $std;
                }
            }
        }
        $missing = array_diff(array_keys($aliases), array_values($map));
        if ($missing) {
            throw new DomainError('Thiếu cột: ' . implode(', ', $missing) . '. Hãy dùng đúng file mẫu.');
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            $line++;
            if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) {
                continue;
            }
            $row = array_fill_keys(array_keys($aliases), '');
            foreach ($map as $i => $std) {
                $row[$std] = trim((string)($cells[$i] ?? ''));
            }
            $row['_line'] = (string)$line;
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                throw new DomainError('Tối đa ' . self::MAX_ROWS . ' dòng mỗi lần nhập');
            }
        }
        fclose($h);
        if (!$rows) {
            throw new DomainError('File không có dòng dữ liệu nào');
        }
        return $rows;
    }

    private static function aliasesItems(): array
    {
        return ['sku' => ['sku', 'ma hang', 'ma'], 'name' => ['ten hang', 'ten', 'ten mat hang'], 'unit' => ['don vi tinh', 'dvt', 'don vi'], 'category' => ['loai hang', 'danh muc'],
            'lot' => ['theo lo', 'quan ly lo'], 'reorder' => ['ton toi thieu', 'dinh muc', 'dinh muc toi thieu'], 'pack' => ['quy cach', 'quy cach dong goi'],
            'warranty' => ['bao hanh', 'bao hanh thang'], 'barcode' => ['ma vach', 'barcode']];
    }

    private static function aliasesStock(): array
    {
        return ['sku' => ['sku', 'ma hang', 'ma'], 'lot' => ['so lo', 'lo'], 'expiry' => ['han dung', 'hsd'], 'qty' => ['so luong', 'sl']];
    }

    private static function truthy(string $v): bool
    {
        return in_array(fold_vn(trim($v)), ['co', 'c', '1', 'x', 'yes', 'y', 'true', 'dung'], true);
    }

    /** @return ?string Y-m-d, hoặc null nếu không phải ngày hợp lệ */
    public static function parseDate(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $v, $m)) {
            [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    // ───── danh mục mặt hàng ─────

    /** @return list<array{line:int,data:array<string,mixed>,error:?string}> */
    public function previewItems(string $path): array
    {
        $units = [];
        foreach ($this->db->all('SELECT id, ma, ten FROM don_vi_tinh') as $u) {
            $units[fold_vn(mb_strtolower($u['ma']))] = (int)$u['id'];
            $units[fold_vn(mb_strtolower($u['ten']))] = (int)$u['id'];
        }
        $cats = [];
        foreach ($this->db->all('SELECT id, ten FROM danh_muc WHERE hoat_dong') as $c) {
            $cats[fold_vn(mb_strtolower($c['ten']))] = (int)$c['id'];
        }
        $seen = [];
        $out = [];
        foreach ($this->read($path, self::aliasesItems()) as $r) {
            $err = null;
            $sku = strtoupper($r['sku']);
            $unit = $units[fold_vn(mb_strtolower($r['unit']))] ?? null;
            $cat = $r['category'] === '' ? null : ($cats[fold_vn(mb_strtolower($r['category']))] ?? false);
            $reorder = $r['reorder'] === '' ? 0 : (ctype_digit($r['reorder']) ? (int)$r['reorder'] : -1);
            $pack = $r['pack'] === '' ? 1 : (ctype_digit($r['pack']) ? (int)$r['pack'] : 0);
            $warranty = $r['warranty'] === '' ? 0 : (ctype_digit($r['warranty']) ? (int)$r['warranty'] : -1);
            $barcode = $r['barcode'] === '' ? null : CatalogService::normalizeBarcode($r['barcode']);
            if (!preg_match('/^[A-Z0-9._-]{2,40}$/', $sku)) {
                $err = 'SKU gồm 2–40 ký tự A-Z, 0-9, ., _ hoặc -';
            } elseif (isset($seen[$sku])) {
                $err = "SKU $sku bị trùng trong file (dòng {$seen[$sku]})";
            } elseif ($this->db->value('SELECT 1 FROM mat_hang WHERE ma_hang = ?', [$sku])) {
                $err = "SKU $sku đã có trong hệ thống";
            } elseif ($r['name'] === '' || mb_strlen($r['name']) > 160) {
                $err = 'Thiếu hoặc quá dài tên hàng';
            } elseif ($unit === null) {
                $err = 'Chưa có đơn vị tính «' . $r['unit'] . '» — thêm ở Hàng hoá trước';
            } elseif ($cat === false) {
                $err = 'Chưa có loại hàng «' . $r['category'] . '»';
            } elseif ($reorder < 0 || $reorder > 1000000) {
                $err = 'Tồn tối thiểu phải là số từ 0 đến 1.000.000';
            } elseif ($pack < 1 || $pack > 1000000) {
                $err = 'Quy cách phải là số từ 1';
            } elseif ($warranty < 0 || $warranty > 120) {
                $err = 'Bảo hành phải là số tháng từ 0 đến 120';
            } elseif ($r['barcode'] !== '' && $barcode === null) {
                $err = 'Mã vạch không hợp lệ';
            } elseif ($barcode !== null && $this->db->value('SELECT 1 FROM mat_hang WHERE ma_vach = ?', [$barcode])) {
                $err = "Mã vạch $barcode đã gắn cho mặt hàng khác";
            }
            $seen[$sku] ??= $r['_line'];
            $out[] = ['line' => (int)$r['_line'], 'error' => $err, 'data' => ['sku' => $sku, 'name' => $r['name'], 'unit_id' => $unit, 'unit' => $r['unit'], 'category_id' => $cat ?: null, 'category' => $r['category'],
                'lot' => self::truthy($r['lot']), 'reorder' => max(0, $reorder), 'pack' => max(1, $pack), 'warranty' => max(0, $warranty), 'barcode' => $barcode]];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $rows dòng hợp lệ đã xem trước. @return array{ok:int,fail:list<string>} */
    public function applyItems(User $actor, array $rows): array
    {
        $ok = 0;
        $fail = [];
        foreach ($rows as $d) {
            try {
                $this->catalog->addItem($actor, (string)$d['sku'], (string)$d['name'], (int)$d['unit_id'], (bool)$d['lot'], (int)$d['reorder'], (int)$d['pack'],
                    $d['category_id'] ? (int)$d['category_id'] : null, false, $d['barcode'] ?? null, (int)$d['warranty']);
                $ok++;
            } catch (DomainError $e) {
                $fail[] = $d['sku'] . ': ' . $e->getMessage();
            }
        }
        return ['ok' => $ok, 'fail' => $fail];
    }

    // ───── tồn đầu kỳ ─────

    /** @return list<array{line:int,data:array<string,mixed>,error:?string}> */
    public function previewStock(string $path): array
    {
        $out = [];
        foreach ($this->read($path, self::aliasesStock()) as $r) {
            $err = null;
            $sku = strtoupper($r['sku']);
            $item = $this->db->one('SELECT id, ten, quan_ly_lo, hoat_dong FROM mat_hang WHERE ma_hang = ?', [$sku]);
            $qty = StockRules::parseQty($r['qty']);
            $exp = $r['expiry'] === '' ? null : self::parseDate($r['expiry']);
            if (!$item || !$item['hoat_dong']) {
                $err = "Không có mặt hàng $sku";
            } elseif ($qty === null || $qty < 1 || $qty > 1000000) {
                $err = 'Số lượng phải là số nguyên từ 1 đến 1.000.000';
            } elseif ($item['quan_ly_lo'] && ($r['lot'] === '' || !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $r['lot']))) {
                $err = 'Hàng quản lý theo lô: nhập số lô (A-Z, 0-9, ., _ , -)';
            } elseif ($item['quan_ly_lo'] && $exp === null) {
                $err = 'Hàng quản lý theo lô: nhập hạn dùng hợp lệ (dd/mm/yyyy)';
            }
            $out[] = ['line' => (int)$r['_line'], 'error' => $err, 'data' => ['sku' => $sku, 'name' => $item['ten'] ?? '', 'item_id' => (int)($item['id'] ?? 0), 'tracked' => (bool)($item['quan_ly_lo'] ?? false),
                'lot' => strtoupper($r['lot']), 'expiry' => $exp, 'qty' => (int)$qty]];
        }
        return $out;
    }

    /** Gom các dòng hợp lệ thành một phiếu nhập NHÁP của kho $warehouseId. @return int id phiếu */
    public function applyStock(User $actor, int $warehouseId, array $rows, string $note): int
    {
        if ($rows === []) {
            throw new DomainError('Không có dòng hợp lệ nào để nhập');
        }
        $keys = [];
        $qtys = [];
        foreach ($rows as $d) {
            $lotId = $d['tracked']
                ? $this->catalog->ensureReceiptLot($actor, (int)$d['item_id'], (string)$d['lot'], (string)$d['expiry'])
                : (int)$this->db->value('SELECT id FROM lo_hang WHERE mat_hang_id = ? ORDER BY id LIMIT 1', [(int)$d['item_id']]);
            $keys[] = $d['item_id'] . ':' . $lotId;
            $qtys[] = (string)$d['qty'];
        }
        $res = $this->vouchers->createDraft($actor, VoucherType::Receipt, $warehouseId, StockRules::normalizeLines($keys, $qtys), 'Tồn đầu kỳ', $note !== '' ? $note : 'Nhập tồn đầu kỳ từ file', null);
        return (int)$res['voucher']['id'];
    }
}
