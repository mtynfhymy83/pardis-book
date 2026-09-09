<?php
declare(strict_types=1);
use App\Framework\Bootstrap\EnvironmentManager;
$base = dirname(__DIR__); require $base . '/vendor/autoload.php'; EnvironmentManager::initialize();
$pdo = new PDO((string) $_ENV['DB_DSN'], (string) ($_ENV['DB_USERNAME'] ?? ''), (string) ($_ENV['DB_PASSWORD'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version varchar(255) primary key, applied_at timestamptz not null default now())');
foreach (glob($base . '/migrations/*.up.sql') ?: [] as $file) {
    $version = basename($file); $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=?'); $check->execute([$version]); if ($check->fetchColumn()) continue;
    $pdo->beginTransaction(); try { $pdo->exec((string) file_get_contents($file)); $pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)')->execute([$version]); $pdo->commit(); echo "Applied {$version}\n"; } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
