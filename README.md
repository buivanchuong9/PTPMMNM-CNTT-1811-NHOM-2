<div align="center">

<img src="docs/images/hero.svg" alt="StockFlow WMS" width="100%">

<a href="https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1"><img src="https://readme-typing-svg.demolab.com?font=Fira+Code&weight=600&size=22&duration=2600&pause=800&color=EE4D2D&center=true&vCenter=true&width=700&lines=Qu%E1%BA%A3n+l%C3%BD+kho+%C4%91a+%C4%91i%E1%BB%83m;%C4%90i%E1%BB%81u+chuy%E1%BB%83n+h%C3%A0ng+an+to%C3%A0n+nguy%C3%AAn;Ki%E1%BB%83m+k%C3%AA+%E2%86%92+%C4%91i%E1%BB%81u+ch%E1%BB%89nh+c%C3%B3+d%E1%BA%A5u+v%E1%BA%BFt;T%E1%BB%93n+lu%C3%B4n+kh%E1%BB%9Bp+s%E1%BB%95+ph%C3%A1t+sinh" alt="Typing SVG"></a>

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![SQLite · MySQL](https://img.shields.io/badge/SQLite%20demo%20·%20MySQL%208%2B-4479A1?logo=sqlite&logoColor=white)](#-chạy-từ-a-đến-z)
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

<div align="center"><img src="docs/images/terminal.svg" alt="Chạy demo bằng 3 lệnh" width="92%"></div>

> **Chỉ cần:** `git` + **PHP ≥ 8.1**. Không cần MySQL, không cần cấu hình — dữ liệu mẫu nằm trong một file SQLite tự sinh.

### 1️⃣ Cài PHP (nếu chưa có)

<details open><summary><b>🍎 macOS</b></summary>

```bash
brew install php git
```
</details>

<details><summary><b>🐧 Ubuntu / Debian / WSL</b></summary>

```bash
sudo apt update && sudo apt install -y git php-cli php-sqlite3
```
</details>

<details><summary><b>🪟 Windows</b></summary>

Cài **WSL2** (`wsl --install` trong PowerShell quản trị) rồi làm theo tab Ubuntu.
</details>

Kiểm tra: `php -v` (≥ 8.1) · `php -m | grep sqlite` (phải thấy `pdo_sqlite`)

### 2️⃣ Lấy mã nguồn

```bash
git clone https://github.com/buivanchuong9/PTPMMNM-CNTT-1811-NHOM-1.git stockflow
cd stockflow
```

### 3️⃣ Chạy

```bash
bin/stockflow demo
```

Lần đầu tự dựng bảng + nạp dữ liệu mẫu (**3 kho · 30 mặt hàng · 235 dòng sổ phát sinh**, đối chiếu **KHỚP**), rồi mở web tại **http://127.0.0.1:8080**.
Đổi cổng: `PORT=9000 bin/stockflow demo` · Làm lại từ đầu: `bin/stockflow demo --reset`

### 4️⃣ Đăng nhập (mật khẩu chung `demo1234`)

| Tài khoản | Làm được gì |
|---|---|
| `hn.keeper` · `dn.keeper` · `hcm.keeper` | Lập phiếu, kiểm kê **ở kho của mình** |
| `hn.approver` · `dn.approver` · `hcm.approver` | Xác nhận / điều chỉnh **ở kho của mình** |
| `giamsat` | Mọi quyền trên cả 3 kho |
| `admin` | Danh mục · tài khoản · phân quyền |

<details><summary><b>🛠 Gặp lỗi?</b></summary>

| Thông báo | Cách xử lý |
|---|---|
| `php: command not found` | Cài PHP ở bước 1 rồi mở lại terminal |
| `PHP chưa có extension pdo_sqlite` | macOS: `brew install php` · Ubuntu: `sudo apt install php-sqlite3` |
| `Permission denied: bin/stockflow` | `chmod +x bin/stockflow` hoặc chạy `php bin/stockflow demo` |
| Cổng 8080 bận | `PORT=9000 bin/stockflow demo` |
</details>

<details><summary><b>🗄 Dùng MySQL thật (tuỳ chọn, cho môi trường chạy lâu dài)</b></summary>

Cần **MySQL ≥ 8.0.16** (MariaDB chưa hỗ trợ). Trong `mysql`:

```sql
CREATE DATABASE stockflow      CHARACTER SET utf8mb4;
CREATE DATABASE stockflow_test CHARACTER SET utf8mb4;   -- chỉ để chạy test
CREATE USER 'stockflow'@'localhost' IDENTIFIED BY 'DoiMatKhauNay';
GRANT ALL ON stockflow.*      TO 'stockflow'@'localhost';
GRANT ALL ON stockflow_test.* TO 'stockflow'@'localhost';
SET GLOBAL log_bin_trust_function_creators = 1;         -- tuỳ chọn: khoá bất biến bằng trigger
```

```bash
bin/stockflow db-config    # nhập kết nối → .local/db.json
bin/stockflow setup        # tạo bảng + nạp dữ liệu mẫu
bin/stockflow serve        # → http://127.0.0.1:8080
bin/stockflow test         # 263 test trên CSDL stockflow_test
```

Lỗi kết nối được giải thích kèm cách xử lý (MySQL chưa chạy / sai mật khẩu / chưa có CSDL / thiếu quyền).
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
bin/stockflow test         # 263 test · cần MySQL (xem mục MySQL ở trên)
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
bin/stockflow      demo · db-config · migrate · seed · setup · serve · test
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
