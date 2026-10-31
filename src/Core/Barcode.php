<?php
declare(strict_types=1);

namespace StockFlow\Core;

/**
 * Sinh mã vạch dạng SVG (không cần thư viện): EAN-13 cho mã vạch 13 số hợp lệ, còn lại dùng Code 128 (bộ B, ký tự in được).
 * Trả về thẻ <svg> kéo giãn theo khung chứa (preserveAspectRatio="none"), chỉ vẽ vạch — chữ do nhãn in tự ghi.
 */
final class Barcode
{
    /** Độ rộng các phần tử (vạch, cách, vạch, cách, vạch, cách) của 106 ký tự Code 128 theo giá trị 0..105; dừng riêng. */
    private const C128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232',
    ];
    private const C128_STOP = '2331112';
    /** Mã bên phải EAN-13 của chữ số 0..9; mã trái bộ L là bù của nó, bộ G là đảo ngược. */
    private const EAN_R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
    private const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /** EAN-13 hợp lệ: đủ 13 chữ số và số kiểm tra đúng. */
    public static function isEan13(string $code): bool
    {
        if (!preg_match('/^\d{13}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$code[$i] * ($i % 2 ? 3 : 1);
        }
        return (10 - $sum % 10) % 10 === (int)$code[12];
    }

    /** Chuỗi bit vạch (1 = vạch đen) của EAN-13. */
    public static function ean13Bits(string $code): string
    {
        $parity = self::EAN_PARITY[(int)$code[0]];
        $bits = '101';
        for ($i = 1; $i <= 6; $i++) {
            $r = self::EAN_R[(int)$code[$i]];
            $bits .= match ($parity[$i - 1]) {
                'L' => strtr($r, '01', '10'),
                default => strrev($r),
            };
        }
        $bits .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::EAN_R[(int)$code[$i]];
        }
        return $bits . '101';
    }

    /** Chuỗi bit vạch của Code 128 bộ B; ký tự ngoài ASCII in được bị bỏ. */
    public static function code128Bits(string $text): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        $values = [104];   // bắt đầu bộ B
        $sum = 104;
        foreach (str_split($text) as $i => $ch) {
            $v = ord($ch) - 32;
            $values[] = $v;
            $sum += $v * ($i + 1);
        }
        $values[] = $sum % 103;
        $widths = '';
        foreach ($values as $v) {
            $widths .= self::C128[$v];
        }
        $widths .= self::C128_STOP;
        $bits = '';
        foreach (str_split($widths) as $i => $w) {
            $bits .= str_repeat($i % 2 === 0 ? '1' : '0', (int)$w);
        }
        return $bits;
    }

    /** Thẻ <svg> của mã vạch: EAN-13 nếu $code là EAN-13 hợp lệ, còn lại Code 128. */
    public static function svg(string $code, int $quiet = 8): string
    {
        $bits = self::isEan13($code) ? self::ean13Bits($code) : self::code128Bits($code);
        $total = strlen($bits) + 2 * $quiet;
        $rects = '';
        $i = 0;
        $n = strlen($bits);
        while ($i < $n) {
            if ($bits[$i] === '1') {
                $j = $i;
                while ($j < $n && $bits[$j] === '1') {
                    $j++;
                }
                $rects .= '<rect x="' . ($i + $quiet) . '" y="0" width="' . ($j - $i) . '" height="50"/>';
                $i = $j;
            } else {
                $i++;
            }
        }
        return '<svg class="bc" viewBox="0 0 ' . $total . ' 50" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Mã vạch ' . htmlspecialchars($code, ENT_QUOTES) . '" shape-rendering="crispEdges" fill="#000">' . $rects . '</svg>';
    }
}
