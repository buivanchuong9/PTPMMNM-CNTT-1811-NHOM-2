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
        $driverIds = array_map(fn($d) => $fleet->addDriver($admin, $d[0], $d[1], $d[2]), $drivers);
        $i = 0;
        foreach ([['HN01', ['29C-123.45', 'Xe tải 2 tấn'], ['30H-678.90', 'Xe tải 1,5 tấn']],
                  ['DN01', ['43C-234.56', 'Xe tải 2 tấn'], ['43H-789.01', 'Xe tải 1 tấn']],
                  ['HCM01', ['51C-345.67', 'Xe tải 2,5 tấn'], ['51D-890.12', 'Xe tải 1,5 tấn']]] as [$code, $a, $b]) {
            $fleet->addVehicle($admin, $a[0], $a[1], $wh[$code], $driverIds[$i++]);
            $fleet->addVehicle($admin, $b[0], $b[1], $wh[$code], $driverIds[$i++]);
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
