<?php
declare(strict_types=1);
namespace App\Http\Middlewares;
use App\Framework\Core\MiddlewareInterface; use Swoole\Http\Request; use Swoole\Http\Response;
final class CorsMiddleware implements MiddlewareInterface {
 public function handle(Request $request,Response $response,callable $next):mixed { $origin=(string)($request->header['origin']??'');$allowed=array_filter(array_map('trim',explode(',',(string)($_ENV['CORS_ALLOWED_ORIGINS']??''))));if($origin!==''&&in_array($origin,$allowed,true)){$response->header('Access-Control-Allow-Origin',$origin);$response->header('Vary','Origin');}$response->header('Access-Control-Allow-Methods','GET, POST, PUT, DELETE, PATCH, OPTIONS');$response->header('Access-Control-Allow-Headers','Content-Type, Authorization, X-Request-Id, X-Guest-Cart-Token, Idempotency-Key, Accept-Language, X-Client-Version, X-Device-Id');$response->header('Access-Control-Allow-Credentials','true');if(($request->server['request_method']??'GET')==='OPTIONS'){$response->status(204);$response->end();return null;}return $next($request,$response); }
}
