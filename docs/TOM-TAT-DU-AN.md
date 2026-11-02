# Tóm tắt dự án «StockFlow — Quản lý kho»

> File này để đọc lại nhanh ở các lần làm việc sau. **Có tính năng mới, đổi quy tắc, thêm bảng/trang thì cập nhật vào đây** (mục 12 là nhật ký thay đổi).
> Cập nhật lần cuối: 2026-11-01 · nhánh làm việc `Duong` · 18 migration · 33 file test.

## 1. Tổng quan

- Hệ thống quản lý **nhiều kho** viết bằng **PHP 8.1+ thuần + MySQL 8 / MariaDB 10.4**, **không framework, không Composer** (autoloader PSR-4 tự viết ở `src/bootstrap.php`).
- Đề tài môn *Phát triển phần mềm mã nguồn mở*, lớp CNTT 1811, nhóm 2. Repo GitHub: `buivanchuong9/PTPMMNM-CNTT-1811-NHOM-2`.
- Giao diện tiếng Việt, tên bảng/cột trong CSDL cũng tiếng Việt (không dấu).
- **Nguyên tắc cốt lõi:** tồn kho tính từ **sổ phát sinh chỉ ghi thêm** (`so_phat_sinh`). Phiếu đã xác nhận **bất biến**; sửa sai bằng **phiếu điều chỉnh/đảo**, không sửa phiếu cũ. Trigger ở CSDL chặn sửa/xoá sổ.
- Tiền là **số nguyên đồng (VND)**, không dùng số thập phân.
- Giờ lưu trong CSDL là **UTC**; hiển thị và tính ngày theo **giờ Việt Nam (UTC+7)** (`gmdate(..., time() + 7*3600)`). Từng có lỗi lệch ngày do quên đổi múi giờ — chú ý khi viết query theo ngày.

## 2. Chạy và kiểm thử

```bash
php bin/stockflow update   # cấu hình XAMPP, tạo/sửa DB đúng chuẩn, nạp dữ liệu mẫu, bật git hook
php bin/stockflow serve    # http://127.0.0.1:8080  (PORT=9000 để đổi cổng)
php bin/stockflow test     # chạy toàn bộ test trên DB riêng <tên DB>_test (tự xoá và dựng lại)
```

- Đăng nhập mẫu (mật khẩu `demo1234`): `admin`, `hn.quanly`, `hn.nv1`, `dn.quanly`.
- Lệnh CLI khác: `db-config`, `check`, `db-sync` (`--check` chỉ báo cáo), `migrate`, `seed`, `setup`, `backup`/`restore` (file `database/backup/stockflow-data.json.gz`, commit lên git để cả nhóm dùng chung dữ liệu), `demo` (SQLite, không cần MySQL).
- Cấu hình DB: biến môi trường `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` hoặc `.local/db.json` (không commit). Mặc định XAMPP: root, không mật khẩu, DB `stockflow`.
- `.githooks/post-merge`: sau `git pull`, nếu `database/` đổi thì tự chạy `db-sync` (sao lưu vào `.local/` rồi sửa DB cho khớp).
- CI: `.github/workflows/test.yml` (PHP 8.3 + MySQL 8) chạy `php bin/stockflow test` khi push `main` hoặc mở PR.
- Test: `tests/Unit` (thuần), `tests/Integration` (cần MySQL, có test đồng thời bằng 2 tiến trình thật), `tests/Http`. Lần chạy ngày 2026-11-01: **1521 test, 0 lỗi**.
- Ánh xạ yêu cầu đề tài ↔ test: `docs/PHEP-THU-VA-TINH-HUONG.md`.

## 3. Cấu trúc thư mục

| Thư mục | Nội dung |
|---|---|
| `public/index.php` | Front controller. `public/assets/` gồm `css/` (app, theme, ui, dark, dialog, scan, erp, **navy** — nạp cuối cùng, finova), `js/` (app, scan, address, route-map, convoy, crew, trips, logistics, delivery-km, dialog-fx, theme…), `data/vn-address.json` (34 tỉnh/thành, 3.321 phường/xã), `img/`. |
| `src/Core/` | Hạ tầng: `App` (định tuyến + xác thực + CSRF + xử lý lỗi + header bảo mật), `Router`, `Db` (mysqli + SQLite demo, giao dịch lồng, tự thử lại deadlock), `Migrator`, `SchemaSync`, `DataBackup`, `Session`, `View`, `Container` (DI tự nối theo type-hint), `Barcode` (SVG EAN-13/Code128), `Hooks` (chèn lỗi cho test), `Paginator` (mặc định 20 dòng), `helpers.php` (hàm giao diện: `e()`, `n()`, `dt()`, `icon()`, nhãn trạng thái/loại phiếu, lọc tìm kiếm không dấu…). |
| `src/Domain/` | Thuần nghiệp vụ, không chạm DB: `Permission` (enum quyền), `AccountRole`, `StockRules`, `VoucherType`, `TransportRules` (quy định vận tải theo Luật 36/2024), `VnAddress`, `User`, các exception (`DomainError`=422, `Forbidden`=403, `NotFound`=404, `InsufficientStock`). |
| `src/Service/` | Nghiệp vụ (xem mục 6). |
| `src/Http/Controller/` | Mỏng, gọi service. Mọi controller kế thừa `Controller` (có `need($user, Permission)` và `render()`). |
| `templates/` | View PHP theo thư mục chức năng; layout chung `layout/app.php` (menu, chuông thông báo), `layout/bare.php` (trang lỗi). |
| `database/migrations/` | 001…018, phân cách câu lệnh bằng dòng `-- @@`, chạy lại an toàn. Cột thêm sau nằm ở `Migrator::ADDED_COLUMNS`. |
| `database/seeds/DemoSeed.php` | Dữ liệu mẫu xác định: 3 kho, 30 mặt hàng, hơn 200 dòng sổ; đi qua chính các service. |
| `storage/` | Ảnh đại diện, ảnh bằng lái (không commit). `.local/` = cấu hình + bản sao lưu cục bộ (không commit). |
| `cj.txt`, `cj2.txt`, `cj3.txt` | File cookie của curl dùng khi thử tay, không phải mã nguồn. |

