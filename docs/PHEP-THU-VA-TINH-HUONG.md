# Phép thử bắt buộc và hai tình huống demo

Tài liệu này ánh xạ từng yêu cầu của đề tài 10 (mục 3, 4, 6) tới test tự động và tới các bước chạy tay trên giao diện.

## 1. Cách chạy

```bash
php bin/stockflow update          # cài đặt / cập nhật CSDL, nạp dữ liệu mẫu (3 kho, 30 mặt hàng, hơn 200 dòng phát sinh)
php bin/stockflow test            # chạy toàn bộ test trên CSDL riêng <tên CSDL>_test (tự xoá và dựng lại)
```

Chạy lẻ một file (bắt buộc trỏ vào CSDL kiểm thử, test từ chối chạy trên CSDL có tên không chứa `test`):

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=stockflow_test DB_USER=root DB_PASS=... php tests/Integration/ConcurrentIssueTest.php
```

Mỗi file in `TESTS=<tổng> FAILED=<số lỗi>` ở dòng cuối. Lần chạy đầy đủ gần nhất: **852 test, 0 lỗi**.

## 2. Năm phép thử bắt buộc (mục 6)

| # | Phép thử của đề | Test | Mục trong file | Kỳ vọng |
|---|---|---|---|---|
| 1 | Kho còn 5, hai yêu cầu cùng xuất 4 | `tests/Integration/ConcurrentIssueTest.php` | *Hai phiếu xuất 4 cùng lúc (2 tiến trình, 2 kết nối) khi tồn = 5* · *Nhiều lượt lặp lại: không lần nào tồn âm* | Hai tiến trình PHP thật chạy song song: chỉ một phiếu được ghi sổ, tồn còn 1, không bao giờ âm. |
| 2 | Lỗi giữa lúc trừ kho A và cộng kho B | `tests/Integration/TransferAtomicityTest.php` | *Lỗi NGAY SAU khi trừ kho A, TRƯỚC khi cộng kho B* · *Lỗi SAU khi đã cộng kho B, TRƯỚC khi chốt* · *Thử lại không lỗi ⇒ trừ đúng một lần, cộng đúng một lần* | Chèn lỗi giữa hai thao tác: giao dịch huỷ hết, không mất và không nhân đôi hàng; thử lại chỉ trừ và cộng đúng một lần. |
| 3 | Gửi lại chứng từ đã xác nhận | `tests/Integration/IdempotencyTest.php` · `tests/Http/AuthorizationHttpTest.php` | *Xác nhận phiếu nhập hai lần* · *Gửi lại biểu mẫu tạo phiếu (cùng khoá idempotency)* · *Xác nhận điều chuyển hai lần* | Số tồn và sổ phát sinh không tăng thêm; gửi lại cùng khoá chỉ ra một phiếu. |
| 4 | Tài khoản kho A lập phiếu xuất kho B bằng yêu cầu trực tiếp | `tests/Integration/AuthorizationTest.php` · `tests/Http/AuthorizationHttpTest.php` | *Nhân viên kho A thao tác lên kho B bằng yêu cầu trực tiếp* · *Nhân viên kho A gửi yêu cầu trực tiếp lên kho B* | Máy chủ trả **403**, không có phiếu hay phát sinh mới ở kho B. Quyền kiểm tra ngay đầu xử lý, trước cả bước nhập thông tin khách. |
| 5 | Điều chỉnh kiểm kê có lý do và chứng từ | `tests/Integration/StockCountTest.php` | *Không có lý do thì không nộp được* · *Xác nhận chênh lệch ⇒ phiếu nhập xuất điều chỉnh có lý do* · *Tổng tồn sau điều chỉnh khớp lịch sử phát sinh* | Chênh lệch phải có lý do mới nộp; duyệt thì sinh phiếu điều chỉnh; tồn sau điều chỉnh bằng tổng sổ phát sinh. |

## 3. Hai yêu cầu kỹ thuật (mục 3)

| Yêu cầu | Cách thực hiện | Bằng chứng |
|---|---|---|
| **1.** Sổ phát sinh và giao dịch cập nhật tồn; không tồn âm; gửi lại không ghi thêm | Bảng `so_phat_sinh` chỉ thêm, không sửa, không xoá (trigger ở CSDL). Tồn `ton_kho` cập nhật trong cùng giao dịch, khoá dòng khi xuất, ràng buộc `CHECK` không âm. Phiếu có trạng thái và khoá chống trùng. | `ConcurrentIssueTest` · `IdempotencyTest` · mục *Ràng buộc CSDL là lớp bảo vệ cuối cùng* trong `IdempotencyTest` · `CorrectionTest` (*Khoá bất biến ở CSDL*) |
| **2.** Điều chuyển trừ kho đi và cộng kho đến trong cùng giao dịch | Cặp phiếu DCX và DCN cùng một giao dịch; kho đến nhập số thực nhận. | `TransferAtomicityTest` (cả mục *Điều chuyển hai chiều đồng thời không deadlock*) · `TransferReceiptTest` |

## 4. Hai tình huống sử dụng (mục 4)

Đăng nhập bằng tài khoản mẫu (mật khẩu `demo1234`): `hn.nv1` (nhân viên Hà Nội), `hn.quanly` (quản lý Hà Nội), `dn.quanly` (quản lý Đà Nẵng), `admin` (quản trị).

### Tình huống 1: nhập hàng → xác nhận → xuất hàng → tra cứu tồn và lịch sử

1. `hn.nv1`: **Lập phiếu nhập** (chọn nhà cung cấp, mặt hàng, số lượng, hàng theo lô thì nhập số lô và hạn dùng) → lưu nháp.
2. `hn.quanly`: mở phiếu → **Xác nhận**. Tồn tăng, sổ phát sinh có thêm dòng.
3. `hn.nv1`: **Lập phiếu xuất** (khách hàng có sẵn hoặc khách mới) → `hn.quanly` **Xác nhận**. Hàng theo lô tự xuất lô hết hạn trước.
4. Trang **Hàng hoá** xem tồn theo kho và theo lô; trang **Lịch sử phát sinh** thấy đủ hai dòng nhập, xuất.

Test tự động: `tests/Integration/ScenarioTest.php`, mục *Tình huống 1*.

### Tình huống 2: điều chuyển giữa hai kho → kiểm kê → xác nhận chênh lệch → lập chứng từ điều chỉnh

1. `hn.nv1`: **Điều chuyển kho** `HN01 → DN01` → `hn.quanly` chọn xe, **Xuất kho**: sinh cặp phiếu **DCX** (đi) và **DCN** (đến).
2. `dn.quanly`: mở phiếu DCN, nhập số thực nhận, xác nhận đúng tài xế và đúng xe → **Nhận hàng**. Trang **Đối chiếu tồn** báo **KHỚP**.
3. `dn.quanly` hoặc nhân viên kho Đà Nẵng: **Kiểm kê** → mở đợt, nhập số đếm thực tế, ghi lý do cho dòng chênh lệch, nộp duyệt.
4. `dn.quanly`: duyệt chênh lệch. Hệ thống lập **phiếu điều chỉnh** có lý do; tồn sau điều chỉnh khớp sổ phát sinh (trang **Đối chiếu tồn** vẫn **KHỚP**).

Test tự động: `tests/Integration/ScenarioTest.php`, mục *Tình huống 2* (điều chuyển và đối chiếu) cùng `tests/Integration/StockCountTest.php` (kiểm kê và điều chỉnh).

## 5. Dữ liệu mẫu (mục 5)

`php bin/stockflow seed` nạp dữ liệu xác định (cùng một kết quả mỗi lần): 3 kho, 30 mặt hàng (10 mặt hàng quản lý theo lô và hạn dùng), hơn 200 dòng sổ phát sinh, có điều chuyển, chênh lệch kiểm kê và phiếu điều chỉnh. Mỗi mặt hàng có mã vạch mẫu (EAN-13). Mã nguồn: `database/seeds/DemoSeed.php`. Mọi thao tác đi qua chính các service nghiệp vụ nên dữ liệu mẫu luôn tuân theo quy tắc của hệ thống.

Tên bảng trong code dùng tiếng Việt; tương ứng với tên gợi ý của đề:

| Gợi ý của đề | Bảng trong dự án |
|---|---|
| warehouses | `kho` |
| items | `mat_hang` |
| batches | `lo_hang` |
| vouchers | `phieu_nhap_xuat` |
| voucher_lines | `chi_tiet_phieu_nhap_xuat` |
| stock_movements | `so_phat_sinh` |
| stock_counts | `dot_kiem_ke` · `chi_tiet_kiem_ke` |
