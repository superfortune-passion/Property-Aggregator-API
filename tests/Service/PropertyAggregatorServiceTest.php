<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PropertyAggregatorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PropertyAggregatorServiceTest extends TestCase
{
    private string $dataDirectory;

    protected function setUp(): void
    {
        $this->dataDirectory = sys_get_temp_dir() . '/sympony_property_test_' . uniqid('', true);
        mkdir($this->dataDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataDirectory);
    }

    public function testAggregateMergesAndNormalizesBothSources(): void
    {
        $this->writeJson('source_a.json', [
            'properties' => [
                [
                    'property_id' => 'EH-1',
                    'location' => [
                        'street' => '1 Test Street',
                        'city' => 'London',
                        'postcode' => 'E1 1AA',
                    ],
                    'listing_price' => 250000,
                ],
            ],
        ]);

        $this->writeJson('source_b.json', [
            'listings' => [
                [
                    'id' => 'PH-1',
                    'full_address' => '2 Sample Road, Bristol',
                    'price_usd' => 300000,
                ],
            ],
        ]);

        $service = $this->createService();
        $result = $service->aggregate();

        self::assertFalse($result['cached']);
        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['data']);
        self::assertSame('EH-1', $result['data'][0]['id']);
        self::assertSame('1 Test Street, London, E1 1AA', $result['data'][0]['address']);
        self::assertSame(250000, $result['data'][0]['price']);
        self::assertSame('source_a', $result['data'][0]['source']);
        self::assertSame('PH-1', $result['data'][1]['id']);
        self::assertSame('source_b', $result['data'][1]['source']);
    }

    public function testAggregateSkipsInvalidRecords(): void
    {
        $this->writeJson('source_a.json', [
            'properties' => [
                [
                    'property_id' => '',
                    'location' => ['street' => 'Bad', 'city' => 'Data'],
                    'listing_price' => 100,
                ],
                [
                    'property_id' => 'EH-OK',
                    'location' => ['street' => 'Good St', 'city' => 'Leeds'],
                    'listing_price' => 150000,
                ],
            ],
        ]);

        $this->writeJson('source_b.json', [
            'listings' => [
                [
                    'full_address' => 'No ID Street',
                    'price_usd' => 200000,
                ],
            ],
        ]);

        $result = $this->createService()->aggregate();

        self::assertCount(1, $result['data']);
        self::assertSame('EH-OK', $result['data'][0]['id']);
    }

    public function testAggregateReturnsCachedDataWhenSourcesFail(): void
    {
        $cache = new ArrayAdapter();
        $cachedData = [
            [
                'id' => 'CACHE-1',
                'address' => 'Cached Address',
                'price' => 999000,
                'source' => 'source_a',
            ],
        ];

        $item = $cache->getItem(PropertyAggregatorService::CACHE_KEY);
        $item->set([
            'data' => $cachedData,
            'fingerprint' => 'stale-fingerprint',
            'sources_loaded' => ['source_a'],
        ]);
        $cache->save($item);

        $service = new PropertyAggregatorService(
            cache: $cache,
            logger: new NullLogger(),
            dataDirectory: $this->dataDirectory,
            cacheTtl: 300,
        );

        $result = $service->aggregate();

        self::assertTrue($result['cached']);
        self::assertSame($cachedData, $result['data']);
        self::assertCount(2, $result['errors']);
        self::assertSame('source_a', $result['errors'][0]['source']);
        self::assertSame('source_b', $result['errors'][1]['source']);
    }

    public function testAggregateServesFromCacheWhenSourceFilesAreUnchanged(): void
    {
        $this->writeJson('source_a.json', [
            'properties' => [
                [
                    'property_id' => 'EH-1',
                    'location' => ['street' => '1 Test Street', 'city' => 'London'],
                    'listing_price' => 250000,
                ],
            ],
        ]);

        $this->writeJson('source_b.json', [
            'listings' => [
                [
                    'id' => 'PH-1',
                    'full_address' => '2 Sample Road, Bristol',
                    'price_usd' => 300000,
                ],
            ],
        ]);

        $cache = new ArrayAdapter();
        $service = new PropertyAggregatorService(
            cache: $cache,
            logger: new NullLogger(),
            dataDirectory: $this->dataDirectory,
            cacheTtl: 300,
        );

        $first = $service->aggregate();
        self::assertFalse($first['cached']);

        $second = $service->aggregate();

        self::assertTrue($second['cached']);
        self::assertSame($first['data'], $second['data']);
        self::assertSame(['source_a', 'source_b'], $second['sources_loaded']);
        self::assertSame([], $second['errors']);
    }

    public function testAggregateReloadsWhenSourceFilesChange(): void
    {
        $this->writeJson('source_a.json', [
            'properties' => [
                [
                    'property_id' => 'EH-1',
                    'location' => ['street' => 'Old Street', 'city' => 'London'],
                    'listing_price' => 250000,
                ],
            ],
        ]);

        $this->writeJson('source_b.json', ['listings' => []]);

        $service = $this->createService();

        $first = $service->aggregate();
        self::assertSame('Old Street, London', $first['data'][0]['address']);

        clearstatcache(true, $this->dataDirectory . '/source_a.json');
        sleep(1);

        $this->writeJson('source_a.json', [
            'properties' => [
                [
                    'property_id' => 'EH-1',
                    'location' => ['street' => 'New Street', 'city' => 'London'],
                    'listing_price' => 250000,
                ],
            ],
        ]);

        $second = $service->aggregate();

        self::assertFalse($second['cached']);
        self::assertSame('New Street, London', $second['data'][0]['address']);
    }

    public function testAggregateReportsCorruptedJson(): void
    {
        file_put_contents($this->dataDirectory . '/source_a.json', '{invalid json');
        $this->writeJson('source_b.json', ['listings' => []]);

        $result = $this->createService()->aggregate();

        self::assertCount(1, $result['errors']);
        self::assertSame('source_a', $result['errors'][0]['source']);
        self::assertStringContainsString('invalid JSON', $result['errors'][0]['message']);
    }

    private function createService(): PropertyAggregatorService
    {
        return new PropertyAggregatorService(
            cache: new ArrayAdapter(),
            logger: new NullLogger(),
            dataDirectory: $this->dataDirectory,
            cacheTtl: 300,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $filename, array $payload): void
    {
        file_put_contents(
            $this->dataDirectory . '/' . $filename,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
