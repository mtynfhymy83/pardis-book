<?php
declare(strict_types=1); namespace App\Http\Controllers;
use App\Application\Services\ReportService; use Swoole\Http\Request;
final class ReportController extends Controller { public function __construct(private ReportService $reports){} public function create(Request $r,array $d):array{return $this->created($this->reports->createExport($this->requireAuthUserId($r),$d));} public function status(Request $r,string $jobId):array{return $this->ok($this->reports->export($this->requireAuthUserId($r),$jobId));} }
