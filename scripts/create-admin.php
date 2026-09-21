<?php

declare(strict_types=1);

use App\Framework\Bootstrap\EnvironmentManager;

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
EnvironmentManager::initialize();

$username = mb_strtolower(trim((string) ($_ENV['ADMIN_USERNAME'] ?? '')));
$password = (string) ($_ENV['ADMIN_PASSWORD'] ?? '');
$name = trim((string) ($_ENV['ADMIN_NAME'] ?? 'مدیر فروشگاه'));
$role = trim((string) ($_ENV['ADMIN_ROLE'] ?? 'admin'));

if (preg_match('/^[a-z0-9._-]{3,100}$/', $username) !== 1) {
    fwrite(STDERR, "ADMIN_USERNAME must be 3-100 characters using a-z, 0-9, dot, dash or underscore.\n");
    exit(1);
}
if (strlen($password) < 12 || str_starts_with($password, 'REPLACE_WITH_')) {
    fwrite(STDERR, "ADMIN_PASSWORD must be replaced with a real password containing at least 12 characters.\n");
    exit(1);
}
if (!in_array($role, ['admin', 'super_admin', 'catalog_manager', 'content_manager'], true)) {
    fwrite(STDERR, "ADMIN_ROLE is not an allowed admin-panel role.\n");
    exit(1);
}

$phone = trim((string) ($_ENV['ADMIN_PHONE'] ?? ''));
if ($phone === '') {
    $phone = '+989' . str_pad((string) (abs(crc32($username)) % 1000000000), 9, '0', STR_PAD_LEFT);
}

$pdo = new PDO(
    (string) $_ENV['DB_DSN'],
    (string) ($_ENV['DB_USERNAME'] ?? ''),
    (string) ($_ENV['DB_PASSWORD'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('SELECT id,public_id FROM users WHERE lower(username)=:username FOR UPDATE');
    $statement->execute([':username' => $username]);
    $user = $statement->fetch();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($user) {
        $pdo->prepare('UPDATE users SET password_hash=:hash,name=:name,status=\'active\' WHERE id=:id')
            ->execute([':hash' => $hash, ':name' => $name, ':id' => $user['id']]);
        $userId = (int) $user['id'];
        $publicId = (string) $user['public_id'];
    } else {
        $publicId = 'usr_' . bin2hex(random_bytes(10));
        $statement = $pdo->prepare('INSERT INTO users(public_id,phone,name,username,password_hash,status) VALUES(:publicId,:phone,:name,:username,:hash,\'active\') RETURNING id');
        $statement->execute([':publicId' => $publicId, ':phone' => $phone, ':name' => $name, ':username' => $username, ':hash' => $hash]);
        $userId = (int) $statement->fetchColumn();
    }

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO user_roles(user_id,role_id)
        SELECT :userId,id FROM roles WHERE name=:role
        ON CONFLICT DO NOTHING
        SQL);
    $statement->execute([':userId' => $userId, ':role' => $role]);
    if ($statement->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=:userId AND r.name=:role');
        $check->execute([':userId' => $userId, ':role' => $role]);
        if (!$check->fetchColumn()) throw new RuntimeException('Requested role does not exist. Run migrations first.');
    }

    $pdo->commit();
    fwrite(STDOUT, "Admin account {$username} ({$publicId}) is ready with role {$role}.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
