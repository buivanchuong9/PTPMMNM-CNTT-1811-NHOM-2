# Chương Đại Dương — Quản lý kho

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
