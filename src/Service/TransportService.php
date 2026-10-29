<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\TransportRules;

/**
 * Chọn xe có đủ điều kiện chở một lô hàng: tải trọng, thể tích thùng, hàng có lọt lòng thùng không, đăng kiểm / bảo hiểm còn hạn,
 * khổ xe trong giới hạn đường bộ, và tài xế có giấy phép lái xe đúng hạng, còn hạn, đủ tuổi.
 *
 * Kết quả kiểm tra là danh sách vấn đề: «block» (chặn xuất kho — vi phạm thật) và «warn» (chưa khai số liệu — cảnh báo để khai dần,
 * không chặn để dữ liệu cũ vẫn dùng được). Các hàm nhận mảng dòng của bảng xe, tai_xe, mat_hang để dùng cả ở trang phiếu lẫn khi ghi sổ.
 */
final class TransportService
{
    public function __construct(private Db $db, private SettingsService $settings) {}

    /** Quy định đang áp dụng: mặc định của dự án, ghi đè bằng giá trị quản trị đã chỉnh. @return array<string,int> */
    public function limits(): array
    {
        return $this->settings->transport();
    }

    /**
     * Tổng tải của một lô hàng. $lines: [mat_hang_id, so_luong]. Cân thật = Σ số lượng × cân nặng đơn vị; thể tích = Σ số lượng × (dài × rộng × cao);
     * trọng lượng quy đổi = thể tích × hệ số (kg/m³) — cước vận tải tính theo số lớn hơn giữa cân thật và quy đổi.
     * @param list<array<string,mixed>> $lines
     * @return array{weight_kg:float,volume_m3:float,chargeable_kg:float,items:list<array<string,mixed>>,no_weight:list<string>,no_size:list<string>}
     */
    public function loadOf(array $lines): array
    {
        $lim = $this->limits();
        $w = $v = 0.0;
        $items = [];
        $noWeight = $noSize = [];
        foreach ($lines as $l) {
            $item = $this->db->one('SELECT ma_hang, ten, quy_cach_lo, khoi_luong_g, dai_mm, rong_mm, cao_mm, kien_g, kien_dai_mm, kien_rong_mm, kien_cao_mm FROM mat_hang WHERE id = ?', [(int)$l['mat_hang_id']]);
            if (!$item) {
                continue;
            }
            $qty = (int)$l['so_luong'];
            $pack = max(1, (int)$item['quy_cach_lo']);
            $boxes = intdiv($qty, $pack);   // số kiện nguyên (thùng đóng sẵn); phần còn lại là hàng lẻ
            $loose = $qty - $boxes * $pack;
            $unitG = (int)$item['khoi_luong_g'];
            $hasW = $unitG > 0 || (int)$item['kien_g'] > 0;
            $hasD = (int)$item['dai_mm'] > 0 && (int)$item['rong_mm'] > 0 && (int)$item['cao_mm'] > 0;
            $hasKienD = (int)$item['kien_dai_mm'] > 0 && (int)$item['kien_rong_mm'] > 0 && (int)$item['kien_cao_mm'] > 0;
            $unitM3 = $hasD ? TransportRules::cubicMeters((int)$item['dai_mm'], (int)$item['rong_mm'], (int)$item['cao_mm']) : 0.0;
            // 1 kiện: dùng số đã khai (cân cả bì, kích thước thùng); chưa khai thì tự tính từ các đơn vị bên trong
            $boxG = (int)$item['kien_g'] > 0 ? (int)$item['kien_g'] : $pack * $unitG;
            $boxM3 = $hasKienD ? TransportRules::cubicMeters((int)$item['kien_dai_mm'], (int)$item['kien_rong_mm'], (int)$item['kien_cao_mm']) : $pack * $unitM3;
            $kg = $hasW ? ($boxes * $boxG + $loose * $unitG) / 1000 : 0.0;
            $m3 = ($hasD || $hasKienD) ? $boxes * $boxM3 + $loose * $unitM3 : 0.0;
            $w += $kg;
            $v += $m3;
            if (!$hasW) {
                $noWeight[$item['ma_hang']] = $item['ma_hang'];
            }
            if (!$hasD && !($hasKienD && $loose === 0)) {
                $noSize[$item['ma_hang']] = $item['ma_hang'];
            }
            // kiện vật lý lớn nhất của dòng này: thùng (nếu có ít nhất một kiện nguyên và đã khai kích thước thùng), ngược lại món lẻ
            $dims = $boxes > 0 && $hasKienD ? [(int)$item['kien_dai_mm'], (int)$item['kien_rong_mm'], (int)$item['kien_cao_mm']] : ($hasD ? [(int)$item['dai_mm'], (int)$item['rong_mm'], (int)$item['cao_mm']] : null);
            $items[] = ['ma' => $item['ma_hang'], 'ten' => $item['ten'], 'qty' => $qty, 'pack' => $pack, 'boxes' => $boxes, 'loose' => $loose, 'kg' => $kg, 'm3' => $m3, 'dims' => $dims];
        }
        return ['weight_kg' => $w, 'volume_m3' => $v, 'chargeable_kg' => max($w, $v * $lim['quy_doi_kg_m3']), 'items' => $items,
                'no_weight' => array_values($noWeight), 'no_size' => array_values($noSize)];
    }

