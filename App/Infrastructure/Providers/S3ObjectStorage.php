<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Domain\Contracts\Providers\ObjectStorageInterface;

/** Minimal AWS Signature V4 presigner; works with AWS S3 and S3-compatible endpoints. */
final class S3ObjectStorage implements ObjectStorageInterface
{
    public function presignPut(string $key, string $mime, int $expiresInSeconds = 900): array
    {
        $bucket = $this->required('S3_BUCKET');
        $region = (string) ($_ENV['S3_REGION'] ?? 'us-east-1');
        $access = $this->required('S3_ACCESS_KEY_ID');
        $secret = $this->required('S3_SECRET_ACCESS_KEY');
        $expires = max(60, min(900, $expiresInSeconds));
        $now = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        [$base, $host, $path] = $this->endpoint($bucket, $region, $key);
        $scope = "$date/$region/s3/aws4_request";
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "$access/$scope",
            'X-Amz-Date' => $now,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'content-type;host',
        ];
        if (!empty($_ENV['S3_SESSION_TOKEN'])) $query['X-Amz-Security-Token'] = (string) $_ENV['S3_SESSION_TOKEN'];
        $canonicalQuery = $this->query($query);
        $canonicalHeaders = "content-type:$mime\nhost:$host\n";
        $canonical = "PUT\n$path\n$canonicalQuery\n$canonicalHeaders\ncontent-type;host\nUNSIGNED-PAYLOAD";
        $stringToSign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $query['X-Amz-Signature'] = hash_hmac('sha256', $stringToSign, $signingKey);
        return ['url' => $base . $path . '?' . $this->query($query), 'method' => 'PUT', 'headers' => ['Content-Type' => $mime], 'expiresAt' => gmdate('c', time() + $expires)];
    }

    public function putString(string $key, string $contents, string $mime): void
    {
        $signed = $this->presignPut($key, $mime, 900);
        $context = stream_context_create(['http' => ['method' => 'PUT', 'header' => 'Content-Type: ' . $mime . "\r\nContent-Length: " . strlen($contents), 'content' => $contents, 'ignore_errors' => true, 'timeout' => 30]]);
        $result = @file_get_contents($signed['url'], false, $context);
        $status = $http_response_header[0] ?? '';
        if ($result === false || preg_match('/\s2\d\d\s/', $status) !== 1) throw new \RuntimeException('S3 upload failed: ' . $status);
    }

    public function publicUrl(string $key): string
    {
        $path = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        $publicBase = rtrim((string) ($_ENV['S3_PUBLIC_URL'] ?? ''), '/');
        if ($publicBase !== '') return $publicBase . $path;

        [$base, , $objectPath] = $this->endpoint(
            $this->required('S3_BUCKET'),
            (string) ($_ENV['S3_REGION'] ?? 'us-east-1'),
            $key
        );
        return $base . $objectPath;
    }

    public function presignGet(string $key, int $expiresInSeconds = 300): array
    {
        $bucket = $this->required('S3_BUCKET'); $region = (string) ($_ENV['S3_REGION'] ?? 'us-east-1'); $access = $this->required('S3_ACCESS_KEY_ID'); $secret = $this->required('S3_SECRET_ACCESS_KEY');
        $expires = max(60, min(900, $expiresInSeconds)); $now = gmdate('Ymd\THis\Z'); $date = gmdate('Ymd'); [$base,$host,$path] = $this->endpoint($bucket,$region,$key); $scope = "$date/$region/s3/aws4_request";
        $query=['X-Amz-Algorithm'=>'AWS4-HMAC-SHA256','X-Amz-Credential'=>"$access/$scope",'X-Amz-Date'=>$now,'X-Amz-Expires'=>(string)$expires,'X-Amz-SignedHeaders'=>'host']; if(!empty($_ENV['S3_SESSION_TOKEN']))$query['X-Amz-Security-Token']=(string)$_ENV['S3_SESSION_TOKEN'];
        $canonical="GET\n$path\n".$this->query($query)."\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD"; $string="AWS4-HMAC-SHA256\n$now\n$scope\n".hash('sha256',$canonical);
        $dateKey=hash_hmac('sha256',$date,'AWS4'.$secret,true);$regionKey=hash_hmac('sha256',$region,$dateKey,true);$serviceKey=hash_hmac('sha256','s3',$regionKey,true);$signingKey=hash_hmac('sha256','aws4_request',$serviceKey,true);$query['X-Amz-Signature']=hash_hmac('sha256',$string,$signingKey);
        return ['url'=>$base.$path.'?'.$this->query($query),'method'=>'GET','expiresAt'=>gmdate('c',time()+$expires)];
    }

    /** @return array{string,string,string} */
    private function endpoint(string $bucket, string $region, string $key): array
    {
        $path = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        $endpoint = rtrim((string) ($_ENV['S3_ENDPOINT'] ?? ''), '/');
        $pathStyle = filter_var($_ENV['S3_FORCE_PATH_STYLE'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($endpoint !== '') {
            $parts = parse_url($endpoint);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) throw new \RuntimeException('S3_ENDPOINT must be an absolute URL.');
            $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $base = $parts['scheme'] . '://' . $host . rtrim($parts['path'] ?? '', '/');
            return $pathStyle ? [$base, $host, '/' . rawurlencode($bucket) . $path] : [$parts['scheme'] . '://' . $bucket . '.' . $host . rtrim($parts['path'] ?? '', '/'), $bucket . '.' . $host, $path];
        }
        $host = $bucket . '.s3.' . $region . '.amazonaws.com';
        return ['https://' . $host, $host, $path];
    }

    private function query(array $params): string { ksort($params, SORT_STRING); return implode('&', array_map(static fn(string $k, string $v): string => rawurlencode($k) . '=' . rawurlencode($v), array_keys($params), array_values($params))); }
    private function required(string $key): string { $value = trim((string) ($_ENV[$key] ?? '')); if ($value === '') throw new \RuntimeException("$key is required for S3 storage."); return $value; }
}
