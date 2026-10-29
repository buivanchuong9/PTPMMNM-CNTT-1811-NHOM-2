<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\User;

/** Đối tác (nhà cung cấp / khách hàng) và quan hệ «mặt hàng ← nhà cung cấp». Chỉ tài khoản cấp cao được sửa. */
final class PartnerService
{
    public const TYPES = ['SUPPLIER' => 'Nhà cung cấp', 'CUSTOMER' => 'Khách hàng'];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    /** Quản trị hệ thống và quản lý kho được thêm / sửa đối tác; nhân viên kho chỉ xem. */
    public function canEdit(User $user): bool
    {
        return $user->isAdmin || $user->role === AccountRole::Manager;
    }

    private function requireEditor(User $user): void
    {
        if (!$this->canEdit($user)) {
            throw new Forbidden('Chỉ quản trị hệ thống hoặc quản lý kho mới được thao tác với đối tác');
        }
    }

    /**
     * Đối tác kèm số mặt hàng cung cấp và danh sách SKU. Nhà cung cấp dùng chung cả hệ thống; khách hàng mỗi kho một danh sách riêng —
     * $kho khác null thì chỉ lấy khách của kho đó (và khách chưa gắn kho). @return list<array<string,mixed>>
     */
    public function all(?int $kho = null): array
    {
        $rows = $this->db->all('SELECT p.*, k.ma AS kho_ma, (SELECT COUNT(*) FROM mat_hang_nha_cung_cap l WHERE l.doi_tac_id = p.id) AS item_count FROM doi_tac p LEFT JOIN kho k ON k.id = p.kho_id'
            . ($kho !== null ? " WHERE p.loai = 'SUPPLIER' OR p.kho_id IS NULL OR p.kho_id = " . (int)$kho : '') . ' ORDER BY p.ma');
        $skus = [];
        foreach ($this->db->all('SELECT l.doi_tac_id, l.mat_hang_id, i.ma_hang, i.ten FROM mat_hang_nha_cung_cap l JOIN mat_hang i ON i.id = l.mat_hang_id ORDER BY i.ma_hang') as $l) {
            $skus[(int)$l['doi_tac_id']][] = ['id' => (int)$l['mat_hang_id'], 'sku' => $l['ma_hang'], 'name' => $l['ten']];
        }
        foreach ($rows as &$r) {
            $r['items'] = $skus[(int)$r['id']] ?? [];
        }
        return $rows;
    }

    /** Nhà cung cấp đang hoạt động (cho ô chọn). @return list<array<string,mixed>> */
    public function suppliers(): array
    {
        return $this->db->all("SELECT id, ma, ten FROM doi_tac WHERE loai = 'SUPPLIER' AND hoat_dong ORDER BY ten");
    }

    /**
     * Khách hàng thuộc về một kho. Tài khoản của kho tạo khách ⇒ khách của kho đó; quản trị tạo ⇒ kho gần nhất theo địa chỉ
     * (miền nào kho miền đó phục vụ). Nhà cung cấp, hoặc địa chỉ chưa nhận ra tỉnh, thì để trống. $force: đổi cả khi đã có kho.
     */
    private function assignWarehouse(int $id, string $type, string $address, ?User $actor = null, bool $force = false): void
    {
        if ($type !== 'CUSTOMER' || (!$force && $this->db->value('SELECT kho_id FROM doi_tac WHERE id = ?', [$id]) !== null)) {
            return;
        }
        if ($actor && $actor->warehouseId) {
            $this->db->exec('UPDATE doi_tac SET kho_id = ? WHERE id = ?', [$actor->warehouseId, $id]);
            return;
        }
        $kho = \StockFlow\Domain\VnAddress::nearest($this->db->all('SELECT id, dia_chi FROM kho WHERE hoat_dong'), $address);
        if ($kho !== null) {
            $this->db->exec('UPDATE doi_tac SET kho_id = ? WHERE id = ?', [$kho, $id]);
        }
    }

    /** Gợi ý tên đối tác cho form phiếu: nhập ⇒ nhà cung cấp, xuất ⇒ khách hàng. @return list<string> */
    public function names(string $type, ?int $kho = null): array
    {
        return array_column($this->db->all('SELECT ten FROM doi_tac WHERE loai = ? AND hoat_dong' . ($kho !== null ? ' AND (kho_id IS NULL OR kho_id = ' . (int)$kho . ')' : '') . ' ORDER BY ten', [$type]), 'ten');
    }