    /**
     * Kiểm tra một xe (cùng tài xế phụ trách) có chở được lô hàng $load không.
     * @param array<string,mixed> $vehicle dòng bảng xe · @param ?array<string,mixed> $driver dòng bảng tai_xe (tài xế phụ trách) · @param array<string,mixed> $load kết quả loadOf()
     * @return array{blocks:list<string>,warns:list<string>,load:list<string>} load = phần của blocks do QUY MÔ LÔ HÀNG (quá tải, thiếu chỗ, không lọt thùng) — chia thêm xe thì hết; các lỗi còn lại (giấy tờ, khổ xe, bằng lái) là lỗi của chính xe / tài xế
     */
    public function check(array $vehicle, ?array $driver, array $load, ?string $today = null, bool $needDriver = true): array
    {
        $today ??= date('Y-m-d');
        $lim = $this->limits();
        $blocks = $warns = $byLoad = [];
        $kg = fn(float $x) => number_format($x, $x < 10 ? 1 : 0, ',', '.') . ' kg';
        $m3 = fn(float $x) => number_format($x, 2, ',', '.') . ' m³';
        $date = fn(string $d) => date('d/m/Y', strtotime($d));

        // tải trọng
        $cap = (int)($vehicle['tai_trong_kg'] ?? 0);
        if ($cap <= 0) {
            $warns[] = 'Xe chưa khai tải trọng — chưa kiểm được quá tải';
        } elseif ($load['weight_kg'] > $cap) {
            $blocks[] = $byLoad[] = 'Quá tải: hàng nặng ' . $kg($load['weight_kg']) . ', xe chỉ chở được ' . $kg((float)$cap);
        }
        if ($load['no_weight']) {
            $warns[] = 'Hàng chưa khai cân nặng (' . implode(', ', array_slice($load['no_weight'], 0, 3)) . (count($load['no_weight']) > 3 ? '…' : '') . ') — tải thật có thể lớn hơn số tính';
        }
        // thể tích thùng + hàng có lọt thùng không
        $box = [(int)($vehicle['thung_dai_mm'] ?? 0), (int)($vehicle['thung_rong_mm'] ?? 0), (int)($vehicle['thung_cao_mm'] ?? 0)];
        if (in_array(0, $box, true)) {
            $warns[] = 'Xe chưa khai kích thước thùng — chưa kiểm được thể tích';
        } else {
            $usable = TransportRules::cubicMeters(...$box) * $lim['xep_hang_pct'] / 100;
            if ($load['volume_m3'] > $usable) {
                $blocks[] = $byLoad[] = 'Thiếu chỗ: hàng chiếm ' . $m3($load['volume_m3']) . ', thùng xếp được khoảng ' . $m3($usable);
            }
            foreach ($load['items'] as $it) {
                if ($it['dims'] && !TransportRules::fits($it['dims'], $box)) {
                    $blocks[] = $byLoad[] = 'Hàng ' . $it['ma'] . ' (' . implode(' × ', array_map(fn($d) => rtrim(rtrim(number_format($d / 10, 1, '.', ''), '0'), '.'), $it['dims'])) . ' cm) không lọt lòng thùng';
                }
            }
        }
        if ($load['no_size']) {
            $warns[] = 'Hàng chưa khai kích thước (' . implode(', ', array_slice($load['no_size'], 0, 3)) . (count($load['no_size']) > 3 ? '…' : '') . ') — chưa tính đủ thể tích';
        }
        // giấy tờ xe
        foreach (['han_dang_kiem' => 'đăng kiểm', 'han_bao_hiem' => 'bảo hiểm TNDS'] as $col => $name) {
            if ($col === 'han_dang_kiem' && ($vehicle['kieu_xe'] ?? 'TAI') === 'MAY') {
                continue;   // xe máy không phải đăng kiểm định kỳ
            }
            $d = (string)($vehicle[$col] ?? '');
            if ($d === '') {
                $warns[] = "Xe chưa khai hạn $name";
            } elseif ($d < $today) {
                $blocks[] = "Xe hết hạn $name từ " . $date($d);
            } elseif ($d <= date('Y-m-d', strtotime("$today +{$lim['canh_bao_han_ngay']} days"))) {
                $warns[] = "Xe sắp hết hạn $name (" . $date($d) . ')';
            }
        }
        // khổ xe
        foreach (['tong_cao_mm' => ['cao_max_mm', 'cao'], 'tong_rong_mm' => ['rong_max_mm', 'rộng'], 'tong_dai_mm' => ['dai_max_mm', 'dài']] as $col => [$limit, $name]) {
            if ((int)($vehicle[$col] ?? 0) > $lim[$limit]) {
                $blocks[] = "Xe quá khổ giới hạn đường bộ ($name " . number_format((int)$vehicle[$col] / 1000, 2, ',', '.') . ' m > ' . number_format($lim[$limit] / 1000, 2, ',', '.') . ' m) — cần giấy phép lưu hành riêng';
            }
        }
        if (!$needDriver) {   // chỉ xét riêng xe (danh sách chọn xe): tài xế được chọn sau, có thể là người khác lái thay
            return ['blocks' => $blocks, 'warns' => $warns, 'load' => $byLoad];
        }
        if (!$driver) {
            return ['blocks' => array_merge(['Chưa chọn tài xế lái xe'], $blocks), 'warns' => $warns, 'load' => $byLoad];
        }
        $dr = $this->driverIssues($driver, $vehicle, $today);
        return ['blocks' => array_merge($blocks, $dr['blocks']), 'warns' => array_merge($warns, $dr['warns']), 'load' => $byLoad];
    }