## 4. Vai trò và phân quyền

- **ADMIN** (chủ hệ thống): xem thống kê mọi kho, quản lý kho/mặt hàng/tài khoản/phân quyền, đặt giá gốc, duyệt đề xuất hàng. **Không** thao tác nhập/xuất/điều chuyển (`Permission::operational()`).
- **MANAGER** (quản lý kho): mỗi kho đúng 1 (UNIQUE ở DB). Xác nhận phiếu, duyệt kiểm kê, quản lý đội xe của kho mình, đặt giá bán của kho mình, lập đơn đặt hàng.
- **STAFF** (nhân viên kho): nhiều người/kho; lập phiếu nháp, đếm kiểm kê, nhận bảo hành, đề xuất huỷ.
- Hai lớp kiểm tra ở `Authorizer`: (1) **chức năng** `Permission` theo bảng `phan_quyen_vai_tro` (thiếu dòng thì dùng `Permission::defaultFor`); (2) **phạm vi kho** — MANAGER/STAFF chỉ thao tác trong kho được gắn. Quyền thao tác phụ thuộc quyền xem tương ứng (`Permission::requires()`). Service luôn gọi `require()` trước khi ghi; ẩn nút chỉ là tiện lợi.
- Quyền hiện có (33): nhóm *Xem* (tổng quan, tồn kho, phiếu, lịch sử, đối chiếu, báo cáo, danh mục, kiểm kê, đội xe, bảo hành, kho huỷ, đơn đặt hàng), nhóm *Tác nghiệp* (lập phiếu nhập/xuất/điều chuyển, xác nhận, nhận hàng điều chuyển, điều chỉnh phiếu, kiểm kê thực hiện/duyệt, thêm lô, điều chỉnh giá, quản lý đội xe, gán mã vạch, xử lý bảo hành, lập/xác nhận huỷ, lập đơn đặt hàng), `QuanLyNhanVien`, nhóm *Quản trị* (quản lý kho/mặt hàng/tài khoản, phân quyền, đặt giá gốc, duyệt đề xuất hàng).
- Thêm quyền mới: sửa enum `Permission` (case + `label` + `description` + `group` + `operational`/`adminOnly`/`requires`/`defaultFor` nếu cần) rồi thêm vào menu `templates/layout/app.php`.

## 5. Mô hình dữ liệu chính

- **Kho & người:** `kho`, `nguoi_dung` (role ADMIN/MANAGER/STAFF, `kho_id`), `phan_quyen_vai_tro`, `cai_dat` (cài đặt phạm vi system/user), `nhat_ky` (audit).
- **Hàng:** `don_vi_tinh`, `danh_muc`, `mat_hang` (có `dinh_muc_toi_thieu`, `quan_ly_lo`, `quy_cach_lo`, `ma_vach`, `bao_hanh_thang`, `gia_von`, `thue_suat`, `gia_ban`), `lo_hang` (số lô + hạn dùng; hàng không theo lô dùng lô mặc định `NOLOT`), `mat_hang_nha_cung_cap`.
- **Sổ cái:** `phieu_nhap_xuat` (loại RECEIPT/ISSUE/TRANSFER_OUT/TRANSFER_IN/ADJUSTMENT, trạng thái DRAFT/CONFIRMED/CANCELLED, `khoa_chong_trung` UNIQUE, `dieu_chinh_cho_id` UNIQUE), `chi_tiet_phieu_nhap_xuat`, `ton_kho` (số dư hiện hành, `CHECK >= 0`, chỉ để khoá + chặn âm), `so_phat_sinh` (**nguồn sự thật**, `chi_tiet_phieu_nhap_xuat_id` UNIQUE, có `bien_dong` và `ton_sau`), `bo_dem` (đánh số mã phiếu).
- **Tiền:** `lich_su_gia`, `gia_ban_kho` (giá bán riêng từng kho; giá gốc + thuế dùng chung do admin đặt).
- **Kiểm kê:** `dot_kiem_ke`, `chi_tiet_kiem_ke`.
- **Vận tải:** `tai_xe`, `xe`, `chuyen_van_chuyen`, `that_thoat_hang`, `khoang_cach_kho`, bảng giao hàng cho khách (migration 015), convoy (016).
- **Quy trình khác:** `yeu_cau_chuyen_hang` (+`_dong`), `de_xuat_mat_hang`, `bao_hanh`, `huy_hang`, `nhap_loi`, `tra_hang_ncc` (+`chi_tiet_tra_hang`), bảng khách trả hàng + đơn đặt hàng NCC (018), `doi_tac`.
- Mã phiếu: PN (nhập), PX (xuất), DCX / DCN (điều chuyển đi / đến), DCH (điều chỉnh).

