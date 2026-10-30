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
                                private Authorizer $auth, private AuditLog $audit, private StockQuery $query, private FleetService $fleet, private TransportService $transport) {}

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
                $lines = $this->costLines($lines);
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
    public function confirm(User $user, int $outId, bool $checkFefo = true): array
    {
        $peek = $this->repo->find($outId);
        if ($peek['loai'] !== VoucherType::TransferOut->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        $already = $this->db->transaction(function () use ($user, $outId, $checkFefo) {
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
            if ($checkFefo && ($errors = $this->query->fefoErrors($from, $outLines))) {
                throw new DomainError('Xuất theo hạn dùng (hết hạn trước, xuất trước) — ' . implode('; ', $errors));
            }
            $outOps = $this->repo->ops($out, $outLines);
            $this->ledger->lock($this->repo->lockKeys($outOps));

            // Trừ kho đi → (điểm chèn lỗi cho test) → mở phiếu nhận ở kho đến (chưa cộng tồn).
            $this->ledger->post($outOps);
            Hooks::fire('transfer.between', ['out' => $outId]);
            $inLines = array_map(fn($l) => ['mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'], 'so_luong' => (int)$l['so_luong'],
                'don_gia' => $l['don_gia'], 'thue_suat' => (int)$l['thue_suat'], 'gia_von' => (int)$l['gia_von']], $outLines);
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
     * $pay (bắt buộc khi xuất từ giao diện): hinh_thuc NOI_BO | THUE_NGOAI, km, tien (số tiền thực trả), ghi_chu, và với THUE_NGOAI thêm company · driver · cccd · phone · plate · type
     * của đơn vị vận tải bên thứ ba (bắt buộc đủ). Tiền gợi ý = km × đơn giá/km của kho đi; có thể nhập số tiền khác.
     * @param ?array<string,mixed> $pay null = không ghi tiền công (chuyến cũ / kiểm thử)
     * @return array{voucher:array,already:bool}
     */
    public function dispatch(User $user, int $outId, int $vehicleId, bool $driverConfirmed, ?array $pay = null, bool $checkFefo = true, ?int $driverId = null): array
    {
        $peek = $this->repo->find($outId);
        if ($peek['loai'] !== VoucherType::TransferOut->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        return $this->db->transaction(function () use ($user, $outId, $vehicleId, $driverConfirmed, $pay, $checkFefo, $driverId) {
            $out = $this->repo->lock($outId);
            $xe = $tx = null;
            $deposit = ['tien' => 0, 'pt' => ''];
            $trip = ['hinh_thuc' => 'NOI_BO', 'km' => 0, 'don_gia_km' => 0, 'tien_goi_y' => 0, 'tien_tra' => 0, 'don_vi_van_tai' => '', 'ghi_chu_gia' => ''];
            if ($out['trang_thai'] === 'DRAFT') {
                $external = ($pay['hinh_thuc'] ?? 'NOI_BO') === 'THUE_NGOAI';
                if ($pay !== null) {
                    $trip = $this->tripPay((int)$out['kho_id'], (int)$out['kho_doi_ung_id'], $pay);
                }
                if ($external) {
                    $deposit = $this->fleet->externalDeposit($outId, $pay['ngoai'] ?? []);   // cọc đủ giá trị hàng mới được bàn giao
                    $vehicleId = $this->fleet->registerExternal((int)$out['kho_id'], $pay['ngoai'] ?? []);   // đủ thông tin bên thứ ba mới được bàn giao hàng
                }
                if (!$vehicleId) {
                    throw new DomainError('Chọn xe chở hàng trước khi xuất kho');
                }
                if (!$driverConfirmed) {
                    throw new DomainError($external ? 'Tài xế của đơn vị vận tải phải xác nhận đã nhận đủ hàng trước khi xuất kho' : 'Tài xế phải xác nhận đã nhận đủ hàng trước khi xuất kho');
                }
                $xe = $this->db->one('SELECT * FROM xe WHERE id = ? FOR UPDATE', [$vehicleId]);
                if (!$xe || !$xe['hoat_dong']) {
                    throw new DomainError('Xe không tồn tại hoặc đã ngừng hoạt động');
                }
                if (($xe['kieu_xe'] ?? 'TAI') === 'MAY') {
                    throw new DomainError('Xe máy chỉ dùng giao hàng cho khách, không chạy điều chuyển giữa các kho — chọn ô tô hoặc xe tải');
                }
                if ((int)($xe['kho_hien_tai_id'] ?? 0) !== (int)$out['kho_id']) {
                    throw new DomainError('Xe ' . $xe['bien_so'] . ' hiện không ở kho đi — chọn xe đang đứng tại kho này');
                }
                if ($driverId !== null && !$external) {
                    $tx = $this->fleet->assignableDriver($driverId, (int)$out['kho_id']);   // xe và tài xế không gắn cố định: tài xế nào có bằng đúng hạng, đang rảnh đều lái được
                } else {
                    $tx = $xe['tai_xe_id'] ? $this->db->one('SELECT * FROM tai_xe WHERE id = ? FOR UPDATE', [(int)$xe['tai_xe_id']]) : null;   // thuê ngoài: tài xế đi kèm xe của đơn vị vận tải
                    if (!$tx) {
                        throw new DomainError('Chọn tài xế lái xe ' . $xe['bien_so']);
                    }
                    if (!$tx['hoat_dong']) {
                        throw new DomainError('Tài xế ' . $tx['ho_ten'] . ' đã nghỉ — chọn tài xế khác lái thay');
                    }
                }
                $this->transport->assertCanCarry($vehicleId, $this->repo->lines($outId), (int)$tx['id']);   // đủ tải trọng, thể tích, giấy tờ xe, bằng lái đúng hạng — vi phạm thật thì chặn
            }
            $res = $this->confirm($user, $outId, $checkFefo);
            if (!$res['already'] && $xe && $tx) {
                $in = $this->repo->pairOf($res['voucher']) ?? throw new DomainError('Không tìm thấy phiếu đến đối ứng');
                $this->db->insert(
                    'INSERT INTO chuyen_van_chuyen (phieu_di_id, phieu_den_id, xe_id, tai_xe_id, kho_di_id, kho_den_id, tai_xe_xac_nhan_du_hang, nguoi_giao_id,
                                                    hinh_thuc, km, don_gia_km, tien_goi_y, tien_tra, don_vi_van_tai, ghi_chu_gia, tien_coc, phuong_thuc_coc) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$outId, (int)$in['id'], $vehicleId, (int)$tx['id'], (int)$out['kho_id'], (int)$out['kho_doi_ung_id'], 1, $user->id,
                     $trip['hinh_thuc'], $trip['km'], $trip['don_gia_km'], $trip['tien_goi_y'], $trip['tien_tra'], $trip['don_vi_van_tai'], $trip['ghi_chu_gia'], $deposit['tien'], $deposit['pt']]
                );
                $this->db->exec('UPDATE xe SET kho_hien_tai_id = NULL, dang_den_kho_id = ? WHERE id = ?', [(int)$out['kho_doi_ung_id'], $vehicleId]);
                $this->audit->record($user->id, 'transfer.vehicle', 'voucher', $outId, "{$xe['bien_so']} · {$tx['ho_ten']} · tài xế xác nhận đủ hàng"
                    . ($pay !== null ? ' · ' . ($trip['hinh_thuc'] === 'THUE_NGOAI' ? 'thuê ngoài ' . $trip['don_vi_van_tai'] : 'xe kho') . " · {$trip['km']} km · " . number_format($trip['tien_tra'], 0, ',', '.') . ' đ' : ''));
            }
            return $res;
        });
    }

    /**
     * XUẤT KHO ĐI bằng NHIỀU XE: mỗi xe chở một phần số lượng của từng dòng hàng. Xe chính (đã chọn ở khối vận chuyển) chở phần còn lại sau khi trừ các xe phụ.
     * Phiếu nháp gốc được tách thành mỗi xe một phiếu điều chuyển riêng (nên nhận hàng, thu hồi, sổ cái chạy theo từng xe như thường) rồi xuất kho cùng lúc;
     * phiếu gốc bị huỷ kèm ghi chú. Các phiếu cùng đợt được gom trong dot_dieu_chuyen để hiện nối đuôi nhau trên bản đồ.
     * Chỉ dùng xe của kho (tiền công xe phụ = km × đơn giá gợi ý). Hàng xuất theo hạn dùng được kiểm trên cả lô một lần.
     * @param array<int,array<int,int>> $extra id xe phụ ⇒ [id dòng phiếu ⇒ số lượng xe đó chở] · $mainDriverId, $extraDrivers = tài xế lái xe chính / từng xe phụ (id xe ⇒ id tài xế)
     * @return array{voucher:array,already:bool,split:list<int>} voucher = phiếu của xe chính
     */
    public function dispatchConvoy(User $user, int $outId, int $mainVehicleId, array $extra, bool $driverConfirmed, array $pay, ?int $mainDriverId = null, array $extraDrivers = []): array
    {
        $extra = array_filter(array_map(fn($q) => array_filter(array_map('intval', $q), fn($n) => $n > 0), $extra), fn($q) => $q !== []);
        if ($extra === []) {
            return $this->dispatch($user, $outId, $mainVehicleId, $driverConfirmed, $pay, true, $mainDriverId) + ['split' => []];
        }
        $peek = $this->repo->find($outId);
        if ($peek['loai'] !== VoucherType::TransferOut->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        if (($pay['hinh_thuc'] ?? 'NOI_BO') !== 'NOI_BO') {
            throw new DomainError('Chia hàng cho nhiều xe chỉ áp dụng với xe của kho — thuê đơn vị vận tải ngoài thì chở bằng một xe');
        }
        return $this->db->transaction(function () use ($user, $outId, $mainVehicleId, $extra, $driverConfirmed, $pay, $mainDriverId, $extraDrivers) {
            $out = $this->repo->lock($outId);
            if ($out['trang_thai'] !== 'DRAFT') {
                throw new DomainError('Chỉ chia hàng cho nhiều xe khi phiếu còn là bản nháp');
            }
            if (!$mainVehicleId) {
                throw new DomainError('Chọn xe chở hàng trước khi xuất kho');
            }
            if (isset($extra[$mainVehicleId])) {
                throw new DomainError('Xe chính không được chọn lại làm xe phụ');
            }
            $lines = $this->repo->lines($outId);
            $byId = array_column($lines, null, 'id');
            $plan = [$mainVehicleId => []];   // xe ⇒ [id dòng ⇒ số lượng]
            foreach ($lines as $l) {
                $plan[$mainVehicleId][(int)$l['id']] = (int)$l['so_luong'];
            }
            foreach ($extra as $vid => $qty) {
                foreach ($qty as $lineId => $n) {
                    if (!isset($byId[$lineId])) {
                        throw new DomainError('Dòng hàng không thuộc phiếu này');
                    }
                    $plan[$mainVehicleId][$lineId] -= $n;
                    $plan[(int)$vid][$lineId] = $n;
                }
            }
            foreach ($plan as $vid => $qty) {
                foreach ($qty as $lineId => $n) {
                    if ($n < 0) {
                        throw new DomainError('Các xe chở nhiều hơn số lượng ' . $byId[$lineId]['ma_hang'] . ' trên phiếu');
                    }
                }
                if (array_sum($qty) < 1) {
                    $plate = $this->db->value('SELECT bien_so FROM xe WHERE id = ?', [$vid]) ?: '#' . $vid;
                    throw new DomainError('Xe ' . $plate . ' chưa được chia hàng nào — bỏ xe đó hoặc chia thêm số lượng');
                }
            }
            if ($errors = $this->query->fefoErrors((int)$out['kho_id'], $lines)) {
                throw new DomainError('Xuất theo hạn dùng (hết hạn trước, xuất trước) — ' . implode('; ', $errors));
            }
            $km = (int)($pay['km'] ?? 0);
            $from = (int)$out['kho_id'];
            $to = (int)$out['kho_doi_ung_id'];
            $this->db->exec("UPDATE phieu_nhap_xuat SET trang_thai = 'CANCELLED', ghi_chu = ? WHERE id = ?", [mb_substr('Đã tách thành ' . count($plan) . ' phiếu theo xe — xem các phiếu cùng đợt', 0, 255), $outId]);
            $newIds = [];
            foreach ($plan as $vid => $qty) {
                $vlines = [];
                foreach ($lines as $l) {
                    if (($n = $qty[(int)$l['id']] ?? 0) > 0) {
                        $vlines[] = ['mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'], 'so_luong' => $n, 'don_gia' => $l['don_gia'],
                                     'thue_suat' => (int)$l['thue_suat'], 'gia_von' => (int)$l['gia_von'], 'ghi_chu' => $l['ghi_chu']];
                    }
                }
                $newId = $this->repo->insert([
                    'loai' => VoucherType::TransferOut->value, 'kho_id' => $from, 'kho_doi_ung_id' => $to,
                    'ghi_chu' => mb_substr(trim((string)$out['ghi_chu']) . ($out['ghi_chu'] !== '' ? ' · ' : '') . 'tách từ ' . $out['ma'], 0, 255), 'nguoi_lap_id' => (int)$out['nguoi_lap_id'],
                ], $vlines);
                $legPay = $newIds === [] ? $pay : ['hinh_thuc' => 'NOI_BO', 'km' => $km, 'tien' => (int)($this->fleet->quote($from, $to, 'NOI_BO', $km)['amount'] ?? 0), 'ghi_chu' => 'Xe cùng đợt — tiền công theo gợi ý'];
                $this->dispatch($user, $newId, $vid, $driverConfirmed, $legPay, false, $newIds === [] ? $mainDriverId : (($extraDrivers[$vid] ?? 0) ?: null));   // mỗi xe một tài xế riêng: tài xế đã nhận xe trước thì lượt sau báo đang chạy
                $newIds[] = $newId;
                $this->db->exec('INSERT INTO dot_dieu_chuyen (dot_id, phieu_di_id, thu_tu) VALUES (?,?,?)', [$newIds[0], $newId, count($newIds)]);
            }
            $this->audit->record($user->id, 'transfer.split', 'voucher', $outId, $out['ma'] . ' → ' . count($newIds) . ' xe cùng đợt');
            return ['voucher' => $this->repo->find($newIds[0]), 'already' => false, 'split' => $newIds];
        });
    }

    /** Lý do xe hỏng giữa đường. */
    public const BREAKDOWN_REASONS = ['Hỏng động cơ', 'Thủng lốp / hỏng bánh', 'Tai nạn / kẹt đường', 'Hết nhiên liệu', 'Khác'];

    /**
     * Kho đi (hoặc quản trị) báo XE HỎNG giữa đường: hàng vẫn đang vận chuyển nhưng sẽ tới muộn — kho đến chưa nhận được cho tới khi xe chạy tiếp
     * (resumeTrip) hoặc kho đi thu hồi chuyến. Vị trí xe dừng trên bản đồ cố định theo chuyến (minh hoạ).
     */
    public function reportBreakdown(User $user, int $outId, string $reason, string $note = ''): void
    {
        $reason = trim($reason);
        $note = trim($note);
        if (!in_array($reason, self::BREAKDOWN_REASONS, true)) {
            throw new DomainError('Chọn lý do xe hỏng');
        }
        if ($reason === 'Khác' && $note === '') {
            throw new DomainError('Ghi rõ lý do khác');
        }
        if (mb_strlen($note) > 120) {
            throw new DomainError('Ghi chú tối đa 120 ký tự');
        }
        $peek = $this->repo->find($outId);
        if ($peek['loai'] !== VoucherType::TransferOut->value) {
            throw new DomainError('Chỉ báo xe hỏng từ phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        $this->db->transaction(function () use ($user, $outId, $reason, $note) {
            $trip = $this->openTrip($outId);
            if ($this->fleet->breakdownOf((int)$trip['id'])) {
                throw new DomainError('Xe này đã được báo hỏng — bấm «Xe đã chạy tiếp» khi sửa xong');
            }
            $text = $reason . ($note !== '' ? ' — ' . $note : '');
            $this->db->exec('INSERT INTO su_co_xe (chuyen_id, ly_do, tien_do, nguoi_bao_id) VALUES (?,?,?,?)', [(int)$trip['id'], mb_substr($text, 0, 200), 25 + ((int)$trip['id'] * 37) % 51, $user->id]);
            $this->audit->record($user->id, 'transfer.breakdown', 'voucher', $outId, $trip['bien_so'] . ' hỏng giữa đường · ' . $text);
        });
    }

    /** Xe đã sửa xong, chạy tiếp tới kho đến. */
    public function resumeTrip(User $user, int $outId): void
    {
        $peek = $this->repo->find($outId);
        if ($peek['loai'] !== VoucherType::TransferOut->value) {
            throw new DomainError('Chỉ xác nhận xe chạy tiếp từ phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        $this->db->transaction(function () use ($user, $outId) {
            $trip = $this->openTrip($outId);
            $b = $this->fleet->breakdownOf((int)$trip['id']) ?? throw new DomainError('Xe này không ở trạng thái hỏng');
            $this->db->exec('UPDATE su_co_xe SET ngay_sua = CURRENT_TIMESTAMP, nguoi_sua_id = ? WHERE id = ?', [$user->id, (int)$b['id']]);
            $this->audit->record($user->id, 'transfer.resume', 'voucher', $outId, $trip['bien_so'] . ' đã sửa xong, chạy tiếp');
        });
    }

    /** Chuyến đang chạy của phiếu đi (khoá để sửa). */
    private function openTrip(int $outId): array
    {
        $trip = $this->db->one('SELECT c.*, x.bien_so FROM chuyen_van_chuyen c JOIN xe x ON x.id = c.xe_id WHERE c.phieu_di_id = ? FOR UPDATE', [$outId]);
        if (!$trip || $trip['trang_thai'] !== 'IN_TRANSIT') {
            throw new DomainError('Chỉ áp dụng cho chuyến đang vận chuyển');
        }
        return $trip;
    }

    private function tripPay(int $fromWh, int $toWh, array $pay): array
    {
        return $this->fleet->tripPay($fromWh, $toWh, $pay);
    }

    /** Lý do thu hồi chuyến khi xe còn trên đường. */
    public const RECALL_REASONS = ['Chọn nhầm hàng / sai số lượng', 'Giao nhầm kho đến', 'Kho đến không còn nhu cầu', 'Xe gặp sự cố', 'Khác'];

    /** Lý do nhận THIẾU hàng (bắt buộc khi thực nhận ít hơn số trên phiếu). */
    public const SHORT_REASONS = ['Hàng vỡ / hư hỏng trong lúc vận chuyển', 'Hàng rơi mất trên đường', 'Tài xế giao thiếu', 'Kho đi xuất thiếu so với phiếu', 'Khác'];

    /** Lý do thiếu hàng do tài xế gây ra ⇒ tài xế chịu: trừ lương theo giá trị phần thiếu và trừ điểm. Lý do khác thì kho chịu (vẫn ghi nhận, không trừ tài xế). */
    public const DRIVER_FAULT_REASONS = ['Hàng vỡ / hư hỏng trong lúc vận chuyển', 'Hàng rơi mất trên đường', 'Tài xế giao thiếu'];
    public const SHORT_PENALTY_POINTS = 5;

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
    public function receiveChecked(User $user, int $inId, ?array $received, bool $rightDriver, string $shortReason = '', string $shortNote = ''): array
    {
        $peek = $this->repo->find($inId);
        if ($peek['loai'] !== VoucherType::TransferIn->value) {
            throw new DomainError('Đây không phải phiếu điều chuyển đến');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::NhanHangDieuChuyen);
        $shortReason = trim($shortReason);
        $shortNote = trim($shortNote);
        return $this->db->transaction(function () use ($user, $inId, $received, $rightDriver, $peek, $shortReason, $shortNote) {
            $in = $this->repo->lock($inId);
            if ($in['trang_thai'] === 'CONFIRMED') {
                return ['voucher' => $in, 'already' => true, 'short' => [], 'adjustment' => null];
            }
            $trip = $this->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_den_id = ? FOR UPDATE', [$inId]);
            $open = $trip && $trip['trang_thai'] === 'IN_TRANSIT';
            if ($open && ($b = $this->fleet->breakdownOf((int)$trip['id']))) {
                throw new DomainError('Xe đang hỏng giữa đường (' . $b['ly_do'] . ') — hàng tới muộn, chưa nhận được. Chờ kho đi báo xe chạy tiếp.');
            }
            if ($open && !$rightDriver) {
                throw new DomainError('Chỉ nhận hàng khi đúng tài xế và đúng xe. Nếu không, hãy bấm «Chưa nhận được hàng».');
            }
            $short = [];
            $shortValue = 0;   // giá trị phần thiếu theo giá gốc
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
                    $shortValue += ($expected - $got) * (int)$this->db->value('SELECT gia_von FROM mat_hang WHERE id = ?', [(int)$l['mat_hang_id']]);
                    $adjLines[] = ['mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'], 'so_luong' => $got - $expected, 'ghi_chu' => 'Nhận thiếu ' . ($expected - $got) . '/' . $expected];
                }
            }
            if ($gotTotal === 0) {
                throw new DomainError('Chưa nhận được hàng nào — hãy bấm «Chưa nhận được hàng» và chọn lý do.');
            }
            $why = '';
            if ($short !== []) {
                if (!in_array($shortReason, self::SHORT_REASONS, true)) {
                    throw new DomainError('Nhận thiếu hàng — chọn lý do thiếu (vỡ, rơi mất, tài xế giao thiếu…)');
                }
                if ($shortReason === 'Khác' && $shortNote === '') {
                    throw new DomainError('Ghi rõ lý do thiếu hàng');
                }
                if (mb_strlen($shortNote) > 120) {
                    throw new DomainError('Ghi chú tối đa 120 ký tự');
                }
                $why = $shortReason . ($shortNote !== '' ? ' — ' . $shortNote : '');
            }
            $res = $this->receive($user, $inId);   // ghi sổ đủ số trên phiếu (+)
            $adj = null;
            if ($adjLines !== []) {                 // rồi trừ phần thiếu bằng phiếu điều chỉnh tại kho đến
                $desc = implode('; ', array_map(fn($s) => $s['sku'] . ' ' . $s['got'] . '/' . $s['expected'], $short));
                $adjId = $this->repo->insert([
                    'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$in['kho_id'],
                    'phieu_nhap_xuat_lien_quan_id' => $inId, 'ly_do' => mb_substr('Nhận thiếu hàng điều chuyển ' . $in['ma'] . ' (' . $why . '): ' . $desc, 0, 255), 'nguoi_lap_id' => $user->id,
                ], $adjLines);
                $adjRow = $this->repo->lock($adjId);
                $ops = $this->repo->ops($adjRow, $this->repo->lines($adjId));
                $this->ledger->lock($this->repo->lockKeys($ops));
                $this->ledger->post($ops);
                $adj = $this->repo->find($adjId);
                $this->audit->record($user->id, 'transfer.short', 'voucher', $inId, $in['ma'] . ' · nhận thiếu: ' . $desc . ' · lý do: ' . $why);
            }
            if ($open) {
                $this->db->exec("UPDATE chuyen_van_chuyen SET trang_thai = 'DELIVERED', nhan_du_hang = ?, nhan_dung_tai_xe = 1, sai_lech = ?, nguoi_nhan_id = ?, ngay_nhan = CURRENT_TIMESTAMP WHERE id = ?",
                    [$short ? 0 : 1, $short ? mb_substr('Nhận thiếu: ' . $why . ' · ' . implode('; ', array_map(fn($s) => $s['sku'] . ' ' . $s['got'] . '/' . $s['expected'], $short)), 0, 255) : '', $user->id, (int)$trip['id']]);
                $this->db->exec('UPDATE xe SET kho_hien_tai_id = ?, dang_den_kho_id = NULL WHERE id = ?', [(int)$trip['kho_den_id'], (int)$trip['xe_id']]);
                if ($short) {
                    $faulty = in_array($shortReason, self::DRIVER_FAULT_REASONS, true);
                    $deduct = $faulty ? $shortValue : 0;
                    $points = $faulty ? self::SHORT_PENALTY_POINTS : 0;
                    $this->db->insert('INSERT INTO that_thoat_hang (chuyen_id, tai_xe_id, loai, nguoi_chiu, ly_do, gia_tri, tien_tru_luong, diem_tru, nguoi_xac_nhan_id) VALUES (?,?,?,?,?,?,?,?,?)',
                        [(int)$trip['id'], (int)$trip['tai_xe_id'], 'NHAN_THIEU', $faulty ? 'TAI_XE' : 'CONG_TY', mb_substr($why, 0, 255), $shortValue, $deduct, $points, $user->id]);
                    if ($points > 0) {
                        $this->db->exec('UPDATE tai_xe SET diem = CASE WHEN diem > ? THEN diem - ? ELSE 0 END WHERE id = ?', [$points, $points, (int)$trip['tai_xe_id']]);
                        $this->audit->record($user->id, 'driver.penalty', 'driver', (int)$trip['tai_xe_id'], $in['ma'] . ' · nhận thiếu (' . $why . ') · −' . number_format($deduct, 0, ',', '.') . ' đ lương, −' . $points . ' điểm');
                    }
                }
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

    /**
     * THU HỒI CHUYẾN khi xe còn trên đường (kho đến chưa nhận): quản lý kho đi ghi lý do, hàng được cộng lại vào tồn kho đi bằng một phiếu điều chỉnh,
     * phiếu đến bị huỷ, chuyến đóng RECALLED, xe quay về kho đi. Kho đến đã nhận hoặc đã báo «chưa nhận được hàng» thì không thu hồi được.
     * @return array{voucher:array} phiếu điều chỉnh vừa lập ở kho đi
     */
    public function recall(User $user, int $outId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw new DomainError('Ghi lý do thu hồi chuyến (tối đa 200 ký tự)');
        }
        $peek = $this->repo->find($outId);
        if ($peek['loai'] !== VoucherType::TransferOut->value) {
            throw new DomainError('Chỉ thu hồi từ phiếu điều chuyển đi');
        }
        $this->auth->require($user, (int)$peek['kho_id'], Permission::XacNhanPhieu);
        $adjId = $this->db->transaction(function () use ($user, $outId, $reason) {
            $out = $this->repo->lock($outId);
            $trip = $this->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_di_id = ? FOR UPDATE', [$outId]);
            if ($out['trang_thai'] !== 'CONFIRMED' || !$trip) {
                throw new DomainError('Chỉ thu hồi được chuyến đã xuất kho và đang vận chuyển');
            }
            if ($trip['trang_thai'] !== 'IN_TRANSIT') {
                throw new DomainError('Chuyến này đã đóng — không thu hồi được nữa');
            }
            $in = $this->repo->lock((int)$trip['phieu_den_id']);
            if ($in['trang_thai'] !== 'DRAFT') {
                throw new DomainError('Kho đến đã nhận hàng — không thu hồi được nữa');
            }
            if ($trip['sai_lech'] !== '') {
                throw new DomainError('Kho đến đã báo «chưa nhận được hàng» — quản trị hệ thống đang xử lý chuyến này nên chưa thu hồi được');
            }
            if ($this->repo->correctionOf($outId)) {
                throw new DomainError('Phiếu này đã được điều chỉnh');
            }
            $lines = array_map(fn($l) => [
                'mat_hang_id' => (int)$l['mat_hang_id'], 'lo_hang_id' => (int)$l['lo_hang_id'],
                'so_luong' => StockRules::reversalQty(VoucherType::TransferOut, (int)$l['so_luong']), 'ghi_chu' => 'Thu hồi ' . $out['ma'],
            ], $this->repo->lines($outId));
            $adjId = $this->repo->insert([
                'loai' => VoucherType::Adjustment->value, 'trang_thai' => 'CONFIRMED', 'kho_id' => (int)$out['kho_id'],
                'dieu_chinh_cho_id' => $outId, 'ly_do' => 'Thu hồi chuyến: ' . $reason, 'nguoi_lap_id' => $user->id,
            ], $lines);
            $adj = $this->repo->lock($adjId);
            $ops = $this->repo->ops($adj, $this->repo->lines($adjId));
            $this->ledger->lock($this->repo->lockKeys($ops));
            $this->ledger->post($ops);
            $this->db->exec("UPDATE phieu_nhap_xuat SET trang_thai = 'CANCELLED', ghi_chu = ? WHERE id = ?", [mb_substr('Kho đi thu hồi chuyến: ' . $reason, 0, 255), (int)$in['id']]);
            $this->db->exec("UPDATE chuyen_van_chuyen SET trang_thai = 'RECALLED', sai_lech = ? WHERE id = ?", [mb_substr('Kho đi thu hồi chuyến: ' . $reason, 0, 255), (int)$trip['id']]);
            $this->db->exec('UPDATE su_co_xe SET ngay_sua = CURRENT_TIMESTAMP, nguoi_sua_id = ? WHERE chuyen_id = ? AND ngay_sua IS NULL', [$user->id, (int)$trip['id']]);
            $this->db->exec('UPDATE xe SET kho_hien_tai_id = ?, dang_den_kho_id = NULL WHERE id = ?', [(int)$out['kho_id'], (int)$trip['xe_id']]);
            $this->audit->record($user->id, 'transfer.recall', 'voucher', $outId, "{$out['ma']} · xe quay về kho đi · $reason");
            return $adjId;
        });
        return ['voucher' => $this->repo->find($adjId)];
    }

    /** Điều chuyển tính theo giá gốc chung của mọi kho: ghi giá gốc + thuế tại lúc lập phiếu lên từng dòng (để in phiếu không đổi khi giá gốc đổi sau này). */
    private function costLines(array $lines): array
    {
        foreach ($lines as &$l) {
            $item = $this->db->one('SELECT thue_suat, gia_von FROM mat_hang WHERE id = ?', [$l['mat_hang_id']]);
            $l['thue_suat'] = (int)($item['thue_suat'] ?? 0);
            $l['gia_von'] = (int)($item['gia_von'] ?? 0);
            $l['don_gia'] = $l['gia_von'];
        }
        return $lines;
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
