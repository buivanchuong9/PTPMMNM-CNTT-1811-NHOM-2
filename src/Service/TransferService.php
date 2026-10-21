<?php
declare(strict_types=1);

namespace StockFlow\Service;

use mysqli_sql_exception;
use StockFlow\Core\Db;
use StockFlow\Core\Hooks;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Role;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;

/**
 * Điều chuyển giữa hai kho trong cùng CSDL.
 * Phiếu "điều chuyển đi" (DCX) tại kho nguồn được lập nháp; khi xác nhận, hệ thống TRONG MỘT GIAO DỊCH:
 *   khoá tồn cả hai kho → trừ kho đi → (điểm chèn lỗi) → tạo chứng từ "điều chuyển đến" (DCN) + cộng kho đến → chốt.
 * Lỗi giữa chừng ⇒ rollback: không mất hàng, không nhân đôi hàng, không có chứng từ mồ côi.
 */
final class TransferService
{
    public function __construct(private Db $db, private VoucherRepository $repo, private Ledger $ledger,
                                private Authorizer $auth, private AuditLog $audit) {}

    /** @param list<array{item_id:int,batch_id:int,qty:int}> $lines @return array{voucher:array,created:bool} */
    public function createDraft(User $user, int $fromWh, int $toWh, array $lines, string $note, ?string $idempotencyKey): array
    {
        $this->auth->require($user, $fromWh, Role::Keeper);
        if ($fromWh === $toWh) {
            throw new DomainError('Kho đi và kho đến phải khác nhau');
        }
        if ($idempotencyKey !== null && ($existing = $this->repo->findByKey($idempotencyKey))) {
            return ['voucher' => $existing, 'created' => false];
        }
        try {
            $id = $this->db->transaction(function () use ($user, $fromWh, $toWh, $lines, $note, $idempotencyKey) {
                foreach ([$fromWh, $toWh] as $wh) {
                    if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND active', [$wh])) {
                        throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
                    }
                }
                $this->validate($lines);
                $id = $this->repo->insert([
                    'type' => VoucherType::TransferOut->value, 'warehouse_id' => $fromWh, 'counterpart_wh_id' => $toWh,
                    'note' => trim($note), 'idempotency_key' => $idempotencyKey, 'created_by' => $user->id,
                ], $lines);
                $this->audit->record($user->id, 'transfer.create', 'voucher', $id, "$fromWh → $toWh");
                return $id;
            });
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === Db::DUPLICATE_KEY && $idempotencyKey !== null && ($existing = $this->repo->findByKey($idempotencyKey))) {
                return ['voucher' => $existing, 'created' => false];
            }
            throw $e;
        }
        return ['voucher' => $this->repo->find($id), 'created' => true];
    }

    /** @return array{voucher:array,already:bool} */
    public function confirm(User $user, int $outId): array
    {
        $peek = $this->repo->find($outId);
        if ($peek['type'] !== VoucherType::TransferOut->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['warehouse_id'], Role::Approver);
        $already = $this->db->transaction(function () use ($user, $outId) {
            $out = $this->repo->lock($outId);
            if ($out['status'] === 'CONFIRMED') {
                return true;
            }
            if ($out['status'] !== 'DRAFT') {
                throw new DomainError('Chỉ phiếu nháp mới được xác nhận');
            }
            $from = (int)$out['warehouse_id'];
            $to = (int)$out['counterpart_wh_id'];
            if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND active', [$to])) {
                throw new DomainError('Kho đến đã ngừng hoạt động');
            }
            $outLines = $this->repo->lines($outId);
            if ($outLines === []) {
                throw new DomainError('Phiếu không có dòng hàng');
            }
            $outOps = $this->repo->ops($out, $outLines);

            // 1) Khoá tồn của CẢ HAI kho theo thứ tự toàn cục (tránh deadlock với phiếu chiều ngược lại).
            $keys = $this->repo->lockKeys($outOps);
            foreach ($outLines as $l) {
                $keys[] = [$to, (int)$l['item_id'], (int)$l['batch_id']];
            }
            $this->ledger->lock($keys);

            // 2) Tạo chứng từ nơi đến (đã xác nhận) + dòng của nó.
            $inLines = array_map(fn($l) => ['item_id' => (int)$l['item_id'], 'batch_id' => (int)$l['batch_id'], 'qty' => (int)$l['qty']], $outLines);
            $inId = $this->repo->insert([
                'type' => VoucherType::TransferIn->value, 'status' => 'CONFIRMED', 'warehouse_id' => $to, 'counterpart_wh_id' => $from,
                'ref_voucher_id' => $outId, 'partner' => $out['code'], 'created_by' => $user->id,
            ], $inLines);
            $in = $this->repo->lock($inId);
            $inOps = $this->repo->ops($in, $this->repo->lines($inId));

            // 3) Trừ kho đi → (điểm chèn lỗi cho test) → cộng kho đến.
            $this->ledger->post($outOps);
            Hooks::fire('transfer.between', ['out' => $outId, 'in' => $inId]);
            $this->ledger->post($inOps);

            $this->db->exec("UPDATE chung_tu SET status = 'CONFIRMED', confirmed_by = ?, confirmed_at = CURRENT_TIMESTAMP WHERE id = ?", [$user->id, $outId]);
            Hooks::fire('transfer.before_commit', ['out' => $outId, 'in' => $inId]);
            $this->audit->record($user->id, 'transfer.confirm', 'voucher', $outId, $out['code'] . ' ⇄ ' . $in['code']);
            return false;
        });
        return ['voucher' => $this->repo->find($outId), 'already' => $already];
    }

    /**
     * Đảo toàn bộ một điều chuyển đã xác nhận: lập 2 chứng từ điều chỉnh (nơi đi +, nơi đến −) trong một giao dịch.
     * @return array{voucher:array}
     */
    public function correct(User $user, int $voucherId, string $reason): array
    {
        $peek = $this->repo->find($voucherId);
        $outId = $peek['type'] === VoucherType::TransferOut->value ? $voucherId : (int)$peek['ref_voucher_id'];
        $newId = $this->db->transaction(function () use ($user, $outId, $reason) {
            $out = $this->repo->lock($outId);
            $in = $this->db->one('SELECT * FROM chung_tu WHERE ref_voucher_id = ? AND type = ? FOR UPDATE', [$outId, VoucherType::TransferIn->value]);
            if ($out['status'] !== 'CONFIRMED' || !$in) {
                throw new DomainError('Chỉ điều chuyển đã xác nhận mới cần điều chỉnh');
            }
            // Người điều chỉnh phải có quyền duyệt ở CẢ HAI kho, nếu không có thể dùng đường này để rút hàng khỏi kho khác.
            $this->auth->require($user, (int)$out['warehouse_id'], Role::Approver);
            $this->auth->require($user, (int)$in['warehouse_id'], Role::Approver);
            if ($this->repo->correctionOf($outId) || $this->repo->correctionOf((int)$in['id'])) {
                throw new DomainError('Điều chuyển này đã được điều chỉnh');
            }
            $mk = fn(array $orig, VoucherType $t, ?int $ref) => $this->repo->insert([
                'type' => VoucherType::Adjustment->value, 'status' => 'CONFIRMED', 'warehouse_id' => (int)$orig['warehouse_id'],
                'correction_of' => (int)$orig['id'], 'ref_voucher_id' => $ref, 'reason' => $reason, 'created_by' => $user->id,
            ], array_map(fn($l) => [
                'item_id' => (int)$l['item_id'], 'batch_id' => (int)$l['batch_id'],
                'qty' => StockRules::reversalQty($t, (int)$l['qty']), 'note' => 'Đảo ' . $orig['code'],
            ], $this->repo->lines((int)$orig['id'])));
            $adjOutId = $mk($out, VoucherType::TransferOut, null);
            $adjInId = $mk($in, VoucherType::TransferIn, $adjOutId);
            $adjOut = $this->repo->lock($adjOutId);
            $adjIn = $this->repo->lock($adjInId);
            $opsOut = $this->repo->ops($adjOut, $this->repo->lines($adjOutId));
            $opsIn = $this->repo->ops($adjIn, $this->repo->lines($adjInId));
            $this->ledger->lock(array_merge($this->repo->lockKeys($opsOut), $this->repo->lockKeys($opsIn)));
            $this->ledger->post($opsIn);   // kho đến trừ trước: nếu hàng đã dùng hết thì dừng sớm
            $this->ledger->post($opsOut);
            $this->audit->record($user->id, 'transfer.correct', 'voucher', $outId, "{$out['code']}: $reason");
            return $adjOutId;
        });
        return ['voucher' => $this->repo->find($newId)];
    }

    private function validate(array $lines): void
    {
        if ($lines === []) {
            throw new DomainError('Phiếu cần ít nhất một dòng hàng');
        }
        foreach ($lines as $l) {
            if (!$this->db->value('SELECT 1 FROM mat_hang i JOIN lo_hang b ON b.item_id = i.id WHERE i.id = ? AND b.id = ? AND i.active', [$l['item_id'], $l['batch_id']])) {
                throw new DomainError('Mặt hàng/lô không hợp lệ hoặc đã ngừng hoạt động');
            }
            if ((int)$l['qty'] < 1) {
                throw new DomainError('Số lượng phải là số nguyên dương');
            }
        }
    }
}
