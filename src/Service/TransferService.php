<?php
declare(strict_types=1);

namespace StockFlow\Service;

use mysqli_sql_exception;
use StockFlow\Core\Db;
use StockFlow\Core\Hooks;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Permission;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;

/**
 * Điều chuyển giữa hai kho trong cùng CSDL.
 * Hai bước, hàng có trạng thái rõ ràng:
 *   1) Kho đi xác nhận phiếu «điều chuyển đi» (DCX): trừ tồn kho đi ⇒ hàng ĐANG VẬN CHUYỂN, mở phiếu «điều chuyển đến» (DCN) chờ nhận.
 *   2) Kho đến xác nhận nhận hàng trên phiếu DCN: cộng tồn kho đến ⇒ ĐÃ NHẬN.
 * Mỗi bước là một giao dịch: lỗi giữa chừng ⇒ rollback, không mất hàng, không nhân đôi hàng.
 */
final class TransferService
{
    public function __construct(private Db $db, private VoucherRepository $repo, private Ledger $ledger,
                                private Authorizer $auth, private AuditLog $audit, private StockQuery $query) {}

    /** @param list<array{item_id:int,batch_id:int,qty:int}> $lines @return array{voucher:array,created:bool} */
    public function createDraft(User $user, int $fromWh, int $toWh, array $lines, string $note, ?string $idempotencyKey): array
    {
        $this->auth->require($user, $fromWh, Permission::LapPhieuDieuChuyen);
        if ($fromWh === $toWh) {
            throw new DomainError('Kho đi và kho đến phải khác nhau');
        }
        if (mb_strlen(trim($note)) > 255) {
            throw new DomainError('Ghi chú tối đa 255 ký tự');
        }
        if ($idempotencyKey !== null && ($existing = $this->repo->findByKey($idempotencyKey))) {
            return ['voucher' => $existing, 'created' => false];
        }
        try {
            $id = $this->db->transaction(function () use ($user, $fromWh, $toWh, $lines, $note, $idempotencyKey) {
                foreach ([$fromWh, $toWh] as $wh) {
                    if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$wh])) {
                        throw new DomainError('Kho không tồn tại hoặc đã ngừng hoạt động');
                    }
                }
                $this->validate($lines);
                $id = $this->repo->insert([
                    'loai' => VoucherType::TransferOut->value, 'kho_id' => $fromWh, 'kho_doi_ung_id' => $toWh,
                    'ghi_chu' => trim($note), 'khoa_chong_trung' => $idempotencyKey, 'nguoi_lap_id' => $user->id,
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

    /**
     * Bước 1 — XUẤT KHO ĐI: trừ tồn kho đi, phiếu đi thành «đã xác nhận», đồng thời mở phiếu nhập «điều chuyển đến» ở trạng thái chờ nhận.
     * Hàng lúc này ĐANG VẬN CHUYỂN: đã rời kho đi, chưa vào tồn kho đến. Trong một giao dịch: lỗi giữa chừng ⇒ rollback.
     * @return array{voucher:array,already:bool}
     */
    public function confirm(User $user, int $outId): array
    {
        $peek = $this->repo->find($outId);
        if ($peek['loai'] !== VoucherType::TransferOut->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        $already = $this->db->transaction(function () use ($user, $outId) {
            $out = $this->repo->lock($outId);
            if ($out['trang_thai'] === 'CONFIRMED') {
                return true;
            }
            if ($out['trang_thai'] !== 'DRAFT') {
                throw new DomainError('Chỉ phiếu nháp mới được xác nhận');
            }
            $from = (int)$out['kho_id'];
            $to = (int)$out['kho_doi_ung_id'];
            if (!$this->db->value('SELECT 1 FROM kho WHERE id = ? AND hoat_dong', [$to])) {
                throw new DomainError('Kho đến đã ngừng hoạt động');
            }
            $outLines = $this->repo->lines($outId);
            if ($outLines === []) {
                throw new DomainError('Phiếu không có dòng hàng');
            }
            if ($errors = $this->query->fefoErrors($from, $outLines)) {
                throw new DomainError('Xuất theo hạn dùng (hết hạn trước, xuất trước) — ' . implode('; ', $errors));
            }
            $outOps = $this->repo->ops($out, $outLines);
            $this->ledger->lock($this->repo->lockKeys($outOps));

            // Trừ kho đi → (điểm chèn lỗi cho test) → mở phiếu nhận ở kho đến (chưa cộng tồn).
            $this->ledger->post($outOps);
            Hooks::fire('transfer.between', ['out' => $outId]);
            $inLines = array_map(fn($l) => ['mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'], 'so_luong' => (int)$l['so_luong']], $outLines);
            $inId = $this->repo->insert([
                'loai' => VoucherType::TransferIn->value, 'kho_id' => $to, 'kho_doi_ung_id' => $from,
                'phieu_nhap_xuat_lien_quan_id' => $outId, 'doi_tac' => $out['ma'], 'nguoi_lap_id' => $user->id,
            ], $inLines);

            $this->db->exec("UPDATE phieu_nhap_xuat SET trang_thai = 'CONFIRMED', nguoi_xac_nhan_id = ?, ngay_xac_nhan = CURRENT_TIMESTAMP WHERE id = ?", [$user->id, $outId]);
            Hooks::fire('transfer.before_commit', ['out' => $outId, 'in' => $inId]);
            $this->audit->record($user->id, 'transfer.dispatch', 'voucher', $outId, $out['ma'] . ' → đang vận chuyển');
            return false;
        });
        return ['voucher' => $this->repo->find($outId), 'already' => $already];
    }

    /**
     * Bước 2 — NHẬN HÀNG TẠI KHO ĐẾN: người có quyền xác nhận của kho đến cộng hàng vào tồn.
     * @return array{voucher:array,already:bool}
     */
    public function receive(User $user, int $inId): array
    {
        $peek = $this->repo->find($inId);
        if ($peek['loai'] !== VoucherType::TransferIn->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đến');
        }
        // Người nhận phải là tài khoản (quản lý hoặc nhân viên) của CHÍNH kho đến.
        $this->auth->require($user, (int)$peek['kho_id'], Permission::NhanHangDieuChuyen);
        $already = $this->db->transaction(function () use ($user, $inId) {
            $in = $this->repo->lock($inId);
            if ($in['trang_thai'] === 'CONFIRMED') {
                return true;
            }
            if ($in['trang_thai'] !== 'DRAFT') {
                throw new DomainError('Phiếu này không còn ở trạng thái chờ nhận hàng');
            }
            $ops = $this->repo->ops($in, $this->repo->lines($inId));
            $this->ledger->lock($this->repo->lockKeys($ops));
            $this->ledger->post($ops);
            $this->db->exec("UPDATE phieu_nhap_xuat SET trang_thai = 'CONFIRMED', nguoi_xac_nhan_id = ?, ngay_xac_nhan = CURRENT_TIMESTAMP WHERE id = ?", [$user->id, $inId]);
            $this->audit->record($user->id, 'transfer.receive', 'voucher', $inId, $in['ma'] . ' đã nhận hàng');
            return false;
        });
        return ['voucher' => $this->repo->find($inId), 'already' => $already];
    }

    /**
     * XUẤT KHO ĐI kèm vận chuyển: bắt buộc chọn xe (đang đứng ở kho đi, đã có tài xế phụ trách) — tài xế đi theo xe — và tài xế đã xác nhận nhận đủ hàng.
     * Một giao dịch: trừ tồn kho đi, mở phiếu đến, lập chuyến, xe chuyển sang «đang chạy tới kho đến».
     * @return array{voucher:array,already:bool}
     */
    public function dispatch(User $user, int $outId, int $vehicleId, bool $driverConfirmed): array
    {
        $peek = $this->repo->find($outId);
        if ($peek['loai'] !== VoucherType::TransferOut->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        return $this->db->transaction(function () use ($user, $outId, $vehicleId, $driverConfirmed) {
            $out = $this->repo->lock($outId);
            $xe = $tx = null;
            if ($out['trang_thai'] === 'DRAFT') {
                if (!$vehicleId) {
                    throw new DomainError('Chọn xe chở hàng trước khi xuất kho');
                }
                if (!$driverConfirmed) {
                    throw new DomainError('Tài xế phải xác nhận đã nhận đủ hàng trước khi xuất kho');
                }
                $xe = $this->db->one('SELECT * FROM xe WHERE id = ? FOR UPDATE', [$vehicleId]);
                if (!$xe || !$xe['hoat_dong']) {
                    throw new DomainError('Xe không tồn tại hoặc đã ngừng hoạt động');
                }
                if ((int)($xe['kho_hien_tai_id'] ?? 0) !== (int)$out['kho_id']) {
                    throw new DomainError('Xe ' . $xe['bien_so'] . ' hiện không ở kho đi — chọn xe đang đứng tại kho này');
                }
                $tx = $xe['tai_xe_id'] ? $this->db->one('SELECT * FROM tai_xe WHERE id = ? FOR UPDATE', [(int)$xe['tai_xe_id']]) : null;
                if (!$tx) {
                    throw new DomainError('Xe ' . $xe['bien_so'] . ' chưa có tài xế phụ trách — liên hệ quản trị hệ thống');
                }
                if (!$tx['hoat_dong']) {
                    throw new DomainError('Tài xế ' . $tx['ho_ten'] . ' của xe ' . $xe['bien_so'] . ' đã ngừng dùng');
                }
            }
            $res = $this->confirm($user, $outId);
            if (!$res['already'] && $xe && $tx) {
                $in = $this->repo->pairOf($res['voucher']) ?? throw new DomainError('Không tìm thấy phiếu đến đối ứng');
                $this->db->insert(
                    'INSERT INTO chuyen_van_chuyen (phieu_di_id, phieu_den_id, xe_id, tai_xe_id, kho_di_id, kho_den_id, tai_xe_xac_nhan_du_hang, nguoi_giao_id) VALUES (?,?,?,?,?,?,?,?)',
                    [$outId, (int)$in['id'], $vehicleId, (int)$tx['id'], (int)$out['kho_id'], (int)$out['kho_doi_ung_id'], 1, $user->id]
                );
                $this->db->exec('UPDATE xe SET kho_hien_tai_id = NULL, dang_den_kho_id = ? WHERE id = ?', [(int)$out['kho_doi_ung_id'], $vehicleId]);
                $this->audit->record($user->id, 'transfer.vehicle', 'voucher', $outId, "{$xe['bien_so']} · {$tx['ho_ten']} · tài xế xác nhận đủ hàng");
            }
            return $res;
        });
    }

    /** Lý do kho đến báo «chưa nhận được hàng». */
    public const MISSING_REASONS = ['Tài xế chưa đến kho', 'Không liên lạc được với tài xế', 'Xe đến nhưng không có hàng', 'Sai tài xế hoặc sai xe', 'Khác'];

    /**
     * NHẬN HÀNG theo SỐ LƯỢNG THỰC TẾ: kho đến nhập số thực nhận của từng dòng (0 … số trên phiếu) và xác nhận «đúng tài xế, đúng xe».
     * $received = [id dòng phiếu đến ⇒ số thực nhận]; null ⇒ nhận đủ như trên phiếu.
     * Phiếu đến ghi sổ đủ số trên phiếu; dòng nhận thiếu được ghi tiếp bằng một PHIẾU ĐIỀU CHỈNH (−phần thiếu) tại kho đến trong cùng giao dịch,
     * nên tồn kho đến chỉ tăng đúng số thực nhận, lịch sử không bị sửa và «Đối chiếu tồn» vẫn khớp. Phần thiếu được ghi vào chuyến để quản trị theo dõi.
     * Phiếu không có chuyến (dữ liệu cũ) nhận như trước. Gọi lại khi đã nhận rồi thì không ghi thêm.
     * @param ?array<int,int> $received
     * @return array{voucher:array,already:bool,short:list<array{sku:string,expected:int,got:int}>,adjustment:?array}
     */
    public function receiveChecked(User $user, int $inId, ?array $received, bool $rightDriver): array
    {
        $peek = $this->repo->find($inId);
        if ($peek['loai'] !== VoucherType::TransferIn->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đến');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::NhanHangDieuChuyen);
        return $this->db->transaction(function () use ($user, $inId, $received, $rightDriver, $peek) {
            $in = $this->repo->lock($inId);
            if ($in['trang_thai'] === 'CONFIRMED') {
                return ['voucher' => $in, 'already' => true, 'short' => [], 'adjustment' => null];
            }
            $trip = $this->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_den_id = ? FOR UPDATE', [$inId]);
            $open = $trip && $trip['trang_thai'] === 'IN_TRANSIT';
            if ($open && !$rightDriver) {
                throw new DomainError('Chỉ nhận hàng khi đúng tài xế và đúng xe. Nếu không, hãy bấm «Chưa nhận được hàng».');
            }
            $short = [];
            $adjLines = [];
            $gotTotal = 0;
            foreach ($this->repo->lines($inId) as $l) {
                $expected = (int)$l['so_luong'];
                $got = $received === null ? $expected : ($received[(int)$l['id']] ?? null);
                if ($got === null || $got < 0 || $got > $expected) {
                    throw new DomainError('Nhập số thực nhận của ' . $l['ma_hang'] . ' (từ 0 đến ' . $expected . ')');
                }
                $gotTotal += $got;
                if ($got < $expected) {
                    $short[] = ['sku' => $l['ma_hang'], 'expected' => $expected, 'got' => $got];
                    $adjLines[] = ['mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'], 'so_luong' => $got - $expected, 'ghi_chu' => 'Nhận thiếu ' . ($expected - $got) . '/' . $expected];
                }
            }
            if ($gotTotal === 0) {
                throw new DomainError('Chưa nhận được hàng nào — hãy bấm «Chưa nhận được hàng» và chọn lý do.');
            }
            $res = $this->receive($user, $inId);   // ghi sổ đủ số trên phiếu (+)
            $adj = null;
            if ($adjLines !== []) {                 // rồi trừ phần thiếu bằng phiếu điều chỉnh tại kho đến
                $desc = implode('; ', array_map(fn($s) => $s['sku'] . ' ' . $s['got'] . '/' . $s['expected'], $short));
                $adjId = $this->repo->insert([
                    'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$in['kho_id'],
                    'phieu_nhap_xuat_lien_quan_id' => $inId, 'ly_do' => 'Nhận thiếu hàng điều chuyển ' . $in['ma'] . ': ' . $desc, 'nguoi_lap_id' => $user->id,
                ], $adjLines);
                $adjRow = $this->repo->lock($adjId);
                $ops = $this->repo->ops($adjRow, $this->repo->lines($adjId));
                $this->ledger->lock($this->repo->lockKeys($ops));
                $this->ledger->post($ops);
                $adj = $this->repo->find($adjId);
                $this->audit->record($user->id, 'transfer.short', 'voucher', $inId, $in['ma'] . ' · nhận thiếu: ' . $desc);
            }
            if ($open) {
                $this->db->exec("UPDATE chuyen_van_chuyen SET trang_thai = 'DELIVERED', nhan_du_hang = ?, nhan_dung_tai_xe = 1, sai_lech = ?, nguoi_nhan_id = ?, ngay_nhan = CURRENT_TIMESTAMP WHERE id = ?",
                    [$short ? 0 : 1, $short ? mb_substr('Nhận thiếu: ' . implode('; ', array_map(fn($s) => $s['sku'] . ' ' . $s['got'] . '/' . $s['expected'], $short)), 0, 255) : '', $user->id, (int)$trip['id']]);
                $this->db->exec('UPDATE xe SET kho_hien_tai_id = ?, dang_den_kho_id = NULL WHERE id = ?', [(int)$trip['kho_den_id'], (int)$trip['xe_id']]);
            }
            return $res + ['short' => $short, 'adjustment' => $adj];
        });
    }

    /**
     * Kho đến báo CHƯA NHẬN ĐƯỢC HÀNG (tài xế chưa tới, không liên lạc được, xe không có hàng, sai tài xế/xe…): chọn lý do, KHÔNG cộng tồn,
     * hàng vẫn «đang vận chuyển» và chuyến hiện ở mục riêng của trang Đội xe để tài khoản cấp cao theo dõi. Hàng về sau vẫn nhận được bình thường.
     */
    public function reportNotReceived(User $user, int $inId, string $reason, string $note = ''): void
    {
        $peek = $this->repo->find($inId);
        if ($peek['loai'] !== VoucherType::TransferIn->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đến');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::NhanHangDieuChuyen);
        $reason = trim($reason);
        $note = trim($note);
        if (!in_array($reason, self::MISSING_REASONS, true)) {
            throw new DomainError('Chọn lý do chưa nhận được hàng');
        }
        if ($reason === 'Khác' && $note === '') {
            throw new DomainError('Ghi rõ lý do khác');
        }
        if (mb_strlen($note) > 120) {
            throw new DomainError('Ghi chú tối đa 120 ký tự');
        }
        $this->db->transaction(function () use ($user, $inId, $reason, $note) {
            $in = $this->repo->lock($inId);
            if ($in['trang_thai'] !== 'DRAFT') {
                throw new DomainError('Phiếu này không còn ở trạng thái chờ nhận hàng');
            }
            $trip = $this->db->one("SELECT * FROM chuyen_van_chuyen WHERE phieu_den_id = ? AND trang_thai = 'IN_TRANSIT' FOR UPDATE", [$inId])
                ?? throw new DomainError('Phiếu này không có chuyến xe đang vận chuyển để báo');
            $text = 'Chưa nhận được hàng: ' . $reason . ($note !== '' ? ' — ' . $note : '');
            $this->db->exec('UPDATE chuyen_van_chuyen SET nhan_du_hang = 0, nhan_dung_tai_xe = ?, sai_lech = ?, nguoi_nhan_id = ? WHERE id = ?',
                [$reason === 'Sai tài xế hoặc sai xe' ? 0 : null, mb_substr($text, 0, 255), $user->id, (int)$trip['id']]);
            $this->audit->record($user->id, 'transfer.missing', 'voucher', $inId, $in['ma'] . ' · ' . $text);
        });
    }

    /**
     * Đảo toàn bộ một điều chuyển đã xác nhận: lập 2 phiếu điều chỉnh (nơi đi +, nơi đến −) trong một giao dịch.
     * @return array{voucher:array}
     */
    public function correct(User $user, int $voucherId, string $reason): array
    {
        $peek = $this->repo->find($voucherId);
        $outId = $peek['loai'] === VoucherType::TransferOut->value ? $voucherId : (int)$peek['phieu_nhap_xuat_lien_quan_id'];
        $newId = $this->db->transaction(function () use ($user, $outId, $reason) {
            $out = $this->repo->lock($outId);
            $in = $this->db->one('SELECT * FROM phieu_nhap_xuat WHERE phieu_nhap_xuat_lien_quan_id = ? AND loai = ? FOR UPDATE', [$outId, VoucherType::TransferIn->value]);
            if ($out['trang_thai'] !== 'CONFIRMED' || !$in) {
                throw new DomainError('Chỉ điều chuyển đã xác nhận mới cần điều chỉnh');
            }
            if ($in['trang_thai'] !== 'CONFIRMED') {
                throw new DomainError('Hàng đang trên đường vận chuyển — kho đến nhận hàng xong mới điều chỉnh được');
            }
            // Người điều chỉnh phải có quyền ở CẢ HAI kho, nếu không có thể dùng đường này để rút hàng khỏi kho khác.
            // Tài khoản quản lý/nhân viên chỉ thuộc một kho nên việc đảo điều chuyển đã xác nhận thuộc về quản trị hệ thống.
            $this->auth->require($user, (int)$out['kho_id'], Permission::DieuChinhPhieu);
            $this->auth->require($user, (int)$in['kho_id'], Permission::DieuChinhPhieu);
            if ($this->repo->correctionOf($outId) || $this->repo->correctionOf((int)$in['id'])) {
                throw new DomainError('Điều chuyển này đã được điều chỉnh');
            }
            $mk = fn(array $orig, VoucherType $t, ?int $ref) => $this->repo->insert([
                'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$orig['kho_id'],
                'dieu_chinh_cho_id' => (int)$orig['id'], 'phieu_nhap_xuat_lien_quan_id' => $ref, 'ly_do' => $reason, 'nguoi_lap_id' => $user->id,
            ], array_map(fn($l) => [
                'mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'],
                'so_luong' => StockRules::reversalQty($t, (int)$l['so_luong']), 'ghi_chu' => 'Đảo ' . $orig['ma'],
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
            $this->audit->record($user->id, 'transfer.correct', 'voucher', $outId, "{$out['ma']}: $reason");
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
            if (!$this->db->value('SELECT 1 FROM mat_hang i JOIN lo_hang b ON b.mat_hang_id = i.id WHERE i.id = ? AND b.id = ? AND i.hoat_dong', [$l['mat_hang_id'], $l['lo_hang_id']])) {
                throw new DomainError('Mặt hàng/lô không hợp lệ hoặc đã ngừng hoạt động');
            }
            if ((int)$l['so_luong'] < 1) {
                throw new DomainError('Số lượng phải là số nguyên dương');
            }
        }
    }
}
