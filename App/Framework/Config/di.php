<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use function DI\autowire;

// ----- Sample slice (no DB): Greeter -----
use App\Domain\Contracts\Services\GreeterServiceInterface;
use App\Domain\Services\GreeterService;

// ----- Sample slice (DB-backed): Note -----
use App\Domain\Contracts\Services\NoteServiceInterface;
use App\Domain\Services\NoteService;
use App\Domain\Contracts\Repositories\NoteRepositoryInterface;
use App\Infrastructure\Persistence\Repositories\NoteRepository;
use App\Application\Services\AuthService;
use App\Application\Services\CatalogService;
use App\Application\Services\HomeService;
use App\Application\Services\CartService;
use App\Domain\Services\PricingEngine;
use App\Domain\Services\OrderStateMachine;
use App\Application\Services\CheckoutService;
use App\Domain\Contracts\Providers\PaymentGatewayInterface;
use App\Infrastructure\Providers\FakePaymentGateway;

/**
 * DI bindings. Pattern for every feature:
 *   - Interface -> autowire(Implementation::class)   (so consumers depend on the abstraction)
 *   - Implementation::class => autowire()             (so PHP-DI can build it)
 */
return function (): ContainerInterface {
    $builder = new ContainerBuilder();

    $builder->addDefinitions([
        // Greeter (no DB)
        GreeterServiceInterface::class => autowire(GreeterService::class),
        GreeterService::class => autowire(),

        // Note (Service -> Repository -> DB)
        NoteServiceInterface::class    => autowire(NoteService::class),
        NoteRepositoryInterface::class => autowire(NoteRepository::class),
        NoteService::class    => autowire(),
        NoteRepository::class => autowire(),
        AuthService::class => autowire(), CatalogService::class => autowire(), HomeService::class => autowire(), CartService::class => autowire(),
        PricingEngine::class => autowire(), OrderStateMachine::class => autowire(),
        CheckoutService::class => autowire(), PaymentGatewayInterface::class => autowire(FakePaymentGateway::class), FakePaymentGateway::class => autowire(),
    ]);

    // Compile the container in production for speed:
    // $builder->enableCompilation(__DIR__ . '/../../../storage/cache');

    return $builder->build();
};
