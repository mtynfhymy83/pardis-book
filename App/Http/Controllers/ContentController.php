<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\ContentService;

final class ContentController extends Controller
{
    public function __construct(private ContentService $content)
    {
    }

    public function page(string $slug): array
    {
        return $this->cached($this->ok($this->content->page($slug)), 300);
    }
}
