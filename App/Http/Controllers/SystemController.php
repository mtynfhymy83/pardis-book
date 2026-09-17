<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\HomeService;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;

final class SystemController extends Controller
{
    public function __construct(private HomeService $homeService)
    {
    }

    public function live(): array
    {
        return $this->ok(['status' => 'ok']);
    }

    public function ready(): array
    {
        try {
            DB::fetch('SELECT 1');
        } catch (\Throwable $exception) {
            throw new ApiException('DEPENDENCY_UNAVAILABLE', 'اتصال دیتابیس در دسترس نیست.', 503, previous: $exception);
        }
        return $this->ok(['status' => 'ready', 'database' => 'ok']);
    }

    public function bootstrap(): array
    {
        return $this->ok([
            'currency' => 'TOMAN',
            'locale' => 'fa-IR',
            'features' => ['guestCart' => true, 'onlinePayment' => true],
        ]);
    }

    public function home(): array
    {
        return $this->ok($this->homeService->home());
    }

    public function navigation(): array
    {
        return $this->ok($this->homeService->navigation());
    }
}
