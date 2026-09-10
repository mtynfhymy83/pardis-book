<?php
declare(strict_types=1);
namespace App\Shared\Support;
final class Id { public static function make(string $prefix): string { return $prefix . '_' . strtolower(base_convert((string)(int)(microtime(true)*1000),10,32)) . bin2hex(random_bytes(8)); } }
