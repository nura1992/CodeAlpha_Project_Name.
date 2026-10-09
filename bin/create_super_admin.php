<?php
declare(strict_types=1);

/**
 * Creates the first Super Admin. Run once after importing schema.sql and seed.sql.
 *
 *   SUPER_ADMIN_PASSWORD='long-random-passphrase' php bin/create_super_admin.php --username=admin --email=ops@example.com
 *   (or pipe the password on STDIN:  printf '%s' 'secret' | php bin/create_super_admin.php ...)
 *
 * The password is never accepted as a command-line argument (it would leak into shell history and ps).
 * The account must change its password at first sign-in. Refuses to run if an active Super Admin exists.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$opts = getopt('', ['username:', 'email:']);
$username = strtolower(trim((string) ($opts['username'] ?? '')));
$email = strtolower(trim((string) ($opts['email'] ?? '')));

if (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
    fwrite(STDERR, "Provide --username (3-30 letters, numbers or underscores).\n");
    exit(2);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Provide a valid --email.\n");
    exit(2);
}

$password = getenv('SUPER_ADMIN_PASSWORD');
if ($password === false || $password === '') {
    $password = rtrim((string) fgets(STDIN), "\r\n");
}
if (strlen($password) < 14) {
    fwrite(STDERR, "Password must be at least 14 characters.\n");
    exit(2);
}

$existing = (int) Database::value(
    "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
     JOIN users u ON u.id = ur.user_id
     WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL"
);
if ($existing > 0) {
    fwrite(STDERR, "An active Super Admin already exists. Use the staff console to add more.\n");
    exit(3);
}

$roleId = (int) Database::value("SELECT id FROM roles WHERE slug = 'super_admin'");
if ($roleId === 0) {
    fwrite(STDERR, "The super_admin role is missing. Did you run database/seed.sql?\n");
    exit(4);
}

$userId = Database::transaction(static function () use ($username, $email, $password, $roleId): int {
    $id = Database::insert(
        "INSERT INTO users (username, email, password_hash, account_type, status, email_verified_at, must_change_password)
         VALUES (?, ?, ?, 'staff', 'active', NOW(), 1)",
        [$username, $email, Auth::hashPassword($password)]
    );
    Database::exec('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, NULL)', [$id, $roleId]);
    return $id;
});

Audit::log(null, 'staff.super_admin_created', 'user', $userId, 'Created with bin/create_super_admin.php');
echo "Super Admin #{$userId} created. They must change the password at first sign-in.\n";
