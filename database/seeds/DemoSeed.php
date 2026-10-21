<?php
declare(strict_types=1);

namespace StockFlow\Seeds;

use StockFlow\Core\Container;
use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Role;
use StockFlow\Domain\StockRules;
use StockFlow\Domain\User;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\AuthService;
use StockFlow\Service\CatalogService;
use StockFlow\Service\StockCountService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherService;

/**
 * Dữ liệu demo: 3 kho, 30 mặt hàng (10 mặt hàng có lô/hạn dùng), ≥200 dòng sổ phát sinh,
 * có điều chuyển, chênh lệch kiểm kê, chứng từ điều chỉnh. Mọi thao tác đi qua chính các service
 * (không INSERT tắt) nên dữ liệu mẫu luôn nhất quán với quy tắc nghiệp vụ. Xác định (seed cố định).
 */
final class DemoSeed
{
    public const PASSWORD = 'demo1234';

    public static function run(Db $db, bool $force): int
    {
        if ($db->value('SELECT COUNT(*) FROM warehouses') > 0 && !$force) {
            echo "Đã có dữ liệu — bỏ qua seed (dùng --force để nạp thêm).\n";
            return 0;
        }
        mt_srand(2026);
        $c = new Container();
        $c->set(Db::class, $db);
        $catalog = $c->get(CatalogService::class);
        $vouchers = $c->get(VoucherService::class);
        $transfers = $c->get(TransferService::class);
        $counts = $c->get(StockCountService::class);
        $query = $c->get(StockQuery::class);

        // ── người dùng + kho + quyền ──
        $adminId = $db->insert('INSERT INTO users (username, full_name, password_hash, is_admin) VALUES (?,?,?,1)',
            ['admin', 'Quản trị hệ thống', password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        $admin = new User($adminId, 'admin', 'Quản trị hệ thống', true);
        $wh = [];
        foreach ([['HN01', 'Kho trung tâm Hà Nội', 'KCN Thăng Long, Đông Anh, Hà Nội'],
                  ['DN01', 'Kho Đà Nẵng', 'KCN Hoà Khánh, Liên Chiểu, Đà Nẵng'],
                  ['HCM01', 'Kho TP. Hồ Chí Minh', 'KCN Tân Bình, TP. Hồ Chí Minh']] as [$code, $name, $addr]) {
            $wh[$code] = $catalog->addWarehouse($admin, $code, $name, $addr);
        }
        $people = [
            'hn.keeper' => ['Nguyễn Văn An', 'HN01', [Role::Keeper, Role::Counter]],
            'hn.approver' => ['Trần Thị Bình', 'HN01', [Role::Approver]],
            'dn.keeper' => ['Lê Minh Châu', 'DN01', [Role::Keeper, Role::Counter]],
            'dn.approver' => ['Phạm Quốc Dũng', 'DN01', [Role::Approver]],
            'hcm.keeper' => ['Võ Thanh Hà', 'HCM01', [Role::Keeper, Role::Counter]],
            'hcm.approver' => ['Đặng Thu Hương', 'HCM01', [Role::Approver]],
        ];
        $u = [];
        foreach ($people as $username => [$full, $code, $roles]) {
            $id = $catalog->addUser($admin, $username, $full, self::PASSWORD, false);
            foreach ($roles as $role) {
                $catalog->grant($admin, $id, $wh[$code], $role);
            }
            $u[$username] = new User($id, $username, $full, false);
        }
        $gid = $catalog->addUser($admin, 'giamsat', 'Hoàng Gia Khánh', self::PASSWORD, false);
        foreach ($wh as $wid) {
            foreach ([Role::Keeper, Role::Approver, Role::Counter] as $role) {
                $catalog->grant($admin, $gid, $wid, $role);
            }
        }
        $u['giamsat'] = new User($gid, 'giamsat', 'Hoàng Gia Khánh', false);
        $keeper = ['HN01' => $u['hn.keeper'], 'DN01' => $u['dn.keeper'], 'HCM01' => $u['hcm.keeper']];
        $approver = ['HN01' => $u['hn.approver'], 'DN01' => $u['dn.approver'], 'HCM01' => $u['hcm.approver']];

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
        $item = [];   // sku => ['id'=>, 'batches'=>[batchId,…]]
        foreach ($defs as [$sku, $name, $un, $lot, $reorder]) {
            $id = $catalog->addItem($admin, $sku, $name, $unit[$un], (bool)$lot, $reorder);
            $batches = [];
            if ($lot) {
                foreach ([['L2601A', '+5 months'], ['L2602B', '+11 months']] as [$no, $rel]) {
                    $batches[] = $catalog->addBatch($admin, $id, $no, gmdate('Y-m-d', strtotime($rel)));
                }
            } else {
                $batches[] = (int)$db->value('SELECT id FROM batches WHERE item_id = ?', [$id]);
            }
            $item[$sku] = ['id' => $id, 'batches' => $batches, 'hub' => end($defs[array_search($sku, array_column($defs, 0))])];
        }

        // ── tiện ích ──
        $post = function (string $code, VoucherType $type, array $lines, string $partner, string $note = '') use ($vouchers, $keeper, $approver, $wh): array {
            $v = $vouchers->createDraft($keeper[$code], $type, $wh[$code], $lines, $partner, $note, null)['voucher'];
            $vouchers->confirm($approver[$code], (int)$v['id']);
            return $v;
        };
        $line = fn(string $sku, int $bi, int $qty) => ['item_id' => $item[$sku]['id'], 'batch_id' => $item[$sku]['batches'][$bi], 'qty' => $qty];
        $stock = function (string $code, string $sku, int $bi) use ($db, $item, $wh): int {
            return (int)$db->value('SELECT COALESCE(SUM(qty_change),0) FROM stock_movements WHERE warehouse_id = ? AND item_id = ? AND batch_id = ?',
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
                    $bi = mt_rand(0, count($item[$sku]['batches']) - 1);
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
                $bi = mt_rand(0, count($item[$sku]['batches']) - 1);
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

        // ── 4) nhập nhầm ở DN → chứng từ điều chỉnh đảo (có lý do, giữ nguyên lịch sử) ──
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
                $key = $l['item_id'] . ':' . $l['batch_id'];
                $delta = $variances[$i] ?? 0;
                $counted[$key] = max(0, (int)$l['system_qty'] + $delta);
                if ($delta !== 0 && (int)$l['system_qty'] + $delta >= 0) {
                    $reasons[$key] = $delta < 0 ? ['Hư hỏng / vỡ', 'Thất thoát / mất hàng', 'Nhập sai số lượng'][$i % 3] : 'Chưa ghi chứng từ';
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
            $partial[$l['item_id'] . ':' . $l['batch_id']] = (int)$l['system_qty'];
        }
        $counts->save($keeper['HN01'], $hn, $partial);

        // ── 6) vài phiếu nháp chờ duyệt ──
        $vouchers->createDraft($keeper['HN01'], VoucherType::Issue, $wh['HN01'], [$line('BOX-M', 0, 120), $line('TAPE-48', 0, 60)], 'Đơn xuất SO-1999', 'Chờ duyệt', null);
        $vouchers->createDraft($keeper['HCM01'], VoucherType::Receipt, $wh['HCM01'], [$line('PHN-A15', 0, 40), $line('EARBUD', 0, 60)], 'NPP Điện tử Phong Vũ', 'Hàng về sáng nay', null);
        $transfers->createDraft($keeper['DN01'], $wh['DN01'], $wh['HCM01'], [$line('BOX-S', 0, 25)], 'Chuyển bao bì dư', null);

        $m = (int)$db->value('SELECT COUNT(*) FROM stock_movements');
        $v = (int)$db->value('SELECT COUNT(*) FROM vouchers');
        $rec = $query->reconcile(array_values($wh));
        printf("✔ Đã nạp dữ liệu mẫu: 3 kho · 30 mặt hàng · %d chứng từ · %d dòng sổ phát sinh · đối chiếu: %s\n", $v, $m, $rec['mismatches'] === 0 ? 'KHỚP' : 'LỆCH!');
        printf("  Đăng nhập demo (mật khẩu chung: %s): admin · hn.keeper · hn.approver · dn.keeper · dn.approver · hcm.keeper · hcm.approver · giamsat\n", self::PASSWORD);
        return $rec['mismatches'] === 0 ? 0 : 1;
    }
}
