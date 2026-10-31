<div align="center">


<img src="docs/images/hero.svg" alt="StockFlow — quản lý kho" width="100%">


<a href="https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-2"><img src="https://readme-typing-svg.demolab.com?font=Fira+Code&weight=600&size=22&duration=2600&pause=800&color=EE4D2D&center=true&vCenter=true&width=700&lines=Qu%E1%BA%A3n+l%C3%BD+kho+%C4%91a+%C4%91i%E1%BB%83m;%C4%90i%E1%BB%81u+chuy%E1%BB%83n+h%C3%A0ng+k%C3%A8m+xe+v%C3%A0+t%C3%A0i+x%E1%BA%BF;Ki%E1%BB%83m+k%C3%AA+%E2%86%92+%C4%91i%E1%BB%81u+ch%E1%BB%89nh+c%C3%B3+d%E1%BA%A5u+v%E1%BA%BFt;T%E1%BB%93n+lu%C3%B4n+kh%E1%BB%9Bp+s%E1%BB%95+ph%C3%A1t+sinh" alt="Typing SVG"></a>
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL%208%2B%20·%20MariaDB%2010.4%2B-4479A1?logo=mysql&logoColor=white)](#-cài-đặt-và-chạy)
[![Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen)](#-cấu-trúc-mã-nguồn)
[![Kiểm thử](https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-2/actions/workflows/test.yml/badge.svg)](https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-2/actions/workflows/test.yml)
[![License](https://img.shields.io/badge/license-MIT-fbbf24)](LICENSE)
# StockFlow — Quản lý kho

Hệ thống quản lý nhiều kho viết bằng **PHP thuần + MySQL**, không dùng framework. Đề tài môn *Phát triển phần mềm mã nguồn mở*, lớp CNTT 1811, nhóm 2.

**Chức năng chính:** phiếu nhập / xuất, điều chuyển giữa các kho kèm xe và tài xế, kiểm kê, quét mã vạch, quản lý hàng hoá và đối tác, phân quyền theo vai trò, thống kê và xuất báo cáo CSV.

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
<img src="docs/images/divider.svg" width="100%" height="6" alt="">


<div align="center">


<img src="https://capsule-render.vercel.app/api?type=waving&color=0:ee4d2d,100:ff8a5c&height=110&section=footer" width="100%" alt="">


</div>
