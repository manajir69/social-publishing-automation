<?php
declare(strict_types=1);

namespace App\Security;

use App\Database;

final class Auth
{
    public static function check(): bool { return !empty($_SESSION['admin_id']); }

    public static function id(): ?int { return self::check() ? (int) $_SESSION['admin_id'] : null; }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare('SELECT id, password_hash FROM admins WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($password, $admin['password_hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        return true;
    }

    public static function require(): void
    {
        if (!self::check()) \redirect('index.php?view=login');
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
