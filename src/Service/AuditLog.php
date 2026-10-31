<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;

/** Nhật ký thao tác (ai làm gì, lúc nào). Ghi trong cùng giao dịch với thao tác nghiệp vụ. */
final class AuditLog
{
    public function __construct(private Db $db) {}

    public function record(?int $userId, string $action, string $entity, ?int $entityId, string $detail = ''): void
    {
        $this->db->exec('INSERT INTO nhat_ky (nguoi_dung_id, hanh_dong, doi_tuong, doi_tuong_id, chi_tiet) VALUES (?,?,?,?,?)',
            [$userId, $action, $entity, $entityId, mb_substr($detail, 0, 500)]);
    }

    public function forEntity(string $entity, int $id): array
    {
        return $this->db->all('SELECT a.*, u.ho_ten FROM nhat_ky a LEFT JOIN nguoi_dung u ON u.id = a.nguoi_dung_id
                               WHERE a.doi_tuong = ? AND a.doi_tuong_id = ? ORDER BY a.id', [$entity, $id]);
    }

    /**
     * Các thao tác gần nhất (mới trước), tối đa $limit dòng. $userWarehouses = null: tất cả; ngược lại chỉ thao tác của tài khoản thuộc các kho đó.
     * @param list<int>|null $userWarehouses
     */
    public function recent(?array $userWarehouses, int $limit = 3000): array
    {
        $where = '';
        $args = [];
        if ($userWarehouses !== null) {
            if ($userWarehouses === []) {
                return [];
            }
            $where = 'WHERE u.kho_id IN (' . implode(',', array_fill(0, count($userWarehouses), '?')) . ')';
            $args = $userWarehouses;
        }
        return $this->db->all("SELECT a.id, a.nguoi_dung_id, a.hanh_dong, a.doi_tuong, a.doi_tuong_id, a.chi_tiet, a.ngay_tao, u.ho_ten, u.ten_dang_nhap, k.ma AS kho_ma, v.ma AS phieu_ma
                               FROM nhat_ky a LEFT JOIN nguoi_dung u ON u.id = a.nguoi_dung_id LEFT JOIN kho k ON k.id = u.kho_id
                               LEFT JOIN phieu_nhap_xuat v ON a.doi_tuong = 'voucher' AND v.id = a.doi_tuong_id $where ORDER BY a.id DESC LIMIT " . (int)$limit, $args);
    }
}
