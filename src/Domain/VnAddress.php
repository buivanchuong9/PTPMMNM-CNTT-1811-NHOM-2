<?php
declare(strict_types=1);

namespace StockFlow\Domain;

use StockFlow\Core\Config;

/**
 * Địa chỉ Việt Nam theo cấp hành chính hiện hành: Tỉnh/Thành phố → Phường/Xã (34 tỉnh, thành; 3.321 phường, xã).
 * Dữ liệu ở public/assets/data/vn-address.json — cùng một file cho trình duyệt (gợi ý khi gõ) và máy chủ (kiểm tra lại, không tin dữ liệu từ form).
 * Địa chỉ kho lưu thành một chuỗi: «Số nhà, đường, Phường …, Tỉnh/Thành phố …».
 */
final class VnAddress
{
    /** @var list<array{n:string,w:list<string>}>|null */
    private static ?array $data = null;

    /** @return list<array{n:string,w:list<string>}> */
    public static function provinces(): array
    {
        if (self::$data === null) {
            $json = @file_get_contents(Config::root() . '/public/assets/data/vn-address.json');
            $data = is_string($json) ? json_decode($json, true) : null;
            self::$data = is_array($data) ? $data : [];
        }
        return self::$data;
    }

    /** Bỏ dấu, chữ thường, đ → d, gộp khoảng trắng — để so khớp không phân biệt dấu/hoa thường. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $map = ['à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'đ' => 'd'];
        return trim((string)preg_replace('/\s+/u', ' ', strtr($s, $map)));
    }

    /** Tên không kèm loại đơn vị: «Thành phố Hà Nội» → «ha noi», «Phường Ba Đình» → «ba dinh». */
    private static function core(string $name): string
    {
        return (string)preg_replace('/^(thanh pho|tinh|phuong|xa|dac khu|thi tran) /', '', self::fold($name));
    }

    /** @return array{n:string,w:list<string>}|null */
    public static function findProvince(string $name): ?array
    {
        $f = self::fold($name);
        if ($f === '') {
            return null;
        }
        foreach (self::provinces() as $p) {
            if (self::fold($p['n']) === $f || self::core($p['n']) === $f) {
                return $p;
            }
        }
        return null;
    }

    /** Tên đầy đủ của phường/xã thuộc tỉnh đó, hoặc null nếu không thuộc. */
    public static function findWard(array $province, string $name): ?string
    {
        $f = self::fold($name);
        if ($f === '') {
            return null;
        }
        foreach ($province['w'] as $w) {
            if (self::fold($w) === $f) {
                return $w;
            }
        }
        foreach ($province['w'] as $w) {          // «Ba Đình» (thiếu «Phường»): chỉ nhận khi không mơ hồ
            if (self::core($w) === $f) {
                $hits = array_filter($province['w'], fn($x) => self::core($x) === $f);
                return count($hits) === 1 ? $w : null;
            }
        }
        return null;
    }

    /**
     * Ghép địa chỉ từ ba ô của form, kiểm tra lại với danh mục hành chính.
     * $required = false: cả ba ô trống ⇒ trả về chuỗi rỗng (địa chỉ tuỳ chọn); điền một phần thì vẫn báo lỗi.
     */
    public static function compose(string $street, string $ward, string $province, bool $required = true): string
    {
        $street = trim((string)preg_replace('/\s+/u', ' ', $street));
        if (!$required && $street === '' && trim($ward) === '' && trim($province) === '') {
            return '';
        }
        if ($street === '' || mb_strlen($street) > 150 || preg_match('/[\r\n]/', $street)) {
            throw new DomainError('Nhập số nhà, tên đường (hoặc khu công nghiệp) — tối đa 150 ký tự');
        }
        $p = self::findProvince($province) ?? throw new DomainError('Tỉnh / Thành phố không hợp lệ — hãy chọn từ danh sách gợi ý');
        $w = self::findWard($p, $ward) ?? throw new DomainError('Phường / Xã không thuộc ' . $p['n'] . ' — hãy chọn từ danh sách gợi ý');
        return $street . ', ' . $w . ', ' . $p['n'];
    }

