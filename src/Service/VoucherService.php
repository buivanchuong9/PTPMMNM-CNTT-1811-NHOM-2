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
                                private Authorizer $auth, private AuditLog $audit, private TransferService $transfers, private StockQuery $query, private PricingService $pricing,
                                private SupplierReturnService $returns, private DeliveryService $deliveries, private SaleCodeService $saleCodes, private OrderService $orders) {}

    /**
     * Lập phiếu nhập/xuất ở trạng thái nháp (chưa ảnh hưởng tồn).
     * Gửi lại cùng $idempotencyKey ⇒ trả về phiếu đã có, không tạo phiếu thứ hai.
     * Phiếu nhập: $lines mang số lượng NHẬN; $quality ("hàng:lô" ⇒ [loi, ly_do, ghi_chu]) là phần KHÔNG ĐẠT trả lại nhà cung cấp.
     * Dòng phiếu (và tồn kho) chỉ nhận phần đạt = nhận − không đạt. $replaceId: phiếu nhập này là hàng nhà cung cấp cấp lại cho một phiếu trả hàng.
     * @param list<array{item_id:int,batch_id:int,qty:int,note?:string}> $lines
     * @param array<string,array{loi:int,ly_do:string,ghi_chu:string}> $quality
     * @return array{voucher:array,created:bool}
     */
    public function createDraft(User $user, VoucherType $type, int $warehouseId, array $lines, string $partner, string $note, ?string $idempotencyKey, array $quality = [], ?int $replaceId = null, ?int $orderId = null): array
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
            $id = $this->db->transaction(function () use ($user, $type, $warehouseId, $lines, $partner, $note, $idempotencyKey, $quality, $replaceId, $orderId) {
                $this->requireActiveWarehouse($warehouseId);
                $this->validateLines($lines);
                $received = [];
                if ($type === VoucherType::Receipt) {
                    [$lines, $received] = $this->applyQuality($lines, $quality);
                    if ($replaceId !== null) {
                        $this->returns->assertReplacement($replaceId, $warehouseId, $partner, $lines);
                    }
                    if ($orderId !== null) {   // nhập hàng theo đơn đặt hàng nhà cung cấp
                        $this->orders->assertReceipt($orderId, $warehouseId, $partner, $lines);
                    }
                } elseif ($replaceId !== null || $quality || $orderId !== null) {
                    throw new DomainError('Chỉ phiếu nhập mới có kiểm tra chất lượng');
                }
                $lines = $this->priceLines($type, $warehouseId, $lines);
                $id = $this->repo->insert([
                    'loai' => $type->value, 'kho_id' => $warehouseId, 'doi_tac' => trim($partner), 'ghi_chu' => trim($note),
                    'khoa_chong_trung' => $idempotencyKey, 'nguoi_lap_id' => $user->id, 'cap_lai_cho_id' => $replaceId,
                ], $lines);
                if ($orderId !== null) {
                    $this->db->exec('UPDATE phieu_nhap_xuat SET don_dat_hang_id = ? WHERE id = ?', [$orderId, $id]);
                }
                foreach ($received as $q) {
                    $this->db->exec('INSERT INTO nhap_loi (phieu_nhap_xuat_id, mat_hang_id, lo_hang_id, so_luong_nhan, so_luong_loi, ly_do, ghi_chu) VALUES (?,?,?,?,?,?,?)',
                        [$id, $q['mat_hang_id'], $q['lo_hang_id'], $q['so_luong'], $q['loi'], $q['ly_do'], $q['ghi_chu']]);
                }
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
     * Phiếu xuất: $delivery = cách giao hàng cho khách (khách tự lấy / xe của kho / thuê ngoài), xem DeliveryService::record.
     * @return array{voucher:array,already:bool}
     */
    public function confirm(User $user, int $id, ?array $delivery = null): array
    {
        $peek = $this->repo->find($id);
        if ($peek['loai'] === VoucherType::TransferOut->value) {
            return $this->transfers->confirm($user, $id);
        }
        if ($peek['loai'] === VoucherType::TransferIn->value) {
            return $this->transfers->receive($user, $id);
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        $already = $this->db->transaction(function () use ($user, $id, $delivery) {
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
            if ($v['loai'] === VoucherType::Issue->value && ($errors = $this->query->fefoErrors((int)$v['kho_id'], $lines))) {
                throw new DomainError('Xuất theo hạn dùng (hết hạn trước, xuất trước) — ' . implode('; ', $errors));
            }
            $ops = $this->repo->ops($v, $lines);
            $this->ledger->lock($this->repo->lockKeys($ops));
            $this->ledger->post($ops);
            $this->db->exec("UPDATE phieu_nhap_xuat SET trang_thai = 'CONFIRMED', nguoi_xac_nhan_id = ?, ngay_xac_nhan = CURRENT_TIMESTAMP WHERE id = ?", [$user->id, $id]);
            $this->audit->record($user->id, 'voucher.confirm', 'voucher', $id, $v['ma']);
            if ($v['loai'] === VoucherType::Issue->value && $delivery !== null) {
                $this->deliveries->record($user, $v, $delivery);
            }
            if ($v['loai'] === VoucherType::Issue->value) {   // hàng có bảo hành: sinh mã lô hàng + mã món hàng để tra bảo hành / hoá đơn
                $this->saleCodes->issueFor($v, $lines);
            }
            if ($v['loai'] === VoucherType::Receipt->value) {   // hàng không đạt ⇒ phiếu trả nhà cung cấp; hàng cấp lại ⇒ trừ vào số còn thiếu
                $this->returns->createFromReceipt($user, $v);
                $this->returns->fulfil($v, $lines);
                $this->orders->fulfil($v, $lines);   // phiếu nhập theo đơn đặt hàng: cộng số đã nhận
            }
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
            $this->auth->require($user, (int)$peek['kho_id'], match ($peek['loai']) {
                VoucherType::Receipt->value => Permission::LapPhieuNhap, VoucherType::TransferOut->value => Permission::LapPhieuDieuChuyen, default => Permission::LapPhieuXuat,
            });
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
            $this->saleCodes->voidFor($id);   // phiếu bị đảo ⇒ mã bán hàng của phiếu không còn hiệu lực
            $this->audit->record($user->id, 'voucher.correct', 'voucher', $id, "{$v['ma']} → $newId: $reason");
            return $newId;
        });
        return ['voucher' => $this->repo->find($newId)];
    }

    /**
     * Tách số nhận thành phần đạt (vào dòng phiếu) và phần không đạt (trả lại nhà cung cấp).
     * @param list<array{mat_hang_id:int,lo_hang_id:int,so_luong:int}> $lines
     * @return array{0:list<array>,1:list<array>} [dòng đạt, dòng nhận kèm số không đạt]
     */
    private function applyQuality(array $lines, array $quality): array
    {
        $accepted = $received = [];
        foreach ($lines as $l) {
            $q = $quality[$l['mat_hang_id'] . ':' . $l['lo_hang_id']] ?? ['loi' => 0, 'ly_do' => '', 'ghi_chu' => ''];
            $bad = (int)$q['loi'];
            $name = (string)$this->db->value('SELECT ma_hang FROM mat_hang WHERE id = ?', [$l['mat_hang_id']]);
            if ($bad < 0 || $bad > (int)$l['so_luong']) {
                throw new DomainError("Mặt hàng $name: số không đạt chất lượng ($bad) phải từ 0 đến số lượng nhận ({$l['so_luong']})");
            }
            $note = mb_substr(trim((string)$q['ghi_chu']), 0, 255);
            if ($bad > 0 && !isset(SupplierReturnService::REASONS[$q['ly_do']])) {
                throw new DomainError("Mặt hàng $name: chọn tình trạng của hàng không đạt chất lượng");
            }
            if ($bad > 0 && $q['ly_do'] === 'KHAC' && $note === '') {
                throw new DomainError("Mặt hàng $name: tình trạng «Khác» cần ghi chú rõ");
            }
            $received[] = $l + ['loi' => $bad, 'ly_do' => $bad > 0 ? $q['ly_do'] : '', 'ghi_chu' => $bad > 0 ? $note : ''];
            if ((int)$l['so_luong'] - $bad > 0) {
                $accepted[] = ['so_luong' => (int)$l['so_luong'] - $bad] + $l;
            }
        }
        if ($accepted === []) {
            throw new DomainError('Toàn bộ hàng nhận đều không đạt chất lượng — không lập phiếu nhập, hãy từ chối nhận lô hàng này với nhà cung cấp');
        }
        return [$accepted, $received];
    }

    private function requireActiveWarehouse(int $id): void
    {
        if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$id])) {
            throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
        }
    }

    /** Mặt hàng/lô phải tồn tại, đang hoạt động và khớp nhau. */
    /**
     * Giá tại lúc lập phiếu (lưu lại trên dòng vì phiếu đã xác nhận bất biến):
     *  - phiếu xuất: đơn giá = giá bán hiện hành CỦA KHO LẬP PHIẾU (quản lý kho đặt), kèm thuế suất và giá gốc;
     *  - phiếu nhập: đơn giá = giá gốc quản trị đặt cho mặt hàng (nhân viên kho không nhập giá), kèm thuế suất.
     * @return list<array<string,mixed>>
     */
    private function priceLines(VoucherType $type, int $warehouseId, array $lines): array
    {
        foreach ($lines as &$l) {
            $item = $this->db->one('SELECT thue_suat, gia_von FROM mat_hang WHERE id = ?', [$l['mat_hang_id']]);
            $l['thue_suat'] = (int)$item['thue_suat'];
            $l['gia_von'] = (int)$item['gia_von'];
            $l['don_gia'] = $type === VoucherType::Issue ? $this->pricing->sellPrice($warehouseId, (int)$l['mat_hang_id']) : (int)$item['gia_von'];
        }
        return $lines;
    }

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