    /**
     * Một tài xế có được lái xe này không: bằng đúng hạng cho xe (theo GVW, có rơ moóc), còn hạn, đủ tuổi tối thiểu của hạng, còn điểm, khám sức khoẻ còn hạn.
     * Không truyền $vehicle thì chỉ xét riêng hồ sơ tài xế (không xét «đúng hạng cho xe»).
     * @param array<string,mixed> $driver dòng tai_xe · @param ?array<string,mixed> $vehicle dòng xe
     * @return array{blocks:list<string>,warns:list<string>}
     */
    private function driverIssues(array $driver, ?array $vehicle, string $today): array
    {
        $lim = $this->limits();
        $blocks = $warns = [];
        $date = fn(string $d) => date('d/m/Y', strtotime($d));
        $gvw = (int)($vehicle['gvw_kg'] ?? 0);
        $need = $vehicle ? TransportRules::needFor($vehicle) : null;
        $isMoto = ($vehicle['kieu_xe'] ?? 'TAI') === 'MAY';
        $class = (string)($driver['hang_gplx'] ?? '');
        if ($vehicle && $need === null) {
            $warns[] = $isMoto ? 'Xe máy chưa khai dung tích xi-lanh (cm³) — chưa kiểm được hạng bằng lái cần có (A1 / A)' : 'Xe chưa khai khối lượng toàn bộ (GVW) — chưa kiểm được hạng bằng lái cần có';
        }
        if ($class === '' || (string)($driver['so_gplx'] ?? '') === '') {
            $warns[] = 'Tài xế ' . $driver['ho_ten'] . ' chưa khai giấy phép lái xe';
        } else {
            if ($need !== null && !TransportRules::covers($class, $need)) {
                $blocks[] = 'Tài xế ' . $driver['ho_ten'] . ' có ' . TransportRules::label($class) . ' — ' . ($isMoto ? 'xe máy này (' . (int)$vehicle['dung_tich_cc'] . ' cm³' : 'xe này (GVW ' . number_format($gvw, 0, ',', '.') . ' kg'
                    . ((int)($vehicle['ro_mooc_kg'] ?? 0) > TransportRules::TRAILER_LIGHT_MAX ? ', kéo rơ moóc' : '')) . ') cần ' . TransportRules::label($need) . ' trở lên';
            }
            $exp = (string)($driver['han_gplx'] ?? '');
            if ($exp !== '' && $exp < $today) {
                $blocks[] = 'Giấy phép lái xe của ' . $driver['ho_ten'] . ' hết hạn từ ' . $date($exp);
            } elseif ($exp !== '' && $exp <= date('Y-m-d', strtotime("$today +{$lim['canh_bao_han_ngay']} days"))) {
                $warns[] = 'Giấy phép lái xe của ' . $driver['ho_ten'] . ' sắp hết hạn (' . $date($exp) . ')';
            }
            $birth = (string)($driver['ngay_sinh'] ?? '');
            if ($birth === '') {
                $warns[] = 'Tài xế ' . $driver['ho_ten'] . ' chưa khai ngày sinh — chưa kiểm được độ tuổi tối thiểu';
            } elseif (isset(TransportRules::CLASSES[$class]) && TransportRules::ageOn($birth, $today) < TransportRules::minAge($class)) {
                $blocks[] = 'Tài xế ' . $driver['ho_ten'] . ' chưa đủ ' . TransportRules::minAge($class) . ' tuổi để lái xe ' . TransportRules::label($class);
            }
            if ((int)($driver['diem_gplx'] ?? 12) <= 0) {
                $blocks[] = 'Giấy phép lái xe của ' . $driver['ho_ten'] . ' đã bị trừ hết điểm';
            }
        }
        $health = (string)($driver['han_kham_sk'] ?? '');
        if ($health === '') {
            $warns[] = 'Tài xế ' . $driver['ho_ten'] . ' chưa khai giấy khám sức khoẻ';
        } elseif ($health < $today) {
            $blocks[] = 'Giấy khám sức khoẻ lái xe của ' . $driver['ho_ten'] . ' hết hạn từ ' . $date($health);
        }
        return ['blocks' => $blocks, 'warns' => $warns];
    }

