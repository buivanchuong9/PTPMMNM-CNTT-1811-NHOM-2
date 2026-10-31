<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;

/**
 * Mã bán hàng để tra bảo hành và hoá đơn. Sinh LÚC BÁN, không gắn sẵn vào lô hay mặt hàng:
 * mỗi dòng phiếu xuất có một «mã lô hàng» (ma_lo) chung, mỗi chiếc bán ra có một «mã món hàng» (ma_mon) riêng.
 * Chỉ sinh cho dòng mà lô hàng có thời hạn bảo hành (lo_hang.bao_hanh_thang > 0). Tra cứu cần cả hai mã.
 */
final class SaleCodeService
{
    /** Bảng chữ cái bỏ ký tự dễ nhầm (0/O, 1/I/L) để đọc và gõ tay không sai. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const MAX_UNITS_PER_LINE = 5000;

    public function __construct(private Db $db) {}

    private static function random(string $prefix): string
    {
        $s = '';
        for ($i = 0; $i < 8; $i++) {
            $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $prefix . '-' . $s;
    }

    /** Chuẩn hoá mã người dùng gõ / quét: bỏ khoảng trắng, chữ hoa. */
    public static function clean(string $code): string
    {
        return strtoupper((string)preg_replace('/\s+/', '', $code));
    }

    /**
     * Sinh mã cho các dòng của một phiếu xuất vừa ghi sổ (gọi trong giao dịch xác nhận). Chạy lại an toàn: phiếu đã có mã thì bỏ qua.
     * @param array<string,mixed> $voucher phiếu (cần id, kho_id)
     * @param list<array<string,mixed>> $lines dòng phiếu (id, mat_hang_id, lo_hang_id, so_luong)
     * @return int số chiếc đã sinh mã
     */
    public function issueFor(array $voucher, array $lines, ?string $purchaseDate = null): int
    {
        $voucherId = (int)$voucher['id'];
        if ($this->db->value('SELECT 1 FROM san_pham_ban WHERE phieu_nhap_xuat_id = ? LIMIT 1', [$voucherId])) {
            return 0;
        }
        $purchaseDate ??= StockQuery::today();
        $made = 0;
        foreach ($lines as $l) {
            $qty = (int)$l['so_luong'];
            $months = (int)$this->db->value('SELECT bao_hanh_thang FROM lo_hang WHERE id = ?', [(int)$l['lo_hang_id']]);
            if ($qty < 1 || $months < 1) {
                continue;
            }
            if ($qty > self::MAX_UNITS_PER_LINE) {
                throw new DomainError('Hàng có bảo hành: mỗi dòng phiếu xuất tối đa ' . self::MAX_UNITS_PER_LINE . ' chiếc');
            }
            $lot = $this->freshLotCode();
            $expiry = WarrantyService::expiryOf($purchaseDate, $months);
            for ($i = 0; $i < $qty; $i++) {
                $this->insertUnit($lot, $voucherId, $l, (int)$voucher['kho_id'], $purchaseDate, $months, $expiry);
                $made++;
            }
        }
        return $made;
    }

    private function freshLotCode(): string
    {
        do {
            $code = self::random('LB');
        } while ($this->db->value('SELECT 1 FROM san_pham_ban WHERE ma_lo = ? LIMIT 1', [$code]));
        return $code;
    }

    private function insertUnit(string $lot, int $voucherId, array $line, int $warehouseId, string $date, int $months, string $expiry): void
    {
        for ($try = 0; $try < 8; $try++) {   // trùng mã món hàng (cực hiếm) thì sinh mã khác
            try {
                $this->db->exec(
                    'INSERT INTO san_pham_ban (ma_lo, ma_mon, phieu_nhap_xuat_id, chi_tiet_id, mat_hang_id, lo_hang_id, kho_id, ngay_mua, bao_hanh_thang, het_han) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$lot, self::random('SP'), $voucherId, (int)$line['id'], (int)$line['mat_hang_id'], (int)$line['lo_hang_id'], $warehouseId, $date, $months, $expiry]
                );
                return;
            } catch (\mysqli_sql_exception $e) {
                if ($e->getCode() !== Db::DUPLICATE_KEY) {
                    throw $e;
                }
            }
        }
        throw new DomainError('Không sinh được mã món hàng duy nhất — thử lại');
    }

