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
}