    /** @return array<int,list<array{id:int,ma:string,ten:string}>> mặt hàng ⇒ nhà cung cấp */
    public function suppliersByItem(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT l.mat_hang_id, p.id, p.ma, p.ten, p.hoat_dong FROM mat_hang_nha_cung_cap l JOIN doi_tac p ON p.id = l.doi_tac_id ORDER BY p.ten') as $r) {
            $out[(int)$r['mat_hang_id']][] = ['id' => (int)$r['id'], 'ma' => $r['ma'], 'ten' => $r['ten'], 'hoat_dong' => (bool)$r['hoat_dong']];
        }
        return $out;
    }

    private function clean(string $code, string $name, string $type, string $contact, string $phone, string $email, string $address, string $note): array
    {
        $code = strtoupper(trim($code));
        $name = trim($name);
        $phone = trim($phone);
        $email = trim($email);
        if (!preg_match('/^[A-Z0-9._-]{2,30}$/', $code)) {
            throw new DomainError('Mã đối tác gồm 2–30 ký tự A-Z, 0-9, ., _ hoặc -');
        }
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainError('Tên đối tác không hợp lệ');
        }
        if (!isset(self::TYPES[$type])) {
            throw new DomainError('Loại đối tác không hợp lệ');
        }
        if ($phone !== '' && !preg_match('/^[0-9 .+-]{8,15}$/', $phone)) {
            throw new DomainError('Số điện thoại không hợp lệ');
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120)) {
            throw new DomainError('Email không hợp lệ');
        }
        foreach ([[$contact, 120], [$address, 255], [$note, 255]] as [$v, $max]) {
            if (mb_strlen(trim($v)) > $max) {
                throw new DomainError('Nội dung nhập quá dài');
            }
        }
        return [$code, $name, $type, trim($contact), $phone, $email, trim($address), trim($note)];
    }

    public function add(User $actor, string $code, string $name, string $type, string $contact, string $phone, string $email, string $address, string $note): int
    {
        $this->requireEditor($actor);
        [$code, $name, $type, $contact, $phone, $email, $address, $note] = $this->clean($code, $name, $type, $contact, $phone, $email, $address, $note);
        if ($this->db->value('SELECT 1 FROM doi_tac WHERE ma = ?', [$code])) {
            throw new DomainError("Mã đối tác $code đã tồn tại");
        }
        $id = $this->db->insert('INSERT INTO doi_tac (ma, ten, loai, nguoi_lien_he, so_dien_thoai, email, dia_chi, ghi_chu) VALUES (?,?,?,?,?,?,?,?)',
            [$code, $name, $type, $contact, $phone, $email, $address, $note]);
        $this->assignWarehouse($id, $type, $address, $actor);
        $this->audit->record($actor->id, 'partner.create', 'partner', $id, "$code · $name");
        return $id;
    }

    public function update(User $actor, int $id, string $code, string $name, string $type, string $contact, string $phone, string $email, string $address, string $note): void
    {
        $this->requireEditor($actor);
        [$code, $name, $type, $contact, $phone, $email, $address, $note] = $this->clean($code, $name, $type, $contact, $phone, $email, $address, $note);
        $old = $this->db->one('SELECT * FROM doi_tac WHERE id = ?', [$id]) ?? throw new DomainError('Đối tác không tồn tại');
        $this->requireOwnCustomer($actor, $old);
        if ($this->db->value('SELECT 1 FROM doi_tac WHERE ma = ? AND id <> ?', [$code, $id])) {
            throw new DomainError("Mã đối tác $code đã tồn tại");
        }
        if ($old['loai'] === 'SUPPLIER' && $type !== 'SUPPLIER' && $this->db->value('SELECT 1 FROM mat_hang_nha_cung_cap WHERE doi_tac_id = ?', [$id])) {
            throw new DomainError('Đối tác đang là nhà cung cấp của một số mặt hàng — gỡ khỏi các mặt hàng đó trước khi đổi loại');
        }
        $this->db->exec('UPDATE doi_tac SET ma = ?, ten = ?, loai = ?, nguoi_lien_he = ?, so_dien_thoai = ?, email = ?, dia_chi = ?, ghi_chu = ? WHERE id = ?',
            [$code, $name, $type, $contact, $phone, $email, $address, $note, $id]);
        $this->assignWarehouse($id, $type, $address, $actor, $actor->isAdmin && $old['dia_chi'] !== $address);   // quản trị đổi địa chỉ khách ⇒ xếp lại kho gần nhất
        $this->audit->record($actor->id, 'partner.update', 'partner', $id, "$code · $name");
    }

    /** Quản lý kho chỉ thao tác với khách hàng của kho mình (khách chưa gắn kho thì ai cũng sửa được); quản trị thao tác tất cả. */
    private function requireOwnCustomer(User $actor, array $partner): void
    {
        if ($partner['loai'] === 'CUSTOMER' && !$actor->isAdmin && $partner['kho_id'] !== null && (int)$partner['kho_id'] !== (int)$actor->warehouseId) {
            throw new Forbidden('Khách hàng này thuộc kho khác — chỉ kho đó hoặc quản trị hệ thống mới sửa được');
        }
    }

    public function setActive(User $actor, int $id, bool $active): void
    {
        $this->requireEditor($actor);
        $p = $this->db->one('SELECT ma, loai, kho_id FROM doi_tac WHERE id = ?', [$id]) ?? throw new DomainError('Đối tác không tồn tại');
        $this->requireOwnCustomer($actor, $p);
        $this->db->exec('UPDATE doi_tac SET hoat_dong = ? WHERE id = ?', [(int)$active, $id]);
        $this->audit->record($actor->id, $active ? 'partner.enable' : 'partner.disable', 'partner', $id, $p['ma']);
    }

    private function normName(string $name): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $name));
    }

    /** Tên này đã có trong danh sách đối tác (không phân biệt hoa thường)? $kho: chỉ tính khách của kho đó (khách kho khác không dùng chung). */
    public function known(string $name, ?int $kho = null): bool
    {
        return (bool)$this->db->value('SELECT 1 FROM doi_tac WHERE LOWER(ten) = LOWER(?)' . ($kho !== null ? ' AND (loai = \'SUPPLIER\' OR kho_id IS NULL OR kho_id = ' . (int)$kho . ')' : ''), [$this->normName($name)]);
    }

    /** Khách chưa có trong danh sách ⇒ bắt buộc có đủ người liên hệ, SĐT, địa chỉ mới cho xuất. @return bool true nếu là khách mới */
    public function requireNewCustomerInfo(string $name, string $contact, string $phone, string $address, ?int $kho = null): bool
    {
        $name = $this->normName($name);
        if ($name === '' || $this->known($name, $kho)) {
            return false;
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new DomainError('Tên khách hàng không hợp lệ');
        }
        if (trim($contact) === '' || trim($phone) === '' || trim($address) === '') {
            throw new DomainError("«{$name}» là khách hàng mới — nhập đủ người liên hệ, số điện thoại và địa chỉ rồi mới xuất được");
        }
        if (!preg_match('/^[0-9 .+-]{8,15}$/', trim($phone))) {
            throw new DomainError('Số điện thoại khách hàng không hợp lệ');
        }
        if (mb_strlen(trim($contact)) > 120 || mb_strlen(trim($address)) > 255) {
            throw new DomainError('Thông tin khách hàng quá dài');
        }
        return true;
    }

    /**
     * Ghi nhớ địa chỉ giao hàng + người nhận cho khách đã có trong danh sách, để lần sau chọn khách là tự điền (tích «Lưu địa chỉ cho khách» khi giao hàng).
     * Chỉ cập nhật khách hàng đã tồn tại; người có chức năng xác nhận phiếu xuất. @return bool true nếu đã cập nhật
     */
    public function rememberDelivery(User $actor, string $name, string $address, string $contact, string $phone): bool
    {
        $this->auth->requirePermission($actor, \StockFlow\Domain\Permission::XacNhanPhieu);
        $row = $this->db->one("SELECT id, ma FROM doi_tac WHERE loai = 'CUSTOMER' AND LOWER(ten) = LOWER(?)" . ($actor->warehouseId ? ' AND (kho_id IS NULL OR kho_id = ' . (int)$actor->warehouseId . ')' : '') . ' ORDER BY kho_id IS NULL', [$this->normName($name)]);
        $address = trim($address);
        if (!$row || $address === '' || mb_strlen($address) > 255) {
            return false;
        }
        $contact = mb_substr(trim($contact), 0, 120);
        $phone = preg_match('/^[0-9 .+-]{8,15}$/', trim($phone)) ? trim($phone) : '';
        $this->db->exec('UPDATE doi_tac SET dia_chi = ?, nguoi_lien_he = CASE WHEN ? <> \'\' THEN ? ELSE nguoi_lien_he END, so_dien_thoai = CASE WHEN ? <> \'\' THEN ? ELSE so_dien_thoai END WHERE id = ?',
            [$address, $contact, $contact, $phone, $phone, (int)$row['id']]);
        $this->assignWarehouse((int)$row['id'], 'CUSTOMER', $address, $actor);   // khách chưa gắn kho ⇒ gắn vào kho đang giao
        $this->audit->record($actor->id, 'partner.update', 'partner', (int)$row['id'], $row['ma'] . ' · lưu địa chỉ giao hàng: ' . $address);
        return true;
    }

    /** Lưu khách hàng mới (mã KH-0001…) kèm thông tin đã nhập ở phiếu xuất. @return ?int id vừa tạo, null nếu tên đã có */
    public function ensureCustomer(User $actor, string $name, string $contact = '', string $phone = '', string $address = ''): ?int
    {
        $name = $this->normName($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160 || $this->known($name, $actor->warehouseId)) {
            return null;
        }
        $n = (int)$this->db->value('SELECT COALESCE(MAX(id), 0) FROM doi_tac') + 1;
        while ($this->db->value('SELECT 1 FROM doi_tac WHERE ma = ?', ['KH-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT)])) {
            $n++;
        }
        $code = 'KH-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
        $id = $this->db->insert("INSERT INTO doi_tac (ma, ten, loai, nguoi_lien_he, so_dien_thoai, dia_chi) VALUES (?,?,'CUSTOMER',?,?,?)", [$code, $name, trim($contact), trim($phone), trim($address)]);
        $this->assignWarehouse($id, 'CUSTOMER', trim($address), $actor);
        $this->audit->record($actor->id, 'partner.auto', 'partner', $id, "$code · $name (tự lưu từ phiếu xuất)");
        return $id;
    }

    /** Đặt lại danh sách mặt hàng mà một nhà cung cấp cung cấp (quản lý kho / quản trị). */
    public function setPartnerItems(User $actor, int $partnerId, array $itemIds): void
    {
        $this->requireEditor($actor);
        $p = $this->db->one('SELECT ma, loai FROM doi_tac WHERE id = ?', [$partnerId]) ?? throw new DomainError('Đối tác không tồn tại');
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn($v) => $v > 0)));
        if ($p['loai'] !== 'SUPPLIER' && $ids) {
            throw new DomainError('Chỉ nhà cung cấp mới gắn được mặt hàng');
        }
        foreach ($ids as $iid) {
            if (!$this->db->value('SELECT 1 FROM mat_hang WHERE id = ?', [$iid])) {
                throw new DomainError('Mặt hàng không hợp lệ');
            }
        }
        $this->db->transaction(function () use ($partnerId, $ids) {
            $this->db->exec('DELETE FROM mat_hang_nha_cung_cap WHERE doi_tac_id = ?', [$partnerId]);
            foreach ($ids as $iid) {
                $this->db->exec('INSERT INTO mat_hang_nha_cung_cap (mat_hang_id, doi_tac_id) VALUES (?,?)', [$iid, $partnerId]);
            }
        });
        $this->audit->record($actor->id, 'partner.items', 'partner', $partnerId, $p['ma'] . ' · ' . count($ids) . ' mặt hàng');
    }

    /** Đặt lại toàn bộ nhà cung cấp của một mặt hàng. Chỉ nhận đối tác loại nhà cung cấp. */
    public function setItemSuppliers(User $actor, int $itemId, array $partnerIds): void
    {
        $this->auth->requireAdmin($actor);
        $item = $this->db->one('SELECT ma_hang FROM mat_hang WHERE id = ?', [$itemId]) ?? throw new DomainError('Mặt hàng không tồn tại');
        $ids = array_values(array_unique(array_filter(array_map('intval', $partnerIds), fn($v) => $v > 0)));
        foreach ($ids as $pid) {
            if (!$this->db->value("SELECT 1 FROM doi_tac WHERE id = ? AND loai = 'SUPPLIER'", [$pid])) {
                throw new DomainError('Nhà cung cấp không hợp lệ');
            }
        }
        $this->db->transaction(function () use ($itemId, $ids) {
            $this->db->exec('DELETE FROM mat_hang_nha_cung_cap WHERE mat_hang_id = ?', [$itemId]);
            foreach ($ids as $pid) {
                $this->db->exec('INSERT INTO mat_hang_nha_cung_cap (mat_hang_id, doi_tac_id) VALUES (?,?)', [$itemId, $pid]);
            }
        });
        $this->audit->record($actor->id, 'item.suppliers', 'item', $itemId, $item['ma_hang'] . ' · ' . count($ids) . ' nhà cung cấp');
    }
}
