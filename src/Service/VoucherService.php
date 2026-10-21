<?php
declare(strict_types=1);

namespace StockFlow\Service;

use mysqli_sql_exception;
use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Role;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;

/** Vòng đời phiếu nhập / xuất / điều chỉnh: lập → kiểm tra → xác nhận (ghi sổ) hoặc huỷ; sửa bằng chứng từ điều chỉnh. */
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
        $this->auth->require($user, $warehouseId, Role::Keeper);
        if ($idempotencyKey !== null && ($existing = $this->repo->findByKey($idempotencyKey))) {
            return ['voucher' => $existing, 'created' => false];
        }
        try {
            $id = $this->db->transaction(function () use ($user, $type, $warehouseId, $lines, $partner, $note, $idempotencyKey) {
                $this->requireActiveWarehouse($warehouseId);
                $this->validateLines($lines);
                $id = $this->repo->insert([
                    'type' => $type->value, 'warehouse_id' => $warehouseId, 'partner' => trim($partner), 'note' => trim($note),
                    'idempotency_key' => $idempotencyKey, 'created_by' => $user->id,
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
        $this->auth->requireVisible($user, (int)$v['warehouse_id']);
        $lines = $this->repo->lines($id);
        $result = [];
        foreach ($lines as $l) {
            $available = (int)$this->db->value(
                'SELECT COALESCE(SUM(qty_change),0) FROM so_phat_sinh WHERE warehouse_id = ? AND item_id = ? AND batch_id = ?',
                [$v['warehouse_id'], $l['item_id'], $l['batch_id']]
            );
            $delta = StockRules::delta(VoucherType::from($v['type']), (int)$l['qty']);
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
        if ($peek['type'] === VoucherType::TransferOut->value) {
            return $this->transfers->confirm($user, $id);
        }
        if ($peek['type'] === VoucherType::TransferIn->value) {
            throw new DomainError('Chứng từ điều chuyển đến được tạo tự động cùng phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['warehouse_id'], Role::Approver);
        $already = $this->db->transaction(function () use ($user, $id) {
            $v = $this->repo->lock($id);
            if ($v['status'] === 'CONFIRMED') {
                return true;
            }
            if ($v['status'] !== 'DRAFT') {
                throw new DomainError('Chỉ phiếu nháp mới được xác nhận');
            }
            $lines = $this->repo->lines($id);
            if ($lines === []) {
                throw new DomainError('Phiếu không có dòng hàng');
            }
            $ops = $this->repo->ops($v, $lines);
            $this->ledger->lock($this->repo->lockKeys($ops));
            $this->ledger->post($ops);
            $this->db->exec("UPDATE chung_tu SET status = 'CONFIRMED', confirmed_by = ?, confirmed_at = CURRENT_TIMESTAMP WHERE id = ?", [$user->id, $id]);
            $this->audit->record($user->id, 'voucher.confirm', 'voucher', $id, $v['code']);
            return false;
        });
        return ['voucher' => $this->repo->find($id), 'already' => $already];
    }

    /** Huỷ phiếu nháp (phiếu vẫn còn trong lịch sử, trạng thái CANCELLED). */
    public function cancel(User $user, int $id): void
    {
        $peek = $this->repo->find($id);
        // Người lập hoặc người duyệt của kho đều được huỷ nháp.
        if (!$this->auth->can($user, (int)$peek['warehouse_id'], Role::Approver)) {
            $this->auth->require($user, (int)$peek['warehouse_id'], Role::Keeper);
        }
        $this->db->transaction(function () use ($user, $id) {
            $v = $this->repo->lock($id);
            if ($v['status'] !== 'DRAFT') {
                throw new DomainError('Chỉ phiếu nháp mới được huỷ');
            }
            $this->db->exec("UPDATE chung_tu SET status = 'CANCELLED' WHERE id = ?", [$id]);
            $this->audit->record($user->id, 'voucher.cancel', 'voucher', $id, $v['code']);
        });
    }

    /**
     * Sửa chứng từ đã ghi sổ bằng chứng từ điều chỉnh đảo (không xoá/sửa lịch sử). Mỗi chứng từ chỉ đảo được một lần.
     * @return array{voucher:array} chứng từ điều chỉnh vừa lập (hoặc phía nơi đi nếu là điều chuyển)
     */
    public function correct(User $user, int $id, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainError('Cần ghi rõ lý do điều chỉnh');
        }
        $peek = $this->repo->find($id);
        if (in_array($peek['type'], [VoucherType::TransferOut->value, VoucherType::TransferIn->value], true)) {
            return $this->transfers->correct($user, $id, $reason);
        }
        $this->auth->require($user, (int)$peek['warehouse_id'], Role::Approver);
        $newId = $this->db->transaction(function () use ($user, $id, $reason) {
            $v = $this->repo->lock($id);
            if ($v['status'] !== 'CONFIRMED') {
                throw new DomainError('Chỉ chứng từ đã xác nhận mới cần điều chỉnh');
            }
            if ($this->repo->correctionOf($id)) {
                throw new DomainError('Chứng từ này đã được điều chỉnh');
            }
            $type = VoucherType::from($v['type']);
            $lines = array_map(fn($l) => [
                'item_id' => (int)$l['item_id'], 'batch_id' => (int)$l['batch_id'],
                'qty' => StockRules::reversalQty($type, (int)$l['qty']), 'note' => 'Đảo ' . $v['code'],
            ], $this->repo->lines($id));
            $newId = $this->repo->insert([
                'type' => VoucherType::Adjustment->value, 'status' => 'CONFIRMED', 'warehouse_id' => (int)$v['warehouse_id'],
                'correction_of' => $id, 'reason' => $reason, 'created_by' => $user->id,
            ], $lines);
            $adj = $this->repo->lock($newId);
            $ops = $this->repo->ops($adj, $this->repo->lines($newId));
            $this->ledger->lock($this->repo->lockKeys($ops));
            $this->ledger->post($ops);
            $this->audit->record($user->id, 'voucher.correct', 'voucher', $id, "{$v['code']} → $newId: $reason");
            return $newId;
        });
        return ['voucher' => $this->repo->find($newId)];
    }

    private function requireActiveWarehouse(int $id): void
    {
        if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND active', [$id])) {
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
            $ok = $this->db->value('SELECT 1 FROM mat_hang i JOIN lo_hang b ON b.item_id = i.id WHERE i.id = ? AND b.id = ? AND i.active',
                [$l['item_id'], $l['batch_id']]);
            if (!$ok) {
                throw new DomainError('Mặt hàng/lô không hợp lệ hoặc đã ngừng hoạt động');
            }
            if ((int)$l['qty'] < 1) {
                throw new DomainError('Số lượng phải là số nguyên dương');
            }
        }
    }
}
