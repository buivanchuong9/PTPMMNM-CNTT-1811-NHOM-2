<?php
declare(strict_types=1);

namespace StockFlow\Seeds;

use StockFlow\Core\Container;
use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\AuthService;
use StockFlow\Service\CatalogService;
use StockFlow\Service\FleetService;
use StockFlow\Service\PartnerService;
use StockFlow\Service\PricingService;
use StockFlow\Service\StockCountService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherService;

/**
 * Dữ liệu demo: 3 kho, 30 mặt hàng (10 mặt hàng có lô/hạn dùng), ≥200 dòng sổ phát sinh,
 * có điều chuyển, chênh lệch kiểm kê, phiếu nhập xuất điều chỉnh. Mọi thao tác đi qua chính các service
 * (không INSERT tắt) nên dữ liệu mẫu luôn nhất quán với quy tắc nghiệp vụ. Xác định (seed cố định).
 */
final class DemoSeed
{
    public const PASSWORD = 'demo1234';
    /** SKU của 30 mặt hàng demo. */
    private const SKUS = ['BOX-S', 'BOX-M', 'BOX-L', 'TAPE-48', 'BUBBLE', 'PE-BAG', 'LBL-A6', 'STRETCH', 'PALLET', 'STRAP', 'GLOVE', 'CUTTER', 'PHN-A15', 'PHN-A25', 'EARBUD', 'CHG-65W', 'CAB-USBC', 'PWR-20K',
        'SPK-BT', 'WATCH-S', 'MLK-1L', 'NDL-PK', 'CFE-3IN1', 'TEA-GRN', 'SOAP-500', 'DET-3KG', 'VIT-C', 'MASK-50', 'OIL-1L', 'SGR-1KG'];

    /** Mã vạch EAN-13 mẫu của một SKU: đầu số 893 (Việt Nam) + 9 số cố định theo SKU + số kiểm tra. Cùng SKU luôn ra cùng mã. */
    public static function barcode(string $sku): string
    {
        $body = '893' . str_pad((string)(crc32('stockflow:' . $sku) % 1000000000), 9, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($body) as $i => $d) {
            $sum += (int)$d * ($i % 2 ? 3 : 1);
        }
        return $body . (10 - $sum % 10) % 10;
    }

    /** Gán mã vạch mẫu cho các mặt hàng demo chưa có mã (không đụng mặt hàng đã có mã hay mặt hàng khác). Chạy lại an toàn. @return int số mặt hàng được gán */
    /** Thời hạn bảo hành mẫu (tháng): điện tử / linh kiện có, hàng tiêu dùng không. */
    private const WARRANTY_MONTHS = ['PHN-A15' => 12, 'PHN-A25' => 12, 'EARBUD' => 6, 'CHG-65W' => 12, 'CAB-USBC' => 3, 'PWR-20K' => 12, 'SPK-BT' => 12, 'WATCH-S' => 12];

    /** CSDL demo có từ trước khi có kho bảo hành: gán thời hạn bảo hành cho mặt hàng điện tử chưa đặt. */
    public static function backfillWarranty(Db $db): int
    {
        $n = 0;
        foreach (self::WARRANTY_MONTHS as $sku => $months) {
            $n += $db->exec('UPDATE mat_hang SET bao_hanh_thang = ? WHERE ma_hang = ? AND bao_hanh_thang = 0', [$months, $sku]);
            // bảo hành tính theo lô (SaleCodeService đọc lo_hang): lô chưa đặt thì theo mặt hàng
            $db->exec('UPDATE lo_hang b JOIN mat_hang i ON i.id = b.mat_hang_id SET b.bao_hanh_thang = i.bao_hanh_thang WHERE i.ma_hang = ? AND b.bao_hanh_thang = 0 AND i.bao_hanh_thang > 0', [$sku]);
        }
        return $n;
    }