    /**
     * Tách địa chỉ đã lưu về ba ô để điền sẵn vào form sửa. Địa chỉ cũ (không theo dạng chuẩn) được giữ nguyên trong ô «Số nhà, đường».
     * @return array{street:string,ward:string,province:string}
     */
    public static function parse(?string $address): array
    {
        $address = trim((string)$address);
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn($x) => $x !== ''));
        $out = ['street' => $address, 'ward' => '', 'province' => ''];
        if (count($parts) < 2) {
            return $out;
        }
        $p = self::findProvince((string)end($parts));
        if (!$p) {
            return $out;
        }
        array_pop($parts);
        $out['province'] = $p['n'];
        $w = $parts ? self::findWard($p, (string)end($parts)) : null;
        if ($w) {
            array_pop($parts);
            $out['ward'] = $w;
        }
        $out['street'] = implode(', ', $parts);
        return $out;
    }

    /** Toạ độ tâm (thành phố / tỉnh lỵ) của 34 tỉnh, thành — đủ để vẽ lộ trình minh hoạ giữa hai kho. @var array<string,array{float,float}> */
    private const CENTERS = [
        'can tho' => [10.03, 105.78], 'hue' => [16.46, 107.59], 'ha noi' => [21.03, 105.85], 'hai phong' => [20.84, 106.69],
        'ho chi minh' => [10.78, 106.70], 'da nang' => [16.05, 108.21], 'an giang' => [10.37, 105.43], 'bac ninh' => [21.19, 106.07],
        'cao bang' => [22.67, 106.26], 'ca mau' => [9.18, 105.15], 'gia lai' => [13.98, 108.00], 'ha tinh' => [18.34, 105.91],
        'hung yen' => [20.65, 106.05], 'khanh hoa' => [12.24, 109.19], 'lai chau' => [22.40, 103.46], 'lao cai' => [22.48, 103.97],
        'lam dong' => [11.94, 108.44], 'lang son' => [21.85, 106.76], 'nghe an' => [18.68, 105.68], 'ninh binh' => [20.25, 105.97],
        'phu tho' => [21.32, 105.40], 'quang ngai' => [15.12, 108.80], 'quang ninh' => [20.95, 107.08], 'quang tri' => [16.82, 107.10],
        'son la' => [21.33, 103.91], 'thanh hoa' => [19.81, 105.78], 'thai nguyen' => [21.59, 105.84], 'tuyen quang' => [21.82, 105.21],
        'tay ninh' => [11.31, 106.10], 'vinh long' => [10.25, 105.97], 'dien bien' => [21.39, 103.02], 'dak lak' => [12.67, 108.04],
        'dong nai' => [10.95, 106.82], 'dong thap' => [10.46, 105.63],
    ];

    /** Vị trí gần đúng của một địa chỉ đã lưu (theo tỉnh/thành ở cuối chuỗi); null nếu không nhận ra tỉnh. @return array{lat:float,lng:float,province:string}|null */
    public static function locate(?string $address): ?array
    {
        $province = self::parse($address)['province'];
        if ($province === '') {   // địa chỉ cũ viết tắt («TP. Hồ Chí Minh», «TPHCM», «Sài Gòn»): đọc từ đoạn cuối
            $parts = array_map('trim', explode(',', (string)$address));
            $last = self::fold((string)end($parts));
            $last = (string)preg_replace('/^(tp\.? ?|thanh pho |tinh )/', '', $last);
            $last = ['hcm' => 'ho chi minh', 'tphcm' => 'ho chi minh', 'sai gon' => 'ho chi minh', 'tp hcm' => 'ho chi minh'][$last] ?? $last;
            $province = isset(self::CENTERS[$last]) ? $last : '';
            $c = $province === '' ? null : self::CENTERS[$province];
            return $c ? ['lat' => $c[0], 'lng' => $c[1], 'province' => trim((string)end($parts))] : null;
        }
        $c = self::CENTERS[self::core($province)] ?? null;
        return $c ? ['lat' => $c[0], 'lng' => $c[1], 'province' => $province] : null;
    }

    /** Các thành phố dọc quốc lộ 1 (Bắc → Nam): đường đi giữa hai kho xa nhau được tính qua các điểm này, giống tuyến vẽ trên bản đồ (public/assets/js/route-map.js). */
    private const SPINE = [[21.03, 105.85], [20.25, 105.97], [19.81, 105.78], [18.68, 105.68], [18.34, 105.91], [16.82, 107.10], [16.46, 107.59], [16.05, 108.21], [15.12, 108.80], [13.78, 109.22], [12.24, 109.19], [10.78, 106.70]];

    /** Quãng đường bộ ước tính (km) giữa hai kho theo địa chỉ; null nếu một trong hai không nhận ra tỉnh. */
    public static function roadKm(?string $fromAddress, ?string $toAddress): ?int
    {
        $a = self::locate($fromAddress);
        $b = self::locate($toAddress);
        if (!$a || !$b) {
            return null;
        }
        $nearest = function (array $p): int {
            $best = 0;
            $bestD = INF;
            foreach (self::SPINE as $i => $s) {
                $d = hypot($s[0] - $p['lat'], $s[1] - $p['lng']);
                if ($d < $bestD) {
                    [$best, $bestD] = [$i, $d];
                }
            }
            return $best;
        };
        [$i, $j] = [$nearest($a), $nearest($b)];
        $mid = $i <= $j ? array_slice(self::SPINE, $i, $j - $i + 1) : array_reverse(array_slice(self::SPINE, $j, $i - $j + 1));
        $path = [[$a['lat'], $a['lng']]];
        foreach ($mid as $m) {
            if (hypot($m[0] - $a['lat'], $m[1] - $a['lng']) > 0.4 && hypot($m[0] - $b['lat'], $m[1] - $b['lng']) > 0.4) {
                $path[] = $m;
            }
        }
        $path[] = [$b['lat'], $b['lng']];
        $km = 0.0;
        for ($k = 1; $k < count($path); $k++) {
            $km += self::haversine($path[$k - 1], $path[$k]);
        }
        return max(1, (int)round($km * 1.15));   // 1,15: đường bộ quanh co hơn đường chim bay
    }

    /** @param array{float,float} $p @param array{float,float} $q */
    private static function haversine(array $p, array $q): float
    {
        [$la1, $la2] = [deg2rad($p[0]), deg2rad($q[0])];
        $h = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin(deg2rad($q[1] - $p[1]) / 2) ** 2;
        return 6371 * 2 * asin(sqrt($h));
    }
}