## 6. Nghiệp vụ theo service

- **Lõi sổ:** `Ledger` (khoá dòng tồn theo thứ tự cố định → không deadlock; kiểm tra đủ hàng; ghi `so_phat_sinh` — luôn trong giao dịch của người gọi), `VoucherService` (lập → kiểm tra → xác nhận/huỷ; sửa bằng phiếu điều chỉnh), `VoucherRepository`, `StockQuery` (tồn/lịch sử/đối chiếu, tính từ sổ).
- **Xuất hàng:** hàng theo lô tự xuất **lô hết hạn trước (FEFO)**; không xuất lô đã hết hạn. Xuất bán có thể kèm giao hàng (`DeliveryService`: khách tự lấy / xe kho trả công theo km / thuê vận tải ngoài). `SaleCodeService` sinh mã lô hàng + mã món hàng lúc bán cho mặt hàng có bảo hành.
- **Điều chuyển** (`TransferService`): 2 bước trong 2 giao dịch — kho đi xác nhận DCX (trừ tồn, hàng "đang vận chuyển", mở DCN chờ) → kho đến nhận trên DCN (cộng tồn, nhập số thực nhận, xác nhận đúng tài xế/xe). Thu hồi chuyến khi xe còn trên đường; báo "chưa nhận được hàng" → admin xác nhận mất hàng (`that_thoat_hang`, trừ điểm/lương tài xế nếu tài xế chịu). `TransferRequestService`: kho thiếu hàng xin kho khác (nhân viên lập → quản lý kho mình xác nhận → quản lý kho cấp chấp nhận hoặc từ chối → mở DCX nháp).
- **Vận tải** (`TransportService` + `TransportRules` + `FleetService`): chọn xe theo tải trọng, thể tích, khổ xe, đăng kiểm/bảo hiểm còn hạn, bằng lái đúng hạng, tuổi tài xế. Kết quả có `block` (chặn xuất kho) và `warn` (chưa khai số liệu). Đội xe do quản lý từng kho quản lý; xe/tài xế tách rời nhau, có tài xế dự phòng, xe máy cho đơn nhỏ, thuê ngoài. Tài xế có điểm uy tín; thu nhập = tiền từng chuyến.
- **Kiểm kê** (`StockCountService`): OPEN (đếm) → REVIEW (nộp, mọi dòng lệch phải có lý do) → CLOSED (duyệt, lập phiếu điều chỉnh). Điều chỉnh = thực đếm − sổ sách lúc mở đợt, **cộng** vào tồn hiện tại để giữ nguyên giao dịch phát sinh trong lúc đếm. Dòng trống = khớp.
- **Giá** (`PricingService`): giá gốc + thuế do admin đặt (dùng chung, điều chuyển lấy giá này); giá bán mỗi kho một giá do quản lý kho đặt; dòng phiếu lưu giá tại lúc lập; mọi lần đổi ghi `lich_su_gia`.
- **Nhận hàng NCC có kiểm chất lượng** (`SupplierReturnService`): phiếu nhập ghi số nhận + số không đạt; tồn chỉ cộng phần đạt; tự lập phiếu trả hàng NCC (CHO_CAP_LAI) → NCC cấp lại bằng phiếu nhập "cấp lại" → DA_CAP_LAI.
- **Đơn đặt hàng NCC** (`OrderService`): NHAP → DA_GUI → NHAN_MOT_PHAN → DA_NHAN (tự cập nhật khi phiếu nhập gắn đơn được ghi sổ); DA_DONG / HUY. Chỉ quản lý kho lập; admin chỉ xem.
- **Khách trả hàng** (`CustomerReturnService`): chọn phiếu xuất gốc, ghi số trả + số lỗi; DRAFT → CONFIRMED: phần tốt nhập lại kho bằng phiếu điều chỉnh có lý do bắt đầu **"Khách trả hàng …"**; phần lỗi theo nguyên nhân: lỗi nhà sản xuất → kho bảo hành, còn lại → đề xuất huỷ. Tính tiền hoàn khách và tiền bên chịu trách nhiệm phải đền.
- **Bảo hành** (`WarrantyService`): chỉ mặt hàng `bao_hanh_thang > 0`; tra cứu bằng mã lô + mã món; TIEP_NHAN → DA_SUA | DOI_MOI | TU_CHOI → DA_TRA; đổi mới thì trừ 1 đơn vị tồn bằng phiếu điều chỉnh; in phiếu bảo hành.
- **Kho huỷ** (`DisposalService`): CHO_DUYET → TRONG_KHO_HUY (trừ tồn) → DA_TIEU_HUY; TU_CHOI. Người có quyền duyệt tự lập thì duyệt luôn. Tính giá trị thiệt hại theo giá vốn.
- **Danh mục & nhập liệu:** `CatalogService` (kho, mặt hàng, lô, tài khoản), `PartnerService` (NCC/khách; quản lý thêm ⇒ chờ quản trị duyệt), `ItemProposalService` (quét mã vạch chưa có → quản lý đề xuất → admin duyệt kèm giá gốc/thuế), `ImportService` (CSV: danh mục mặt hàng cho admin; tồn đầu kỳ gom thành **một phiếu nhập nháp**; luôn xem trước, dòng lỗi bị bỏ qua).
- **Hệ thống:** `AuthService`, `SettingsService` (3 cấp: cá nhân / kho / hệ thống; có `idle_minutes` tự đăng xuất, `canh_bao_han_ngay`…), `AuditLog` (ghi cùng giao dịch nghiệp vụ), `ChangeTracker` (số "+N" và bôi đậm mục mới theo từng tài khoản), `NavBadges`, `NotificationService` (chuông: tính từ trạng thái hiện tại, xong việc thì tự mất).