    /** Phiếu xuất bị điều chỉnh đảo ⇒ các mã của phiếu đó không còn hiệu lực. */
    public function voidFor(int $voucherId): void
    {
        $this->db->exec('UPDATE san_pham_ban SET da_huy = 1 WHERE phieu_nhap_xuat_id = ?', [$voucherId]);
    }

    /** Mã của một phiếu xuất, gom theo dòng để hiện trên chi tiết phiếu. @return array<int,array{lot:string,units:list<array{code:string,expiry:string,months:int}>}> khoá = chi_tiet_id */
    public function forVoucher(int $voucherId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, chi_tiet_id, ma_lo, ma_mon, bao_hanh_thang, het_han, da_huy FROM san_pham_ban WHERE phieu_nhap_xuat_id = ? ORDER BY id', [$voucherId]) as $r) {
            $out[(int)$r['chi_tiet_id']] ??= ['lot' => $r['ma_lo'], 'units' => []];
            $out[(int)$r['chi_tiet_id']]['units'][] = ['code' => $r['ma_mon'], 'expiry' => $r['het_han'], 'months' => (int)$r['bao_hanh_thang'], 'void' => (bool)(int)$r['da_huy']];
        }
        return $out;
    }

    /**
     * Gợi ý mã khi gõ vào form nhận bảo hành. Chỉ gợi ý chiếc còn hiệu lực, thuộc các kho cho phép và chưa có phiếu bảo hành đang mở.
     * Đang gõ ô mã lô mà chưa có mã món hàng ⇒ gợi ý theo LÔ (mã lô, mặt hàng, hoá đơn, số chiếc); còn lại ($units hoặc đã gõ mã món) ⇒ gợi ý từng CHIẾC (cặp mã lô + mã món hàng).
     * @param list<int> $warehouseIds
     * @return array{mode:string,rows:list<array<string,mixed>>}
     */
    public function suggest(string $lotPrefix, string $itemPrefix, array $warehouseIds, bool $units = false, int $limit = 8): array
    {
        $lot = preg_replace('/[^A-Z0-9-]/', '', self::clean($lotPrefix));
        $item = preg_replace('/[^A-Z0-9-]/', '', self::clean($itemPrefix));
        if ($warehouseIds === []) {
            return ['mode' => 'units', 'rows' => []];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $where = "s.da_huy = 0 AND s.kho_id IN ($in) AND s.ma_lo LIKE ? AND s.ma_mon LIKE ?
                  AND NOT EXISTS (SELECT 1 FROM bao_hanh b WHERE b.san_pham_ban_id = s.id AND b.trang_thai <> 'DA_TRA')
                  AND NOT EXISTS (SELECT 1 FROM bao_hanh l WHERE l.ma_lo = s.ma_lo AND l.san_pham_ban_id IS NULL AND l.trang_thai <> 'DA_TRA')";   // cả lô đang bảo hành thì không gợi ý
        $args = [...$warehouseIds, $lot . '%', $item . '%'];
        if ($item === '' && !$units) {
            $rows = $this->db->all("SELECT s.ma_lo AS lot, i.ma_hang AS sku, i.ten AS name, v.ma AS voucher, COUNT(*) AS n
                                    FROM san_pham_ban s JOIN mat_hang i ON i.id = s.mat_hang_id JOIN phieu_nhap_xuat v ON v.id = s.phieu_nhap_xuat_id
                                    WHERE $where GROUP BY s.ma_lo, i.ma_hang, i.ten, v.ma ORDER BY MAX(s.id) DESC LIMIT " . (int)$limit, $args);
            return ['mode' => 'lots', 'rows' => $rows];
        }
        $rows = $this->db->all("SELECT s.ma_lo AS lot, s.ma_mon AS code, i.ma_hang AS sku, i.ten AS name, s.het_han AS expiry
                                FROM san_pham_ban s JOIN mat_hang i ON i.id = s.mat_hang_id
                                WHERE $where ORDER BY s.id DESC LIMIT " . (int)$limit, $args);
        return ['mode' => 'units', 'rows' => $rows];
    }

    /**
     * Các chiếc của một lô còn có thể nhận bảo hành (chưa bị đảo, thuộc kho đó, chưa có phiếu bảo hành đang mở). Rỗng = mã lô sai hoặc cả lô đang bảo hành.
     * Mỗi dòng như find() nhưng chưa tính còn hạn; cùng một lô thì mặt hàng, ngày mua, hạn bảo hành giống nhau.
     * @return list<array<string,mixed>>
     */
    public function unitsOfLot(string $lotCode, int $warehouseId): array
    {
        return $this->db->all(
            "SELECT s.*, i.ma_hang, i.ten AS item_name, b.so_lo, v.ma AS voucher_code, v.doi_tac AS customer, w.ma AS wh_code, NULL AS dang_bao_hanh
             FROM san_pham_ban s JOIN mat_hang i ON i.id = s.mat_hang_id JOIN lo_hang b ON b.id = s.lo_hang_id
             JOIN phieu_nhap_xuat v ON v.id = s.phieu_nhap_xuat_id JOIN kho w ON w.id = s.kho_id
             WHERE s.ma_lo = ? AND s.kho_id = ? AND s.da_huy = 0
               AND NOT EXISTS (SELECT 1 FROM bao_hanh x WHERE x.san_pham_ban_id = s.id AND x.trang_thai <> 'DA_TRA') ORDER BY s.id",
            [self::clean($lotCode), $warehouseId]
        );
    }

    /**
     * Tra bằng cả hai mã. Trả về thông tin chiếc đã bán (mặt hàng, lô, hoá đơn, ngày mua, hạn bảo hành, còn hạn không) hoặc null nếu không khớp.
     * @return array<string,mixed>|null
     */
    public function find(string $lotCode, string $itemCode): ?array
    {
        $lotCode = self::clean($lotCode);
        $itemCode = self::clean($itemCode);
        if ($itemCode === '') {
            return null;
        }
        // mã món hàng là duy nhất toàn hệ thống nên chỉ cần mã món; có gõ mã lô thì phải khớp
        $row = $this->db->one(
            'SELECT s.*, i.ma_hang, i.ten AS item_name, b.so_lo, v.ma AS voucher_code, v.doi_tac AS customer, w.ma AS wh_code, w.ten AS wh_name
             FROM san_pham_ban s JOIN mat_hang i ON i.id = s.mat_hang_id JOIN lo_hang b ON b.id = s.lo_hang_id
             JOIN phieu_nhap_xuat v ON v.id = s.phieu_nhap_xuat_id JOIN kho w ON w.id = s.kho_id
             WHERE s.ma_mon = ?' . ($lotCode !== '' ? ' AND s.ma_lo = ?' : ''),
            $lotCode !== '' ? [$itemCode, $lotCode] : [$itemCode]
        );
        if (!$row) {
            return null;
        }
        $row['con_han'] = $row['het_han'] >= StockQuery::today() ? 1 : 0;
        $open = $this->db->one("SELECT ma, trang_thai FROM bao_hanh WHERE san_pham_ban_id = ? AND trang_thai <> 'DA_TRA' ORDER BY id DESC LIMIT 1", [(int)$row['id']]);
        $row['dang_bao_hanh'] = $open['ma'] ?? null;
        return $row;
    }
}
