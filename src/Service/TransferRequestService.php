<?php
declare(strict_types=1);

namespace StockFlow\Service;

use mysqli_sql_exception;
use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Yêu cầu chuyển hàng giữa hai kho:
 *   nhân viên kho nhận lập → quản lý kho nhận xác nhận → quản lý kho cấp chấp nhận hoặc từ chối (kèm lý do).
 *   Chấp nhận ⇒ mở phiếu điều chuyển đi (nháp) ở kho cấp, đi tiếp quy trình sẵn có (chọn xe, tài xế xác nhận đủ hàng, xuất kho, kho nhận kiểm đếm).
 *   Từ chối ⇒ yêu cầu bị huỷ, quản lý kho nhận được báo lý do.
 * Quản lý lập thì bỏ bước xác nhận nội bộ (đã là người xác nhận).
 */
final class TransferRequestService
{
    public const LABELS = [
        'WAIT_OWN' => ['Chờ quản lý kho xác nhận', 'warn'], 'WAIT_SOURCE' => ['Chờ kho cấp chấp nhận', 'info'],
        'ACCEPTED' => ['Đã chấp nhận', 'ok'], 'REJECTED' => ['Bị từ chối', 'danger'], 'CANCELLED' => ['Đã huỷ', 'muted'],
    ];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit, private TransferService $transfers, private StockQuery $query) {}

    /** @param list<array{mat_hang_id:int,lo_hang_id:int,so_luong:int}> $lines @return array{id:int,created:bool} */
    public function create(User $user, int $sourceWh, array $lines, string $note, ?string $idem): array
    {
        $this->auth->requirePermission($user, Permission::LapPhieuDieuChuyen);
        if ($user->warehouseId === null) {
            throw new Forbidden('Chỉ tài khoản thuộc một kho mới lập được yêu cầu chuyển hàng');
        }
        $own = (int)$user->warehouseId;
        if ($sourceWh === $own) {
            throw new DomainError('Kho cấp hàng phải khác kho của bạn');
        }
        if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$sourceWh])) {
            throw new DomainError('Chọn kho cấp hàng đang hoạt động');
        }
        if ($lines === []) {
            throw new DomainError('Chọn ít nhất một mặt hàng cần lấy');
        }
        if (mb_strlen(trim($note)) > 255) {
            throw new DomainError('Ghi chú tối đa 255 ký tự');
        }
        foreach ($lines as $l) {
            if (!$this->db->value('SELECT 1 FROM mat_hang i JOIN lo_hang b ON b.mat_hang_id = i.id WHERE i.id = ? AND b.id = ? AND i.hoat_dong', [$l['mat_hang_id'], $l['lo_hang_id']])) {
                throw new DomainError('Mặt hàng/lô không hợp lệ hoặc đã ngừng hoạt động');
            }
        }
        if ($errors = $this->query->shortages($sourceWh, $lines)) {
            throw new DomainError('Kho cấp không đủ hàng — ' . implode('; ', $errors));
        }
        if ($errors = $this->query->fefoErrors($sourceWh, $lines)) {
            throw new DomainError('Kho cấp phải xuất theo hạn dùng — ' . implode('; ', $errors));
        }
        if ($idem !== null && ($id = $this->db->value('SELECT id FROM yeu_cau_chuyen_hang WHERE khoa_chong_trung = ?', [$idem]))) {
            return ['id' => (int)$id, 'created' => false];
        }
        $manager = $this->auth->allows($user, Permission::XacNhanPhieu);
        try {
            $id = $this->db->transaction(function () use ($user, $own, $sourceWh, $lines, $note, $idem, $manager) {
                $code = sprintf('YC-%s-%06d', gmdate('ymd'), $this->db->nextSequence('transfer_request'));
                $id = $this->db->insert(
                    'INSERT INTO yeu_cau_chuyen_hang (ma, kho_nhan_id, kho_cap_id, trang_thai, ghi_chu, nguoi_lap_id, nguoi_duyet_id, ngay_duyet, khoa_chong_trung)
                     VALUES (?,?,?,?,?,?,?,' . ($manager ? 'CURRENT_TIMESTAMP' : 'NULL') . ',?)',
                    [$code, $own, $sourceWh, $manager ? 'WAIT_SOURCE' : 'WAIT_OWN', trim($note), $user->id, $manager ? $user->id : null, $idem]
                );
                foreach ($lines as $l) {
                    $this->db->exec('INSERT INTO yeu_cau_chuyen_hang_dong (yeu_cau_id, mat_hang_id, lo_hang_id, so_luong) VALUES (?,?,?,?)',
                        [$id, $l['mat_hang_id'], $l['lo_hang_id'], $l['so_luong']]);
                }
                $this->audit->record($user->id, 'request.create', 'request', $id, "$code: kho $sourceWh → kho $own");
                return $id;
            });
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === Db::DUPLICATE_KEY && $idem !== null && ($id = $this->db->value('SELECT id FROM yeu_cau_chuyen_hang WHERE khoa_chong_trung = ?', [$idem]))) {
                return ['id' => (int)$id, 'created' => false];
            }
            throw $e;
        }
        return ['id' => (int)$id, 'created' => true];
    }

    /** Quản lý kho nhận xác nhận yêu cầu của nhân viên ⇒ gửi sang kho cấp. */
    public function approveOwn(User $user, int $id): void
    {
        $this->db->transaction(function () use ($user, $id) {
            $r = $this->lock($id);
            $this->auth->require($user, (int)$r['kho_nhan_id'], Permission::XacNhanPhieu);
            if ($r['trang_thai'] !== 'WAIT_OWN') {
                throw new DomainError('Yêu cầu này không còn ở bước chờ quản lý kho xác nhận');
            }
            $this->db->exec("UPDATE yeu_cau_chuyen_hang SET trang_thai = 'WAIT_SOURCE', nguoi_duyet_id = ?, ngay_duyet = CURRENT_TIMESTAMP WHERE id = ?", [$user->id, $id]);
            $this->audit->record($user->id, 'request.approve', 'request', $id, $r['ma'] . ' · gửi kho cấp');
        });
    }

    /** Kho nhận tự rút (người lập hoặc quản lý kho nhận), khi kho cấp chưa xử lý. */
    public function cancel(User $user, int $id, string $reason): void
    {
        $this->db->transaction(function () use ($user, $id, $reason) {
            $r = $this->lock($id);
            $this->auth->requirePermission($user, Permission::LapPhieuDieuChuyen);
            $isManager = $this->auth->can($user, (int)$r['kho_nhan_id'], Permission::XacNhanPhieu);
            if (!$isManager && (int)$r['nguoi_lap_id'] !== $user->id) {
                throw new Forbidden('Chỉ người lập hoặc quản lý kho nhận mới huỷ được yêu cầu này');
            }
            if (!in_array($r['trang_thai'], ['WAIT_OWN', 'WAIT_SOURCE'], true)) {
                throw new DomainError('Yêu cầu đã được xử lý, không huỷ được nữa');
            }
            $reason = trim($reason);
            if (mb_strlen($reason) > 200) {
                throw new DomainError('Lý do tối đa 200 ký tự');
            }
            $this->db->exec("UPDATE yeu_cau_chuyen_hang SET trang_thai = 'CANCELLED', nguoi_xu_ly_id = ?, ngay_xu_ly = CURRENT_TIMESTAMP, ly_do = ?, da_bao = 1 WHERE id = ?", [$user->id, $reason, $id]);
            $this->audit->record($user->id, 'request.cancel', 'request', $id, $r['ma'] . ($reason !== '' ? ' · ' . $reason : ''));
        });
    }

    /** Quản lý kho cấp chấp nhận ⇒ mở phiếu điều chuyển đi (nháp) từ kho cấp sang kho nhận. */
    public function accept(User $user, int $id): int
    {
        $r = $this->find($id);
        $this->auth->require($user, (int)$r['kho_cap_id'], Permission::XacNhanPhieu);
        if ($r['trang_thai'] !== 'WAIT_SOURCE') {
            throw new DomainError('Yêu cầu này không ở bước chờ kho cấp chấp nhận');
        }
        $lines = array_map(fn($l) => ['mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'], 'so_luong' => (int)$l['so_luong']], $r['lines']);
        if ($errors = $this->query->shortages((int)$r['kho_cap_id'], $lines)) {
            throw new DomainError('Kho không còn đủ hàng để chấp nhận — ' . implode('; ', $errors) . '. Hãy từ chối kèm lý do.');
        }
        if ($errors = $this->query->fefoErrors((int)$r['kho_cap_id'], $lines)) {
            throw new DomainError('Kho phải xuất theo hạn dùng — ' . implode('; ', $errors) . '. Hãy từ chối kèm lý do hoặc nhờ kho nhận lập lại yêu cầu.');
        }
        // Khoá chống trùng suy ra từ mã yêu cầu: bấm đúp cũng chỉ ra một phiếu.
        $res = $this->transfers->createDraft($user, (int)$r['kho_cap_id'], (int)$r['kho_nhan_id'], $lines, 'Theo yêu cầu ' . $r['ma'], md5('request:' . $id));
        $voucherId = (int)$res['voucher']['id'];
        $this->db->transaction(function () use ($user, $id, $voucherId, $r) {
            $cur = $this->lock($id);
            if ($cur['trang_thai'] === 'WAIT_SOURCE') {
                $this->db->exec("UPDATE yeu_cau_chuyen_hang SET trang_thai = 'ACCEPTED', nguoi_xu_ly_id = ?, ngay_xu_ly = CURRENT_TIMESTAMP, phieu_id = ?, da_bao = 1 WHERE id = ?", [$user->id, $voucherId, $id]);
                $this->audit->record($user->id, 'request.accept', 'request', $id, $r['ma'] . ' → phiếu ' . $voucherId);
            }
        });
        return $voucherId;
    }

    /** Quản lý kho cấp từ chối, bắt buộc có lý do ⇒ yêu cầu bị huỷ, kho nhận được báo. */
    public function reject(User $user, int $id, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainError('Nhập lý do từ chối để kho nhận biết');
        }
        if (mb_strlen($reason) > 200) {
            throw new DomainError('Lý do tối đa 200 ký tự');
        }
        $this->db->transaction(function () use ($user, $id, $reason) {
            $r = $this->lock($id);
            $this->auth->require($user, (int)$r['kho_cap_id'], Permission::XacNhanPhieu);
            if ($r['trang_thai'] !== 'WAIT_SOURCE') {
                throw new DomainError('Yêu cầu này không ở bước chờ kho cấp chấp nhận');
            }
            $this->db->exec("UPDATE yeu_cau_chuyen_hang SET trang_thai = 'REJECTED', nguoi_xu_ly_id = ?, ngay_xu_ly = CURRENT_TIMESTAMP, ly_do = ?, da_bao = 0 WHERE id = ?", [$user->id, $reason, $id]);
            $this->audit->record($user->id, 'request.reject', 'request', $id, $r['ma'] . ' · ' . $reason);
        });
    }

    public function find(int $id): array
    {
        $r = $this->db->one(self::SELECT . ' WHERE y.id = ?', [$id]) ?? throw new NotFound('Không tìm thấy yêu cầu chuyển hàng');
        $r['lines'] = $this->lines($id);
        return $r;
    }

    /** Người dùng có xem được yêu cầu này không (kho nhận hoặc kho cấp của mình; quản trị xem tất cả). */
    public function canSee(User $user, array $r): bool
    {
        return $user->isAdmin || in_array((int)$user->warehouseId, [(int)$r['kho_nhan_id'], (int)$r['kho_cap_id']], true);
    }

    /** @return list<array> yêu cầu liên quan tới các kho (kho nhận hoặc kho cấp), mới nhất trước */
    public function list(array $warehouseIds, ?string $status = null): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $sql = self::SELECT . " WHERE (y.kho_nhan_id IN ($in) OR y.kho_cap_id IN ($in))";
        $args = [...$warehouseIds, ...$warehouseIds];
        if ($status) {
            $sql .= ' AND y.trang_thai = ?';
            $args[] = $status;
        }
        $rows = $this->db->all($sql . ' ORDER BY y.id DESC LIMIT 500', $args);
        foreach ($rows as &$r) {
            $r['lines'] = $this->lines((int)$r['id']);
        }
        return $rows;
    }

    /** @return array<string,int> số yêu cầu theo trạng thái trong phạm vi kho */
    public function counts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT trang_thai, COUNT(*) AS n FROM yeu_cau_chuyen_hang WHERE kho_nhan_id IN ($in) OR kho_cap_id IN ($in) GROUP BY trang_thai", [...$warehouseIds, ...$warehouseIds]) as $row) {
            $out[$row['trang_thai']] = (int)$row['n'];
        }
        return $out;
    }

    /** Số việc đang chờ tài khoản xử lý (số nhỏ trên menu): quản lý — chờ xác nhận + chờ chấp nhận + bị từ chối chưa xem; nhân viên — bị từ chối chưa xem. */
    public function pendingFor(User $user): int
    {
        if ($user->warehouseId === null) {
            return 0;
        }
        $wh = (int)$user->warehouseId;
        $n = (int)$this->db->value("SELECT COUNT(*) FROM yeu_cau_chuyen_hang WHERE kho_nhan_id = ? AND trang_thai = 'REJECTED' AND da_bao = 0", [$wh]);
        if ($this->auth->allows($user, Permission::XacNhanPhieu)) {
            $n += (int)$this->db->value("SELECT COUNT(*) FROM yeu_cau_chuyen_hang WHERE (kho_nhan_id = ? AND trang_thai = 'WAIT_OWN') OR (kho_cap_id = ? AND trang_thai = 'WAIT_SOURCE')", [$wh, $wh]);
        }
        return $n;
    }

    /** Mở trang danh sách: các yêu cầu bị từ chối của kho mình coi như đã báo. */
    public function markSeen(User $user): void
    {
        if ($user->warehouseId !== null) {
            $this->db->exec("UPDATE yeu_cau_chuyen_hang SET da_bao = 1 WHERE kho_nhan_id = ? AND trang_thai = 'REJECTED' AND da_bao = 0", [$user->warehouseId]);
        }
    }

    private const SELECT = 'SELECT y.*, wn.ma AS nhan_ma, wn.ten AS nhan_ten, wc.ma AS cap_ma, wc.ten AS cap_ten, lu.ho_ten AS lap_ten, du.ho_ten AS duyet_ten, xu.ho_ten AS xu_ly_ten, p.ma AS phieu_ma
        FROM yeu_cau_chuyen_hang y JOIN kho wn ON wn.id = y.kho_nhan_id JOIN kho wc ON wc.id = y.kho_cap_id JOIN nguoi_dung lu ON lu.id = y.nguoi_lap_id
        LEFT JOIN nguoi_dung du ON du.id = y.nguoi_duyet_id LEFT JOIN nguoi_dung xu ON xu.id = y.nguoi_xu_ly_id LEFT JOIN phieu_nhap_xuat p ON p.id = y.phieu_id';

    private function lines(int $id): array
    {
        return $this->db->all(
            'SELECT d.*, i.ma_hang, i.ten, i.quan_ly_lo, u.ma AS unit, b.so_lo FROM yeu_cau_chuyen_hang_dong d JOIN mat_hang i ON i.id = d.mat_hang_id
             JOIN don_vi_tinh u ON u.id = i.don_vi_tinh_id JOIN lo_hang b ON b.id = d.lo_hang_id WHERE d.yeu_cau_id = ? ORDER BY d.id',
            [$id]
        );
    }

    private function lock(int $id): array
    {
        return $this->db->one('SELECT * FROM yeu_cau_chuyen_hang WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound('Không tìm thấy yêu cầu chuyển hàng');
    }
}