    /**
     * Gợi ý cho danh sách chọn TÀI XẾ: mỗi tài xế ⇒ [hard = lỗi hồ sơ không lái được xe nào (bằng hết hạn, chưa đủ tuổi, hết điểm, hết hạn khám SK),
     * warns = chưa khai, class = hạng bằng]. Việc «đúng hạng cho xe đang chọn» do trình duyệt đối chiếu với xe và máy chủ kiểm lại khi xuất kho.
     * @param list<array<string,mixed>> $drivers · @return array<int,array{hard:list<string>,warns:list<string>,class:string,age:?int}>
     */
    public function driverFit(array $drivers, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $out = [];
        foreach ($drivers as $d) {
            $r = $this->driverIssues($d, null, $today);
            $out[(int)$d['id']] = ['hard' => $r['blocks'], 'warns' => $r['warns'], 'class' => (string)($d['hang_gplx'] ?? ''),
                                   'age' => ($d['ngay_sinh'] ?? '') !== '' ? TransportRules::ageOn((string)$d['ngay_sinh'], $today) : null];
        }
        return $out;
    }

    /**
     * Gợi ý cho danh sách chọn xe: mỗi xe ⇒ [hard = lỗi của riêng xe / tài xế (không chọn được), load = lỗi do lô hàng quá lớn (chia thêm xe được), warns = chưa khai].
     * @param list<array<string,mixed>> $vehicles dòng có 'id' · @return array<int,array{hard:list<string>,load:list<string>,warns:list<string>}>
     */
    public function fitMap(array $vehicles, array $load, bool $transfer = false): array
    {
        $out = [];
        foreach ($vehicles as $x) {
            $r = $this->checkVehicle((int)$x['id'], $load, null, false);
            $hard = array_values(array_diff($r['blocks'], $r['load']));
            if ($transfer && ($x['kieu_xe'] ?? 'TAI') === 'MAY') {
                array_unshift($hard, 'Xe máy chỉ giao hàng cho khách, không chạy điều chuyển giữa các kho');
            }
            $out[(int)$x['id']] = ['hard' => $hard, 'load' => $r['load'], 'warns' => $r['warns']];
        }
        return $out;
    }