    /**
     * Phiếu xuất đã ghi sổ từ trước khi có mã bán hàng: sinh «mã lô hàng» + «mã món hàng» cho hàng có bảo hành (ngày mua = ngày xác nhận phiếu).
     * Bỏ qua phiếu đã có mã và phiếu đã bị điều chỉnh đảo. Chạy lại an toàn. @return int số chiếc đã sinh mã
     */
    /**
     * Phiếu bảo hành mẫu cho kho bảo hành còn trống: nhận theo mã lô hàng + mã món hàng của các chiếc đã bán (đủ các trạng thái:
     * đang giữ, đã sửa, đổi mới, từ chối vì hết hạn, đã trả khách). Đi qua WarrantyService nên đúng quy tắc nghiệp vụ. Chạy lại an toàn. @return int số phiếu tạo
     */
    public static function backfillWarrantyTickets(Db $db): int
    {
        if ((int)$db->value('SELECT COUNT(*) FROM bao_hanh') > 0 || !(int)$db->value('SELECT COUNT(*) FROM san_pham_ban')) {
            return 0;
        }
        $c = new Container();
        $c->set(Db::class, $db);
        $svc = $c->get(\StockFlow\Service\WarrantyService::class);
        $managers = [];   // admin không xử lý bảo hành: dùng quản lý của đúng kho đã bán hàng
        foreach ($db->all("SELECT id, ten_dang_nhap, ho_ten, kho_id FROM nguoi_dung WHERE vai_tro = 'MANAGER' AND hoat_dong AND kho_id IS NOT NULL") as $m) {
            $managers[(int)$m['kho_id']] ??= new User((int)$m['id'], $m['ten_dang_nhap'], $m['ho_ten'], AccountRole::Manager, (int)$m['kho_id']);
        }
        $today = gmdate('Y-m-d');
        $pick = fn(string $cond, int $limit) => $db->all("SELECT s.ma_lo, s.ma_mon, s.kho_id FROM san_pham_ban s WHERE s.da_huy = 0 AND $cond
            AND NOT EXISTS (SELECT 1 FROM bao_hanh b WHERE b.san_pham_ban_id = s.id) AND s.id = (SELECT MIN(x.id) FROM san_pham_ban x WHERE x.phieu_nhap_xuat_id = s.phieu_nhap_xuat_id AND x.mat_hang_id = s.mat_hang_id)
            ORDER BY s.id DESC LIMIT $limit", [$today]);
        $valid = $pick('s.het_han >= ?', 8);
        $expired = $pick('s.het_han < ?', 1);
        $cases = [   // [trạng thái, khách, điện thoại, lỗi, ghi chú kết quả]
            ['TIEP_NHAN', 'Nguyễn Văn Hùng', '0903 456 781', 'Không lên nguồn sau khi cắm sạc', ''],
            ['TIEP_NHAN', 'Trần Thị Mai', '0912 334 556', 'Loa rè, mất tiếng bên phải', ''],
            ['TIEP_NHAN', 'Công ty TNHH Minh Phát', '0283 822 1144', 'Pin chai, sạc không vào', ''],
            ['DA_SUA', 'Lê Quốc Bảo', '0987 123 450', 'Cổng sạc lỏng, mất kết nối', 'Đã thay cổng sạc, kiểm tra hoạt động tốt'],
            ['DA_SUA', 'Phạm Thị Thu', '0935 778 902', 'Màn hình chớp tắt', 'Đã cập nhật phần mềm, hết lỗi'],
            ['DOI_MOI', 'Võ Minh Tuấn', '0977 210 365', 'Hỏng nguồn do lỗi nhà sản xuất', 'Lỗi nhà sản xuất, đổi mới cho khách'],
            ['DA_TRA', 'Đặng Hoàng Nam', '0908 665 421', 'Tai nghe một bên không có tiếng', 'Đã thay driver'],
            ['DA_TRA', 'Bùi Thị Lan', '0961 889 007', 'Dây cáp đứt đầu cắm', 'Đã bấm lại đầu cắm'],
        ];
        $n = 0;
        foreach ($cases as $i => [$state, $name, $phone, $defect, $note]) {
            $u = $valid[$i] ?? null;
            if (!$u) {
                break;
            }
            if (!($mgr = $managers[(int)$u['kho_id']] ?? null)) {
                continue;
            }
            try {
                $id = $svc->receiveByCode($mgr, (int)$u['kho_id'], $u['ma_lo'], $u['ma_mon'], $name, $phone, '', $defect, gmdate('Y-m-d', strtotime('-' . (8 - $i) . ' days')), gmdate('Y-m-d', strtotime('+' . (1 + $i) . ' days')));
                if ($state !== 'TIEP_NHAN') {
                    $svc->resolve($mgr, $id, $state === 'DA_TRA' ? 'DA_SUA' : $state, $note);
                }
                if ($state === 'DA_TRA') {
                    $svc->handBack($mgr, $id);
                }
                $n++;
            } catch (\StockFlow\Domain\DomainError) {   // thiếu nhà cung cấp / hết tồn để đổi mới: bỏ qua ca này
            }
        }
        if (($e = $expired[0] ?? null) && ($mgr = $managers[(int)$e['kho_id']] ?? null)) {   // hàng hết hạn bảo hành ⇒ từ chối
            try {
                $id = $svc->receiveByCode($mgr, (int)$e['kho_id'], $e['ma_lo'], $e['ma_mon'], 'Hoàng Gia Bảo', '0918 552 036', '', 'Hỏng sau thời gian dài sử dụng');
                $svc->resolve($mgr, $id, 'TU_CHOI', 'Đã hết thời hạn bảo hành');
                $n++;
            } catch (\StockFlow\Domain\DomainError) {
            }
        }
        return $n;
    }

    public static function backfillSaleCodes(Db $db): int
    {
        $c = new Container();
        $c->set(Db::class, $db);
        $codes = $c->get(\StockFlow\Service\SaleCodeService::class);
        $n = 0;
        foreach ($db->all("SELECT v.id, v.kho_id, v.ngay_xac_nhan FROM phieu_nhap_xuat v
                           WHERE v.loai = 'ISSUE' AND v.trang_thai = 'CONFIRMED'
                             AND NOT EXISTS (SELECT 1 FROM phieu_nhap_xuat a WHERE a.dieu_chinh_cho_id = v.id)
                             AND NOT EXISTS (SELECT 1 FROM san_pham_ban s WHERE s.phieu_nhap_xuat_id = v.id) ORDER BY v.id") as $v) {
            $lines = $db->all('SELECT id, mat_hang_id, lo_hang_id, so_luong FROM chi_tiet_phieu_nhap_xuat WHERE phieu_nhap_xuat_id = ? ORDER BY id', [(int)$v['id']]);
            $n += $db->transaction(fn() => $codes->issueFor($v, $lines, substr((string)$v['ngay_xac_nhan'], 0, 10)));
        }
        return $n;
    }

    public static function backfillBarcodes(Db $db): int
    {
        $n = 0;
        foreach ($db->all('SELECT id, ma_hang FROM mat_hang WHERE ma_vach IS NULL') as $r) {
            if (in_array($r['ma_hang'], self::SKUS, true)) {
                $db->exec('UPDATE mat_hang SET ma_vach = ? WHERE id = ?', [self::barcode($r['ma_hang']), $r['id']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Cân nặng + kích thước hàng, thông số xe, giấy phép lái xe tài xế cho CSDL đã có sẵn (xe, hàng, tài xế tạo từ trước khi có tính năng tải trọng).
     * Chỉ điền ô còn TRỐNG (cân nặng 0, tải trọng 0, chưa có hạng bằng…) nên không đè số liệu đã nhập tay; chạy lại an toàn.
     * Số liệu theo xe thật cùng loại trên thị trường; hạng bằng của mỗi tài xế được chọn đủ để lái xe được giao theo Luật TTATGTĐB 2024,
     * ngày cấp / ngày sinh tính lùi từ hôm nay để dữ liệu demo không tự hết hạn. @return int số dòng đã điền
     */
    public static function backfillTransport(Db $db): int
    {
        $n = 0;
        // ── hàng hoá: [gram, dài mm, rộng mm, cao mm] của 1 đơn vị tính ──
        $items = [
            'BOX-S' => [100, 200, 150, 100], 'BOX-M' => [180, 300, 200, 200], 'BOX-L' => [450, 500, 350, 300],
            'TAPE-48' => [300, 120, 120, 50], 'BUBBLE' => [4500, 1000, 200, 200], 'PE-BAG' => [300, 350, 250, 40], 'LBL-A6' => [250, 110, 110, 100],
            'STRETCH' => [2500, 500, 130, 130], 'PALLET' => [14000, 1200, 1000, 150], 'STRAP' => [8000, 380, 380, 200],
            'GLOVE' => [80, 250, 120, 20], 'CUTTER' => [50, 170, 40, 20],
            'PHN-A15' => [350, 180, 95, 55], 'PHN-A25' => [400, 185, 100, 60], 'EARBUD' => [120, 120, 90, 40], 'CHG-65W' => [250, 130, 80, 45],
            'CAB-USBC' => [60, 150, 100, 20], 'PWR-20K' => [500, 180, 100, 45], 'SPK-BT' => [400, 170, 110, 90], 'WATCH-S' => [200, 130, 90, 60],
            'MLK-1L' => [1050, 70, 70, 190], 'NDL-PK' => [2600, 400, 270, 180], 'CFE-3IN1' => [380, 200, 130, 80], 'TEA-GRN' => [80, 120, 70, 65],
            'SOAP-500' => [550, 65, 65, 200], 'DET-3KG' => [3050, 280, 190, 95], 'VIT-C' => [60, 40, 40, 120], 'MASK-50' => [200, 175, 95, 65],
            'OIL-1L' => [970, 85, 85, 245], 'SGR-1KG' => [1010, 190, 110, 55],
            'TRE' => [350, 250, 150, 100], 'SS' => [500, 200, 150, 100], 'HEHE' => [250, 120, 120, 80],
        ];
        foreach ($items as $sku => [$g, $l, $w, $h]) {
            $n += $db->exec('UPDATE mat_hang SET khoi_luong_g = ?, dai_mm = ?, rong_mm = ?, cao_mm = ? WHERE ma_hang = ? AND khoi_luong_g = 0 AND dai_mm = 0', [$g, $l, $w, $h, $sku]);
        }

        // ── kiện (thùng đóng sẵn = quy_cach_lo đơn vị): xếp các đơn vị thành khối a × b × c gần vuông nhất (+1 cm đệm mỗi chiều);
        //    cân cả kiện = số đơn vị × cân đơn vị + 3% chèn lót + bì thùng carton (≈ 7 g mỗi dm² bề mặt) ──
        $flat = ['BOX-S' => [220, 170, 160], 'BOX-M' => [310, 210, 210], 'BOX-L' => [510, 360, 135]];   // thùng carton rỗng xếp phẳng thành chồng, không xếp nguyên khối
        foreach ($db->all('SELECT id, ma_hang AS sku, quy_cach_lo AS pack, khoi_luong_g AS g, dai_mm AS l, rong_mm AS w, cao_mm AS h FROM mat_hang WHERE quy_cach_lo > 1 AND dai_mm > 0 AND kien_g = 0 AND kien_dai_mm = 0') as $it) {
            $pack = (int)$it['pack'];
            $best = null;
            for ($a = 1; $a <= $pack; $a++) {
                for ($b = 1; $a * $b <= $pack * 2; $b++) {
                    $c = (int)ceil($pack / ($a * $b));
                    $dims = [$a * (int)$it['l'], $b * (int)$it['w'], $c * (int)$it['h']];
                    $cost = $dims[0] * $dims[1] * $dims[2] * (1 + 0.25 * (max($dims) / min($dims) - 1));
                    if ($best === null || $cost < $best[0]) {
                        $best = [$cost, $dims];
                    }
                }
            }
            [$kl, $kw, $kh] = $flat[$it['sku']] ?? array_map(fn($d) => $d + 10, $best[1]);
            $tare = 2 * ($kl * $kw + $kl * $kh + $kw * $kh) / 10_000 * 7;
            $n += $db->exec('UPDATE mat_hang SET kien_g = ?, kien_dai_mm = ?, kien_rong_mm = ?, kien_cao_mm = ? WHERE id = ?', [(int)round($pack * (int)$it['g'] * 1.03 + $tare), $kl, $kw, $kh, (int)$it['id']]);
        }

        // ── kiểu xe: tải trọng kg, GVW kg, loại thùng, lòng thùng mm (dài, rộng, cao), cả xe mm (dài, rộng, cao), số trục ──
        $kinds = [
            'tai15' => [1500, 3490, 'KIN', [3100, 1650, 1650], [5300, 1750, 2350], 2],      // Hyundai Porter 150 …: đến 3,5 tấn ⇒ bằng B
            'tai19' => [1900, 4400, 'KIN', [3600, 1700, 1700], [5900, 1900, 2600], 2],      // ⇒ bằng C1
            'tai25' => [2490, 4990, 'KIN', [4300, 1800, 1800], [6300, 1950, 2500], 2],      // Hyundai N250 …: 3,5–7,5 tấn ⇒ C1
            'tai35' => [3490, 7300, 'KIN', [4900, 2050, 2100], [7000, 2250, 3200], 2],      // Isuzu NPR …: ⇒ C1
            'banta' => [900, 3150, 'LUNG', [1500, 1500, 500], [5300, 1850, 1850], 2],       // Ford Ranger …: ⇒ B
            'tai50' => [4950, 10400, 'MUI_BAT', [6200, 2200, 2100], [8300, 2350, 3200], 2], // Isuzu FRR …: trên 7,5 tấn ⇒ C
            'ben' => [6500, 15000, 'BEN', [3800, 2200, 1100], [6200, 2450, 2900], 2],       // xe ben 2 trục ⇒ C
            'khach' => [300, 9500, '', [0, 0, 0], [8500, 2400, 3000], 2],                    // xe khách 29 chỗ: chỉ chở hành lý, hạng D2 — không dùng chở hàng
        ];
        // biển số ⇒ [kiểu xe, đăng kiểm còn (ngày), bảo hiểm còn (ngày)]: xe 6 sắp hết đăng kiểm; xe 18 hết hạn bảo hiểm (để thấy bị chặn); xe 13 sắp hết đăng kiểm
        $fleet = [
            '30H12345' => ['tai19', 420, 300], '99C-01234' => ['ben', 380, 250], '70G-99999' => ['khach', 500, 330],
            '29C-102.11' => ['tai15', 540, 350], '29C-109.24' => ['tai25', 300, 200], '29C-116.37' => ['tai35', 19, 260], '29C-123.50' => ['banta', 470, 310], '29C-130.63' => ['tai50', 330, 220],
            '51C-104.13' => ['tai15', 400, 280], '51C-111.26' => ['tai25', 360, 240], '51C-118.39' => ['tai35', 280, 190], '51C-125.52' => ['banta', 510, 330], '51C-132.65' => ['tai50', 22, 210],
            '43C-103.12' => ['tai15', 450, 300], '43C-110.25' => ['tai25', 390, 260], '43C-117.38' => ['tai35', 310, 230], '43C-124.51' => ['banta', 480, 320], '43C-131.64' => ['tai50', 340, -25],
        ];
        $day = fn(int $d) => date('Y-m-d', strtotime(($d >= 0 ? '+' : '') . $d . ' days'));
        foreach ($fleet as $plate => [$kind, $reg, $ins]) {
            [$cap, $gvw, $body, $box, $tot, $axles] = $kinds[$kind];
            $n += $db->exec('UPDATE xe SET tai_trong_kg = ?, gvw_kg = ?, loai_thung = ?, thung_dai_mm = ?, thung_rong_mm = ?, thung_cao_mm = ?, tong_dai_mm = ?, tong_rong_mm = ?, tong_cao_mm = ?, so_truc = ?, han_dang_kiem = ?, han_bao_hiem = ?
                             WHERE bien_so = ? AND tai_trong_kg = 0 AND gvw_kg = 0', [$cap, $gvw, $body, ...$box, ...$tot, $axles, $day($reg), $day($ins), $plate]);
        }

        // ── tài xế (theo CCCD): [hạng bằng, tuổi hiện nay, đã cấp bằng bao nhiêu năm, nơi cấp, số ngày nữa hết hạn khám sức khoẻ, điểm còn lại] ──
        // Hạng bằng đủ cho xe được giao: B cho xe đến 3,5 tấn; C1 cho 3,5–7,5 tấn; C cho trên 7,5 tấn; CE cho xe có thể kéo rơ moóc.
        $hn = 'Sở GTVT Hà Nội';
        $dn = 'Sở GTVT Đà Nẵng';
        $hcm = 'Sở GTVT TP. Hồ Chí Minh';
        $drivers = [
            '001206012000' => ['D2', 42, 2.5, $hcm, 250, 12],   // xe khách 70G-99999 (hạng D2 — không được chở hàng)
            '001206018866' => ['C', 37, 3.5, $dn, 300, 12],     // xe ben 99C-01234
            '001206012888' => ['C1', 35, 4.0, $hn, 190, 12],    // 30H12345
            '031000200501' => ['B', 33, 7.0, $hn, 340, 12],     // 29C-102.11 (1,5 tấn)
            '031000200502' => ['C1', 36, 2.0, $hn, 12, 12],     // 29C-109.24 (khám sức khoẻ sắp hết hạn)
            '031000200503' => ['C', 39, 5.0 - 21 / 365, $hn, 260, 12],   // 29C-116.37: bằng C hết hạn sau ~3 tuần
            '031000200504' => ['B2', 31, 8.0, $hn, 220, 12],    // 29C-123.50 (bán tải) — bằng B2 cũ cấp trước 2025
            '031000200505' => ['C', 41, 3.0, $hn, 280, 10],     // 29C-130.63 (5 tấn)
            '051000200701' => ['B', 30, 5.0, $hcm, 330, 12],    // 51C-104.13
            '051000200702' => ['C1', 34, 1.5, $hcm, 310, 12],   // 51C-111.26
            '051000200703' => ['C1', 38, 4.0, $hcm, 240, 12],   // 51C-118.39
            '051000200704' => ['B', 32, 6.0, $hcm, 200, 12],    // 51C-125.52
            '051000200705' => ['CE', 43, 1.0, $hcm, 350, 12],   // 51C-132.65 (CE: kéo được rơ moóc)
            '041000200601' => ['B', 29, 4.0, $dn, 270, 12],     // 43C-103.12
            '041000200602' => ['C1', 36, 3.0, $dn, 230, 12],    // 43C-110.25
            '041000200603' => ['C', 40, 2.0, $dn, 320, 12],     // 43C-117.38
            '041000200604' => ['B', 28, 3.0, $dn, 210, 12],     // 43C-124.51
            '041000200605' => ['C', 42, 4.0, $dn, 360, 12],     // 43C-131.64
        ];
        foreach ($drivers as $cccd => $spec) {
            $n += self::setLicense($db, (string)$cccd, $spec);
        }
        return $n;
    }

    /**
     * Đặt giấy phép lái xe và hồ sơ cho một tài xế (theo CCCD) nếu còn trống. $spec = [hạng bằng, tuổi hiện nay, đã cấp bằng bao nhiêu năm, nơi cấp, số ngày nữa hết hạn khám SK, điểm còn lại].
     * Ngày sinh / ngày cấp tính lùi từ hôm nay nên tuổi luôn đủ điều kiện của hạng bằng và bằng không tự hết hạn. @return int số dòng đã cập nhật
     */
    private static function setLicense(Db $db, string $cccd, array $spec): int
    {
        [$class, $age, $held, $place, $health, $points] = $spec;
        $issued = date('Y-m-d', strtotime('-' . (int)round($held * 365) . ' days'));
        $term = (int)\StockFlow\Domain\TransportRules::CLASSES[$class][2];
        $birth = date('Y-m-d', strtotime('-' . $age . ' years -' . (crc32($cccd) % 300) . ' days'));   // ngày sinh lẻ theo từng người
        $no = ['Hà Nội' => '01', 'Đà Nẵng' => '48', 'TP. Hồ Chí Minh' => '79'][substr($place, strlen('Sở GTVT '))] . str_pad((string)(crc32('gplx' . $cccd) % 10_000_000_000), 10, '0', STR_PAD_LEFT);
        return $db->exec('UPDATE tai_xe SET so_gplx = ?, hang_gplx = ?, ngay_sinh = ?, ngay_cap_gplx = ?, han_gplx = ?, noi_cap_gplx = ?, han_kham_sk = ?, diem_gplx = ?
                          WHERE cccd = ? AND hang_gplx = \'\' AND so_gplx = \'\'',
            [$no, $class, $birth, $issued, $term > 0 ? date('Y-m-d', strtotime($issued . ' +' . $term . ' years')) : null, $place, date('Y-m-d', strtotime('+' . $health . ' days')), $points, $cccd]);
    }

    /**
     * Xe và tài xế KHÔNG gắn cố định: gỡ liên kết cũ (xe.tai_xe_id) và thêm tài xế dự phòng ở mỗi kho để có người lái thay khi tài xế khác nghỉ
     * (có cả một tài xế đang nghỉ). Tài xế dự phòng cũng có đủ bằng lái, tuổi, khám sức khoẻ hợp lệ. Chạy lại an toàn. @return int số dòng thay đổi
     */
    public static function backfillDecouple(Db $db): int
    {
        $n = $db->exec('UPDATE xe SET tai_xe_id = NULL WHERE tai_xe_id IS NOT NULL AND thue_ngoai = 0');
        $hn = 'Sở GTVT Hà Nội';
        $dn = 'Sở GTVT Đà Nẵng';
        $hcm = 'Sở GTVT TP. Hồ Chí Minh';
        // CCCD ⇒ [họ tên, điện thoại, mã kho, active, [hạng bằng, tuổi, số năm đã có bằng, nơi cấp, ngày nữa hết khám SK, điểm]]
        $spare = [
            '031000200506' => ['Vũ Đình Khải', '0912000101', 'HN01', 1, ['C1', 38, 3.0, $hn, 300, 12]],
            '031000200507' => ['Mai Văn Toàn', '0912000102', 'HN01', 1, ['B', 27, 5.0, $hn, 280, 12]],
            '031000200508' => ['Đỗ Thành Lộc', '0912000103', 'HN01', 0, ['C', 45, 3.0, $hn, 200, 12]],   // đang nghỉ
            '041000200606' => ['Nguyễn Hữu Phúc', '0912000201', 'DN01', 1, ['C', 44, 4.0, $dn, 320, 12]],
            '041000200607' => ['Lê Công Minh', '0912000202', 'DN01', 1, ['C1', 33, 2.0, $dn, 260, 12]],
            '051000200706' => ['Trần Quốc Bình', '0912000301', 'HCM01', 1, ['C', 46, 4.0, $hcm, 340, 12]],
            '051000200707' => ['Phạm Gia Huy', '0912000302', 'HCM01', 1, ['B', 26, 3.0, $hcm, 240, 12]],
        ];
        foreach ($spare as $cccd => [$name, $phone, $kho, $active, $lic]) {
            if (!$db->value('SELECT 1 FROM tai_xe WHERE cccd = ?', [(string)$cccd]) && ($khoId = $db->value('SELECT id FROM kho WHERE ma = ?', [$kho]))) {
                $db->exec('INSERT INTO tai_xe (ho_ten, cccd, so_dien_thoai, kho_id, hoat_dong) VALUES (?,?,?,?,?)', [$name, (string)$cccd, $phone, (int)$khoId, $active]);
                $n++;
            }
            $n += self::setLicense($db, (string)$cccd, $lic);
        }
        return $n;
    }

    /**
     * Xe máy giao đơn nhỏ: mỗi kho 2 xe máy + 2 tài xế giao hàng có bằng mô tô (A1 đến 175 cm³, A cho xe phân khối lớn); đánh dấu xe bán tải là «ô tô nhỏ».
     * Xe máy tải khoảng 50 kg (thùng giao hàng gắn sau), không đăng kiểm định kỳ, chỉ giao cho khách — không chạy điều chuyển giữa các kho. Chạy lại an toàn. @return int số dòng thay đổi
     */
    public static function backfillMotorbikes(Db $db): int
    {
        $n = $db->exec("UPDATE xe SET kieu_xe = 'OTO_NHO' WHERE kieu_xe = 'TAI' AND loai_xe LIKE 'Xe bán tải%'");
        $day = fn(int $d) => date('Y-m-d', strtotime('+' . $d . ' days'));
        // biển số ⇒ [loại xe, mã kho, dung tích cm³, tải kg, bảo hiểm còn (ngày)]
        $bikes = [
            '29B1-234.56' => ['Honda Wave Alpha 110', 'HN01', 110, 50, 310], '29B2-345.67' => ['Honda Vision 125', 'HN01', 125, 50, 240],
            '43B1-123.45' => ['Honda Wave RSX 110', 'DN01', 110, 50, 280], '43B1-123.46' => ['Honda Air Blade 160', 'DN01', 160, 50, 200],
            '59B1-234.56' => ['Yamaha Exciter 150', 'HCM01', 150, 50, 330], '59B1-345.67' => ['Honda SH 350i', 'HCM01', 330, 60, 260],
        ];
        foreach ($bikes as $plate => [$type, $kho, $cc, $cap, $ins]) {
            if (!$db->value('SELECT 1 FROM xe WHERE bien_so = ?', [$plate]) && ($khoId = $db->value('SELECT id FROM kho WHERE ma = ?', [$kho]))) {
                $db->exec('INSERT INTO xe (bien_so, loai_xe, kho_goc_id, kho_hien_tai_id) VALUES (?,?,?,?)', [$plate, $type, (int)$khoId, (int)$khoId]);
                $n++;
            }
            $n += $db->exec("UPDATE xe SET kieu_xe = 'MAY', dung_tich_cc = ?, tai_trong_kg = ?, gvw_kg = 0, loai_thung = 'KIN', thung_dai_mm = 450, thung_rong_mm = 400, thung_cao_mm = 400,
                                    tong_dai_mm = 1900, tong_rong_mm = 700, tong_cao_mm = 1100, so_truc = 0, han_dang_kiem = NULL, han_bao_hiem = ?
                             WHERE bien_so = ? AND kieu_xe = 'TAI' AND tai_trong_kg = 0", [$cc, $cap, $day($ins), $plate]);
        }
        $hn = 'Sở GTVT Hà Nội';
        $dn = 'Sở GTVT Đà Nẵng';
        $hcm = 'Sở GTVT TP. Hồ Chí Minh';
        // CCCD ⇒ [họ tên, điện thoại, mã kho, [hạng bằng, tuổi, số năm đã có bằng, nơi cấp, ngày nữa hết khám SK, điểm]]
        $riders = [
            '031000200509' => ['Lê Hải Nam', '0912000104', 'HN01', ['A1', 24, 4.0, $hn, 300, 12]],
            '031000200510' => ['Phạm Quốc Việt', '0912000105', 'HN01', ['A1', 28, 5.0, $hn, 260, 12]],
            '041000200608' => ['Võ Minh Hiếu', '0912000203', 'DN01', ['A1', 25, 3.0, $dn, 280, 12]],
            '041000200609' => ['Đinh Công Thành', '0912000204', 'DN01', ['A1', 29, 6.0, $dn, 330, 12]],
            '051000200708' => ['Nguyễn Thanh Phong', '0912000303', 'HCM01', ['A', 31, 6.0, $hcm, 310, 12]],   // bằng A: chạy được xe 330 cm³
            '051000200709' => ['Trần Đức Anh', '0912000304', 'HCM01', ['A1', 27, 5.0, $hcm, 250, 12]],
        ];
        foreach ($riders as $cccd => [$name, $phone, $kho, $lic]) {
            if (!$db->value('SELECT 1 FROM tai_xe WHERE cccd = ?', [(string)$cccd]) && ($khoId = $db->value('SELECT id FROM kho WHERE ma = ?', [$kho]))) {
                $db->exec('INSERT INTO tai_xe (ho_ten, cccd, so_dien_thoai, kho_id, hoat_dong) VALUES (?,?,?,?,1)', [$name, (string)$cccd, $phone, (int)$khoId]);
                $n++;
            }
            $n += self::setLicense($db, (string)$cccd, $lic);
        }
        return $n;
    }

    /**
     * Khách hàng quanh từng kho: mỗi kho 10 khách ở các tỉnh gần kho đó (miền Bắc → HN01, miền Trung → DN01, miền Nam → HCM01) — khách miền Bắc không lấy hàng từ kho
     * miền Nam. Địa chỉ đủ số nhà, phường / xã, tỉnh theo danh mục hành chính; phường chọn cố định theo mã khách. Gán kho phụ trách (gần nhất) cho mọi khách có địa chỉ nhận ra
     * tỉnh, và chuẩn hoá địa chỉ hai khách mẫu cũ. Chạy lại an toàn. @return int số dòng thay đổi
     */
    public static function backfillRegionalCustomers(Db $db): int
    {
        // mã ⇒ [tên khách, người liên hệ, điện thoại, tỉnh, số nhà + đường]
        $north = [
            'KH-HN-01' => ['Siêu thị mini Hồng Hà', 'Nguyễn Thị Lan', '0983111001', 'Hà Nội', '25 Nguyễn Trãi'], 'KH-HN-02' => ['Cửa hàng tiện lợi Bắc Việt', 'Trần Văn Hải', '0983111002', 'Hà Nội', '112 Cầu Giấy'],
            'KH-HN-03' => ['Đại lý Kim Anh', 'Lê Kim Anh', '0983111003', 'Bắc Ninh', '8 Lý Thái Tổ'], 'KH-HN-04' => ['Tạp hoá Thanh Bình', 'Phạm Thanh Bình', '0983111004', 'Hải Phòng', '54 Điện Biên Phủ'],
            'KH-HN-05' => ['Công ty TNHH Thương mại Hạ Long', 'Đỗ Quang Minh', '0983111005', 'Quảng Ninh', '17 Trần Hưng Đạo'], 'KH-HN-06' => ['Nhà thuốc An Khang', 'Vũ Thị Hoa', '0983111006', 'Thái Nguyên', '3 Hoàng Văn Thụ'],
            'KH-HN-07' => ['Siêu thị Ninh Bình Mart', 'Đinh Thị Mai', '0983111007', 'Ninh Bình', '62 Trần Hưng Đạo'], 'KH-HN-08' => ['Cửa hàng điện máy Xứ Thanh', 'Lê Văn Cường', '0983111008', 'Thanh Hóa', '66 Lê Hoàn'],
            'KH-HN-09' => ['Công ty TNHH Thương mại Xứ Nghệ', 'Hồ Sỹ Tuấn', '0983111009', 'Nghệ An', '33 Lê Mao'], 'KH-HN-10' => ['Nhà sách Phố Hiến', 'Bùi Văn Long', '0983111010', 'Hưng Yên', '29 Điện Biên'],
        ];
        $central = [
            'KH-DN-01' => ['Siêu thị mini Sông Hàn', 'Trần Minh Quân', '0935222001', 'Đà Nẵng', '120 Lê Duẩn'], 'KH-DN-02' => ['Cửa hàng tiện lợi Biển Xanh', 'Lê Thị Hạnh', '0935222002', 'Đà Nẵng', '45 Nguyễn Văn Linh'],
            'KH-DN-03' => ['Đại lý Hải Vân', 'Nguyễn Văn Tâm', '0935222003', 'Đà Nẵng', '78 Điện Biên Phủ'], 'KH-DN-04' => ['Đại lý Cố Đô', 'Nguyễn Văn Phước', '0935222004', 'Huế', '14 Lê Lợi'],
            'KH-DN-05' => ['Tạp hoá Sa Huỳnh', 'Phan Thị Thu', '0935222005', 'Quảng Ngãi', '7 Quang Trung'], 'KH-DN-06' => ['Quán cà phê Cửa Việt', 'Trương Công Danh', '0935222006', 'Quảng Trị', '5 Hùng Vương'],
            'KH-DN-07' => ['Nhà thuốc Hà Tĩnh', 'Nguyễn Thị Ngọc', '0935222007', 'Hà Tĩnh', '19 Phan Đình Phùng'], 'KH-DN-08' => ['Nhà sách Phố Núi', 'Đặng Văn Hùng', '0935222008', 'Gia Lai', '12 Hùng Vương'],
            'KH-DN-09' => ['Cửa hàng điện máy Miền Trung', 'Võ Văn Lộc', '0935222009', 'Đà Nẵng', '203 Nguyễn Hữu Thọ'], 'KH-DN-10' => ['Siêu thị Huế Mart', 'Hoàng Thị Thủy', '0935222010', 'Huế', '88 Hùng Vương'],
        ];
        $south = [
            'KH-HCM-01' => ['Siêu thị mini Sài Gòn Xanh', 'Lê Hoàng Nam', '0908333001', 'Hồ Chí Minh', '36 Cách Mạng Tháng Tám'], 'KH-HCM-02' => ['Cửa hàng tạp hoá Bến Thành', 'Nguyễn Thị Cẩm', '0908333002', 'Hồ Chí Minh', '210 Lê Văn Sỹ'],
            'KH-HCM-03' => ['Đại lý Biên Hoà', 'Trần Văn Dũng', '0908333003', 'Đồng Nai', '58 Phạm Văn Thuận'], 'KH-HCM-04' => ['Công ty TNHH Thương mại Tây Ninh', 'Phạm Thị Hồng', '0908333004', 'Tây Ninh', '9 Trần Hưng Đạo'],
            'KH-HCM-05' => ['Nhà thuốc Tây Đô', 'Võ Minh Khoa', '0908333005', 'Cần Thơ', '27 Nguyễn Văn Cừ'], 'KH-HCM-06' => ['Siêu thị Long Xuyên Mart', 'Huỳnh Thị Thu', '0908333006', 'An Giang', '84 Hùng Vương'],
            'KH-HCM-07' => ['Cửa hàng điện máy Vĩnh Long', 'Lâm Văn Tài', '0908333007', 'Vĩnh Long', '15 Phạm Thái Bường'], 'KH-HCM-08' => ['Siêu thị Nha Trang Mart', 'Võ Thị Kim', '0908333008', 'Khánh Hòa', '90 Thống Nhất'],
            'KH-HCM-09' => ['Nhà sách Đà Lạt', 'Đỗ Thị Thủy', '0908333009', 'Lâm Đồng', '21 Phan Đình Phùng'], 'KH-HCM-10' => ['Quán cà phê Buôn Ma Thuột', 'Y Thanh Niê', '0908333010', 'Đắk Lắk', '74 Lê Duẩn'],
        ];
        $addr = function (string $code, string $province, string $street, ?string $wardLike = null): ?string {
            $p = \StockFlow\Domain\VnAddress::findProvince($province);
            if (!$p) {
                return null;
            }
            $wards = array_values(array_filter($p['w'], fn($w) => str_starts_with($w, 'Phường'))) ?: $p['w'];
            if ($wardLike !== null) {
                $hit = array_values(array_filter($wards, fn($w) => str_contains($w, $wardLike)));
                $wards = $hit ?: $wards;
            }
            return $street . ', ' . $wards[crc32($code) % count($wards)] . ', ' . $p['n'];
        };
        $n = 0;
        foreach ([$north, $central, $south] as $group) {
            foreach ($group as $code => [$name, $contact, $phone, $province, $street]) {
                if ($db->value('SELECT 1 FROM doi_tac WHERE ma = ?', [$code]) || $db->value('SELECT 1 FROM doi_tac WHERE LOWER(ten) = LOWER(?)', [$name]) || !($address = $addr($code, $province, $street))) {
                    continue;
                }
                $db->exec("INSERT INTO doi_tac (ma, ten, loai, nguoi_lien_he, so_dien_thoai, dia_chi) VALUES (?,?,'CUSTOMER',?,?,?)", [$code, $name, $contact, $phone, $address]);
                $n++;
            }
        }
        // hai khách mẫu cũ chỉ có địa chỉ tắt («Quận 1, TP.HCM», «Hải Châu, Đà Nẵng»): ghi đủ số nhà, phường, tỉnh
        foreach (['KH-TIENLOI' => ['Hồ Chí Minh', '15 Lê Lợi', 'Sài Gòn'], 'KH-BINHAN' => ['Đà Nẵng', '23 Bạch Đằng', 'Hải Châu']] as $code => [$province, $street, $like]) {
            $cur = (string)$db->value('SELECT dia_chi FROM doi_tac WHERE ma = ?', [$code]);
            if ($cur !== '' && \StockFlow\Domain\VnAddress::parse($cur)['ward'] === '' && ($full = $addr($code, $province, $street, $like))) {
                $n += $db->exec('UPDATE doi_tac SET dia_chi = ? WHERE ma = ?', [$full, $code]);
            }
        }
        // kho phụ trách = kho gần nhất theo địa chỉ
        $khos = $db->all('SELECT id, dia_chi FROM kho WHERE hoat_dong');
        foreach ($db->all("SELECT id, dia_chi FROM doi_tac WHERE loai = 'CUSTOMER' AND kho_id IS NULL") as $r) {
            if (($k = \StockFlow\Domain\VnAddress::nearest($khos, (string)$r['dia_chi'])) !== null) {
                $n += $db->exec('UPDATE doi_tac SET kho_id = ? WHERE id = ?', [$k, (int)$r['id']]);
            }
        }
        return $n;
    }

    public static function run(Db $db, bool $force): int
    {
        if ($db->value('SELECT COUNT(*) FROM kho') > 0 && !$force) {
            echo "Đã có dữ liệu — bỏ qua seed (dùng --force để nạp thêm).\n";
            return 0;
        }
        mt_srand(2026);
        $c = new Container();
        $c->set(Db::class, $db);
        $catalog = $c->get(CatalogService::class);
        $pricing = $c->get(PricingService::class);
        $vouchers = $c->get(VoucherService::class);
        $transfers = $c->get(TransferService::class);
        $counts = $c->get(StockCountService::class);
        $query = $c->get(StockQuery::class);

        // ── kho + tài khoản: 1 tài khoản cấp cao; mỗi kho có 1 quản lý và nhiều nhân viên ──
        $adminId = $db->insert('INSERT INTO nguoi_dung (ten_dang_nhap, ho_ten, mat_khau_ma_hoa, vai_tro) VALUES (?,?,?,?)',
            ['admin', 'Quản trị hệ thống', password_hash(self::PASSWORD, PASSWORD_DEFAULT), AccountRole::Admin->value]);
        $admin = new User($adminId, 'admin', 'Quản trị hệ thống', AccountRole::Admin);
        $wh = [];
        foreach ([['HN01', 'Kho trung tâm Hà Nội', 'KCN Thăng Long, Đông Anh, Hà Nội'],
                  ['DN01', 'Kho Đà Nẵng', 'KCN Hoà Khánh, Liên Chiểu, Đà Nẵng'],
                  ['HCM01', 'Kho TP. Hồ Chí Minh', 'KCN Tân Bình, TP. Hồ Chí Minh']] as [$code, $name, $addr]) {
            $wh[$code] = $catalog->addWarehouse($admin, $code, $name, $addr);
        }
        // kho => [quản lý [tài khoản, họ tên], nhân viên [[tài khoản, họ tên], …]]
        $team = [
            'HN01' => [['hn.quanly', 'Trần Thị Bình'], [['hn.nv1', 'Nguyễn Văn An'], ['hn.nv2', 'Lý Hoàng Nam']]],
            'DN01' => [['dn.quanly', 'Phạm Quốc Dũng'], [['dn.nv1', 'Lê Minh Châu'], ['dn.nv2', 'Ngô Thị Lan']]],
            'HCM01' => [['hcm.quanly', 'Đặng Thu Hương'], [['hcm.nv1', 'Võ Thanh Hà'], ['hcm.nv2', 'Bùi Quang Huy']]],
        ];
        $keeper = [];    // nhân viên đầu tiên của mỗi kho: lập phiếu, kiểm kê
        $approver = [];  // quản lý kho: xác nhận phiếu, duyệt kiểm kê
        foreach ($team as $code => [[$mgrName, $mgrFull], $staff]) {
            $mid = $catalog->createAccount($admin, $mgrName, $mgrFull, self::PASSWORD, AccountRole::Manager, $wh[$code]);
            $approver[$code] = new User($mid, $mgrName, $mgrFull, AccountRole::Manager, $wh[$code]);
            foreach ($staff as $i => [$sName, $sFull]) {
                $sid = $catalog->createAccount($admin, $sName, $sFull, self::PASSWORD, AccountRole::Staff, $wh[$code]);
                if ($i === 0) {
                    $keeper[$code] = new User($sid, $sName, $sFull, AccountRole::Staff, $wh[$code]);
                }
            }
        }

        // ── đội xe & tài xế: mỗi kho 2 xe tải, mỗi xe một tài xế phụ trách (tài xế đi theo xe) ──
        $fleet = $c->get(FleetService::class);
        $drivers = [['Nguyễn Văn Hùng', '001085012345', '0912345601'], ['Trần Quang Vinh', '048090023456', '0912345602'],
                    ['Lê Hữu Nghĩa', '079088034567', '0912345603'], ['Phạm Đức Thắng', '001092045678', '0912345604'],
                    ['Đỗ Minh Tuấn', '079091056789', '0912345605'], ['Hoàng Văn Sơn', '001087067890', '0912345606']];
        $i = 0;   // xe và tài xế do quản lý từng kho thêm
        foreach ([['HN01', ['29C-123.45', 'Xe tải 2 tấn'], ['30H-678.90', 'Xe tải 1,5 tấn']],
                  ['DN01', ['43C-234.56', 'Xe tải 2 tấn'], ['43H-789.01', 'Xe tải 1 tấn']],
                  ['HCM01', ['51C-345.67', 'Xe tải 2,5 tấn'], ['51D-890.12', 'Xe tải 1,5 tấn']]] as [$code, $a, $b]) {
            foreach ([$a, $b] as $v) {
                $d = $drivers[$i++];
                $fleet->addVehicle($approver[$code], $v[0], $v[1], $wh[$code], $fleet->addDriver($approver[$code], $d[0], $d[1], $d[2]));
            }
        }

        // ── đơn vị + mặt hàng + lô ──
        $unit = [];
        foreach ([['cái', 'Cái'], ['cuộn', 'Cuộn'], ['gói', 'Gói'], ['đôi', 'Đôi'], ['chai', 'Chai'], ['hộp', 'Hộp'], ['thùng', 'Thùng'], ['túi', 'Túi'], ['kg', 'Kilogram']] as [$code, $name]) {
            $unit[$code] = $catalog->addUnit($admin, $code, $name);
        }
        // sku, tên, đơn vị, có lô, định mức, tồn đầu cơ sở (hub HN)
        $defs = [
            ['BOX-S', 'Thùng carton 20×15×10 cm', 'cái', 0, 300, 1200], ['BOX-M', 'Thùng carton 30×20×20 cm', 'cái', 0, 300, 1500],
            ['BOX-L', 'Thùng carton 50×35×30 cm', 'cái', 0, 150, 600], ['TAPE-48', 'Băng keo OPP 48 mm × 100 yard', 'cuộn', 0, 200, 900],
            ['BUBBLE', 'Màng xốp hơi 1 m × 100 m', 'cuộn', 0, 40, 160], ['PE-BAG', 'Túi niêm phong PE 25×35 cm', 'gói', 0, 100, 450],
            ['LBL-A6', 'Tem nhãn vận đơn A6', 'cuộn', 0, 120, 500], ['STRETCH', 'Màng co PE 50 cm', 'cuộn', 0, 30, 120],
            ['PALLET', 'Pallet nhựa 1,2 × 1 m', 'cái', 0, 20, 80], ['STRAP', 'Dây đai PP 12 mm', 'cuộn', 0, 25, 100],
            ['GLOVE', 'Găng tay bảo hộ', 'đôi', 0, 80, 300], ['CUTTER', 'Dao rọc giấy', 'cái', 0, 30, 120],
            ['PHN-A15', 'Điện thoại A15 128 GB', 'cái', 0, 40, 160], ['PHN-A25', 'Điện thoại A25 256 GB', 'cái', 0, 25, 90],
            ['EARBUD', 'Tai nghe không dây', 'cái', 0, 60, 240], ['CHG-65W', 'Sạc nhanh 65 W', 'cái', 0, 60, 260],
            ['CAB-USBC', 'Cáp USB-C 1 m', 'cái', 0, 150, 700], ['PWR-20K', 'Pin dự phòng 20.000 mAh', 'cái', 0, 40, 150],
            ['SPK-BT', 'Loa Bluetooth mini', 'cái', 0, 30, 110], ['WATCH-S', 'Đồng hồ thông minh S', 'cái', 0, 20, 70],
            ['MLK-1L', 'Sữa tươi tiệt trùng 1 L', 'hộp', 1, 100, 420], ['NDL-PK', 'Mì ăn liền (thùng 30 gói)', 'thùng', 1, 80, 300],
            ['CFE-3IN1', 'Cà phê hoà tan 3in1 (hộp 20)', 'hộp', 1, 60, 240], ['TEA-GRN', 'Trà xanh túi lọc (hộp 25)', 'hộp', 1, 50, 200],
            ['SOAP-500', 'Nước rửa tay 500 ml', 'chai', 1, 70, 280], ['DET-3KG', 'Bột giặt 3 kg', 'túi', 1, 50, 180],
            ['VIT-C', 'Vitamin C 1000 mg (hộp 20 viên)', 'hộp', 1, 40, 150], ['MASK-50', 'Khẩu trang y tế (hộp 50)', 'hộp', 1, 60, 220],
            ['OIL-1L', 'Dầu ăn 1 L', 'chai', 1, 80, 300], ['SGR-1KG', 'Đường tinh luyện 1 kg', 'kg', 1, 100, 400],
        ];
        // sku => [giá nhập, giá bán chưa thuế (đồng), thuế GTGT %]
        $money = ['BOX-S' => [4200, 5500, 10], 'BOX-M' => [6800, 8800, 10], 'BOX-L' => [12500, 16000, 10], 'TAPE-48' => [14000, 18500, 10], 'BUBBLE' => [95000, 125000, 10],
            'PE-BAG' => [32000, 42000, 10], 'LBL-A6' => [38000, 49000, 10], 'STRETCH' => [78000, 98000, 10], 'PALLET' => [185000, 240000, 10], 'STRAP' => [145000, 185000, 10],
            'GLOVE' => [9500, 13000, 10], 'CUTTER' => [11000, 15500, 10], 'PHN-A15' => [3850000, 4490000, 10], 'PHN-A25' => [6900000, 7990000, 10], 'EARBUD' => [420000, 590000, 10],
            'CHG-65W' => [310000, 429000, 10], 'CAB-USBC' => [38000, 59000, 10], 'PWR-20K' => [380000, 529000, 10], 'SPK-BT' => [520000, 749000, 10], 'WATCH-S' => [1450000, 1990000, 10],
            'MLK-1L' => [21500, 27500, 5], 'NDL-PK' => [92000, 118000, 5], 'CFE-3IN1' => [58000, 79000, 5], 'TEA-GRN' => [34000, 45000, 5], 'SOAP-500' => [26000, 39000, 10],
            'DET-3KG' => [118000, 155000, 10], 'VIT-C' => [64000, 89000, 8], 'MASK-50' => [41000, 59000, 8], 'OIL-1L' => [43000, 54000, 8], 'SGR-1KG' => [19500, 24500, 5]];
        // Quy cách lô: 1 lô (thùng/kiện đóng sẵn) gồm bao nhiêu đơn vị tính; không có trong bảng ⇒ bán lẻ (1)
        $pack = ['BOX-S' => 50, 'BOX-M' => 50, 'BOX-L' => 25, 'TAPE-48' => 6, 'BUBBLE' => 4, 'PE-BAG' => 10, 'LBL-A6' => 10, 'STRETCH' => 6, 'PALLET' => 10,
            'STRAP' => 4, 'GLOVE' => 12, 'CUTTER' => 24, 'PHN-A15' => 10, 'PHN-A25' => 10, 'EARBUD' => 20, 'CHG-65W' => 20, 'CAB-USBC' => 50, 'PWR-20K' => 10,
            'SPK-BT' => 10, 'WATCH-S' => 10, 'MLK-1L' => 12, 'NDL-PK' => 10, 'CFE-3IN1' => 24, 'TEA-GRN' => 24, 'SOAP-500' => 24, 'DET-3KG' => 6,
            'VIT-C' => 20, 'MASK-50' => 40, 'OIL-1L' => 12, 'SGR-1KG' => 20];
        $catDefs = ['PKG' => 'Vật tư đóng gói', 'TOOL' => 'Dụng cụ & bảo hộ', 'ELEC' => 'Điện tử', 'FOOD' => 'Thực phẩm & đồ uống', 'HOME' => 'Hoá mỹ phẩm & gia dụng', 'MED' => 'Y tế & sức khoẻ'];
        $cat = [];
        foreach ($catDefs as $code => $name) {
            $cat[$code] = $catalog->addCategory($admin, $code, $name);
        }
        $catOf = ['GLOVE' => 'TOOL', 'CUTTER' => 'TOOL', 'PHN-A15' => 'ELEC', 'PHN-A25' => 'ELEC', 'EARBUD' => 'ELEC', 'CHG-65W' => 'ELEC', 'CAB-USBC' => 'ELEC',
            'PWR-20K' => 'ELEC', 'SPK-BT' => 'ELEC', 'WATCH-S' => 'ELEC', 'MLK-1L' => 'FOOD', 'NDL-PK' => 'FOOD', 'CFE-3IN1' => 'FOOD', 'TEA-GRN' => 'FOOD',
            'OIL-1L' => 'FOOD', 'SGR-1KG' => 'FOOD', 'SOAP-500' => 'HOME', 'DET-3KG' => 'HOME', 'VIT-C' => 'MED', 'MASK-50' => 'MED'];
        $warranty = self::WARRANTY_MONTHS;
        $item = [];   // sku => ['id'=>, 'batches'=>[batchId,…]]
        foreach ($defs as [$sku, $name, $un, $lot, $reorder]) {
            $id = $catalog->addItem($admin, $sku, $name, $unit[$un], (bool)$lot, $reorder, $pack[$sku] ?? 1, $cat[$catOf[$sku] ?? 'PKG']);
            $batches = [];
            if ($lot) {
                $soon = in_array($sku, ['MLK-1L', 'VIT-C'], true);   // vài mặt hàng có lô sắp hết hạn để minh hoạ cảnh báo
                foreach ([['L2601A', $soon ? '+25 days' : '+5 months'], ['L2602B', '+11 months']] as [$no, $rel]) {
                    $batches[] = $catalog->addBatch($admin, $id, $no, gmdate('Y-m-d', strtotime($rel)));
                }
            } else {
                $batches[] = (int)$db->value('SELECT id FROM lo_hang WHERE mat_hang_id = ?', [$id]);
            }
            if (isset($money[$sku])) {   // giá gốc + thuế do quản trị đặt; giá bán do quản lý từng kho đặt bên dưới
                $pricing->setCost($admin, $id, $money[$sku][0], $money[$sku][2], 'Giá gốc khởi tạo');
            }
            $db->exec('UPDATE mat_hang SET ma_vach = ? WHERE id = ?', [self::barcode($sku), $id]);
            if (isset($warranty[$sku])) {   // điện tử / linh kiện có bảo hành; hàng tiêu dùng thì không
                $db->exec('UPDATE mat_hang SET bao_hanh_thang = ? WHERE id = ?', [$warranty[$sku], $id]);
            }
            $item[$sku] = ['id' => $id, 'batches' => $batches, 'hub' => end($defs[array_search($sku, array_column($defs, 0))])];
        }

        // ── giá bán riêng từng kho do quản lý kho đặt: HN theo giá niêm yết, HCM cao hơn 3%, DN thấp hơn 2% ──
        foreach (['HN01' => 1.0, 'HCM01' => 1.03, 'DN01' => 0.98] as $code => $k) {
            foreach ($money as $sku => [$cost, $sell]) {
                if (isset($item[$sku])) {
                    $pricing->setSellPrice($approver[$code], $wh[$code], $item[$sku]['id'], (int)(round($sell * $k / 100) * 100), 'Giá bán khởi tạo của kho');
                }
            }
        }

        // ── đối tác: nhà cung cấp theo danh mục hàng + khách hàng ──
        $partners = $c->get(PartnerService::class);
        $suppliers = [['NCC-BAOBI', 'Công ty Bao bì Sài Gòn', 'Nguyễn Thị Lan', '0281234567', 'kinhdoanh@baobisg.example', 'KCN Tân Bình, TP.HCM', ['PKG']],
            ['NCC-GIAYHN', 'Công ty Giấy & Bao bì Hà Nội', 'Trần Văn Bình', '0241234568', 'banhang@giayhn.example', 'KCN Quang Minh, Hà Nội', ['PKG']],
            ['NCC-DIENTU', 'Công ty Điện tử Viễn Thông Việt', 'Lê Quốc Huy', '0241234569', 'sales@dientuviet.example', 'Cầu Giấy, Hà Nội', ['ELEC']],
            ['NCC-THUCPHAM', 'Công ty Thực phẩm Việt Hương', 'Phạm Thị Mai', '0281234570', 'dathang@vthuong.example', 'KCN Vĩnh Lộc, TP.HCM', ['FOOD']],
            ['NCC-HOAMY', 'Công ty Hoá mỹ phẩm Hoa Sen', 'Đỗ Thanh Tùng', '0231234571', 'lienhe@hoasen.example', 'Hải Châu, Đà Nẵng', ['HOME']],
            ['NCC-DUOC', 'Công ty Dược phẩm An Khang', 'Võ Minh Châu', '0281234572', 'cskh@ankhang.example', 'Quận 7, TP.HCM', ['MED']],
            ['NCC-BAOHO', 'Công ty Thiết bị bảo hộ Minh Phát', 'Hoàng Đức Anh', '0241234573', 'info@minhphat.example', 'Thanh Xuân, Hà Nội', ['TOOL', 'PKG']]];
        $itemsOf = fn(array $cats) => array_values(array_filter(array_keys($catOf + array_fill_keys(array_column($defs, 0), 'PKG')), fn($sku) => in_array($catOf[$sku] ?? 'PKG', $cats, true)));
        $bySupplier = [];
        foreach ($suppliers as [$code, $name, $contact, $phone, $email, $addr, $cats]) {
            $partners->add($admin, $code, $name, 'SUPPLIER', $contact, $phone, $email, $addr, '');
            foreach ($itemsOf($cats) as $sku) {
                if (isset($item[$sku])) {
                    $bySupplier[$sku][] = (int)$db->value('SELECT id FROM doi_tac WHERE ma = ?', [$code]);
                }
            }
        }
        foreach ($bySupplier as $sku => $ids) {
            $partners->setItemSuppliers($admin, $item[$sku]['id'], $ids);
        }
        $partners->add($admin, 'KH-TIENLOI', 'Chuỗi cửa hàng Tiện Lợi 24h', 'CUSTOMER', 'Ngô Thu Hà', '0901234574', 'muahang@tienloi24.example', 'Quận 1, TP.HCM', 'Khách mua sỉ');
        $partners->add($admin, 'KH-BINHAN', 'Siêu thị mini Bình An', 'CUSTOMER', 'Đặng Văn Khoa', '0901234575', '', 'Hải Châu, Đà Nẵng', '');

        // ── tiện ích ──
        $post = function (string $code, VoucherType $type, array $lines, string $partner, string $note = '') use ($vouchers, $keeper, $approver, $wh): array {
            $v = $vouchers->createDraft($keeper[$code], $type, $wh[$code], $lines, $partner, $note, null)['voucher'];
            $vouchers->confirm($approver[$code], (int)$v['id']);
            return $v;
        };
        $line = fn(string $sku, int $bi, int $qty) => ['mat_hang_id' => $item[$sku]['id'], 'lo_hang_id' => $item[$sku]['batches'][$bi], 'so_luong' => $qty];
        $stock = function (string $code, string $sku, int $bi) use ($db, $item, $wh): int {
            return (int)$db->value('SELECT COALESCE(SUM(bien_dong),0) FROM so_phat_sinh WHERE kho_id = ? AND mat_hang_id = ? AND lo_hang_id = ?',
                [$wh[$code], $item[$sku]['id'], $item[$sku]['batches'][$bi]]);
        };

        // ── 1) nhập tồn đầu: HN đủ 30 mặt hàng, HCM ~80%, DN ~60% ──
        $groups = [array_slice($defs, 0, 12), array_slice($defs, 12, 8), array_slice($defs, 20, 10)];
        $scale = ['HN01' => 1.0, 'HCM01' => 0.55, 'DN01' => 0.32];
        $suppliers = ['Công ty TNHH Bao bì Hoà Phát', 'NPP Điện tử Phong Vũ', 'Công ty CP Thực phẩm Á Châu'];
        foreach ($scale as $code => $k) {
            foreach ($groups as $gi => $group) {
                $lines = [];
                foreach ($group as $d) {
                    $sku = $d[0];
                    if ($code === 'DN01' && mt_rand(1, 100) <= 35) {
                        continue; // DN không kinh doanh đủ danh mục
                    }
                    foreach ($item[$sku]['batches'] as $bi => $_) {
                        $lines[] = $line($sku, $bi, max(5, (int)round($d[5] * $k / count($item[$sku]['batches']) * (0.8 + mt_rand(0, 40) / 100))));
                    }
                }
                $post($code, VoucherType::Receipt, $lines, $suppliers[$gi], 'Nhập tồn đầu kỳ');
            }
        }

        // ── 2) xuất bán / tiêu hao ──
        $skus = array_keys($item);
        foreach (['HN01' => 9, 'HCM01' => 8, 'DN01' => 6] as $code => $vouchersCount) {
            for ($i = 0; $i < $vouchersCount; $i++) {
                $lines = [];
                foreach (array_rand(array_flip($skus), 3) as $sku) {
                    $bi = 0;   // xuất theo hạn dùng: lô hết hạn trước (lô đầu) xuất trước
                    $have = $stock($code, $sku, $bi);
                    if ($have >= 4) {
                        $lines[$sku . $bi] = $line($sku, $bi, mt_rand(2, max(2, (int)($have * 0.25))));
                    }
                }
                if ($lines) {
                    $post($code, VoucherType::Issue, array_values($lines), 'Đơn xuất SO-' . (1000 + mt_rand(1, 899)), 'Xuất giao khách');
                }
            }
        }

        // ── 3) điều chuyển giữa các kho (giám sát lập, người duyệt kho đi xác nhận) ──
        $route = [['HN01', 'HCM01'], ['HN01', 'DN01'], ['HCM01', 'DN01'], ['HN01', 'HCM01'], ['HCM01', 'HN01'], ['HN01', 'DN01'], ['DN01', 'HCM01']];
        foreach ($route as [$from, $to]) {
            $lines = [];
            foreach (array_rand(array_flip($skus), 4) as $sku) {
                $bi = 0;   // điều chuyển đi cũng lấy lô hết hạn trước
                $have = $stock($from, $sku, $bi);
                if ($have >= 8) {
                    $lines[$sku . $bi] = $line($sku, $bi, mt_rand(3, max(3, (int)($have * 0.3))));
                }
            }
            if ($lines) {
                $t = $transfers->createDraft($keeper[$from], $wh[$from], $wh[$to], array_values($lines), "Cân đối tồn $from → $to", null)['voucher'];
                $transfers->confirm($approver[$from], (int)$t['id']);
            }
        }

        // ── 4) nhập nhầm ở DN → phiếu nhập xuất điều chỉnh đảo (có lý do, giữ nguyên lịch sử) ──
        $wrong = $post('DN01', VoucherType::Receipt, [$line('CAB-USBC', 0, 900)], 'NPP Điện tử Phong Vũ', 'Nhập cáp USB-C');
        $vouchers->correct($approver['DN01'], (int)$wrong['id'], 'Nhập nhầm: thực nhận 90, nhân viên gõ thừa một số 0');
        $post('DN01', VoucherType::Receipt, [$line('CAB-USBC', 0, 90)], 'NPP Điện tử Phong Vũ', 'Nhập lại đúng số lượng');

        // ── 5) kiểm kê: DN (đã chốt, có chênh lệch), HCM (chờ duyệt), HN (đang đếm) ──
        $closeCount = function (string $code, array $variances, bool $approve) use ($counts, $keeper, $approver, $wh) {
            $id = $counts->open($keeper[$code], $wh[$code], 'Kiểm kê định kỳ cuối tháng');
            $c = $counts->find($id);
            $counted = [];
            $reasons = [];
            foreach ($c['lines'] as $i => $l) {
                $key = $l['mat_hang_id'] . ':' . $l['lo_hang_id'];
                $delta = $variances[$i] ?? 0;
                $counted[$key] = max(0, (int)$l['so_luong_so_sach'] + $delta);
                if ($delta !== 0 && (int)$l['so_luong_so_sach'] + $delta >= 0) {
                    $reasons[$key] = $delta < 0 ? ['Hư hỏng / vỡ', 'Thất thoát / mất hàng', 'Nhập sai số lượng'][$i % 3] : 'Chưa ghi phiếu nhập xuất';
                }
            }
            $counts->submit($keeper[$code], $id, $counted, $reasons);
            if ($approve) {
                $counts->approve($approver[$code], $id);
            }
            return $id;
        };
        $closeCount('DN01', [2 => -3, 7 => -1, 12 => 4, 19 => -2], true);
        $closeCount('HCM01', [1 => -2, 8 => 3, 15 => -5], false);
        $hn = $counts->open($keeper['HN01'], $wh['HN01'], 'Kiểm kê đột xuất khu hàng điện tử');
        $lines = $counts->find($hn)['lines'];
        $partial = [];
        foreach (array_slice($lines, 0, 8) as $l) {
            $partial[$l['mat_hang_id'] . ':' . $l['lo_hang_id']] = (int)$l['so_luong_so_sach'];
        }
        $counts->save($keeper['HN01'], $hn, $partial);

        // ── 6) vài phiếu nháp chờ duyệt ──
        $vouchers->createDraft($keeper['HN01'], VoucherType::Issue, $wh['HN01'], [$line('BOX-M', 0, 120), $line('TAPE-48', 0, 60)], 'Đơn xuất SO-1999', 'Chờ duyệt', null);
        $vouchers->createDraft($keeper['HCM01'], VoucherType::Receipt, $wh['HCM01'], [$line('PHN-A15', 0, 40), $line('EARBUD', 0, 60)], 'NPP Điện tử Phong Vũ', 'Hàng về sáng nay', null);
        $transfers->createDraft($keeper['DN01'], $wh['DN01'], $wh['HCM01'], [$line('BOX-S', 0, 25)], 'Chuyển bao bì dư', null);

        $m = (int)$db->value('SELECT COUNT(*) FROM so_phat_sinh');
        $v = (int)$db->value('SELECT COUNT(*) FROM phieu_nhap_xuat');
        $rec = $query->reconcile(array_values($wh));
        printf("✔ Đã nạp dữ liệu mẫu: 3 kho · 30 mặt hàng · %d phiếu nhập xuất · %d dòng sổ phát sinh · đối chiếu: %s\n", $v, $m, $rec['mismatches'] === 0 ? 'KHỚP' : 'LỆCH!');
        printf("  Đăng nhập demo (mật khẩu chung: %s): admin (cấp cao) · hn.quanly · dn.quanly · hcm.quanly (quản lý) · hn.nv1 · hn.nv2 · dn.nv1 · dn.nv2 · hcm.nv1 · hcm.nv2 (nhân viên)\n", self::PASSWORD);
        return $rec['mismatches'] === 0 ? 0 : 1;
    }
}
