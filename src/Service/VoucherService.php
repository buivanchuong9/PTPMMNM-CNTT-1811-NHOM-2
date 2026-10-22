<?php
declare(strict_types=1);

namespace StockFlow\Service;

use mysqli_sql_exception;
use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Permission;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;

/** Vòng đời phiếu nhập / xuất / điều chỉnh: lập → kiểm tra → xác nhận (ghi sổ) hoặc huỷ; sửa bằng phiếu điều chỉnh. */
final class VoucherService
{
    public function __construct(private Db $db, private VoucherRepository $repo, private Ledger $ledger,
                                private Authorizer $auth, private AuditLog $audit, private TransferService $transfers) {}

    /**
     * Lập phiếu nhập/xuất ở trạng thái nháp (chưa ảnh hưởng tồn).
     * Gửi lại cùng $idempotencyKey ⇒ trả về phiếu đã có, không tạo phiếu thứ hai.
     * @param list<array{item_id:int,batch_id:int,qty:int,note?:string}> $lines
     * @return array{voucher:array,created:bool}
     */
    public function createDraft(User $user, VoucherType $type, int $warehouseId, array $lines, string $partner, string $note, ?string $idempotencyKey): array
    {
        if (!in_array($type, [VoucherType::Receipt, VoucherType::Issue], true)) {
            throw new DomainError('Chỉ lập tay phiếu nhập hoặc phiếu xuất');
        }
        $this->auth->require($user, $warehouseId, $type === VoucherType::Receipt ? Permission::LapPhieuNhap : Permission::LapPhieuXuat);
        if (mb_strlen(trim($partner)) > 120) {
            throw new DomainError('Đối tác tối đa 120 ký tự');
        }
        if (mb_strlen(trim($note)) > 255) {
            throw new DomainError('Ghi chú tối đa 255 ký tự');
        }
        if ($idempotencyKey !== null && ($existing = $this->repo->findByKey($idempotencyKey))) {
            return ['voucher' => $existing, 'created' => false];
        }
        try {
            $id = $this->db->transaction(function () use ($user, $type, $warehouseId, $lines, $partner, $note, $idempotencyKey) {
                $this->requireActiveWarehouse($warehouseId);
                $this->validateLines($lines);
                $id = $this->repo->insert([
                    'loai' => $type->value, 'kho_id' => $warehouseId, 'doi_tac' => trim($partner), 'ghi_chu' => trim($note),
                    'khoa_chong_trung' => $idempotencyKey, 'nguoi_lap_id' => $user->id,
                ], $lines);
                $this->audit->record($user->id, 'voucher.create', 'voucher', $id, $type->value);
                return $id;
            });
        } catch (mysqli_sql_exception $e) {
            // Hai yêu cầu cùng khoá idempotency đến đồng thời: yêu cầu thua trả về phiếu của yêu cầu thắng.
            if ($e->getCode() === Db::DUPLICATE_KEY && $idempotencyKey !== null && ($existing = $this->repo->findByKey($idempotencyKey))) {
                return ['voucher' => $existing, 'created' => false];
            }
            throw $e;
        }
        return ['voucher' => $this->repo->find($id), 'created' => true];
    }

    /** Bước "kiểm tra": xem trước kết quả xác nhận, không ghi gì. @return list<array{line:array,available:int,ok:bool}> */
    public function check(User $user, int $id): array
    {
        $v = $this->repo->find($id);
        $this->auth->requireVisible($user, (int)$v['kho_id']);
        $lines = $this->repo->lines($id);
        $result = [];
        foreach ($lines as $l) {
            $available = (int)$this->db->value(
                'SELECT COALESCE(SUM(bien_dong),0) FROM so_phat_sinh WHERE kho_id = ? AND mat_hang_id = ? AND lo_hang_id = ?',
                [$v['kho_id'], $l['mat_hang_id'], $l['lo_hang_id']]
            );
            $delta = StockRules::delta(VoucherType::from($v['loai']), (int)$l['so_luong']);
            $result[] = ['line' => $l, 'available' => $available, 'ok' => $available + $delta >= 0];
        }
        return $result;
    }

