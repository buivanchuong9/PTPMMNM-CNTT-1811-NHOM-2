<?php
declare(strict_types=1);

namespace StockFlow\Domain;

/**
 * Quy định vận tải dùng để chọn xe và tài xế: hạng giấy phép lái xe theo khối lượng xe, độ tuổi tối thiểu, tải trọng, thể tích thùng, khổ xe.
 *
 * Căn cứ: Luật Trật tự, an toàn giao thông đường bộ 2024 (Luật số 36/2024/QH15, hiệu lực 01/01/2025) — điều 57 (hạng giấy phép lái xe),
 * điều 59 (độ tuổi) — và thông tư về tải trọng, khổ giới hạn của đường bộ. Các con số dưới đây là giá trị tham khảo của dự án;
 * quản trị viên phải đối chiếu với văn bản hiện hành và chỉnh lại các giá trị khổ xe ở trang «Quy định vận tải» khi văn bản thay đổi.
 *
 * Thuần tính toán, không truy cập CSDL (xem TransportService cho phần ghép với dữ liệu).
 */
final class TransportRules
{
    /** Ngưỡng khối lượng toàn bộ theo thiết kế (GVW, kg) giữa các hạng bằng cho ô tô tải. */
    public const GVW_B_MAX = 3500;
    public const GVW_C1_MAX = 7500;
    /** Rơ moóc từ mức này trở lên (kg) phải có hạng bằng «E» (BE, C1E, CE). */
    public const TRAILER_LIGHT_MAX = 750;

    /**
     * Hạng giấy phép lái xe có thể chọn cho tài xế: mã ⇒ [tên, tuổi tối thiểu, thời hạn bằng (năm, 0 = không thời hạn), mô tả, lái được ô tô tải?].
     * Hạng A1, A, B1 (mô tô) và D (chở người) có trong luật nhưng không lái xe chở hàng của kho nên chỉ ghi để tra cứu.
     */
    public const CLASSES = [
        'A1'  => ['Hạng A1', 18, 0, 'Mô tô hai bánh có dung tích xi-lanh đến 175 cm³ — xe máy giao hàng thông thường.', true],
        'A'   => ['Hạng A', 18, 0, 'Mô tô hai bánh có dung tích xi-lanh trên 175 cm³. Lái được xe hạng A1.', true],
        'B'   => ['Hạng B', 18, 10, 'Ô tô chở người đến 8 chỗ (không kể người lái); ô tô tải, ô tô chuyên dùng có khối lượng toàn bộ theo thiết kế (GVW) đến 3.500 kg; kể cả kéo rơ moóc đến 750 kg.', true],
        'C1'  => ['Hạng C1', 18, 5, 'Ô tô tải, ô tô chuyên dùng có GVW trên 3.500 kg đến 7.500 kg; kể cả kéo rơ moóc đến 750 kg. Lái được xe hạng B.', true],
        'C'   => ['Hạng C', 21, 5, 'Ô tô tải, ô tô chuyên dùng, ô tô đầu kéo có GVW trên 7.500 kg; kể cả kéo rơ moóc đến 750 kg. Lái được xe hạng C1 và B.', true],
        'BE'  => ['Hạng BE', 21, 5, 'Xe hạng B kéo rơ moóc có khối lượng toàn bộ trên 750 kg.', true],
        'C1E' => ['Hạng C1E', 24, 5, 'Xe hạng C1 kéo rơ moóc trên 750 kg. Lái được các xe của hạng B, C1, BE.', true],
        'CE'  => ['Hạng CE', 24, 5, 'Xe hạng C kéo rơ moóc trên 750 kg, ô tô đầu kéo kéo sơ mi rơ moóc, xe tải nối toa. Lái được các xe của hạng B, C1, C, BE, C1E.', true],
        'D1'  => ['Hạng D1', 24, 5, 'Ô tô chở người từ 9 đến 16 chỗ (không kể người lái) — không dùng để lái xe chở hàng.', false],
        'D2'  => ['Hạng D2', 24, 5, 'Ô tô chở người từ 17 đến 29 chỗ — không dùng để lái xe chở hàng.', false],
        'D'   => ['Hạng D', 27, 5, 'Ô tô chở người trên 29 chỗ — không dùng để lái xe chở hàng.', false],
        'B2'  => ['Hạng B2 (cấp trước 01/01/2025)', 18, 10, 'Bằng cũ còn hiệu lực đến hết hạn: dùng như hạng B (ô tô tải đến 3.500 kg). Muốn lái xe 3.500–7.500 kg phải đổi sang hạng C1.', true],
    ];

