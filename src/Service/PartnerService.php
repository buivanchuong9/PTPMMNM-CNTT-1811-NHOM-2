<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\User;

/** Đối tác (nhà cung cấp / khách hàng) và quan hệ «mặt hàng ← nhà cung cấp». Chỉ tài khoản cấp cao được sửa. */
final class PartnerService
{
    public const TYPES = ['SUPPLIER' => 'Nhà cung cấp', 'CUSTOMER' => 'Khách hàng'];

    public function __construct(private Db $db, private Authorizer $auth, private AuditLog $audit) {}

    /** Đối tác kèm số mặt hàng cung cấp và danh sách SKU. @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->db->all('SELECT p.*, (SELECT COUNT(*) FROM mat_hang_nha_cung_cap l WHERE l.doi_tac_id = p.id) AS item_count FROM doi_tac p ORDER BY p.ma');
        $skus = [];
        foreach ($this->db->all('SELECT l.doi_tac_id, i.ma_hang, i.ten FROM mat_hang_nha_cung_cap l JOIN mat_hang i ON i.id = l.mat_hang_id ORDER BY i.ma_hang') as $l) {
            $skus[(int)$l['doi_tac_id']][] = ['sku' => $l['ma_hang'], 'name' => $l['ten']];
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

    /** Gợi ý tên đối tác cho form phiếu: nhập ⇒ nhà cung cấp, xuất ⇒ khách hàng. @return list<string> */
    public function names(string $type): array
    {
        return array_column($this->db->all('SELECT ten FROM doi_tac WHERE loai = ? AND hoat_dong ORDER BY ten', [$type]), 'ten');
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
        $this->auth->requireAdmin($actor);
        [$code, $name, $type, $contact, $phone, $email, $address, $note] = $this->clean($code, $name, $type, $contact, $phone, $email, $address, $note);
        if ($this->db->value('SELECT 1 FROM doi_tac WHERE ma = ?', [$code])) {
            throw new DomainError("Mã đối tác $code đã tồn tại");
        }
        $id = $this->db->insert('INSERT INTO doi_tac (ma, ten, loai, nguoi_lien_he, so_dien_thoai, email, dia_chi, ghi_chu) VALUES (?,?,?,?,?,?,?,?)',
            [$code, $name, $type, $contact, $phone, $email, $address, $note]);
        $this->audit->record($actor->id, 'partner.create', 'partner', $id, "$code · $name");
        return $id;
    }

    public function update(User $actor, int $id, string $code, string $name, string $type, string $contact, string $phone, string $email, string $address, string $note): void
    {
        $this->auth->requireAdmin($actor);
        [$code, $name, $type, $contact, $phone, $email, $address, $note] = $this->clean($code, $name, $type, $contact, $phone, $email, $address, $note);
        $old = $this->db->one('SELECT * FROM doi_tac WHERE id = ?', [$id]) ?? throw new DomainError('Đối tác không tồn tại');
        if ($this->db->value('SELECT 1 FROM doi_tac WHERE ma = ? AND id <> ?', [$code, $id])) {
            throw new DomainError("Mã đối tác $code đã tồn tại");
        }
        if ($old['loai'] === 'SUPPLIER' && $type !== 'SUPPLIER' && $this->db->value('SELECT 1 FROM mat_hang_nha_cung_cap WHERE doi_tac_id = ?', [$id])) {
            throw new DomainError('Đối tác đang là nhà cung cấp của một số mặt hàng — gỡ khỏi các mặt hàng đó trước khi đổi loại');
        }
        $this->db->exec('UPDATE doi_tac SET ma = ?, ten = ?, loai = ?, nguoi_lien_he = ?, so_dien_thoai = ?, email = ?, dia_chi = ?, ghi_chu = ? WHERE id = ?',
            [$code, $name, $type, $contact, $phone, $email, $address, $note, $id]);
        $this->audit->record($actor->id, 'partner.update', 'partner', $id, "$code · $name");
    }

    public function setActive(User $actor, int $id, bool $active): void
    {
        $this->auth->requireAdmin($actor);
        $p = $this->db->one('SELECT ma FROM doi_tac WHERE id = ?', [$id]) ?? throw new DomainError('Đối tác không tồn tại');
        $this->db->exec('UPDATE doi_tac SET hoat_dong = ? WHERE id = ?', [(int)$active, $id]);
        $this->audit->record($actor->id, $active ? 'partner.enable' : 'partner.disable', 'partner', $id, $p['ma']);
    }

    private function normName(string $name): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $name));
    }

    /** Tên này đã có trong danh sách đối tác (không phân biệt hoa thường)? */
    public function known(string $name): bool
    {
        return (bool)$this->db->value('SELECT 1 FROM doi_tac WHERE LOWER(ten) = LOWER(?)', [$this->normName($name)]);
    }

    /** Khách chưa có trong danh sách ⇒ bắt buộc có đủ người liên hệ, SĐT, địa chỉ mới cho xuất. @return bool true nếu là khách mới */
    public function requireNewCustomerInfo(string $name, string $contact, string $phone, string $address): bool
    {
        $name = $this->normName($name);
        if ($name === '' || $this->known($name)) {
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

    /** Lưu khách hàng mới (mã KH-0001…) kèm thông tin đã nhập ở phiếu xuất. @return ?int id vừa tạo, null nếu tên đã có */
    public function ensureCustomer(User $actor, string $name, string $contact = '', string $phone = '', string $address = ''): ?int
    {
        $name = $this->normName($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160 || $this->known($name)) {
            return null;
        }
        $n = (int)$this->db->value('SELECT COALESCE(MAX(id), 0) FROM doi_tac') + 1;
        while ($this->db->value('SELECT 1 FROM doi_tac WHERE ma = ?', ['KH-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT)])) {
            $n++;
        }
        $code = 'KH-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
        $id = $this->db->insert("INSERT INTO doi_tac (ma, ten, loai, nguoi_lien_he, so_dien_thoai, dia_chi) VALUES (?,?,'CUSTOMER',?,?,?)", [$code, $name, trim($contact), trim($phone), trim($address)]);
        $this->audit->record($actor->id, 'partner.auto', 'partner', $id, "$code · $name (tự lưu từ phiếu xuất)");
        return $id;
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
