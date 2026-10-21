<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\User;

final class AuthService
{
    public function __construct(private Db $db) {}

    public function attempt(string $username, string $password): ?User
    {
        $row = $this->db->one('SELECT * FROM nguoi_dung WHERE username = ? AND active', [trim($username)]);
        // Luôn tốn một lần băm để thời gian phản hồi không lộ tài khoản có tồn tại hay không.
        $ok = password_verify($password, $row['password_hash'] ?? password_hash('timing-equaliser', PASSWORD_DEFAULT));
        return $row && $ok ? $this->hydrate($row) : null;
    }

    public function find(int $id): ?User
    {
        $row = $this->db->one('SELECT * FROM nguoi_dung WHERE id = ? AND active', [$id]);
        return $row ? $this->hydrate($row) : null;
    }

    private function hydrate(array $row): User
    {
        return new User((int)$row['id'], $row['username'], $row['full_name'], (bool)$row['is_admin']);
    }
}