## 7. Trang và đường dẫn (menu theo `templates/layout/app.php`)

| Nhóm | Trang |
|---|---|
| Tổng quan | `/` (dashboard, việc cần làm, cảnh báo), chuông thông báo, `/api/badges` |
| Tác nghiệp (menu) | **Nhập xuất hàng**: `/vouchers` (+ `/new`, `/{id}`, `/{id}/print`, confirm/cancel/recall/correct…), `/transfers/new`, `/orders` · **Đổi trả & hỏng**: `/returns` (trả NCC), `/customer-returns`, `/warranty` (+ lookup/suggest/print), `/disposals` · **Chuyển hàng & kiểm kê**: `/requests`, `/counts` |
| Hàng hoá | `/inventory`, `/import` (CSV), `/labels` (in tem: A4 3×8 và tem cuộn 50×30 mm), `/reconcile`, `/proposals`, `/items` |
| Sổ sách & báo cáo | `/history` (sổ phát sinh), `/audit`; mục **Báo cáo** xổ ra: `/reports/nxt` (Nhập–Xuất–Tồn) + `/reports/stock-card` (thẻ kho), `/reports` (tổng hợp + CSV: `/reports/stock.csv`, `movements.csv`, `reconcile.csv`); mục **Tài chính** xổ ra: `/valuation` (Giá trị tồn kho), `/money/in` (Chi phí nhập hàng), `/money/out` (Doanh thu bán hàng), `/money/profit` (Lãi gộp) |
| Danh mục | `/warehouses`, `/partners`, `/fleet`, `/users`, `/roles`, `/settings` |
| Khác | `/scan/lookup` (quét mã, ảnh xử lý ở trình duyệt, máy chủ chỉ nhận chuỗi mã), `/finova/orders` (trang demo giao diện đơn hàng, dữ liệu mẫu ở JS) |

Thêm trang mới: khai báo route trong `App::routes()`, thêm controller/service, thêm mục menu, thêm template, thêm test trang vào `tests/Http/PagesSmokeTest.php`.

## 8. Quy tắc viết code ở dự án này

- `declare(strict_types=1)` mọi file; controller mỏng, nghiệp vụ nằm ở service; `Domain` không chạm DB.
- Ghi dữ liệu trong `$this->db->transaction(...)`; ghi `AuditLog` trong cùng giao dịch.
- Lỗi nghiệp vụ ném `DomainError` (hiện 422 cho người dùng), thiếu quyền ném `Forbidden`.
- POST bắt buộc CSRF (`App::dispatch` kiểm tra tự động). Tạo phiếu dùng khoá idempotency.
- Comment tiếng Việt, ngắn, giải thích *vì sao*; docblock đầu lớp mô tả vòng đời trạng thái. Giữ phong cách này.
- Header bảo mật (CSP, X-Frame-Options…) đặt ở `App::secure()`; CSP chỉ cho phép OSM tile / OSRM / Nominatim ở ngoài.

## 9. Sở thích giao diện của người dùng

- Bảng màu: **navy** làm màu chính, đỏ chỉ cho lỗi/huỷ/nút xoá (người dùng chọn ngày 2026-11-01). Đổi màu ở `navy.css`, đừng sửa rải rác các file CSS cũ.
- Chê trang rối: **ít chữ, ít khối**, việc cần làm lên trên.
- Mỗi trang cải thiện theo quy trình: đọc code → tra web lớn để tham khảo → đề xuất gọn → kiểm tra bằng ảnh chụp.

