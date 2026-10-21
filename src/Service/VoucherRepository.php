<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\VoucherType;

/** Truy cập dữ liệu chứng từ dùng chung cho các service. */
final class VoucherRepository
{
    private const FIELDS = 'v.*, w.code AS wh_code, w.name AS wh_name, cw.code AS cp_code, cw.name AS cp_name,
                            cu.full_name AS creator_name, au.full_name AS approver_name';
    private const FROM = 'FROM vouchers v JOIN warehouses w ON w.id = v.warehouse_id
                            LEFT JOIN warehouses cw ON cw.id = v.counterpart_wh_id
                            JOIN users cu ON cu.id = v.created_by LEFT JOIN users au ON au.id = v.confirmed_by';
    private const SELECT = 'SELECT ' . self::FIELDS . ' ' . self::FROM;

    public function __construct(private Db $db) {}

    public function find(int $id): array
    {
        return $this->db->one(self::SELECT . ' WHERE v.id = ?', [$id]) ?? throw new NotFound('Không tìm thấy chứng từ');
    }

    public function findByKey(string $key): ?array
    {
        return $this->db->one(self::SELECT . ' WHERE v.idempotency_key = ?', [$key]);
    }

    /** Khoá dòng chứng từ (FOR UPDATE) để chuỗi hoá các thao tác xác nhận/huỷ/điều chỉnh. */
    public function lock(int $id): array
    {
        return $this->db->one('SELECT * FROM vouchers WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound('Không tìm thấy chứng từ');
    }

    public function lines(int $voucherId): array
    {
        return $this->db->all(
            'SELECT l.*, i.sku, i.name, i.track_batch, u.code AS unit, b.batch_no, b.expiry_date
             FROM voucher_lines l JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id JOIN batches b ON b.id = l.batch_id
             WHERE l.voucher_id = ? ORDER BY l.id',
            [$voucherId]
        );
    }

    public function correctionOf(int $id): ?array
    {
        return $this->db->one('SELECT id, code FROM vouchers WHERE correction_of = ?', [$id]);
    }

    public function pairOf(array $voucher): ?array
    {
        if ($voucher['type'] === VoucherType::TransferIn->value && $voucher['ref_voucher_id']) {
            return $this->db->one('SELECT id, code FROM vouchers WHERE id = ?', [$voucher['ref_voucher_id']]);
        }
        if ($voucher['type'] === VoucherType::TransferOut->value) {
            return $this->db->one('SELECT id, code FROM vouchers WHERE ref_voucher_id = ? AND type = ?', [$voucher['id'], VoucherType::TransferIn->value]);
        }
        return null;
    }

    /** Tạo dòng chứng từ + trả về danh sách dòng kèm id (để ghi sổ). */
    public function insert(array $v, array $lines): int
    {
        $seq = $this->db->nextSequence('voucher');
        $type = VoucherType::from($v['type']);
        $code = sprintf('%s-%s-%06d', $type->prefix(), gmdate('ymd'), $seq);
        $confirmed = ($v['status'] ?? 'DRAFT') === 'CONFIRMED';
        $id = $this->db->insert(
            'INSERT INTO vouchers (code, type, status, warehouse_id, counterpart_wh_id, ref_voucher_id, correction_of, idempotency_key,
                                   partner, reason, note, created_by, confirmed_by, confirmed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,' . ($confirmed ? 'CURRENT_TIMESTAMP' : 'NULL') . ')',
            [$code, $v['type'], $v['status'] ?? 'DRAFT', $v['warehouse_id'], $v['counterpart_wh_id'] ?? null, $v['ref_voucher_id'] ?? null,
             $v['correction_of'] ?? null, $v['idempotency_key'] ?? null, mb_substr($v['partner'] ?? '', 0, 120),
             mb_substr($v['reason'] ?? '', 0, 255), mb_substr($v['note'] ?? '', 0, 255), $v['created_by'], $confirmed ? $v['created_by'] : null]
        );
        foreach ($lines as $l) {
            $this->db->exec('INSERT INTO voucher_lines (voucher_id, item_id, batch_id, qty, note) VALUES (?,?,?,?,?)',
                [$id, $l['item_id'], $l['batch_id'], $l['qty'], mb_substr($l['note'] ?? '', 0, 255)]);
        }
        return $id;
    }

    /** @return list<array{voucher_id:int,line_id:int,warehouse_id:int,item_id:int,batch_id:int,delta:int}> */
    public function ops(array $voucher, array $lines): array
    {
        $type = VoucherType::from($voucher['type']);
        return array_map(fn(array $l) => [
            'voucher_id' => (int)$voucher['id'], 'line_id' => (int)$l['id'], 'warehouse_id' => (int)$voucher['warehouse_id'],
            'item_id' => (int)$l['item_id'], 'batch_id' => (int)$l['batch_id'],
            'delta' => \StockFlow\Domain\StockRules::delta($type, (int)$l['qty']),
        ], $lines);
    }

    public function lockKeys(array $ops): array
    {
        return array_map(fn($o) => [$o['warehouse_id'], $o['item_id'], $o['batch_id']], $ops);
    }

    /** Tìm danh sách chứng từ theo bộ lọc, chỉ trong phạm vi kho cho phép. */
    public function search(array $warehouseIds, ?string $type, ?string $status, string $q, int $limit = 200): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $where = ["v.warehouse_id IN ($in)"];
        $params = $warehouseIds;
        if ($type) { $where[] = 'v.type = ?'; $params[] = $type; }
        if ($status) { $where[] = 'v.status = ?'; $params[] = $status; }
        if ($q !== '') { $where[] = '(v.code LIKE ? OR v.partner LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        return $this->db->all(
            'SELECT ' . self::FIELDS . ', (SELECT COUNT(*) FROM voucher_lines l WHERE l.voucher_id = v.id) AS line_count,
                (SELECT COALESCE(SUM(ABS(l.qty)),0) FROM voucher_lines l WHERE l.voucher_id = v.id) AS total_qty ' . self::FROM . '
             WHERE ' . implode(' AND ', $where) . ' ORDER BY v.id DESC LIMIT ' . max(1, min(500, $limit)),
            $params
        );
    }

    /** @return array<string,int> số phiếu theo trạng thái trong phạm vi kho */
    public function statusCounts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($warehouseIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT status, COUNT(*) AS n FROM vouchers WHERE warehouse_id IN ($in) GROUP BY status", $warehouseIds) as $r) {
            $out[$r['status']] = (int)$r['n'];
        }
        return $out;
    }
}
