<div align="center">

<img src="docs/images/hero.svg" alt="Chương Đại Dương — quản lý kho" width="100%">

<a href="https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1"><img src="https://readme-typing-svg.demolab.com?font=Fira+Code&weight=600&size=22&duration=2600&pause=800&color=EE4D2D&center=true&vCenter=true&width=700&lines=Qu%E1%BA%A3n+l%C3%BD+kho+%C4%91a+%C4%91i%E1%BB%83m;%C4%90i%E1%BB%81u+chuy%E1%BB%83n+h%C3%A0ng+an+to%C3%A0n+nguy%C3%AAn;Ki%E1%BB%83m+k%C3%AA+%E2%86%92+%C4%91i%E1%BB%81u+ch%E1%BB%89nh+c%C3%B3+d%E1%BA%A5u+v%E1%BA%BFt;T%E1%BB%93n+lu%C3%B4n+kh%E1%BB%9Bp+s%E1%BB%95+ph%C3%A1t+sinh" alt="Typing SVG"></a>

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL%208%2B%20·%20XAMPP-4479A1?logo=mysql&logoColor=white)](#-chạy-từ-a-đến-z)
[![Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen)](#-cấu-trúc)
[![Tests](https://img.shields.io/badge/tests-263%20passing-ee4d2d)](#-kiểm-thử)
[![License](https://img.shields.io/badge/license-MIT-fbbf24)](LICENSE)
[![Stars](https://img.shields.io/github/stars/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1?style=social)](https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1/stargazers)

**[Xem nhanh](#-xem-nhanh) · [Chạy từ A đến Z](#-chạy-từ-a-đến-z) · [Thử ngay](#-thử-ngay-2-tình-huống) · [Kiểm thử](#-kiểm-thử)**

</div>

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

## 🎬 Xem nhanh

<div align="center"><img src="docs/images/tour.gif" alt="Tour giao diện Chương Đại Dương" width="94%"></div>

| 🏬 | 📥📤 | 🚚 | 📋 | 📖 | ↩️ |
|:-:|:-:|:-:|:-:|:-:|:-:|
| **Nhiều kho**<br>quyền theo kho | **Nhập / Xuất**<br>nháp → duyệt | **Điều chuyển**<br>2 kho · 1 giao dịch | **Đối chiếu**<br>tồn khớp sổ | **Sổ bất biến**<br>tồn tính từ sổ | **Sửa = đảo**<br>không xoá lịch sử |

<div align="center"><img src="docs/images/transfer-flow.svg" alt="Điều chuyển: COMMIT hoặc ROLLBACK" width="92%"></div>

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

## 🚀 Chạy từ A đến Z

> **Cả nhóm dùng một database duy nhất: MySQL (XAMPP).** Cần `git` + **PHP ≥ 8.1** (có `pdo_mysql`, XAMPP đã kèm sẵn) + **MySQL ≥ 8.0.16 / MariaDB ≥ 10.4**.

### 1️⃣ Chuẩn bị

- Cài [XAMPP](https://www.apachefriends.org), mở XAMPP Control Panel → bấm **Start** ở **MySQL**.
- Cài `git`. Kiểm tra PHP: `php -v` (≥ 8.1). Chưa có PHP trong PATH thì dùng PHP của XAMPP (`C:\xampp\php`).

### 2️⃣ Lấy mã nguồn

```bash
git clone https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1.git stockflow
cd stockflow
```

### 3️⃣ Một lệnh để cập nhật mọi thứ

```bash
php bin/stockflow update
```

Lệnh này dùng cho **cả máy mới lẫn sau mỗi lần `git pull`** (chạy lại bao nhiêu lần cũng an toàn):
1. **Cấu hình kết nối:** chưa có thì dùng mặc định của XAMPP (`root`, không mật khẩu, database `stockflow`) → `.local/db.json` (chỉ nằm trên máy bạn, không bị commit). Mật khẩu khác thì chạy `php bin/stockflow db-config`.
2. **Tạo database** `stockflow` nếu máy chưa có, rồi **so với chuẩn của dự án** (bảng · cột · khoá ngoại/CHECK · trigger — chuẩn lấy thẳng từ `database/migrations`).
   - Đúng chuẩn → không đụng gì.
   - Thiếu bảng mới → chỉ thêm bảng, giữ nguyên dữ liệu.
   - Cấu trúc đổi → **sao lưu toàn bộ dữ liệu** vào `.local/backup-…/` → dựng lại đúng chuẩn → nạp lại dữ liệu cũ.
3. **Database trống** → nạp dữ liệu mẫu (3 kho · 30 mặt hàng · phiếu nhập xuất · sổ phát sinh, đối chiếu **KHỚP**).
4. **Bật git hook** `.githooks/post-merge`: từ giờ mỗi lần `git pull` mà thư mục `database/` có thay đổi thì **database tự được cập nhật**.

> ⚠️ Khi `update` / `db-sync` đang chạy **đừng tắt terminal hay kill tiến trình php** — nếu bị ngắt giữa lúc nạp lại, dữ liệu vẫn nằm trong `.local/backup-…/` (mỗi bảng một file `.ndjson`).

`php bin/stockflow db-sync --check` chỉ **báo cáo** database có đúng chuẩn không, không sửa.

### 4️⃣ Chạy web

```bash
php bin/stockflow serve        # → http://127.0.0.1:8080   (đổi cổng: PORT=9000 php bin/stockflow serve)
```

`serve` cũng tự kiểm tra database trước khi mở web.

### 5️⃣ Đăng nhập (mật khẩu chung `demo1234`)

| Tài khoản | Vai trò | Làm được gì |
|---|---|---|
| `admin` | **Quản trị hệ thống** (chủ hệ thống) | **Xem thống kê** tồn & xuất nhập của từng kho · **quản lý** kho, mặt hàng, tài khoản con, phân quyền · *không* lập phiếu nhập xuất |
| `hn.quanly` · `dn.quanly` · `hcm.quanly` | **Quản lý kho** (mỗi kho đúng 1) | Xác nhận / huỷ / điều chỉnh phiếu nhập xuất · đối chiếu, báo cáo — **chỉ trong kho mình** |
| `hn.nv1` `hn.nv2` · `dn.nv1` `dn.nv2` · `hcm.nv1` `hcm.nv2` | **Nhân viên kho** (mỗi kho nhiều) | Lập phiếu nhập / xuất / điều chuyển (nháp) — **chỉ trong kho mình** |

**Mô hình tài khoản:** một tài khoản cấp cao quản lý nhiều tài khoản con; mỗi kho có **1 quản lý + nhiều nhân viên**.
Tài khoản con được gắn đúng một kho nên khi lập phiếu, hệ thống **tự lấy kho (và địa chỉ) của tài khoản**.
Trang **Phân quyền chức năng** cho phép quản trị bật/tắt từng chức năng theo vai trò: chức năng bị thu hồi sẽ **ẩn khỏi menu và bị chặn ở máy chủ**, nhưng **không bị xoá** — bật lại là dùng được ngay.

<details><summary><b>🛠 Gặp lỗi?</b></summary>

| Thông báo | Cách xử lý |
|---|---|
| `php: command not found` | Thêm `C:\xampp\php` vào PATH (hoặc gọi `C:\xampp\php\php.exe bin/stockflow update`) |
| Không kết nối được MySQL / `Connection refused` | Mở XAMPP → Start **MySQL** rồi chạy lại `update` |
| `Access denied` | Máy bạn đặt mật khẩu cho `root`: chạy `php bin/stockflow db-config` |
| Cổng 8080 bận | `PORT=9000 php bin/stockflow serve` |

Lỗi kết nối được giải thích kèm cách xử lý (MySQL chưa chạy / sai mật khẩu / chưa có CSDL / thiếu quyền).
</details>

<details><summary><b>🗄 Dùng tài khoản MySQL riêng thay vì root (tuỳ chọn)</b></summary>

```sql
CREATE DATABASE stockflow      CHARACTER SET utf8mb4;
CREATE DATABASE stockflow_test CHARACTER SET utf8mb4;   -- chỉ để chạy test
CREATE USER 'stockflow'@'localhost' IDENTIFIED BY 'DoiMatKhauNay';
GRANT ALL ON stockflow.*      TO 'stockflow'@'localhost';
GRANT ALL ON stockflow_test.* TO 'stockflow'@'localhost';
SET GLOBAL log_bin_trust_function_creators = 1;         -- tuỳ chọn: khoá bất biến bằng trigger
```

```bash
php bin/stockflow db-config    # nhập kết nối → .local/db.json
php bin/stockflow update
php bin/stockflow test         # chạy test trên CSDL stockflow_test (tự xoá sạch & dựng lại, không đụng dữ liệu thật)
```
</details>

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

## 🧭 Thử ngay: 2 tình huống

**① Nhập → xác nhận → xuất → tra cứu**
`hn.nv1` *Lập phiếu nhập* → đăng xuất → `hn.quanly` *Xác nhận* → `hn.nv1` *Lập phiếu xuất* → `hn.quanly` *Xác nhận* → **Hàng hoá** (danh mục + quy cách lô + tồn theo kho) · **Lịch sử phát sinh**

**② Điều chuyển liên kho → đối chiếu**
`hn.nv1` *Điều chuyển kho* HN01→DN01 → `hn.quanly` *Xác nhận* (sinh **DCX + DCN**) → `dn.quanly` *Nhận hàng* → **Đối chiếu tồn** báo **KHỚP**

> 🔒 Đăng nhập `hn.nv1` rồi mở phiếu nhập xuất của kho DN01 ⇒ **403**; nhân viên mở trang *Đối chiếu* / *Báo cáo* (bị thu hồi) ⇒ **403**. Quyền kiểm tra ở máy chủ, không chỉ ẩn nút.

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

## 🧪 Kiểm thử

```bash
bin/stockflow test         # 263 test · cần MySQL (xem mục MySQL ở trên)
```

| Đề bài yêu cầu | Test |
|---|---|
| Còn 5, hai yêu cầu cùng xuất 4 ⇒ chỉ một thành công | `ConcurrentIssueTest` — 2 tiến trình thật |
| Lỗi giữa trừ A và cộng B ⇒ không dở dang | `TransferAtomicityTest` — chèn lỗi giữa chừng |
| Gửi lại phiếu nhập xuất đã xác nhận ⇒ không ghi thêm | `IdempotencyTest` |
| Tài khoản kho A không xuất được kho B | `AuthorizationTest` + `AuthorizationHttpTest` (POST thật) |
| Điều chuyển liên kho, tồn khớp sổ | `ScenarioTest` |

<details><summary><b>🧱 Cấu trúc</b></summary>

```
public/            front controller + CSS/JS thuần
src/Core/          Router · Session+CSRF · Db (giao dịch, retry deadlock) · DI
src/Domain/        quy tắc thuần · enum · lỗi nghiệp vụ
src/Service/       Ledger · Voucher · Transfer · StockQuery · Authorizer
src/Http/          controller mỏng
templates/         giao diện
database/          migrations · seed
storage/avatars/   ảnh đại diện do người dùng tải lên (tên file lưu ở cột nguoi_dung.anh_dai_dien; không commit ảnh)
tests/             Unit · Integration · Http
bin/stockflow      update · db-config · db-sync · migrate · seed · setup · serve · test
```

Điểm cốt lõi: tồn **tính từ sổ phát sinh bất biến** · khoá dòng theo thứ tự cố định (không deadlock) · `CHECK quantity ≥ 0` · khoá `idempotency_key` + `UNIQUE voucher_line_id` · sửa bằng phiếu nhập xuất đảo (`UNIQUE correction_of`).
</details>

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

<div align="center">

### ⭐ Thấy hữu ích? Tặng một sao nhé!

[![Star History Chart](https://api.star-history.com/svg?repos=buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1&type=Date)](https://star-history.com/#buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1&Date)

<sub>MIT · Phát triển từ chuỗi lab PHP + MySQL của nhóm PTPMMNM-CNTT-1811-NHOM-1</sub>

<img src="https://capsule-render.vercel.app/api?type=waving&color=0:ee4d2d,100:ff8a5c&height=110&section=footer" width="100%" alt="">

</div>