    /**
     * Xác nhận phiếu ⇒ ghi sổ. Idempotent: phiếu đã xác nhận ⇒ không ghi thêm (trả về already=true).
     * @return array{voucher:array,already:bool}
     */
    public function confirm(User $user, int $id): array
    {
        $peek = $this->repo->find($id);
        if ($peek['loai'] === VoucherType::TransferOut->value) {
            return $this->transfers->confirm($user, $id);
        }
        if ($peek['loai'] === VoucherType::TransferIn->value) {
            return $this->transfers->receive($user, $id);
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        $already = $this->db->transaction(function () use ($user, $id) {
            $v = $this->repo->lock($id);
            if ($v['trang_thai'] === 'CONFIRMED') {
                return true;
            }
            if ($v['trang_thai'] !== 'DRAFT') {
                throw new DomainError('Chỉ phiếu nháp mới được xác nhận');
            }
            $lines = $this->repo->lines($id);
            if ($lines === []) {
                throw new DomainError('Phiếu không có dòng hàng');
            }
            $ops = $this->repo->ops($v, $lines);
            $this->ledger->lock($this->repo->lockKeys($ops));
            $this->ledger->post($ops);
            $this->db->exec("UPDATE phieu_nhap_xuat SET trang_thai = 'CONFIRMED', nguoi_xac_nhan_id = ?, ngay_xac_nhan = CURRENT_TIMESTAMP WHERE id = ?", [$user->id, $id]);
            $this->audit->record($user->id, 'voucher.confirm', 'voucher', $id, $v['ma']);
            return false;
        });
        return ['voucher' => $this->repo->find($id), 'already' => $already];
    }

    /** Huỷ phiếu nháp (phiếu vẫn còn trong lịch sử, trạng thái CANCELLED). */
    public function cancel(User $user, int $id): void
    {
        $peek = $this->repo->find($id);
        if ($peek['loai'] === VoucherType::TransferIn->value) {
            throw new DomainError('Hàng điều chuyển đã rời kho đi — không huỷ được phiếu nhận. Hãy nhận hàng, rồi điều chỉnh nếu có sai lệch');
        }
        // Người có chức năng xác nhận, hoặc người được lập loại phiếu đó ở kho, đều được huỷ nháp.
        if (!$this->auth->can($user, (int)$peek['kho_id'], Permission::XacNhanPhieu)) {
            $this->auth->require($user, (int)$peek['kho_id'], $peek['loai'] === VoucherType::Receipt->value ? Permission::LapPhieuNhap : Permission::LapPhieuXuat);
        }
        $this->db->transaction(function () use ($user, $id) {
            $v = $this->repo->lock($id);
            if ($v['trang_thai'] !== 'DRAFT') {
                throw new DomainError('Chỉ phiếu nháp mới được huỷ');
            }
            $this->db->exec("UPDATE phieu_nhap_xuat SET trang_thai = 'CANCELLED' WHERE id = ?", [$id]);
            $this->audit->record($user->id, 'voucher.cancel', 'voucher', $id, $v['ma']);
        });
    }

    /**
     * Sửa phiếu nhập xuất đã ghi sổ bằng phiếu điều chỉnh đảo (không xoá/sửa lịch sử). Mỗi phiếu nhập xuất chỉ đảo được một lần.
     * @return array{voucher:array} phiếu điều chỉnh vừa lập (hoặc phía nơi đi nếu là điều chuyển)
     */
    public function correct(User $user, int $id, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainError('Cần ghi rõ lý do điều chỉnh');
        }
        if (mb_strlen($reason) > 255) {
            throw new DomainError('Lý do điều chỉnh tối đa 255 ký tự');
        }
        $peek = $this->repo->find($id);
        if (in_array($peek['loai'], [VoucherType::TransferOut->value, VoucherType::TransferIn->value], true)) {
            return $this->transfers->correct($user, $id, $reason);
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::DieuChinhPhieu);
        $newId = $this->db->transaction(function () use ($user, $id, $reason) {
            $v = $this->repo->lock($id);
            if ($v['trang_thai'] !== 'CONFIRMED') {
                throw new DomainError('Chỉ phiếu nhập xuất đã xác nhận mới cần điều chỉnh');
            }
            if ($this->repo->correctionOf($id)) {
                throw new DomainError('Phiếu nhập xuất này đã được điều chỉnh');
            }
            $type = VoucherType::from($v['loai']);
            $lines = array_map(fn($l) => [
                'mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'],
                'so_luong' => StockRules::reversalQty($type, (int)$l['so_luong']), 'ghi_chu' => 'Đảo ' . $v['ma'],
            ], $this->repo->lines($id));
            $newId = $this->repo->insert([
                'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$v['kho_id'],
                'dieu_chinh_cho_id' => $id, 'ly_do' => $reason, 'nguoi_lap_id' => $user->id,
            ], $lines);
            $adj = $this->repo->lock($newId);
            $ops = $this->repo->ops($adj, $this->repo->lines($newId));
            $this->ledger->lock($this->repo->lockKeys($ops));
            $this->ledger->post($ops);
            $this->audit->record($user->id, 'voucher.correct', 'voucher', $id, "{$v['ma']} → $newId: $reason");
            return $newId;
        });
        return ['voucher' => $this->repo->find($newId)];
    }

    private function requireActiveWarehouse(int $id): void
    {
        if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$id])) {
            throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
        }
    }

    /** Mặt hàng/lô phải tồn tại, đang hoạt động và khớp nhau. */
    public function validateLines(array $lines): void
    {
        if ($lines === []) {
            throw new DomainError('Phiếu cần ít nhất một dòng hàng');
        }
        foreach ($lines as $l) {
            $ok = $this->db->value('SELECT 1 FROM mat_hang i JOIN lo_hang b ON b.mat_hang_id = i.id WHERE i.id = ? AND b.id = ? AND i.hoat_dong',
                [$l['mat_hang_id'], $l['lo_hang_id']]);
            if (!$ok) {
                throw new DomainError('Mặt hàng/lô không hợp lệ hoặc đã ngừng hoạt động');
            }
            if ((int)$l['so_luong'] < 1) {
                throw new DomainError('Số lượng phải là số nguyên dương');
            }
        }
    }
}
