<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Giá hàng hoá. Tiền là số nguyên đồng (VND) cho 1 đơn vị tính.
 *  - Giá gốc (mat_hang.gia_von) và thuế GTGT (mat_hang.thue_suat): do QUẢN TRỊ HỆ THỐNG đặt, dùng chung mọi kho.
 *    Điều chuyển giữa kho lấy luôn giá gốc này (không đổi giá khi hàng đi từ kho này sang kho khác).
 *  - Giá bán ra: MỖI KHO MỘT GIÁ, do quản lý kho đó đặt (gia_ban_kho). Kho chưa đặt giá thì chưa có giá bán (không có giá mặc định).
 *  - Dòng phiếu lưu lại giá và thuế tại lúc lập (phiếu đã xác nhận bất biến) ⇒ đổi giá sau này không làm lệch phiếu cũ.
 *  - Mọi lần đổi giá gốc / thuế / giá bán ghi vào lich_su_gia (ai, lúc nào, cũ → mới, lý do, kho).
 */
final class PricingService
{
    /** Thuế suất GTGT được chọn. */
    public const VAT_RATES = [0, 5, 8, 10];
    public const LABELS = ['GIA_BAN' => 'Giá bán', 'THUE_SUAT' => 'Thuế suất', 'GIA_VON' => 'Giá gốc'];
    private const MAX_PRICE = 1_000_000_000;

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    /** Số tiền nguyên không âm từ chuỗi người dùng nhập (cho phép «1.250.000» hoặc «1250000»). */
    public static function parseMoney(mixed $raw): ?int
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $s = preg_replace('/[\s.,]/', '', (string)$raw);
        if ($s === '' || !ctype_digit($s) || strlen($s) > 10 || (int)$s > self::MAX_PRICE) {
            return null;
        }
        return (int)$s;
    }

    /** Giá gốc và thuế suất — chỉ quản trị hệ thống. Phải ghi lý do khi có thay đổi. */
    public function setCost(User $actor, int $itemId, int $giaGoc, int $thue, string $reason): void
    {
        $this->auth->requirePermission($actor, Permission::DatGiaGoc);
        if ($giaGoc < 1 || $giaGoc > self::MAX_PRICE) {
            throw new DomainError('Giá gốc phải lớn hơn 0 (đồng / 1 đơn vị tính)');
        }
        if (!in_array($thue, self::VAT_RATES, true)) {
            throw new DomainError('Thuế suất chỉ nhận ' . implode('%, ', self::VAT_RATES) . '%');
        }
        $reason = $this->reason($reason);
        $this->db->transaction(function () use ($actor, $itemId, $giaGoc, $thue, $reason) {
            $item = $this->db->one('SELECT id, ma_hang, gia_von, thue_suat FROM mat_hang WHERE id = ? FOR UPDATE', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại');
            $changes = [];
            if ((int)$item['gia_von'] !== $giaGoc) {
                $changes[] = ['GIA_VON', (int)$item['gia_von'], $giaGoc];
            }
            if ((int)$item['thue_suat'] !== $thue) {
                $changes[] = ['THUE_SUAT', (int)$item['thue_suat'], $thue];
            }
            if ($changes === []) {
                return;
            }
            $this->requireReason($reason);
            $this->db->exec('UPDATE mat_hang SET gia_von = ?, thue_suat = ? WHERE id = ?', [$giaGoc, $thue, $itemId]);
            foreach ($changes as [$loai, $cu, $moi]) {
                $this->history($itemId, $loai, $cu, $moi, $reason, $actor->id, null);
            }
            $this->audit->record($actor->id, 'item.price', 'item', $itemId, $item['ma_hang'] . ': ' . $this->describe($changes));
        });
    }

    /** Giá bán ra của MỘT KHO — chỉ quản lý của kho đó. Phải ghi lý do khi đổi. */
    public function setSellPrice(User $actor, int $warehouseId, int $itemId, int $giaBan, string $reason): void
    {
        $this->auth->require($actor, $warehouseId, Permission::DieuChinhGia);
        if ($giaBan < 1 || $giaBan > self::MAX_PRICE) {
            throw new DomainError('Giá bán phải lớn hơn 0 (đồng / 1 đơn vị tính)');
        }
        $reason = $this->reason($reason);
        $this->db->transaction(function () use ($actor, $warehouseId, $itemId, $giaBan, $reason) {
            $item = $this->db->one('SELECT id, ma_hang FROM mat_hang WHERE id = ? AND hoat_dong FOR UPDATE', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại hoặc đã ngừng hoạt động');
            $row = $this->db->one('SELECT gia_ban FROM gia_ban_kho WHERE kho_id = ? AND mat_hang_id = ?', [$warehouseId, $itemId]);
            $cu = $row ? (int)$row['gia_ban'] : 0;   // chưa đặt giá thì «giá cũ» là 0
            if ($cu === $giaBan) {
                return;   // không đổi gì
            }
            $this->requireReason($reason);
            $this->db->exec('DELETE FROM gia_ban_kho WHERE kho_id = ? AND mat_hang_id = ?', [$warehouseId, $itemId]);
            $this->db->exec('INSERT INTO gia_ban_kho (kho_id, mat_hang_id, gia_ban) VALUES (?,?,?)', [$warehouseId, $itemId, $giaBan]);
            $this->history($itemId, 'GIA_BAN', $cu, $giaBan, $reason, $actor->id, $warehouseId);
            $this->audit->record($actor->id, 'item.price', 'item', $itemId, $item['ma_hang'] . ': giá bán kho ' . $warehouseId . ' ' . $cu . '→' . $giaBan);
        });
    }

    /** Giá bán ra hiện hành của mặt hàng tại một kho (0 = kho chưa đặt giá). */
    public function sellPrice(int $warehouseId, int $itemId): int
    {
        return (int)$this->db->value('SELECT gia_ban FROM gia_ban_kho WHERE kho_id = ? AND mat_hang_id = ?', [$warehouseId, $itemId]);
    }

    /**
     * Giá bán hiện hành của nhiều mặt hàng tại một kho.
     * @param list<int> $itemIds @return array<int,int> mặt hàng ⇒ giá bán (chỉ gồm mặt hàng kho đã đặt giá)
     */
    public function sellPricesAt(int $warehouseId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT mat_hang_id, gia_ban FROM gia_ban_kho WHERE kho_id = ? AND mat_hang_id IN ($in)", [$warehouseId, ...$itemIds]) as $r) {
            $out[(int)$r['mat_hang_id']] = (int)$r['gia_ban'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> 15 lần đổi giá gần nhất của mặt hàng */
    public function historyOf(int $itemId): array
    {
        return $this->db->all('SELECT h.*, u.ho_ten FROM lich_su_gia h LEFT JOIN nguoi_dung u ON u.id = h.nguoi_dung_id WHERE h.mat_hang_id = ? ORDER BY h.id DESC LIMIT 15', [$itemId]);
    }

    /**
     * Lịch sử giá nhiều mặt hàng cho hộp thoại: quản trị xem đổi giá gốc / thuế; quản lý xem đổi giá bán của KHO MÌNH.
     * @param list<int> $itemIds @return array<int,list<array<string,mixed>>> mặt hàng ⇒ tối đa 8 lần đổi gần nhất
     */
    public function historyForMany(array $itemIds, ?int $warehouseId): array
    {
        if ($itemIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $args = $itemIds;
        if ($warehouseId === null) {
            $filter = "h.loai IN ('GIA_VON', 'THUE_SUAT')";
        } else {
            $filter = "h.loai = 'GIA_BAN' AND h.kho_id = ?";
            $args[] = $warehouseId;
        }
        $out = [];
        foreach ($this->db->all("SELECT h.mat_hang_id, h.loai, h.gia_tri_cu AS cu, h.gia_tri_moi AS moi, h.ly_do, h.ngay_tao, u.ho_ten FROM lich_su_gia h LEFT JOIN nguoi_dung u ON u.id = h.nguoi_dung_id
                                  WHERE h.mat_hang_id IN ($in) AND $filter ORDER BY h.id DESC LIMIT 400", $args) as $r) {
            if (count($out[(int)$r['mat_hang_id']] ?? []) < 8) {
                $out[(int)$r['mat_hang_id']][] = ['loai' => $r['loai'], 'cu' => (int)$r['cu'], 'moi' => (int)$r['moi'], 'ly_do' => $r['ly_do'], 'ai' => $r['ho_ten'] ?? 'Hệ thống', 'luc' => dt_short($r['ngay_tao'])];
            }
        }
        return $out;
    }

    /**
     * Giá trị tồn kho theo từng (kho, mặt hàng) còn tồn: số lượng, giá trị theo giá gốc, giá trị theo giá bán CỦA KHO ĐÓ.
     * @param list<int> $warehouseIds
     * @return list<array<string,mixed>>
     */
    public function valuation(array $warehouseIds, ?int $category = null): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $args = $warehouseIds;
        $cat = '';
        if ($category !== null) {
            $cat = $category === -1 ? ' AND i.danh_muc_id IS NULL' : ' AND i.danh_muc_id = ?';
            if ($category !== -1) {
                $args[] = $category;
            }
        }
        return $this->db->all(
            "SELECT t.kho_id, w.ma AS wh_code, w.ten AS wh_name, i.id AS mat_hang_id, i.ma_hang, i.ten, i.danh_muc_id, c.ten AS category, u.ma AS unit,
                    COALESCE(g.gia_ban, 0) AS gia_ban, i.thue_suat, i.gia_von, SUM(t.so_luong) AS so_luong
             FROM ton_kho t JOIN kho w ON w.id = t.kho_id JOIN mat_hang i ON i.id = t.mat_hang_id JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id LEFT JOIN danh_muc c ON c.id = i.danh_muc_id
             LEFT JOIN gia_ban_kho g ON g.kho_id = t.kho_id AND g.mat_hang_id = t.mat_hang_id
             WHERE t.kho_id IN ($in) AND t.so_luong > 0$cat
             GROUP BY t.kho_id, w.ma, w.ten, i.id, i.ma_hang, i.ten, i.danh_muc_id, c.ten, u.ma, g.gia_ban, i.thue_suat, i.gia_von
             ORDER BY w.ma, i.ma_hang",
            $args
        );
    }

    /**
     * Doanh thu bán hàng (phiếu XUẤT đã xác nhận, trừ phiếu đã bị điều chỉnh đảo) theo kho trong khoảng ngày giờ Việt Nam.
     * Mỗi kho: doanh thu chưa thuế, thuế GTGT, tổng thanh toán, giá gốc hàng bán, lãi gộp. Giá lấy từ dòng phiếu (giá tại lúc lập).
     * @param list<int> $warehouseIds
     * @return list<array<string,mixed>>
     */
    public function sales(array $warehouseIds, ?string $from, ?string $to): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $args = $warehouseIds;
        $time = '';
        if ($from) {
            $time .= ' AND v.ngay_xac_nhan >= ?';
            $args[] = gmdate('Y-m-d H:i:s', strtotime("$from 00:00:00 UTC") - 7 * 3600);
        }
        if ($to) {
            $time .= ' AND v.ngay_xac_nhan < ?';
            $args[] = gmdate('Y-m-d H:i:s', strtotime("$to 00:00:00 UTC") + 17 * 3600);
        }
        return $this->db->all(
            "SELECT v.kho_id, w.ma AS wh_code, w.ten AS wh_name, COUNT(DISTINCT v.id) AS phieu, SUM(l.so_luong) AS so_luong,
                    SUM(l.so_luong * COALESCE(l.don_gia, 0)) AS doanh_thu,
                    SUM(ROUND(l.so_luong * COALESCE(l.don_gia, 0) * l.thue_suat / 100.0)) AS thue,
                    SUM(l.so_luong * l.gia_von) AS gia_von
             FROM phieu_nhap_xuat v JOIN chi_tiet_phieu_nhap_xuat l ON l.phieu_nhap_xuat_id = v.id JOIN kho w ON w.id = v.kho_id
             WHERE v.loai = 'ISSUE' AND v.trang_thai = 'CONFIRMED' AND v.kho_id IN ($in)
               AND NOT EXISTS (SELECT 1 FROM phieu_nhap_xuat a WHERE a.dieu_chinh_cho_id = v.id)$time
             GROUP BY v.kho_id, w.ma, w.ten ORDER BY w.ma",
            $args
        );
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) > 200) {
            throw new DomainError('Lý do tối đa 200 ký tự');
        }
        return $reason;
    }

    private function requireReason(string $reason): void
    {
        if ($reason === '') {
            throw new DomainError('Nhập lý do điều chỉnh giá để còn tra cứu sau này');
        }
    }

    /** @param list<array{0:string,1:int,2:int}> $changes */
    private function describe(array $changes): string
    {
        return implode(', ', array_map(fn($c) => self::LABELS[$c[0]] . ' ' . $c[1] . '→' . $c[2], $changes));
    }

    private function history(int $itemId, string $loai, int $cu, int $moi, string $reason, ?int $userId, ?int $warehouseId): void
    {
        $this->db->exec('INSERT INTO lich_su_gia (mat_hang_id, loai, gia_tri_cu, gia_tri_moi, ly_do, nguoi_dung_id, kho_id) VALUES (?,?,?,?,?,?,?)', [$itemId, $loai, $cu, $moi, mb_substr($reason, 0, 255), $userId, $warehouseId]);
    }
}
