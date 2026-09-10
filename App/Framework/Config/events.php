<?php

declare(strict_types=1);

/**
 * Event -> listeners map, consumed by WorkerBootstrapper::initializeEvents().
 *
 * Example:
 *   return [
 *       \App\Domain\Events\UserRegistered::class => [
 *           \App\Application\Listeners\SendWelcomeEmail::class,
 *       ],
 *   ];
 */
return [];