    /** Nhu cầu hạng bằng của xe ⇒ các hạng bằng được lái. */
    private const COVERS = [
        'A1'  => ['A1', 'A'],
        'A'   => ['A'],
        'B'   => ['B', 'C1', 'C', 'C1E', 'CE', 'BE', 'B2'],
        'C1'  => ['C1', 'C', 'C1E', 'CE'],
        'C'   => ['C', 'CE'],
        'BE'  => ['BE', 'C1E', 'CE'],
        'C1E' => ['C1E', 'CE'],
        'CE'  => ['CE'],
    ];

    /** Kiểu xe: xe máy giao hàng nhỏ, ô tô nhỏ (con, bán tải, van), xe tải. */
    public const VEHICLE_KINDS = ['MAY' => 'Xe máy', 'OTO_NHO' => 'Ô tô nhỏ (con, bán tải, van)', 'TAI' => 'Xe tải'];

    /** Xe máy dung tích đến mức này (cm³) cần bằng A1; trên đó cần bằng A. */
    public const MOTO_A1_MAX_CC = 175;

    /**
     * Hạng bằng lái tối thiểu để lái một chiếc xe (dòng bảng xe): xe máy theo dung tích xi-lanh (A1 / A); xe khác theo khối lượng toàn bộ GVW và rơ moóc.
     * null nếu chưa khai đủ số liệu để biết.
     * @param array<string,mixed> $vehicle
     */
    public static function needFor(array $vehicle): ?string
    {
        if (($vehicle['kieu_xe'] ?? 'TAI') === 'MAY') {
            $cc = (int)($vehicle['dung_tich_cc'] ?? 0);
            return $cc <= 0 ? null : ($cc <= self::MOTO_A1_MAX_CC ? 'A1' : 'A');
        }
        return self::requiredClass((int)($vehicle['gvw_kg'] ?? 0), (int)($vehicle['ro_mooc_kg'] ?? 0));
    }

    /** Loại thùng xe. */
    public const BODY_TYPES = ['KIN' => 'Thùng kín', 'MUI_BAT' => 'Thùng mui bạt', 'LUNG' => 'Thùng lửng', 'DONG_LANH' => 'Thùng đông lạnh', 'CONTAINER' => 'Chở container', 'BEN' => 'Xe ben'];

    /** Giá trị mặc định chỉnh được ở trang «Quy định vận tải» (khổ xe theo mm; quy đổi theo kg/m³; xếp hàng theo %). */
    public const DEFAULT_LIMITS = [
        'quy_doi_kg_m3' => 333,    // 1 m³ hàng cồng kềnh tính như 333 kg (cách tính cước vận tải đường bộ thường dùng)
        'xep_hang_pct' => 85,      // phần trăm thể tích thùng xếp được hàng thực tế (còn lại là khe hở, chèn lót)
        'cao_max_mm' => 4200,      // chiều cao xe tối đa không cần giấy phép lưu hành
        'rong_max_mm' => 2500,     // chiều rộng
        'dai_max_mm' => 12000,     // chiều dài xe tải thân liền
        'canh_bao_han_ngay' => 30, // báo sắp hết hạn giấy tờ trước bao nhiêu ngày
    ];

