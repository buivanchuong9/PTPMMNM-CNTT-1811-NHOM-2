<div align="center">

<img src="docs/images/banner.svg" alt="StockFlow — quản lý kho" width="100%">

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL%208%2B%20·%20MariaDB%2010.4%2B-4479A1?logo=mysql&logoColor=white)](#chạy-thử)
[![Kiểm thử](https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-2/actions/workflows/test.yml/badge.svg)](https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-2/actions/workflows/test.yml)
[![License](https://img.shields.io/badge/license-MIT-3d73c4)](LICENSE)


<img src="docs/images/screens.gif" alt="Các màn hình của StockFlow: tổng quan, phiếu nhập xuất, hàng hoá, đội xe, giá trị tồn, báo cáo, kiểm kê, giao diện tối" width="100%">

</div>

# StockFlow — Quản lý kho

Hệ thống quản lý nhiều kho viết bằng **PHP thuần + MySQL**, không dùng framework. Đề tài môn *Phát triển phần mềm mã nguồn mở*, lớp CNTT 1811, nhóm 2.

## Chức năng chính

- **Nhập / xuất kho:** lập phiếu nháp → quản lý kho xác nhận. Hàng theo lô tự xuất lô hết hạn trước (FEFO), không xuất lô đã hết hạn. Xuất bán có thể kèm giao hàng.
- **Điều chuyển giữa các kho:** chọn xe và tài xế hợp lệ (tải trọng, đăng kiểm, bằng lái). Kho đi xuất, kho đến nhận theo số thực nhận; có thu hồi chuyến và báo mất hàng.
- **Đặt hàng nhà cung cấp:** đơn tự cập nhật khi nhận hàng. Lúc nhận có kiểm chất lượng, phần không đạt tự lập phiếu trả nhà cung cấp.
- **Đổi trả, bảo hành, huỷ hàng:** khách trả hàng, tiếp nhận bảo hành theo mã lô / mã món, đề xuất và duyệt huỷ hàng hỏng.
- **Kiểm kê:** đếm → nộp kèm lý do lệch → duyệt, tự lập phiếu điều chỉnh. Có đối chiếu tồn kho với sổ.
- **Hàng hoá:** mặt hàng, lô, hạn dùng, định mức tồn tối thiểu, giá gốc và giá bán riêng từng kho. Quét mã vạch bằng camera, in tem, nhập danh mục và tồn đầu kỳ từ CSV.
- **Báo cáo:** tổng quan và việc cần làm, chuông cảnh báo sắp hết hàng / sắp hết hạn, Nhập–Xuất–Tồn, thẻ kho, giá trị tồn, chi phí nhập, doanh thu. Xuất CSV.
- **Phân quyền:** 3 vai trò (quản trị, quản lý kho, nhân viên), bật tắt từng quyền; quản lý và nhân viên chỉ thao tác trong kho của mình. Mọi thao tác được ghi nhật ký.

Tồn kho được tính từ sổ phát sinh chỉ ghi thêm. Phiếu đã ghi sổ không sửa được: muốn sửa sai thì lập phiếu đảo.

## Chạy thử

Cần PHP 8.1+ và MySQL (XAMPP là đủ, nhớ bấm Start ở MySQL).

```bash
git clone https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-2.git
cd PTPMMNM-CNTT-1811-NHOM-2
php bin/stockflow update    # tạo database và dữ liệu mẫu
php bin/stockflow serve     # mở http://127.0.0.1:8080
```

Đăng nhập bằng `admin`, `hn.quanly` hoặc `hn.nv1`. Mật khẩu: `demo1234`.

Chạy kiểm thử: `php bin/stockflow test`

<div align="center">


<img src="https://capsule-render.vercel.app/api?type=waving&color=0:3d73c4,100:172d4b&height=110&section=footer" width="100%" alt="">

</div>
