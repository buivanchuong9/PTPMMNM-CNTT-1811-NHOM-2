<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/** Danh mục: kho, đơn vị tính, mặt hàng, lô hàng và tài khoản (quản trị hệ thống / quản lý kho / nhân viên kho). */
final class CatalogService
{
    public const NO_LOT = 'NOLOT';

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit, private SettingsService $settings) {}

    // ───── đọc ─────
    public function warehouses(): array
    {
        return $this->db->all('SELECT * FROM kho ORDER BY ma');
    }

    public function units(): array
    {
        return $this->db->all('SELECT * FROM don_vi_tinh ORDER BY ma');
    }

    public function categories(): array
    {
        return $this->db->all('SELECT c.*, (SELECT COUNT(*) FROM mat_hang i WHERE i.danh_muc_id = c.id) AS items FROM danh_muc c WHERE c.hoat_dong ORDER BY c.ten');
    }

    public function items(): array
    {
        return $this->db->all('SELECT i.*, u.ma AS unit, c.ten AS category, (SELECT COUNT(*) FROM lo_hang b WHERE b.mat_hang_id = i.id AND b.so_lo <> ?) AS lots
                               FROM mat_hang i JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id LEFT JOIN danh_muc c ON c.id = i.danh_muc_id ORDER BY i.ma_hang', [self::NO_LOT]);
    }

    public function batches(): array
    {
        return $this->db->all('SELECT b.*, i.ma_hang, i.ten FROM lo_hang b JOIN mat_hang i ON i.id = b.mat_hang_id WHERE b.so_lo <> ? ORDER BY i.ma_hang, b.so_lo', [self::NO_LOT]);
    }

    /** Mọi tài khoản kèm kho được gắn (ADMIN trước, rồi theo kho; quản lý đứng trước nhân viên). */
    public function users(): array
    {
        return $this->db->all(
            "SELECT n.id, n.ten_dang_nhap, n.ho_ten, n.vai_tro, n.kho_id, n.hoat_dong, n.anh_dai_dien, w.ma AS kho_ma, w.ten AS kho_ten, w.dia_chi AS kho_dia_chi
             FROM nguoi_dung n LEFT JOIN kho w ON w.id = n.kho_id
             ORDER BY CASE n.vai_tro WHEN 'ADMIN' THEN 0 WHEN 'MANAGER' THEN 1 ELSE 2 END, w.ma, n.ten_dang_nhap"
        );
    }

    /**
     * Danh sách kho kèm tài khoản quản lý và nhân viên của từng kho.
     * @return list<array> mỗi phần tử là dòng kho + 'quan_ly' (tài khoản hoặc null) + 'nhan_vien' (danh sách tài khoản)
     */
    public function warehouseDirectory(): array
    {
        $byWh = [];
        foreach ($this->db->all("SELECT id, ten_dang_nhap, ho_ten, vai_tro, kho_id, hoat_dong, anh_dai_dien, ngay_tao FROM nguoi_dung WHERE vai_tro <> 'ADMIN' ORDER BY ten_dang_nhap") as $u) {
            $byWh[(int)$u['kho_id']][] = $u;
        }
        $out = [];
        foreach ($this->warehouses() as $w) {
            $accounts = $byWh[(int)$w['id']] ?? [];
            $w['quan_ly'] = null;
            $w['nhan_vien'] = [];
            foreach ($accounts as $u) {
                if ($u['vai_tro'] === AccountRole::Manager->value) {
                    $w['quan_ly'] = $u;
                } else {
                    $w['nhan_vien'][] = $u;
                }
            }
            $out[] = $w;
        }
        return $out;
    }

    /** Độ dài mật khẩu tối thiểu do quản trị đặt (để form hiển thị đúng ràng buộc). */
    public function minPassword(): int
    {
        return $this->settings->minPassword();
    }

    // ───── ghi (quản trị) ─────
    public function addWarehouse(User $actor, string $code, string $name, string $address): int
    {
        $this->auth->requireAdmin($actor);
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
            throw new DomainError('Mã kho gồm 2–20 ký tự A-Z, 0-9, _ hoặc -');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || mb_strlen($address) > 255) {
            throw new DomainError('Tên/địa chỉ kho không hợp lệ');
        }
        $this->requireUnique('kho', 'ma', $code, "Mã kho $code đã tồn tại");
        $id = $this->db->insert('INSERT INTO kho (ma, ten, dia_chi) VALUES (?,?,?)', [$code, $name, trim($address)]);
        $this->audit->record($actor->id, 'warehouse.create', 'warehouse', $id, $code);
        return $id;
    }

    /**
     * Thêm kho KÈM tài khoản quản lý trong một giao dịch: tên đăng nhập trùng hay mật khẩu ngắn thì không có kho nào được tạo.
     * @return array{warehouse_id:int,manager_id:int}
     */
    public function addWarehouseWithManager(User $actor, string $code, string $name, string $address, string $managerUsername, string $managerName, string $managerPassword): array
    {
        $this->auth->requireAdmin($actor);
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        if (trim($managerUsername) === '' || trim($managerName) === '') {
            throw new DomainError('Nhập họ tên và tên đăng nhập của quản lý kho mới');
        }
        return $this->db->transaction(function () use ($actor, $code, $name, $address, $managerUsername, $managerName, $managerPassword) {
            $warehouseId = $this->addWarehouse($actor, $code, $name, $address);
            $managerId = $this->insertAccount($actor, $managerUsername, $managerName, $managerPassword, AccountRole::Manager, $warehouseId);
            return ['warehouse_id' => $warehouseId, 'manager_id' => $managerId];
        });
    }

    /** Đổi địa chỉ kho: tài khoản cấp cao sửa mọi kho; quản lý chỉ sửa kho của mình. Mọi trang đọc kho.dia_chi nên thấy địa chỉ mới ngay. */
    public function setWarehouseAddress(User $actor, int $warehouseId, string $address): void
    {
        $manages = $actor->role === AccountRole::Manager && $actor->warehouseId === $warehouseId;
        if (!$actor->isAdmin && !$manages) {
            throw new \StockFlow\Domain\Forbidden('Chỉ tài khoản cấp cao hoặc quản lý của kho này mới đổi được địa chỉ kho');
        }
        if (mb_strlen($address) > 255) {
            throw new DomainError('Địa chỉ quá dài (tối đa 255 ký tự)');
        }
        $w = $this->db->one('SELECT ma, dia_chi FROM kho WHERE id = ?', [$warehouseId]) ?? throw new DomainError('Kho không tồn tại');
        $this->db->exec('UPDATE kho SET dia_chi = ? WHERE id = ?', [$address, $warehouseId]);
        $this->audit->record($actor->id, 'warehouse.address', 'warehouse', $warehouseId, $w['ma'] . ': ' . ($w['dia_chi'] ?: '—') . ' → ' . ($address ?: '—'));
    }

    public function addUnit(User $actor, string $code, string $name): int
    {
        $this->auth->requireAdmin($actor);
        $code = trim($code);
        $name = trim($name);
        if ($code === '' || mb_strlen($code) > 20 || $name === '' || mb_strlen($name) > 60) {
            throw new DomainError('Đơn vị tính không hợp lệ');
        }
        $this->requireUnique('don_vi_tinh', 'ma', $code, "Đơn vị «{$code}» đã tồn tại");
        return $this->db->insert('INSERT INTO don_vi_tinh (ma, ten) VALUES (?,?)', [$code, $name]);
    }

    /** Quản trị hệ thống và quản lý kho được thêm danh mục hàng hoá. */
    public function addCategory(User $actor, string $code, string $name): int
    {
        if (!$actor->isAdmin && $actor->role !== AccountRole::Manager) {
            $this->auth->requireAdmin($actor);
        }
        $code = strtoupper(trim($code));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9._-]{2,30}$/', $code)) {
            throw new DomainError('Mã danh mục gồm 2–30 ký tự A-Z, 0-9, ., _ hoặc -');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainError('Tên danh mục không hợp lệ');
        }
        $this->requireUnique('danh_muc', 'ma', $code, "Danh mục $code đã tồn tại");
        $id = $this->db->insert('INSERT INTO danh_muc (ma, ten) VALUES (?,?)', [$code, $name]);
        $this->audit->record($actor->id, 'category.create', 'category', $id, "$code · $name");
        return $id;
    }

    private function requireCategory(?int $categoryId): void
    {
        if ($categoryId !== null && !$this->db->value('SELECT 1 FROM danh_muc WHERE id = ? AND hoat_dong', [$categoryId])) {
            throw new DomainError('Danh mục hàng hoá không hợp lệ');
        }
    }

    public function addItem(User $actor, string $sku, string $name, int $unitId, bool $trackBatch, int $reorder, int $packSize = 1, ?int $categoryId = null, bool $viaSupplier = false, ?string $barcode = null, int $warrantyMonths = 0): int
    {
        // Quản lý kho chỉ được thêm mặt hàng mới khi đang lập nhà cung cấp (hàng mới do nhà cung cấp đó cung cấp)
        if (!($viaSupplier && $actor->role === AccountRole::Manager)) {
            $this->auth->requireAdmin($actor);
        }
        $sku = strtoupper(trim($sku));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9._-]{2,40}$/', $sku)) {
            throw new DomainError('SKU gồm 2–40 ký tự A-Z, 0-9, ., _ hoặc -');
        }
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainError('Tên mặt hàng không hợp lệ');
        }
        if ($reorder < 0 || $reorder > 1000000) {
            throw new DomainError('Định mức tồn tối thiểu phải từ 0 đến 1.000.000');
        }
        if ($packSize < 1 || $packSize > 1000000) {
            throw new DomainError('Quy cách đóng gói phải từ 1 đến 1.000.000');
        }
        if (!$this->db->value('SELECT 1 FROM don_vi_tinh WHERE id = ?', [$unitId])) {
            throw new DomainError('Đơn vị tính không hợp lệ');
        }
        self::checkWarranty($warrantyMonths);
        $this->requireCategory($categoryId);
        $this->requireUnique('mat_hang', 'ma_hang', $sku, "SKU $sku đã tồn tại");
        $barcode = self::normalizeBarcode($barcode);
        if ($barcode !== null) {
            $this->requireUnique('mat_hang', 'ma_vach', $barcode, "Mã vạch $barcode đã gắn cho mặt hàng khác");
        }
        return $this->db->transaction(function () use ($actor, $sku, $name, $unitId, $trackBatch, $reorder, $packSize, $categoryId, $barcode, $warrantyMonths) {
            $id = $this->db->insert('INSERT INTO mat_hang (ma_hang, ten, don_vi_tinh_id, quan_ly_lo, dinh_muc_toi_thieu, quy_cach_lo, danh_muc_id, ma_vach, bao_hanh_thang) VALUES (?,?,?,?,?,?,?,?,?)',
                [$sku, $name, $unitId, (int)$trackBatch, $reorder, $packSize, $categoryId, $barcode, $warrantyMonths]);
            if (!$trackBatch) {
                $this->db->exec('INSERT INTO lo_hang (mat_hang_id, so_lo) VALUES (?,?)', [$id, self::NO_LOT]);
            }
            $this->audit->record($actor->id, 'item.create', 'item', $id, $sku . ($barcode !== null ? " · mã vạch $barcode" : ''));
            return $id;
        });
    }

    /**
     * Cân nặng (gram) và kích thước (mm) của MỘT đơn vị tính và của MỘT KIỆN (thùng đóng sẵn gồm quy_cach_lo đơn vị). 0 = chưa khai.
     * Cân nặng kiện = cả bì; để 0 thì tự tính bằng số đơn vị × cân nặng đơn vị.
     * @return array{khoi_luong_g:int,dai_mm:int,rong_mm:int,cao_mm:int,kien_g:int,kien_dai_mm:int,kien_rong_mm:int,kien_cao_mm:int}
     */
    public static function cleanLogistics(int $weightG, int $length, int $width, int $height, int $kienG = 0, int $kienL = 0, int $kienW = 0, int $kienH = 0): array
    {
        foreach ([[$weightG, 'Cân nặng một đơn vị'], [$kienG, 'Cân nặng một kiện']] as [$g, $label]) {
            if ($g < 0 || $g > 20_000_000) {
                throw new DomainError("$label phải từ 0 đến 20.000 kg");
            }
        }
        foreach ([$length, $width, $height, $kienL, $kienW, $kienH] as $d) {
            if ($d < 0 || $d > 20_000) {
                throw new DomainError('Mỗi cạnh của hàng tối đa 2.000 cm (20 m)');
            }
        }
        foreach ([[$length, $width, $height, 'hàng (1 đơn vị)'], [$kienL, $kienW, $kienH, 'kiện']] as [$x, $y, $z, $what]) {
            if (count(array_filter([$x, $y, $z])) % 3 !== 0) {
                throw new DomainError("Kích thước $what: nhập đủ cả dài, rộng, cao (hoặc để trống cả ba)");
            }
        }
        return ['khoi_luong_g' => $weightG, 'dai_mm' => $length, 'rong_mm' => $width, 'cao_mm' => $height,
                'kien_g' => $kienG, 'kien_dai_mm' => $kienL, 'kien_rong_mm' => $kienW, 'kien_cao_mm' => $kienH];
    }

    /** Lưu cân nặng + kích thước của mặt hàng và của kiện (quản trị). */
    public function setItemLogistics(User $actor, int $itemId, int $weightG, int $length, int $width, int $height, int $kienG = 0, int $kienL = 0, int $kienW = 0, int $kienH = 0): void
    {
        $this->auth->requireAdmin($actor);
        $c = self::cleanLogistics($weightG, $length, $width, $height, $kienG, $kienL, $kienW, $kienH);
        $item = $this->db->one('SELECT ma_hang FROM mat_hang WHERE id = ?', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại');
        $this->db->exec('UPDATE mat_hang SET khoi_luong_g = ?, dai_mm = ?, rong_mm = ?, cao_mm = ?, kien_g = ?, kien_dai_mm = ?, kien_rong_mm = ?, kien_cao_mm = ? WHERE id = ?',
            [...array_values($c), $itemId]);
        $dim = fn(int $x, int $y, int $z) => $x ? $x / 10 . '×' . $y / 10 . '×' . $z / 10 . ' cm' : 'chưa khai kích thước';
        $this->audit->record($actor->id, 'item.logistics', 'item', $itemId, $item['ma_hang'] . ' · ' . ($c['khoi_luong_g'] / 1000) . ' kg · ' . $dim($c['dai_mm'], $c['rong_mm'], $c['cao_mm'])
            . ' · kiện ' . ($c['kien_g'] ? $c['kien_g'] / 1000 . ' kg' : 'tự tính cân') . ' · ' . $dim($c['kien_dai_mm'], $c['kien_rong_mm'], $c['kien_cao_mm']));
    }

    /** Thời hạn bảo hành (tháng): 0 = mặt hàng không bảo hành. */
    private static function checkWarranty(int $months): void
    {
        if ($months < 0 || $months > 120) {
            throw new DomainError('Thời hạn bảo hành phải từ 0 đến 120 tháng (0 = không bảo hành)');
        }
    }

    // ───── mã vạch ─────
    /** Chuẩn hoá mã vạch đọc được / gõ vào: bỏ khoảng trắng hai đầu, 4–64 ký tự in được (không có dấu cách). Rỗng ⇒ null (không có mã). */
    public static function normalizeBarcode(?string $code): ?string
    {
        $code = trim((string)$code);
        if ($code === '') {
            return null;
        }
        if (!preg_match('/^[!-~]{4,64}$/', $code)) {
            throw new DomainError('Mã vạch gồm 4–64 ký tự chữ, số hoặc ký hiệu, không có dấu cách');
        }
        return $code;
    }

    /** Mặt hàng đang hoạt động gắn với mã vạch này (null nếu chưa có trong hệ thống). */
    public function itemByBarcode(string $code): ?array
    {
        return $this->db->one('SELECT i.*, u.ma AS unit, c.ten AS category FROM mat_hang i JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id LEFT JOIN danh_muc c ON c.id = i.danh_muc_id
                               WHERE i.ma_vach = ? AND i.hoat_dong', [$code]);
    }

    /** Gán, đổi hoặc gỡ mã vạch của mặt hàng — chỉ tài khoản có chức năng «Gán mã vạch» (quản lý kho). Chỉ lưu chuỗi mã, không lưu ảnh. */
    public function setBarcode(User $actor, int $itemId, ?string $code): void
    {
        $this->auth->requirePermission($actor, Permission::GanMaVach);
        $code = self::normalizeBarcode($code);
        $item = $this->db->one('SELECT ma_hang, ma_vach FROM mat_hang WHERE id = ?', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại');
        if ($code !== null && ($other = $this->db->one('SELECT ma_hang FROM mat_hang WHERE ma_vach = ? AND id <> ?', [$code, $itemId]))) {
            throw new DomainError("Mã vạch $code đã gắn cho mặt hàng {$other['ma_hang']}");
        }
        $this->db->exec('UPDATE mat_hang SET ma_vach = ? WHERE id = ?', [$code, $itemId]);
        $this->audit->record($actor->id, 'item.barcode', 'item', $itemId, "{$item['ma_hang']} · " . ($item['ma_vach'] ?: '—') . ' → ' . ($code ?? '—'));
    }

    /** Sửa danh mục, quy cách đóng gói (1 kiện = bao nhiêu đơn vị tính) và định mức tồn tối thiểu. Không có cách sửa số lượng tồn: tồn chỉ đổi qua phiếu / kiểm kê. */
    public function updateItem(User $actor, int $itemId, int $packSize, int $reorder, ?int $categoryId = null, int $warrantyMonths = 0): void
    {
        $this->auth->requireAdmin($actor);
        if ($packSize < 1 || $packSize > 1000000) {
            throw new DomainError('Quy cách đóng gói phải từ 1 đến 1.000.000');
        }
        if ($reorder < 0 || $reorder > 1000000) {
            throw new DomainError('Định mức tồn tối thiểu phải từ 0 đến 1.000.000');
        }
        self::checkWarranty($warrantyMonths);
        $this->requireCategory($categoryId);
        $item = $this->db->one('SELECT ma_hang FROM mat_hang WHERE id = ?', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại');
        $this->db->exec('UPDATE mat_hang SET quy_cach_lo = ?, dinh_muc_toi_thieu = ?, danh_muc_id = ?, bao_hanh_thang = ? WHERE id = ?', [$packSize, $reorder, $categoryId, $warrantyMonths, $itemId]);
        $this->audit->record($actor->id, 'item.update', 'item', $itemId, "{$item['ma_hang']} · quy cách $packSize · định mức $reorder · bảo hành $warrantyMonths tháng · danh mục #" . ($categoryId ?? '—'));
    }

    /** Thêm lô cho mặt hàng quản lý theo lô — tài khoản được giao chức năng «Thêm lô hàng». */
    public function addBatch(User $actor, int $itemId, string $batchNo, ?string $expiry): int
    {
        $this->auth->requirePermission($actor, Permission::ThemLo);
        $batchNo = strtoupper(trim($batchNo));
        if (!preg_match('/^[A-Z0-9._-]{1,40}$/', $batchNo) || $batchNo === self::NO_LOT) {
            throw new DomainError('Số lô gồm 1–40 ký tự A-Z, 0-9, ., _ hoặc -');
        }
        $item = $this->db->one('SELECT * FROM mat_hang WHERE id = ? AND hoat_dong', [$itemId]);
        if (!$item || !$item['quan_ly_lo']) {
            throw new DomainError('Mặt hàng không tồn tại hoặc không quản lý theo lô');
        }
        if ($expiry === null || trim($expiry) === '') {
            throw new DomainError('Nhập hạn dùng của lô để kho quản lý và xuất theo thứ tự hết hạn trước');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || !checkdate((int)substr($expiry, 5, 2), (int)substr($expiry, 8, 2), (int)substr($expiry, 0, 4))) {
            throw new DomainError('Hạn dùng không hợp lệ');
        }
        if ($this->db->value('SELECT 1 FROM lo_hang WHERE mat_hang_id = ? AND so_lo = ?', [$itemId, $batchNo])) {
            throw new DomainError("Lô $batchNo đã tồn tại cho mặt hàng này");
        }
        $id = $this->db->insert('INSERT INTO lo_hang (mat_hang_id, so_lo, han_dung) VALUES (?,?,?)', [$itemId, $batchNo, $expiry]);
        $this->audit->record($actor->id, 'batch.create', 'batch', $id, "{$item['ma_hang']}/$batchNo");
        return $id;
    }

    /**
     * Lô của hàng nhập kho: người lập phiếu nhập gõ số lô + hạn dùng ghi trên hàng. Lô đã có (cùng số lô, cùng hạn) thì dùng lại;
     * cùng số lô mà khác hạn dùng là nhầm lẫn ⇒ báo lỗi; chưa có thì tạo mới. Không cần quyền «Thêm lô» vì đây là một phần của việc nhập hàng.
     */
    public function ensureReceiptLot(User $actor, int $itemId, string $batchNo, string $expiry): int
    {
        $this->auth->requirePermission($actor, Permission::LapPhieuNhap);
        $batchNo = strtoupper(trim($batchNo));
        $expiry = trim($expiry);
        $item = $this->db->one('SELECT * FROM mat_hang WHERE id = ? AND hoat_dong', [$itemId]);
        if (!$item || !$item['quan_ly_lo']) {
            throw new DomainError('Mặt hàng không tồn tại hoặc không quản lý theo lô');
        }
        if (!preg_match('/^[A-Z0-9._-]{1,40}$/', $batchNo) || $batchNo === self::NO_LOT) {
            throw new DomainError($item['ma_hang'] . ': nhập số lô (1–40 ký tự A-Z, 0-9, ., _ hoặc -)');
        }
        if ($expiry === '') {
            throw new DomainError($item['ma_hang'] . ' lô ' . $batchNo . ': nhập hạn dùng ghi trên hàng');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || !checkdate((int)substr($expiry, 5, 2), (int)substr($expiry, 8, 2), (int)substr($expiry, 0, 4))) {
            throw new DomainError($item['ma_hang'] . ' lô ' . $batchNo . ': hạn dùng không hợp lệ');
        }
        if ($expiry < StockQuery::today()) {
            throw new DomainError($item['ma_hang'] . ' lô ' . $batchNo . ': hạn dùng ' . dt($expiry, false) . ' đã qua — không nhập hàng đã hết hạn');
        }
        $lot = $this->db->one('SELECT id, han_dung FROM lo_hang WHERE mat_hang_id = ? AND so_lo = ?', [$itemId, $batchNo]);
        if ($lot) {
            if ($lot['han_dung'] !== $expiry) {
                throw new DomainError($item['ma_hang'] . ' lô ' . $batchNo . ' đã có hạn dùng ' . dt($lot['han_dung'], false) . ' — hàng khác hạn dùng phải dùng số lô khác');
            }
            return (int)$lot['id'];
        }
        $id = $this->db->insert('INSERT INTO lo_hang (mat_hang_id, so_lo, han_dung) VALUES (?,?,?)', [$itemId, $batchNo, $expiry]);
        $this->audit->record($actor->id, 'batch.create', 'batch', $id, "{$item['ma_hang']}/$batchNo · HSD $expiry");
        return $id;
    }

    /** Kiểm tra vai trò + kho của tài khoản và quy tắc «mỗi kho đúng một quản lý». @return ?int kho_id hợp lệ (null với ADMIN) */
    private function checkPlacement(AccountRole $role, ?int $warehouseId, ?int $exceptUserId = null): ?int
    {
        if ($role === AccountRole::Admin) {
            return null;
        }
        $wh = $warehouseId ? $this->db->one('SELECT id, ma, hoat_dong FROM kho WHERE id = ?', [$warehouseId]) : null;
        if (!$wh || !$wh['hoat_dong']) {
            throw new DomainError('Tài khoản ' . mb_strtolower($role->label()) . ' phải gắn với một kho đang hoạt động');
        }
        if ($role === AccountRole::Manager) {
            $cur = $this->db->one("SELECT id, ten_dang_nhap FROM nguoi_dung WHERE quan_ly_kho_id = ? AND id <> ?", [$warehouseId, $exceptUserId ?? 0]);
            if ($cur) {
                throw new DomainError("Kho {$wh['ma']} đã có quản lý là «{$cur['ten_dang_nhap']}» — mỗi kho chỉ có một quản lý. Hãy chuyển người đó sang nhân viên hoặc kho khác trước.");
            }
        }
        return (int)$warehouseId;
    }

    public function createAccount(User $actor, string $username, string $fullName, string $password, AccountRole $role, ?int $warehouseId): int
    {
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        return $this->insertAccount($actor, $username, $fullName, $password, $role, $warehouseId);
    }

    /**
     * Thêm tài khoản NHÂN VIÊN vào kho. Quản lý kho chỉ thêm được cho kho của chính mình (kho lấy từ tài khoản,
     * không nhận từ form); tài khoản cấp cao chọn kho bất kỳ.
     */
    public function createStaff(User $actor, string $username, string $fullName, string $password, ?int $warehouseId = null): int
    {
        $this->auth->requirePermission($actor, Permission::QuanLyNhanVien);
        $warehouseId = $actor->isAdmin ? $warehouseId : $actor->warehouseId;
        if ($warehouseId === null) {
            throw new DomainError('Hãy chọn kho cho nhân viên');
        }
        $this->auth->require($actor, $warehouseId, Permission::QuanLyNhanVien);
        return $this->insertAccount($actor, $username, $fullName, $password, AccountRole::Staff, $warehouseId);
    }

    private function insertAccount(User $actor, string $username, string $fullName, string $password, AccountRole $role, ?int $warehouseId): int
    {
        $username = User::normalizeUsername($username);
        if (trim($fullName) === '' || mb_strlen($fullName) > 120) {
            throw new DomainError('Họ tên không hợp lệ');
        }
        if (strlen($password) < ($min = $this->settings->minPassword())) {
            throw new DomainError("Mật khẩu tối thiểu $min ký tự");
        }
        $this->requireUnique('nguoi_dung', 'ten_dang_nhap', $username, "Tên đăng nhập $username đã tồn tại");
        $kho = $this->checkPlacement($role, $warehouseId);
        $id = $this->db->insert('INSERT INTO nguoi_dung (ten_dang_nhap, ho_ten, mat_khau_ma_hoa, vai_tro, kho_id, quan_ly_kho_id) VALUES (?,?,?,?,?,?)',
            [$username, trim($fullName), password_hash($password, PASSWORD_DEFAULT), $role->value, $kho, $role === AccountRole::Manager ? $kho : null]);
        $this->audit->record($actor->id, 'user.create', 'user', $id, "$username · {$role->value}" . ($kho ? " · kho #$kho" : ''));
        return $id;
    }

    /** Đổi vai trò và/hoặc kho của tài khoản. Chức năng được dùng thay đổi theo vai trò mới ngay ở lần tải trang kế tiếp. */
    public function updateAccount(User $actor, int $userId, AccountRole $role, ?int $warehouseId): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        $u = $this->db->one('SELECT * FROM nguoi_dung WHERE id = ?', [$userId]) ?? throw new DomainError('Tài khoản không tồn tại');
        if ($userId === $actor->id && $role !== $actor->role) {
            throw new DomainError('Không tự đổi vai trò của chính mình — hãy nhờ một quản trị viên khác');
        }
        if ($u['vai_tro'] === AccountRole::Admin->value && $role !== AccountRole::Admin && $this->activeAdmins($userId) === 0) {
            throw new DomainError('Hệ thống phải còn ít nhất một tài khoản quản trị hoạt động');
        }
        $kho = $this->checkPlacement($role, $warehouseId, $userId);
        $this->db->exec('UPDATE nguoi_dung SET vai_tro = ?, kho_id = ?, quan_ly_kho_id = ? WHERE id = ?',
            [$role->value, $kho, $role === AccountRole::Manager ? $kho : null, $userId]);
        $this->audit->record($actor->id, 'user.update', 'user', $userId, "{$u['ten_dang_nhap']} → {$role->value}" . ($kho ? " · kho #$kho" : ''));
        $this->auth->forget();
    }

    /**
     * Sửa một tài khoản kho trong một lần lưu: tên đăng nhập, vai trò, kho, và (tuỳ chọn) mật khẩu mới.
     * Không áp dụng cho tài khoản quản trị cấp cao. Mật khẩu để trống = giữ nguyên.
     */
    public function editAccount(User $actor, int $userId, string $username, AccountRole $role, ?int $warehouseId, string $newPassword = ''): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        $u = $this->db->one('SELECT * FROM nguoi_dung WHERE id = ?', [$userId]) ?? throw new DomainError('Tài khoản không tồn tại');
        if ($u['vai_tro'] === AccountRole::Admin->value) {
            throw new DomainError('Không sửa tài khoản quản trị cấp cao tại đây');
        }
        $username = User::normalizeUsername($username);
        if ($newPassword !== '' && strlen($newPassword) < ($min = $this->settings->minPassword())) {
            throw new DomainError("Mật khẩu mới tối thiểu $min ký tự");
        }
        $this->db->transaction(function () use ($actor, $userId, $u, $username, $role, $warehouseId, $newPassword) {
            if ($username !== $u['ten_dang_nhap']) {
                if ($this->db->one('SELECT id FROM nguoi_dung WHERE ten_dang_nhap = ? AND id <> ?', [$username, $userId])) {
                    throw new DomainError("Tên đăng nhập $username đã tồn tại");
                }
                $this->db->exec('UPDATE nguoi_dung SET ten_dang_nhap = ? WHERE id = ?', [$username, $userId]);
                $this->audit->record($actor->id, 'user.rename', 'user', $userId, "{$u['ten_dang_nhap']} → $username");
            }
            if ($role->value !== $u['vai_tro'] || (int)$warehouseId !== (int)$u['kho_id']) {
                $this->updateAccount($actor, $userId, $role, $warehouseId);
            }
            if ($newPassword !== '') {
                $this->db->exec('UPDATE nguoi_dung SET mat_khau_ma_hoa = ? WHERE id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                $this->audit->record($actor->id, 'user.password', 'user', $userId, $username);
            }
        });
    }

    public function setAccountActive(User $actor, int $userId, bool $active): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        $u = $this->db->one('SELECT * FROM nguoi_dung WHERE id = ?', [$userId]) ?? throw new DomainError('Tài khoản không tồn tại');
        if (!$active && $userId === $actor->id) {
            throw new DomainError('Không thể tự khoá tài khoản đang đăng nhập');
        }
        if (!$active && $u['vai_tro'] === AccountRole::Admin->value && $this->activeAdmins($userId) === 0) {
            throw new DomainError('Hệ thống phải còn ít nhất một tài khoản quản trị hoạt động');
        }
        $this->db->exec('UPDATE nguoi_dung SET hoat_dong = ? WHERE id = ?', [(int)$active, $userId]);
        $this->audit->record($actor->id, $active ? 'user.unlock' : 'user.lock', 'user', $userId, $u['ten_dang_nhap']);
    }

    public function resetPassword(User $actor, int $userId, string $password): void
    {
        $this->auth->requirePermission($actor, Permission::QuanLyTaiKhoan);
        if (strlen($password) < ($min = $this->settings->minPassword())) {
            throw new DomainError("Mật khẩu tối thiểu $min ký tự");
        }
        $u = $this->db->one('SELECT ten_dang_nhap FROM nguoi_dung WHERE id = ?', [$userId]) ?? throw new DomainError('Tài khoản không tồn tại');
        $this->db->exec('UPDATE nguoi_dung SET mat_khau_ma_hoa = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
        $this->audit->record($actor->id, 'user.password', 'user', $userId, $u['ten_dang_nhap']);
    }

    /** Số tài khoản ADMIN đang hoạt động, không tính một tài khoản (để chặn khoá/hạ cấp admin cuối cùng). */
    private function activeAdmins(int $exceptUserId): int
    {
        return (int)$this->db->value("SELECT COUNT(*) FROM nguoi_dung WHERE vai_tro = 'ADMIN' AND hoat_dong AND id <> ?", [$exceptUserId]);
    }

    private function requireUnique(string $table, string $column, string $value, string $message): void
    {
        if ($this->db->value("SELECT 1 FROM $table WHERE $column = ?", [$value])) {
            throw new DomainError($message);
        }
    }
}
