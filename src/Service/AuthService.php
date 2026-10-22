<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\User;

final class AuthService
{
    public function __construct(private Db $db) {}

    public function attempt(string $username, string $password): ?User
    {
        $row = $this->db->one('SELECT * FROM nguoi_dung WHERE ten_dang_nhap = ? AND hoat_dong', [trim($username)]);
        // Luôn tốn một lần băm để thời gian phản hồi không lộ tài khoản có tồn tại hay không.
        $ok = password_verify($password, $row['mat_khau_ma_hoa'] ?? password_hash('timing-equaliser', PASSWORD_DEFAULT));
        return $row && $ok ? $this->hydrate($row) : null;
    }

    public function find(int $id): ?User
    {
        $row = $this->db->one('SELECT * FROM nguoi_dung WHERE id = ? AND hoat_dong', [$id]);
        return $row ? $this->hydrate($row) : null;
    }

    private function hydrate(array $row): User
    {
        return new User((int)$row['id'], $row['ten_dang_nhap'], $row['ho_ten'], AccountRole::from($row['vai_tro']), $row['kho_id'] === null ? null : (int)$row['kho_id']);
    }
}
