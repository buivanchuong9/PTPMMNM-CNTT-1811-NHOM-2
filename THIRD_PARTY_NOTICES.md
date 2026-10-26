# Thư viện và dữ liệu bên thứ ba

Mã nguồn của dự án phát hành theo giấy phép MIT (xem [LICENSE](LICENSE)). Dự án **không dùng Composer hay npm khi chạy**; các thành phần bên ngoài dưới đây được chép sẵn vào `public/assets` và giữ nguyên giấy phép gốc của từng bên.

## Thư viện chạy trên trình duyệt

| Thành phần | Phiên bản | Giấy phép | Dùng để | Vị trí |
|---|---|---|---|---|
| [Chart.js](https://www.chartjs.org) | 4.4.3 | MIT | Biểu đồ ở trang Tổng quan và Báo cáo | `public/assets/vendor/chart.umd.min.js` |
| [ZXing-JS (`@zxing/library`)](https://github.com/zxing-js/library) | 0.21.3 | Apache-2.0 | Đọc mã vạch từ camera và ảnh ngay trên trình duyệt (tính năng quét mã vạch) | `public/assets/vendor/zxing.min.js` |

ZXing-JS là bản chuyển sang TypeScript/JavaScript của dự án [ZXing](https://github.com/zxing/zxing) (Google, Apache-2.0). Tệp được chép nguyên bản bản phát hành `umd/index.min.js`, không sửa đổi. Khi phát hành lại phải giữ thông báo bản quyền và giấy phép Apache-2.0: https://www.apache.org/licenses/LICENSE-2.0

## Phông chữ

| Thành phần | Giấy phép | Vị trí |
|---|---|---|
| [Be Vietnam Pro](https://fonts.google.com/specimen/Be+Vietnam+Pro) (các tệp `bvp-*.woff2`, kiểu chữ 400, 500, 600, 700, bộ ký tự latin, latin-ext, vietnamese) | SIL Open Font License 1.1 | `public/assets/vendor/fonts/` |

Giấy phép OFL cho phép dùng, nhúng và phát hành kèm phần mềm, miễn là không bán riêng phông chữ và giữ nguyên tên giấy phép: https://openfontlicense.org

## Dữ liệu

| Thành phần | Dùng để | Vị trí |
|---|---|---|
| Danh mục đơn vị hành chính Việt Nam (tỉnh/thành, phường/xã) | Gợi ý và kiểm tra địa chỉ kho, khách hàng | `public/assets/data/vn-address.json` |

Đây là danh mục hành chính công khai. Nếu nhóm lấy từ một nguồn cụ thể (cổng dữ liệu của cơ quan nhà nước hoặc một kho dữ liệu mở), hãy ghi tên nguồn, đường dẫn và ngày lấy dữ liệu vào đây trước khi nộp.

## Hình ảnh

Logo, biểu tượng và ảnh nền trang đăng nhập (`public/assets/img`), cùng sơ đồ và ảnh minh hoạ trong `docs/images`: **nhóm cần xác nhận nguồn trước khi nộp**. Nếu do nhóm tự làm thì ghi rõ ở đây; nếu lấy từ nguồn ngoài (ví dụ `login-hero.jpg`) thì ghi tên nguồn, đường dẫn và giấy phép.

## Công cụ khi phát triển

| Công cụ | Dùng để |
|---|---|
| PHP 8.x (đã chạy thử với 8.4; cần `mysqli`, `mbstring`) | Chạy ứng dụng và bộ test |
| MySQL ≥ 8.0.16 hoặc MariaDB ≥ 10.4 (XAMPP) | Cơ sở dữ liệu |

Bộ test (`tests/`) do nhóm tự viết, không dùng PHPUnit.
