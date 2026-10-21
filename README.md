<div align="center">

<img src="docs/images/hero.svg" alt="StockFlow WMS" width="100%">

<a href="https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1"><img src="https://readme-typing-svg.demolab.com?font=Fira+Code&weight=600&size=22&duration=2600&pause=800&color=EE4D2D&center=true&vCenter=true&width=700&lines=Qu%E1%BA%A3n+l%C3%BD+kho+%C4%91a+%C4%91i%E1%BB%83m;%C4%90i%E1%BB%81u+chuy%E1%BB%83n+h%C3%A0ng+an+to%C3%A0n+nguy%C3%AAn;Ki%E1%BB%83m+k%C3%AA+%E2%86%92+%C4%91i%E1%BB%81u+ch%E1%BB%89nh+c%C3%B3+d%E1%BA%A5u+v%E1%BA%BFt;T%E1%BB%93n+lu%C3%B4n+kh%E1%BB%9Bp+s%E1%BB%95+ph%C3%A1t+sinh" alt="Typing SVG"></a>

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0.16%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen)](#-cấu-trúc)
[![Tests](https://img.shields.io/badge/tests-263%20passing-ee4d2d)](#-kiểm-thử)
[![License](https://img.shields.io/badge/license-MIT-fbbf24)](LICENSE)
[![Stars](https://img.shields.io/github/stars/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1?style=social)](https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1/stargazers)

**[Xem nhanh](#-xem-nhanh) · [Chạy từ A đến Z](#-chạy-từ-a-đến-z) · [Thử ngay](#-thử-ngay-2-tình-huống) · [Kiểm thử](#-kiểm-thử)**

</div>

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

## 🎬 Xem nhanh

<div align="center"><img src="docs/images/tour.gif" alt="Tour giao diện StockFlow" width="94%"></div>

| 🏬 | 📥📤 | 🚚 | 📋 | 📖 | ↩️ |
|:-:|:-:|:-:|:-:|:-:|:-:|
| **Nhiều kho**<br>quyền theo kho | **Nhập / Xuất**<br>nháp → duyệt | **Điều chuyển**<br>2 kho · 1 giao dịch | **Kiểm kê**<br>lý do + điều chỉnh | **Sổ bất biến**<br>tồn tính từ sổ | **Sửa = đảo**<br>không xoá lịch sử |

<div align="center"><img src="docs/images/transfer-flow.svg" alt="Điều chuyển: COMMIT hoặc ROLLBACK" width="92%"></div>

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

## 🚀 Chạy từ A đến Z

<div align="center"><img src="docs/images/terminal.svg" alt="Cài đặt trong 4 lệnh" width="92%"></div>

> **Cần:** `git` · **PHP ≥ 8.1** (có `mysqli`) · **MySQL ≥ 8.0.16** (MariaDB chưa hỗ trợ)

### 1️⃣ Cài PHP + MySQL

<details open><summary><b>🍎 macOS</b></summary>

```bash
brew install php mysql git
brew services start mysql
```
</details>

<details><summary><b>🐧 Ubuntu / Debian / WSL</b></summary>

```bash
sudo apt update && sudo apt install -y git php-cli php-mysql mysql-server
sudo service mysql start        # WSL;  Ubuntu thường: sudo systemctl start mysql
```
</details>

<details><summary><b>🪟 Windows</b></summary>

Cài **WSL2** (`wsl --install` trong PowerShell quản trị) rồi làm theo tab Ubuntu.
</details>

Kiểm tra: `php -v` (≥ 8.1) · `php -m | grep mysqli` · `mysql --version` (≥ 8.0.16)

### 2️⃣ Lấy mã nguồn

```bash
git clone https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1.git stockflow
cd stockflow
```

### 3️⃣ Tạo CSDL + tài khoản

Mở MySQL (macOS: `mysql -uroot` · Ubuntu: `sudo mysql`) rồi dán:

```sql
CREATE DATABASE stockflow      CHARACTER SET utf8mb4;
CREATE DATABASE stockflow_test CHARACTER SET utf8mb4;   -- chỉ để chạy test
CREATE USER 'stockflow'@'localhost' IDENTIFIED BY 'DoiMatKhauNay';
GRANT ALL ON stockflow.*      TO 'stockflow'@'localhost';
GRANT ALL ON stockflow_test.* TO 'stockflow'@'localhost';
SET GLOBAL log_bin_trust_function_creators = 1;         -- tuỳ chọn: bật khoá bất biến bằng trigger
EXIT;
```

### 4️⃣ Khai báo kết nối

```bash
bin/stockflow db-config
```

Enter để nhận mặc định `127.0.0.1` · `3306` · `stockflow` · `stockflow`, rồi nhập mật khẩu ở bước 3.
Lưu vào `.local/db.json` (chmod 600, đã `.gitignore`). *Thích biến môi trường? Đặt `DB_HOST DB_PORT DB_NAME DB_USER DB_PASS`.*

### 5️⃣ Dựng bảng + dữ liệu mẫu

```bash
bin/stockflow setup
```

> ✔ 3 kho · 30 mặt hàng (10 có lô/hạn dùng) · **235 dòng sổ phát sinh** · 7 điều chuyển · 3 đợt kiểm kê · đối chiếu **KHỚP**

### 6️⃣ Chạy

```bash
bin/stockflow serve        # đổi cổng: PORT=9000 bin/stockflow serve
```

Mở **http://127.0.0.1:8080** và đăng nhập (mật khẩu chung `demo1234`):

| Tài khoản | Làm được gì |
|---|---|
| `hn.keeper` · `dn.keeper` · `hcm.keeper` | Lập phiếu, kiểm kê **ở kho của mình** |
| `hn.approver` · `dn.approver` · `hcm.approver` | Xác nhận / điều chỉnh **ở kho của mình** |
| `giamsat` | Mọi quyền trên cả 3 kho |
| `admin` | Danh mục · tài khoản · phân quyền |

<details><summary><b>🛠 Gặp lỗi?</b></summary>

| Thông báo | Cách xử lý |
|---|---|
| `MYSQLI_EXTENSION_MISSING` | Cài `php-mysql` (Ubuntu) hoặc bật `extension=mysqli` |
| `DATABASE_UNAVAILABLE` · `Access denied` | MySQL chưa chạy / sai cổng / sai mật khẩu → chạy lại `bin/stockflow db-config` |
| `Cần MySQL >= 8.0.16` | Nâng cấp MySQL (MariaDB không dùng được) |
| `⚠ Bỏ qua 002_immutability_triggers` | Tuỳ chọn. `SET GLOBAL log_bin_trust_function_creators = 1;` rồi `bin/stockflow migrate` |
| Cổng 8080 bận | `PORT=9000 bin/stockflow serve` |
| Muốn làm lại từ đầu | `DROP DATABASE stockflow; CREATE DATABASE stockflow CHARACTER SET utf8mb4;` rồi `bin/stockflow setup` |
</details>

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

## 🧭 Thử ngay: 2 tình huống

**① Nhập → xác nhận → xuất → tra cứu**
`hn.keeper` *Lập phiếu nhập* → đăng xuất → `hn.approver` *Xác nhận* → `hn.keeper` *Lập phiếu xuất* → `hn.approver` *Xác nhận* → **Tồn kho** · **Lịch sử phát sinh**

**② Điều chuyển → kiểm kê → điều chỉnh**
`hn.keeper` *Điều chuyển kho* HN01→DN01 → `hn.approver` *Xác nhận* (sinh **DCX + DCN**) → `dn.keeper` *Kiểm kê* nhập số đếm + lý do → `dn.approver` *Xác nhận chênh lệch* → **Đối chiếu tồn** báo **KHỚP**

> 🔒 Đăng nhập `hn.keeper` rồi mở chứng từ của kho DN01 ⇒ **403**. Quyền kiểm tra ở máy chủ, không chỉ ẩn nút.

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

## 🧪 Kiểm thử

```bash
bin/stockflow test         # 263 test · tự dựng lại CSDL stockflow_test
```

| Đề bài yêu cầu | Test |
|---|---|
| Còn 5, hai yêu cầu cùng xuất 4 ⇒ chỉ một thành công | `ConcurrentIssueTest` — 2 tiến trình thật |
| Lỗi giữa trừ A và cộng B ⇒ không dở dang | `TransferAtomicityTest` — chèn lỗi giữa chừng |
| Gửi lại chứng từ đã xác nhận ⇒ không ghi thêm | `IdempotencyTest` |
| Tài khoản kho A không xuất được kho B | `AuthorizationTest` + `AuthorizationHttpTest` (POST thật) |
| Điều chỉnh kiểm kê có lý do, tồn khớp sổ | `StockCountTest` · `ScenarioTest` |

<details><summary><b>🧱 Cấu trúc</b></summary>

```
public/            front controller + CSS/JS thuần
src/Core/          Router · Session+CSRF · Db (giao dịch, retry deadlock) · DI
src/Domain/        quy tắc thuần · enum · lỗi nghiệp vụ
src/Service/       Ledger · Voucher · Transfer · StockCount · StockQuery · Authorizer
src/Http/          controller mỏng
templates/         giao diện
database/          migrations · seed
tests/             Unit · Integration · Http
bin/stockflow      db-config · migrate · seed · setup · serve · test
```

Điểm cốt lõi: tồn **tính từ sổ phát sinh bất biến** · khoá dòng theo thứ tự cố định (không deadlock) · `CHECK quantity ≥ 0` · khoá `idempotency_key` + `UNIQUE voucher_line_id` · sửa bằng chứng từ đảo (`UNIQUE correction_of`).
</details>

<img src="docs/images/divider.svg" width="100%" height="6" alt="">

<div align="center">

### ⭐ Thấy hữu ích? Tặng một sao nhé!

[![Star History Chart](https://api.star-history.com/svg?repos=buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1&type=Date)](https://star-history.com/#buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1&Date)

<sub>MIT · Phát triển từ chuỗi lab PHP + MySQL của nhóm PTPMMNM-CNTT-1811-NHOM-1</sub>

<img src="https://capsule-render.vercel.app/api?type=waving&color=0:ee4d2d,100:ff8a5c&height=110&section=footer" width="100%" alt="">

</div>
