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
}
