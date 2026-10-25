<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Core\Session;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * «Thay đổi mới» theo từng chức năng (mục menu), tính riêng cho từng tài khoản.
 *
 * Mỗi tài khoản có một MỐC «đã xem đến đâu» cho mỗi chức năng (lưu ở cai_dat, khoá seen.<chức năng>, giá trị = id nhật ký thao tác cuối đã thấy;
 * riêng «Lịch sử phát sinh» dùng id dòng sổ). Số «+N» cạnh mục menu = số đối tượng mà NGƯỜI KHÁC đã tạo / sửa / xác nhận / huỷ sau mốc đó,
 * trong phạm vi kho của tài khoản. Mở chức năng ⇒ các đối tượng đó được bôi đậm một lần rồi mốc dời lên: xem rồi thì thôi.
 * Lần đầu một tài khoản dùng tính năng này, mốc đặt ở thời điểm hiện tại (không đổ dồn cả lịch sử cũ thành «mới»).
 */
final class ChangeTracker
{
    /** Đường dẫn trang danh sách ⇒ chức năng. Trang chi tiết không tính là «đã xem». */
    public const PATHS = ['/vouchers' => 'vouchers', '/counts' => 'counts', '/inventory' => 'inventory', '/history' => 'history',
        '/partners' => 'partners', '/warehouses' => 'warehouses', '/users' => 'users', '/fleet' => 'fleet', '/requests' => 'requests'];

    /** @var array<int,array<string,int>> bộ nhớ đệm trong một yêu cầu: id tài khoản ⇒ [chức năng ⇒ số mới] */
    private array $counted = [];

    public function __construct(private Db $db, private Authorizer $auth, private Session $session) {}

    /** @return array{perms:list<Permission>,entity:?string,scope:?string} entity = loại đối tượng trong nhật ký (null = dùng sổ phát sinh); scope = bảng để lọc theo kho */
    private function def(string $section): array
    {
        return match ($section) {
            'vouchers' => ['perms' => [Permission::XemPhieu], 'entity' => 'voucher', 'scope' => 'phieu_nhap_xuat'],
            'counts' => ['perms' => [Permission::XemKiemKe, Permission::KiemKeThucHien, Permission::KiemKeDuyet], 'entity' => 'count', 'scope' => 'dot_kiem_ke'],
            'inventory' => ['perms' => [Permission::XemTonKho], 'entity' => 'item', 'scope' => null],
            'partners' => ['perms' => [Permission::XemDanhMuc], 'entity' => 'partner', 'scope' => null],
            'warehouses' => ['perms' => [Permission::XemDanhMuc], 'entity' => 'warehouse', 'scope' => 'kho'],
            'users' => ['perms' => [Permission::QuanLyTaiKhoan], 'entity' => 'user', 'scope' => null],
            'fleet' => ['perms' => [Permission::QuanLyDoiXe], 'entity' => 'vehicle', 'scope' => null],
            'requests' => ['perms' => [Permission::XemPhieu], 'entity' => 'request', 'scope' => 'yeu_cau_chuyen_hang'],
            'history' => ['perms' => [Permission::XemLichSu], 'entity' => null, 'scope' => 'so_phat_sinh'],
        };
    }

    private function allowed(User $user, string $section): bool
    {
        foreach ($this->def($section)['perms'] as $p) {
            if ($this->auth->allows($user, $p)) {
                return true;
            }
        }
        return false;
    }

    private function currentMax(string $section): int
    {
        return (int)$this->db->value($section === 'history' ? 'SELECT COALESCE(MAX(id), 0) FROM so_phat_sinh' : 'SELECT COALESCE(MAX(id), 0) FROM nhat_ky');
    }

    /** Mốc đã xem của từng chức năng; chức năng chưa có mốc thì đặt ở thời điểm hiện tại. @return array<string,int> */
    private function seen(User $user): array
    {
        $out = [];
        foreach ($this->db->all("SELECT khoa, gia_tri FROM cai_dat WHERE pham_vi = 'user' AND doi_tuong_id = ? AND khoa LIKE 'seen.%'", [$user->id]) as $r) {
            if (in_array(substr($r['khoa'], 5), self::PATHS, true)) {   // khoá lạ (chức năng đã bỏ / do nơi khác ghi) thì bỏ qua, không làm hỏng mọi trang
                $out[substr($r['khoa'], 5)] = (int)$r['gia_tri'];
            }
        }
        foreach (self::PATHS as $section) {
            if (!isset($out[$section]) && $this->allowed($user, $section)) {
                $out[$section] = $this->currentMax($section);
                $this->put($user, $section, $out[$section]);
            }
        }
        return $out;
    }

