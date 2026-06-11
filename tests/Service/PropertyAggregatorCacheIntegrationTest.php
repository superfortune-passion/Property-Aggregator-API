<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PropertyAggregatorService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PropertyAggregatorCacheIntegrationTest extends KernelTestCase
{
    public function testSecondRequestIsServedFromApplicationCache(): void
    {
        self::bootKernel();

        /** @var PropertyAggregatorService $service */
        $service = static::getContainer()->get(PropertyAggregatorService::class);

        $first = $service->aggregate();
        $second = $service->aggregate();

        self::assertFalse($first['cached'], 'First request should load from source files.');
        self::assertTrue($second['cached'], 'Second request should be served from cache.');
        self::assertSame($first['data'], $second['data']);
    }
}