    /**
     * Xe NÊN dùng: hàng nhẹ thì khuyến khích xe nhỏ nhất vẫn đủ chở một mình (xe máy → ô tô nhỏ → xe tải), đỡ tốn xe lớn và tiền công.
     * Chỉ gợi ý khi có ít nhất hai xe đủ điều kiện để chọn. Điều chuyển giữa các kho không dùng xe máy.
     * @param list<array<string,mixed>> $vehicles · @param array<int,array{hard:list<string>,load:list<string>,warns:list<string>}> $fit
     * @return ?int id xe được khuyên dùng
     */
    public function recommend(array $vehicles, array $fit, bool $transfer = false): ?int
    {
        $ok = array_filter($vehicles, fn($x) => isset($fit[(int)$x['id']]) && !$fit[(int)$x['id']]['hard'] && !$fit[(int)$x['id']]['load']
            && !($transfer && ($x['kieu_xe'] ?? 'TAI') === 'MAY') && (int)($x['tai_trong_kg'] ?? 0) > 0);
        if (count($ok) < 2) {
            return null;
        }
        usort($ok, fn($a, $b) => [(int)$a['tai_trong_kg'], (int)$a['id']] <=> [(int)$b['tai_trong_kg'], (int)$b['id']]);
        return (int)$ok[0]['id'];
    }

    /**
     * Kiểm tra theo id xe. Tài xế = $driverId (người được giao lái chuyến này); không truyền thì dùng tài xế gắn trong hồ sơ xe (dữ liệu cũ, xe thuê ngoài).
     * $withDriver = false: chỉ xét riêng xe.
     */
    public function checkVehicle(int $vehicleId, array $load, ?int $driverId = null, bool $withDriver = true): array
    {
        $x = $this->db->one('SELECT * FROM xe WHERE id = ?', [$vehicleId]) ?? throw new DomainError('Xe không tồn tại');
        if (!$withDriver) {
            return $this->check($x, null, $load, null, false);
        }
        $tid = $driverId ?: (int)($x['tai_xe_id'] ?? 0);
        $t = $tid ? $this->db->one('SELECT * FROM tai_xe WHERE id = ?', [$tid]) : null;
        return $this->check($x, $t, $load);
    }

    /** Chặn xuất kho nếu xe (và tài xế) không đủ điều kiện chở lô hàng này. Chỉ vi phạm thật mới chặn, thiếu số liệu chỉ cảnh báo. */
    public function assertCanCarry(int $vehicleId, array $lines, ?int $driverId = null): void
    {
        $r = $this->checkVehicle($vehicleId, $this->loadOf($lines), $driverId);
        if ($r['blocks']) {
            $plate = (string)$this->db->value('SELECT bien_so FROM xe WHERE id = ?', [$vehicleId]);
            throw new DomainError("Xe $plate không chở được lô hàng này: " . implode('; ', $r['blocks']));
        }
    }

    /**
     * Gợi ý: ước lượng số xe cần cho lô hàng nếu dùng toàn xe cùng loại tải $capKg / thể tích $capM3 (0 = bỏ qua tiêu chí đó).
     */
    public static function trucksNeeded(array $load, int $capKg, float $capM3): int
    {
        $byWeight = $capKg > 0 ? (int)ceil($load['weight_kg'] / $capKg) : 1;
        $byVolume = $capM3 > 0 ? (int)ceil($load['volume_m3'] / $capM3) : 1;
        return max(1, $byWeight, $byVolume);
    }
}
