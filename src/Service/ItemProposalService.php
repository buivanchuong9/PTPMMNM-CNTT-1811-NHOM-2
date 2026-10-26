<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Đề xuất mặt hàng mới từ mã vạch: quản lý kho quét ra mã chưa có thì gửi đề xuất, quản trị duyệt (đặt giá gốc, thuế) rồi mới có mặt hàng.
 * Chỉ khi được duyệt mặt hàng mới được tạo — bị từ chối thì không tạo gì.
 */
final class ItemProposalService
{
    public const LABELS = ['PENDING' => ['Chờ duyệt', 'warn'], 'APPROVED' => ['Đã duyệt', 'ok'], 'REJECTED' => ['Bị từ chối', 'bad'], 'CANCELLED' => ['Đã rút lại', 'muted']];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit, private CatalogService $catalog, private PricingService $pricing) {}

    /** Đề xuất đang chờ duyệt cho mã vạch này (mọi kho), nếu có. */
    public function pendingByBarcode(string $code): ?array
    {
        return $this->db->one("SELECT d.*, k.ma AS kho_ma FROM de_xuat_mat_hang d JOIN kho k ON k.id = d.kho_id WHERE d.ma_vach = ? AND d.trang_thai = 'PENDING'", [$code]);
    }

    public function create(User $actor, string $barcode, string $sku, string $name, int $unitId, bool $trackBatch, int $categoryId, int $supplierId): int
    {
        $this->auth->requirePermission($actor, Permission::GanMaVach);
        if ($actor->warehouseId === null) {
            throw new DomainError('Chỉ tài khoản thuộc một kho mới gửi được đề xuất');
        }
        $barcode = CatalogService::normalizeBarcode($barcode) ?? throw new DomainError('Chưa có mã vạch');
        $sku = strtoupper(trim($sku));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9._-]{2,40}$/', $sku)) {
            throw new DomainError('SKU gồm 2–40 ký tự A-Z, 0-9, ., _ hoặc -');
        }
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainError('Tên mặt hàng không hợp lệ');
        }
        if (!$this->db->value('SELECT 1 FROM don_vi_tinh WHERE id = ?', [$unitId])) {
            throw new DomainError('Đơn vị tính không hợp lệ');
        }
        if (!$this->db->value('SELECT 1 FROM danh_muc WHERE id = ? AND hoat_dong', [$categoryId])) {
            throw new DomainError('Danh mục hàng hoá không hợp lệ');
        }
        if (!$this->db->value("SELECT 1 FROM doi_tac WHERE id = ? AND loai = 'SUPPLIER' AND hoat_dong", [$supplierId])) {
            throw new DomainError('Chọn nhà cung cấp của mặt hàng mới');
        }
        $this->assertFree($barcode, $sku);
        $id = $this->db->insert('INSERT INTO de_xuat_mat_hang (ma_vach, ma_hang, ten, don_vi_tinh_id, quan_ly_lo, danh_muc_id, nha_cung_cap_id, kho_id, nguoi_de_xuat_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$barcode, $sku, $name, $unitId, (int)$trackBatch, $categoryId, $supplierId, $actor->warehouseId, $actor->id]);
        $this->audit->record($actor->id, 'proposal.create', 'proposal', $id, "$sku · mã vạch $barcode");
        return $id;
    }

    /** Mã vạch / SKU chưa thuộc mặt hàng nào và chưa có đề xuất khác đang chờ. */
    private function assertFree(string $barcode, string $sku, int $exceptId = 0): void
    {
        if ($other = $this->db->one('SELECT ma_hang FROM mat_hang WHERE ma_vach = ?', [$barcode])) {
            throw new DomainError("Mã vạch $barcode đã gắn cho mặt hàng {$other['ma_hang']}");
        }
        if ($this->db->value('SELECT 1 FROM mat_hang WHERE ma_hang = ?', [$sku])) {
            throw new DomainError("SKU $sku đã tồn tại");
        }
        if ($this->db->value("SELECT 1 FROM de_xuat_mat_hang WHERE trang_thai = 'PENDING' AND id <> ? AND (ma_vach = ? OR ma_hang = ?)", [$exceptId, $barcode, $sku])) {
            throw new DomainError('Đã có đề xuất khác đang chờ duyệt với cùng mã vạch hoặc SKU này');
        }
    }

    private function pending(int $id): array
    {
        $p = $this->db->one('SELECT * FROM de_xuat_mat_hang WHERE id = ?', [$id]) ?? throw new DomainError('Đề xuất không tồn tại');
        if ($p['trang_thai'] !== 'PENDING') {
            throw new DomainError('Đề xuất này đã được xử lý');
        }
        return $p;
    }

    /** Quản trị chấp nhận: tạo mặt hàng mang mã vạch, gắn nhà cung cấp, đặt giá gốc + thuế. @return int id mặt hàng mới */
    public function approve(User $actor, int $id, int $cost, int $vat): int
    {
        $this->auth->requirePermission($actor, Permission::DuyetDeXuatHang);
        $this->auth->requirePermission($actor, Permission::DatGiaGoc);
        return $this->db->transaction(function () use ($actor, $id, $cost, $vat) {
            $p = $this->pending($id);
            $this->assertFree($p['ma_vach'], $p['ma_hang'], $id);
            $itemId = $this->catalog->addItem($actor, $p['ma_hang'], $p['ten'], (int)$p['don_vi_tinh_id'], (bool)$p['quan_ly_lo'], 0, 1, (int)$p['danh_muc_id'], false, $p['ma_vach']);
            $this->db->exec('INSERT INTO mat_hang_nha_cung_cap (mat_hang_id, doi_tac_id) VALUES (?,?)', [$itemId, $p['nha_cung_cap_id']]);
            $this->pricing->setCost($actor, $itemId, $cost, $vat, 'Giá gốc khi duyệt đề xuất mặt hàng mới');
            $this->db->exec("UPDATE de_xuat_mat_hang SET trang_thai = 'APPROVED', mat_hang_id = ?, nguoi_xu_ly_id = ?, ngay_xu_ly = CURRENT_TIMESTAMP WHERE id = ?", [$itemId, $actor->id, $id]);
            $this->audit->record($actor->id, 'proposal.approve', 'proposal', $id, "{$p['ma_hang']} · mã vạch {$p['ma_vach']}");
            return $itemId;
        });
    }

    public function reject(User $actor, int $id, string $reason): void
    {
        $this->auth->requirePermission($actor, Permission::DuyetDeXuatHang);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw new DomainError('Nhập lý do từ chối (tối đa 200 ký tự)');
        }
        $p = $this->pending($id);
        $this->db->exec("UPDATE de_xuat_mat_hang SET trang_thai = 'REJECTED', ly_do = ?, nguoi_xu_ly_id = ?, ngay_xu_ly = CURRENT_TIMESTAMP WHERE id = ?", [$reason, $actor->id, $id]);
        $this->audit->record($actor->id, 'proposal.reject', 'proposal', $id, "{$p['ma_hang']} · $reason");
    }

    /** Quản lý kho rút lại đề xuất còn chờ của kho mình. */
    public function cancel(User $actor, int $id): void
    {
        $this->auth->requirePermission($actor, Permission::GanMaVach);
        $p = $this->pending($id);
        if ((int)$p['kho_id'] !== $actor->warehouseId) {
            throw new Forbidden('Đề xuất này của kho khác');
        }
        $this->db->exec("UPDATE de_xuat_mat_hang SET trang_thai = 'CANCELLED', da_bao = 1, nguoi_xu_ly_id = ?, ngay_xu_ly = CURRENT_TIMESTAMP WHERE id = ?", [$actor->id, $id]);
        $this->audit->record($actor->id, 'proposal.cancel', 'proposal', $id, $p['ma_hang']);
    }

    /** Quản trị thấy mọi đề xuất; quản lý kho chỉ thấy của kho mình. @return array{0:string,1:list<mixed>} */
    private function scope(User $user): array
    {
        return $user->isAdmin ? ['1=1', []] : ['d.kho_id = ?', [(int)$user->warehouseId]];
    }

    public function list(User $user, ?string $status): array
    {
        [$where, $args] = $this->scope($user);
        if ($status !== null) {
            $where .= ' AND d.trang_thai = ?';
            $args[] = $status;
        }
        return $this->db->all(
            "SELECT d.*, u.ma AS unit, c.ten AS category, s.ten AS supplier, k.ma AS kho_ma, p.ho_ten AS de_xuat_ten, x.ho_ten AS xu_ly_ten, i.ma_hang AS item_sku
             FROM de_xuat_mat_hang d JOIN don_vi_tinh u ON u.id = d.don_vi_tinh_id JOIN danh_muc c ON c.id = d.danh_muc_id JOIN doi_tac s ON s.id = d.nha_cung_cap_id
             JOIN kho k ON k.id = d.kho_id JOIN nguoi_dung p ON p.id = d.nguoi_de_xuat_id LEFT JOIN nguoi_dung x ON x.id = d.nguoi_xu_ly_id LEFT JOIN mat_hang i ON i.id = d.mat_hang_id
             WHERE $where ORDER BY (d.trang_thai = 'PENDING') DESC, d.id DESC",
            $args
        );
    }

    /** @return array<string,int> số đề xuất theo trạng thái */
    public function counts(User $user): array
    {
        [$where, $args] = $this->scope($user);
        $out = [];
        foreach ($this->db->all("SELECT d.trang_thai AS s, COUNT(*) AS n FROM de_xuat_mat_hang d WHERE $where GROUP BY d.trang_thai", $args) as $r) {
            $out[$r['s']] = (int)$r['n'];
        }
        return $out;
    }

    /** Số việc cần chú ý: quản trị = đề xuất chờ duyệt; quản lý kho = kết quả duyệt chưa xem. */
    public function attention(User $user): int
    {
        if ($user->isAdmin) {
            return (int)$this->db->value("SELECT COUNT(*) FROM de_xuat_mat_hang WHERE trang_thai = 'PENDING'");
        }
        if ($user->warehouseId === null || !$this->auth->allows($user, Permission::GanMaVach)) {
            return 0;
        }
        return (int)$this->db->value("SELECT COUNT(*) FROM de_xuat_mat_hang WHERE kho_id = ? AND trang_thai IN ('APPROVED','REJECTED') AND da_bao = 0", [$user->warehouseId]);
    }

    /** Quản lý kho đã mở trang kết quả ⇒ bỏ số nhỏ. */
    public function markSeen(User $user): void
    {
        if ($user->warehouseId !== null && !$user->isAdmin) {
            $this->db->exec("UPDATE de_xuat_mat_hang SET da_bao = 1 WHERE kho_id = ? AND trang_thai IN ('APPROVED','REJECTED') AND da_bao = 0", [$user->warehouseId]);
        }
    }
}