    private function put(User $user, string $section, int $id): void
    {
        $key = 'seen.' . $section;
        $exists = $this->db->value("SELECT COUNT(*) FROM cai_dat WHERE pham_vi = 'user' AND doi_tuong_id = ? AND khoa = ?", [$user->id, $key]);
        $exists
            ? $this->db->exec("UPDATE cai_dat SET gia_tri = ?, cap_nhat = CURRENT_TIMESTAMP WHERE pham_vi = 'user' AND doi_tuong_id = ? AND khoa = ?", [(string)$id, $user->id, $key])
            : $this->db->exec("INSERT INTO cai_dat (pham_vi, doi_tuong_id, khoa, gia_tri) VALUES ('user', ?, ?, ?)", [$user->id, $key, (string)$id]);
    }

    /** @return list<int> id các đối tượng người khác đã thay đổi trong (since, upto] — giới hạn theo kho của tài khoản. */
    private function changedIds(User $user, string $section, int $since, int $upto): array
    {
        $d = $this->def($section);
        if ($d['entity'] === null || $upto <= $since) {
            return [];
        }
        $sql = "SELECT DISTINCT a.doi_tuong_id AS id FROM nhat_ky a WHERE a.id > ? AND a.id <= ? AND a.doi_tuong = ? AND a.doi_tuong_id IS NOT NULL
                AND (a.nguoi_dung_id IS NULL OR a.nguoi_dung_id <> ?)";
        $args = [$since, $upto, $d['entity'], $user->id];
        if (!$user->isAdmin && $d['scope'] !== null) {
            $ids = $this->auth->visibleWarehouseIds($user);
            if ($ids === []) {
                return [];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            if ($d['scope'] === 'yeu_cau_chuyen_hang') {   // yêu cầu liên quan tới kho nhận hoặc kho cấp của tài khoản
                $sql .= " AND EXISTS (SELECT 1 FROM yeu_cau_chuyen_hang s WHERE s.id = a.doi_tuong_id AND (s.kho_nhan_id IN ($in) OR s.kho_cap_id IN ($in)))";
                array_push($args, ...$ids, ...$ids);
            } else {
                $col = $d['scope'] === 'kho' ? 'id' : 'kho_id';
                $sql .= " AND EXISTS (SELECT 1 FROM {$d['scope']} s WHERE s.id = a.doi_tuong_id AND s.$col IN ($in))";
                array_push($args, ...$ids);
            }
        }
        return array_map(fn($r) => (int)$r['id'], $this->db->all($sql, $args));
    }

    /** Số dòng sổ phát sinh mới trong phạm vi kho. */
    private function ledgerNew(User $user, int $since, int $upto): int
    {
        $ids = $this->auth->visibleWarehouseIds($user);
        if ($ids === [] || $upto <= $since) {
            return 0;
        }
        return (int)$this->db->value('SELECT COUNT(*) FROM so_phat_sinh WHERE id > ? AND id <= ? AND kho_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$since, $upto, ...$ids]);
    }

    /**
     * Số thay đổi mới của từng chức năng (chỉ gồm chức năng có số > 0) — cho số «+N» trên menu và /api/badges. Không đánh dấu «đã xem».
     * @return array<string,int>
     */
    public function counts(User $user): array
    {
        if (isset($this->counted[$user->id])) {
            return $this->counted[$user->id];
        }
        $seen = $this->seen($user);
        $out = [];
        foreach (array_keys($seen) as $section) {
            $upto = $this->currentMax($section);
            $n = $section === 'history' ? $this->ledgerNew($user, $seen[$section], $upto) : count($this->changedIds($user, $section, $seen[$section], $upto));
            if ($n > 0) {
                $out[$section] = $n;
            }
        }
        return $this->counted[$user->id] = $out;
    }

    /**
     * MỞ một chức năng: trả về những gì cần bôi đậm rồi dời mốc «đã xem» lên (xem rồi thì thôi).
     * $continuation = true khi vẫn đang ở chính trang đó (lọc, phân trang, làm tươi bảng): giữ nguyên phần bôi đậm của lần mở này, không dời mốc nữa.
     * @return array{section:string,ids:array<int,true>,after:int,upto:int} ids = đối tượng mới (đặt khoá theo id); after/upto = khoảng id sổ phát sinh mới (chỉ «history»)
     */
    public function visit(User $user, string $section, bool $continuation = false): array
    {
        $none = ['section' => $section, 'ids' => [], 'after' => 0, 'upto' => 0];
        if (!isset($this->def($section)['perms']) || !$this->allowed($user, $section)) {
            return $none;
        }
        $state = $this->session->recall('changes.' . $section);
        if ($continuation && is_array($state)) {
            [$since, $upto] = $state;
        } else {
            $since = $this->seen($user)[$section] ?? 0;
            $upto = $this->currentMax($section);
            $this->put($user, $section, $upto);
            $this->session->remember('changes.' . $section, [$since, $upto]);
            unset($this->counted[$user->id]);
        }
        if ($section === 'history') {
            return ['section' => $section, 'ids' => [], 'after' => $since, 'upto' => $upto];
        }
        return ['section' => $section, 'ids' => array_fill_keys($this->changedIds($user, $section, $since, $upto), true), 'after' => $since, 'upto' => $upto];
    }
}
