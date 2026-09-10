<?php
declare(strict_types=1);
namespace App\Shared\Support;
final class PersianText { public static function normalize(string $v): string { $v=strtr($v,['ي'=>'ی','ك'=>'ک','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',"\xE2\x80\x8C"=>' ']); return mb_strtolower(trim((string)preg_replace('/\s+/u',' ',$v))); } }