## 10. Quy ước làm việc với Claude

- **Không** thêm dòng `Co-Authored-By` vào commit (GitHub sẽ hiện Claude trong Contributors). Chỉ commit/push khi người dùng yêu cầu.
- Hỏi số liệu đúng/sai thì **đọc code và đối chiếu** trước khi kết luận; nói rõ cái nào đã chạy thật, cái nào mới chỉ đọc code.

## 11. Đã có và chưa có (để khỏi đề xuất trùng)

**Đã có:** định mức tồn tối thiểu theo mặt hàng (`dinh_muc_toi_thieu`, đánh dấu "sắp hết" ở Hàng hoá), cảnh báo lô hết hạn / sắp hết hạn (mặc định 30 ngày, chỉnh được) trên chuông và Hàng hoá, FEFO khi xuất, kiểm kê có duyệt, đối chiếu tồn, mã vạch + quét bằng camera, in tem, nhập CSV, đặt hàng NCC, khách trả hàng, bảo hành, kho huỷ, đội xe/tài xế, báo cáo Nhập–Xuất–Tồn + thẻ kho, vốn kho/tiền nhập/xuất/lãi, nhật ký thao tác, sao lưu/khôi phục dữ liệu; về UX: báo lỗi ngay trên form giữ nguyên dữ liệu đã nhập (hộp thoại thông báo + `old-input`), «việc cần làm» trên trang chủ, chuông thông báo, hộp thoại quét mã dùng chung (`scan_dialog`). **Người dùng đã xác nhận (2026-11-01) không cần làm thêm về UI/UX đợt này** — đừng đề xuất lại các ý đó khi chưa đọc code và hỏi.

**Chưa có (ý tưởng):**
- Gợi ý số lượng đặt theo tốc độ bán (hiện đã có đường `?restock=mã:số lượng` từ cảnh báo sắp hết hàng sang form đơn, nhưng số lượng chưa tính theo tốc độ bán). Chưa lưu đơn giá nhập từng dòng và giá nhà cung cấp báo.
- Báo cáo hàng tồn chậm luân chuyển (không xuất trong 60/90 ngày).
- Vị trí trong kho (kệ/ô/tầng) và danh sách soạn hàng (pick list).
- Phân loại ABC, vòng quay tồn kho.
- Đánh giá nhà cung cấp (tỷ lệ hàng lỗi, giao đúng hạn, lệch số lượng so với đơn) — dữ liệu `nhap_loi` đã có.
- Xếp hạng hao hụt theo kho/người.

## 12. Nhật ký thay đổi (thêm dòng mới lên đầu)