    /** Hạng bằng tối thiểu để lái xe theo khối lượng toàn bộ (kg) và khối lượng rơ moóc (kg); null nếu chưa khai khối lượng xe. */
    public static function requiredClass(int $gvwKg, int $trailerKg = 0): ?string
    {
        if ($gvwKg <= 0) {
            return null;
        }
        $base = $gvwKg <= self::GVW_B_MAX ? 'B' : ($gvwKg <= self::GVW_C1_MAX ? 'C1' : 'C');
        return $trailerKg > self::TRAILER_LIGHT_MAX ? ($base === 'B' ? 'BE' : $base . 'E') : $base;
    }

    /** Hạng bằng $license có được lái xe cần hạng $needed không. */
    public static function covers(string $license, string $needed): bool
    {
        return in_array($license, self::COVERS[$needed] ?? [], true);
    }

    /** Bảng cho trình duyệt lọc tài xế theo xe: nhu cầu hạng bằng của xe ⇒ các hạng bằng được lái. @return array<string,list<string>> */
    public static function coverTable(): array
    {
        return self::COVERS;
    }

    /** Hạng bằng này lái được loại xe tải nào, nói gọn: «xe đến 3,5 tấn», «xe đến 7,5 tấn», «mọi xe tải», thêm «kéo rơ moóc» nếu có hạng E. Hạng không lái xe tải ⇒ «không lái xe chở hàng». */
    public static function reach(string $class): string
    {
        if ($class === 'A1' || $class === 'A') {
            return $class === 'A' ? 'mọi xe máy' : 'xe máy đến 175 cm³';
        }
        $c = fn(string $need) => self::covers($class, $need);
        $base = $c('C') ? 'mọi xe tải' : ($c('C1') ? 'xe tải đến 7,5 tấn' : ($c('B') ? 'xe tải đến 3,5 tấn' : ''));
        if ($base === '') {
            return 'không lái xe chở hàng';
        }
        return $base . ($c('BE') ? ', kéo rơ moóc' : '');
    }

    /** Tuổi tối thiểu để được cấp hạng bằng (năm). */
    public static function minAge(string $license): int
    {
        return (int)(self::CLASSES[$license][1] ?? 18);
    }

    /** Hạng bằng tối thiểu để lái xe cần hạng $needed ở dạng chữ cho dễ đọc («C1», «CE»…). */
    public static function label(string $class): string
    {
        return self::CLASSES[$class][0] ?? $class;
    }

    /** Tuổi (năm tròn) tại ngày $on của người sinh ngày $birth (YYYY-MM-DD). */
    public static function ageOn(string $birth, string $on): int
    {
        $b = date_create($birth);
        $d = date_create($on);
        return ($b && $d) ? (int)$b->diff($d)->y : 0;
    }

    /** Hàng hoá (dài, rộng, cao — mm) có lọt lòng thùng (dài, rộng, cao — mm) không, được phép xoay: so sánh các cạnh đã sắp xếp. */
    public static function fits(array $item, array $box): bool
    {
        $a = array_map('intval', $item);
        $b = array_map('intval', $box);
        sort($a);
        sort($b);
        return $a[0] <= $b[0] && $a[1] <= $b[1] && $a[2] <= $b[2];
    }

    /** Số thập phân người dùng gõ («1,5» hoặc «1.5») ⇒ float; rỗng ⇒ 0.0; không phải số không âm ⇒ null. */
    public static function parseDecimal(string $s): ?float
    {
        $s = trim(str_replace(',', '.', $s));
        if ($s === '') {
            return 0.0;
        }
        return preg_match('/^\d{1,9}(\.\d{1,3})?$/', $s) ? (float)$s : null;
    }

    /** Thể tích m³ từ ba cạnh mm. */
    public static function cubicMeters(int $l, int $w, int $h): float
    {
        return $l * $w * $h / 1_000_000_000;
    }
}