| Ngày | Thay đổi |
|---|---|
| 2026-11-02 | **Đối tác cần quản trị duyệt:** quản lý kho thêm đối tác ⇒ `doi_tac.duyet = CHO_DUYET`, `hoat_dong = 0` (chưa hiện trong phiếu); quản trị bấm **Duyệt** (`PartnerService::approve`) hoặc **Từ chối** (xoá). Quản lý không tự duyệt / bật / sửa đối tác chờ duyệt; quản trị thêm thì dùng ngay. Có chuông + số «+N» ở menu Đối tác cho quản trị. Test: `tests/Integration/PartnerApprovalTest.php`. Hộp thoại nhập dài cuộn được, nút Lưu/Huỷ dính đáy (`ui.css`). |
| 2026-11-02 | Khung biểu mẫu rõ hơn (chế độ sáng, `navy.css` cuối file): viền ô nhập / ô chọn `#7d8ba1`, viền khung nhóm (fieldset), thẻ, thanh lọc `#b4bfcf`, chữ gợi ý (placeholder) đậm hơn, nhãn ô đen. |
| 2026-11-02 | Menu trái: **bỏ kéo mép để chỉnh độ rộng** (xoá đoạn «side-grip» trong `app.js`, ẩn `.side-grip`); độ rộng cố định 220px, độ rộng đã lưu cũ bị xoá khỏi trình duyệt. |
| 2026-11-02 | Khung ứng dụng đứng yên: thanh trên + menu trái không bị đẩy lệch khi trình duyệt tự cuộn (đặt `overflow: clip` cho `body.app-shell`, `.shell`, `.main-wrap` ở `navy.css`; `app.js` trả `scrollLeft/scrollTop` của khung về 0). Menu dài quá màn hình vẫn cuộn trong chính nó. |
| 2026-11-02 | Thanh trên: thêm dòng chữ **«Quản lý kho hàng»** cạnh logo (`templates/layout/app.php`, `.brand-tag` trong `navy.css`; một dòng, in hoa, ẩn khi màn hình hẹp dưới 1250px). |
| 2026-11-02 | Menu: tách mục **Tài chính** khỏi «Báo cáo» (`templates/layout/app.php`) và đặt lại tên theo thuật ngữ kế toán bán hàng: Vốn kho → **Giá trị tồn kho**, Tiền nhập → **Chi phí nhập hàng**, Tiền xuất → **Doanh thu bán hàng**, Tiền lãi → **Lãi gộp** (cả tiêu đề trang, nhãn thẻ, tên cột trong `templates/valuation/*`). Đường dẫn giữ nguyên. «Doanh thu» là số ước tính từ phiếu xuất × giá bán, chưa phải sổ thu chi. |
| 2026-11-02 | Trang Hàng hoá (nhân viên / quản lý kho): thêm nút **«Đặt NCC»** cạnh thẻ tình trạng, mở `/orders/new?restock=<mặt hàng>:<số lượng gợi ý>` với mặt hàng đã chọn sẵn (gợi ý = 2 × định mức − tồn, không có định mức thì 1). |
| 2026-11-02 | Trang Hàng hoá: thêm sắp xếp **Số lượng: ít → nhiều / nhiều → ít** vào ô «Sắp theo» (theo tồn của kho mình; admin: tổng các kho đang xem). |
| 2026-11-02 | Trang Hàng hoá: thêm bộ lọc **số lượng còn** (Mọi số lượng / Còn hàng / Sắp hết ≤ định mức / Hết hàng) theo tồn của kho mình (admin: tổng các kho đang xem); liên kết «Hàng sắp hết» từ Tổng quan (`?stock=low`) nay hoạt động (`InventoryController`, `templates/inventory/index.php`). |
| 2026-11-02 | Chuông thông báo: bỏ chia đôi, chỉ còn **một cột dài** (Việc cần làm ở trên, Hoạt động mới ở dưới), rộng 430px (`navy.css`, cuối file). |
| 2026-11-02 | README gọn lại và đổi sang tông xanh: `docs/images/hero.svg` đổi nền cam → xanh theo bảng màu navy của `navy.css` (`#3d73c4` → `#2f5fa8` → `#172d4b`), sau đó vẽ lại kiểu mới: chữ bên trái, khung «Tổng quan kho» thu nhỏ bên phải có cột mọc lên và hộp hàng chạy HN01 → DN01 → HCM01); đổi tên thành `banner.svg` vì GitHub và trình duyệt giữ bản cũ của `hero.svg` trong bộ nhớ đệm; thêm `docs/images/screens.gif` (slide 8 màn hình, chụp bằng Chrome headless 1440×900 với `hn.quanly`, ghép khung trình duyệt bằng Pillow) ngay dưới badge; bỏ dòng chữ chạy và thanh `divider.svg`; sóng cuối trang đổi sang navy (`#3d73c4` → `#172d4b`) kèm dòng «Nhóm 2 · CNTT 1811 · MIT» (`divider.svg` giờ không còn dùng); đóng `<div align="center">` sau badge để nội dung căn trái. |
| 2026-11-02 | **Làm lại trang Tổng quan** (`DashboardController`, `StockQuery::dashboardPeriod`, `templates/dashboard/index.php`, biểu đồ trong `app.js`): chọn kỳ 7 / 30 / 90 ngày / tháng này; 5 thẻ (giá trị tồn kho theo giá gốc, hàng nhập, hàng xuất kèm số phiếu, số mặt hàng và % so với kỳ trước, hàng đang đi đường, phiếu chờ); biểu đồ xu hướng nhập–xuất (cột + đường chênh lệch), giá trị tồn dạng vòng (admin: theo kho, quản lý: theo danh mục), nhập–xuất theo danh mục, tồn so với định mức. Admin có bảng «So sánh các kho»; quản lý chỉ thấy số liệu kho mình. Đã bỏ các khung «Phát sinh gần đây», «Tồn nhiều nhất», «Phiếu chờ xác nhận», «Sắp hết hàng»: gộp thành một dải thông báo dài (xanh khi không có gì, vàng khi có phiếu chờ / hàng dưới định mức). Số liệu chỉ tính phiếu nhập/xuất (chuyển kho không tính); số lượng cộng chung mọi loại đơn vị nên ghi rõ «đơn vị». Trục biểu đồ định dạng kiểu Việt. |
| 2026-11-02 | Thẻ lọc không có màu: **Đơn đặt hàng** và **Khách trả hàng** gắn màu theo trạng thái (`data-tone`: nháp xám, đã gửi xanh dương, nhận một phần cam, đủ xanh lá, huỷ đỏ); các thẻ không gắn trạng thái (danh mục hàng, đội xe, cài đặt) mỗi thẻ một màu xoay vòng 6 màu (`navy.css`). |
| 2026-11-02 | Kiểu thẻ có khung màu áp dụng **cho mọi thanh thẻ** (`.tabs`), kể cả thẻ không có màu trạng thái (danh mục hàng, đội xe, cài đặt…: khung navy) và nút «Khác» (`navy.css`). |
| 2026-11-02 | Thẻ lọc trạng thái (`.tabs a[data-tone]`: Phiếu, Kho huỷ, Đối tác…) có **khung màu bao quanh chữ**, số đếm là viên thuốc đặc, thẻ đang chọn đổ đặc màu (`navy.css`, cuối file). |
| 2026-11-02 | Chữ trong bảng đậm và đen hơn: nội dung `#111827`, chữ phụ (`.muted`, `small`) xám đậm `#4b5563` thay xám nhạt (`navy.css`, cuối file). |
| 2026-11-02 | Trang Hàng hoá: khi chỉ có một cột giá bán (nhân viên kho) thì gộp thành **một ô tiêu đề «Giá bán»** thay vì hai tầng «Giá bán của kho bạn / HN01 (bạn)», cột gọn lại (`templates/inventory/index.php`). |
| 2026-11-02 | Cột trong danh sách rõ hơn (`navy.css`, cuối file): tiêu đề cột chữ đậm, to hơn, nền riêng + viền dưới màu chính; kẻ dọc nhạt giữa các cột; dòng xen kẽ; sẵn lớp `.num` căn phải cho cột số. |
| 2026-11-01 | **Nhận hàng theo đơn đặt có đếm / quét** (`/vouchers/new?type=RECEIPT&po=…`): cột **Theo đơn** (đặt, đã nhận, còn thiếu + thẻ Đủ / Thiếu N / Thừa N / Chưa đếm), cột **Thực nhận** bắt đầu từ 0, **ô quét để đếm** (máy quét USB gõ mã + Enter, hoặc nút «Mở camera» dùng lại hộp thoại `scan.js` ở chế độ đếm, giữ camera mở). **Mỗi lần quét tăng theo kiện** (`stepSize`: hàng đóng kiện +1 kiện, tích «lẻ» thì +1 cái). Mặt hàng ngoài đơn: báo, không tự thêm. Dòng thực nhận 0 không vào phiếu (JS tắt ô của dòng đó khi gửi). Cột «Không đạt» thu nhỏ. Dữ liệu: `OrderService` đã có, `VoucherController` truyền `orderLines`, `StockQuery::pickList` thêm `ma_vach`. 1556 test (thêm kiểm tra HTTP), 0 lỗi. |
| 2026-11-01 | **Bảo hành: thu lại kết quả** (`WarrantyService::reopen`, `POST /warranty/{id}/reopen`): ở tab «Chờ trả khách», phiếu «Từ chối bảo hành» hoặc «Đã sửa xong» có nút «↩ Thu lại» để quay về «Đang giữ». «Đã đổi mới» không thu lại được vì đã trừ tồn. Thêm `tests/Integration/WarrantyReopenTest.php`; 1554 test, 0 lỗi. |
| 2026-11-01 | **Đổi tên và logo thành StockFlow**: `APP_NAME = 'StockFlow'` (`helpers.php`, `app_brand()` tách «Stock» + «Flow»); logo gốc `StockFlow Warehouse Flow Logo.png` ở thư mục gốc (người dùng đổi tên từ `LOGO.png`); tạo `public/assets/img/stockflow-logo.png` (đầy đủ, dùng ở đăng nhập và thanh trên), `logo-mark.png`, `logo.png`, `favicon.png`, `apple-touch-icon.png`, `public/favicon.ico` (cắt phần biểu tượng). README, hero.svg, terminal.svg, tên trên phiếu in đổi theo. `favicon.svg`/`logo-mark.svg` cũ không còn được dùng. |
| 2026-11-01 | **Kho huỷ: xuất nhiều lô lên một xe**. Tab «Trong kho huỷ» có ô tích từng lô + một khung «Xuất đi tiêu huỷ» chọn **một xe, một tài xế** cho cả đợt (`DisposalService::loadMany`; tải cả đợt phải vừa xe); hàng đã lên xe gom thành từng **chuyến** (cùng xe + cùng giờ xuất) với nút «Đã đến nơi» / «Quay lại» cho cả chuyến (`destroyMany`, `unloadMany`). Máy chủ trả tóm tắt tải + xe phù hợp cho đúng các lô đã tích qua `GET /disposals/fit` (JSON); JS `refreshDispatch` trong `app.js`. Thêm `tests/Integration/DisposalBatchTest.php`; 1543 test, 0 lỗi (sau khi thêm test). |
| 2026-11-01 | Trang `/reconcile` (Đối chiếu tồn) làm rõ và chi tiết hơn: tên cột đời thường (Tồn đang lưu · Cộng từ sổ ghi chép · Chênh lệch), khung kết quả nói rõ ý nghĩa + giờ kiểm tra + nút «Kiểm tra lại» + số dòng phiếu đã kiểm, hướng dẫn khi có chênh lệch, cột Tồn tối thiểu (đánh dấu «Sắp hết» theo tổng tồn các lô), Tình trạng hàng (hạn dùng), Giá trị tồn, lọc theo «Phát sinh cuối», nút **Chi tiết** mở 10 dòng sổ gần nhất kèm tồn sau từng dòng (`StockQuery::recentMoves`; dòng lệch tự mở). JS mở/thu gọn: `data-row-toggle` trong `app.js`. |
| 2026-11-01 | Trang `/inventory`: cột **giá bán** chỉ hiện của kho mình (tài khoản gắn kho; quản trị vẫn thấy mọi kho) — biến `$priceWh` trong `templates/inventory/index.php`; cột tồn theo kho vẫn hiện đủ. Ô tồn kho mình tô nền xanh nhạt thay vì hồng. |
| 2026-11-01 | Thêm bộ lọc cho `/disposals` (lý do huỷ, loại hàng, kho cho admin, thời gian; `DisposalService::search`) và `/labels` (nhà cung cấp, đã/chưa gắn mã vạch, hàng theo lô hay không). Số trên tab Kho huỷ theo đúng bộ lọc. |
| 2026-11-01 | Thêm bộ lọc **nhà cung cấp + thời gian** cho `/orders` và **khách + thời gian** cho `/customer-returns` (`OrderService::search/suppliers`, `CustomerReturnService::search/customers`); số trên các tab tính theo đúng bộ lọc, chuyển tab vẫn giữ lọc. Dùng chung `partials/range_filter.php`. |
| 2026-11-01 | Bỏ hiệu ứng nội dung trôi từ dưới lên / mờ khi bấm menu (`app.js`, đổi `fx` thành `false` ở chỗ gọi `load` khi bấm liên kết); chuyển trang vẫn không tải lại, chỉ là tức thì. |
| 2026-11-01 | Thẻ trạng thái (`.tag-*`), thẻ loại phiếu (`.chip-*`) và nút tạo phiếu dùng **nền đặc màu + chữ trắng** (biến `--ok-solid`, `--warn-solid`, `--danger-solid`, `--info-solid`… trong `navy.css`), bỏ kiểu nền nhạt chữ màu theo góp ý người dùng. Nút tạo phiếu tô màu theo loại: nhập xanh lá, xuất cam, điều chuyển xanh dương (class `btn-act-*` trong `navy.css`; dùng ở `vouchers/index.php`, `dashboard/index.php`). |
| 2026-11-01 | Đổi bảng màu sang **navy** bằng một file ghi đè `public/assets/css/navy.css` (nạp cuối ở `layout/app.php` và `layout/bare.php`): navy là màu chính (nút, liên kết, mục đang chọn), thanh trên navy (`#2f4f7f`), nút/liên kết `#3566b0`, menu trái sáng (chỉ chế độ nền tối mới là navy tối) — đã làm nhạt theo góp ý «xanh đậm quá», đỏ chỉ còn cho lỗi/huỷ/nút nguy hiểm (`--danger`), loại phiếu xuất chuyển sang cam, có chỉnh chế độ nền tối, biểu đồ (`app.js` palette) và nút trên trang in. Muốn đổi sắc navy: sửa biến ở đầu `navy.css`. 1521 test, 0 lỗi. |
| 2026-11-01 | Form «Đơn đặt hàng mới» bổ sung thông tin: cột tồn hiện có / định mức tối thiểu / số đang đặt chưa về (`OrderService::itemInfo`), tiền tạm tính = số lượng × giá gốc (chỉ hiển thị, **chưa lưu giá vào DB**), thẻ liên hệ nhà cung cấp (form + trang chi tiết đơn). Tên mục menu dài giờ xuống dòng thay vì bị cắt (`erp.css`). 1521 test, 0 lỗi. |
| 2026-11-01 | Gọn menu (`templates/layout/app.php`): chỉ đổi nhóm/tên, giữ nguyên khoá mục, đường dẫn, quyền. «Nghiệp vụ kho» tách thành Nhập xuất hàng / Đổi trả & hỏng (gồm Bảo hành, Kho huỷ) / Chuyển hàng & kiểm kê; Báo cáo gom một mục xổ ra; Lịch sử + Nhật ký vào nhóm «Sổ sách & báo cáo». 1521 test, 0 lỗi. |
| 2026-11-01 | Tạo file tóm tắt này. Rà soát báo cáo Nhập–Xuất–Tồn (`NxtController`): công thức tồn đầu/nhập/xuất/tồn cuối đúng; cả 5 loại phiếu đều được tính; lưu ý múi giờ UTC của `ngay_ghi_so`. |
| (commit 8504171) | Sửa lỗi 500 trang in tem (view bị `.gitignore` chặn) và lệch ngày bảo hành do dùng giờ UTC. |
| (commit 4458754) | Khách trả hàng, báo cáo Nhập–Xuất–Tồn, in tem mã vạch, nhập từ file CSV, đặt hàng nhà cung cấp. |
| (commit 354519b) | Cảnh báo hạn dùng, in phiếu bảo hành, xuất CSV, nhật ký thao tác. |
| (trước đó) | Bảo hành theo mã lô/mã món, kho huỷ, đội xe + thuê xe ngoài + cọc, bộ lọc tìm kiếm, thông báo, v.v. — xem `git log`. |

## 13. Cách cập nhật file này

Sau mỗi lần thêm/đổi tính năng: (1) sửa mục liên quan (6, 7, 5, 4), (2) chuyển ý tưởng đã làm từ mục 11 "Chưa có" sang "Đã có", (3) thêm một dòng đầu mục 12, (4) đổi ngày "Cập nhật lần cuối" ở đầu file.
