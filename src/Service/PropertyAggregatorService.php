<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

final class PropertyAggregatorService
{
    public const CACHE_KEY = 'properties_aggregated_v1';

    private const SOURCE_A_FILE = 'source_a.json';
    private const SOURCE_B_FILE = 'source_b.json';

    /**
     * @var list<string>
     */
    private const SOURCE_FILES = [
        self::SOURCE_A_FILE,
        self::SOURCE_B_FILE,
    ];

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $dataDirectory,
        private readonly int $cacheTtl,
    ) {
    }

    /**
     * @return array{data: list<array{id: string, address: string, price: int|float, source: string}>, errors: list<array{source: string, message: string}>, cached: bool, sources_loaded: list<string>}
     */
    public function aggregate(): array
    {
        $fingerprint = $this->buildSourceFingerprint();
        $cachedPayload = $this->getCachedPayload();

        if ($this->isCacheValid($cachedPayload, $fingerprint)) {
            $this->logger->debug('Serving aggregated properties from cache.', [
                'fingerprint' => $fingerprint,
            ]);

            return [
                'data' => $cachedPayload['data'],
                'errors' => [],
                'cached' => true,
                'sources_loaded' => $cachedPayload['sources_loaded'],
            ];
        }

        $errors = [];
        $data = [];
        $sourcesLoaded = [];

        $sourceA = $this->loadSource(self::SOURCE_A_FILE, 'source_a', $errors);
        if ($sourceA !== null) {
            $sourcesLoaded[] = 'source_a';
            $data = array_merge($data, $this->normalizeSourceA($sourceA, $errors));
        }

        $sourceB = $this->loadSource(self::SOURCE_B_FILE, 'source_b', $errors);
        if ($sourceB !== null) {
            $sourcesLoaded[] = 'source_b';
            $data = array_merge($data, $this->normalizeSourceB($sourceB, $errors));
        }

        if ($data !== []) {
            $this->cachePayload($data, $fingerprint, $sourcesLoaded);

            return [
                'data' => $data,
                'errors' => $errors,
                'cached' => false,
                'sources_loaded' => $sourcesLoaded,
            ];
        }

        if ($cachedPayload !== null && $cachedPayload['data'] !== []) {
            $this->logger->warning('Serving stale aggregated properties from cache due to source failures.', [
                'errors' => $errors,
            ]);

            return [
                'data' => $cachedPayload['data'],
                'errors' => $errors,
                'cached' => true,
                'sources_loaded' => $sourcesLoaded,
            ];
        }

        $this->logger->error('Unable to load property sources and no cache fallback is available.', [
            'errors' => $errors,
        ]);

        return [
            'data' => [],
            'errors' => $errors,
            'cached' => false,
            'sources_loaded' => $sourcesLoaded,
        ];
    }

    private function buildSourceFingerprint(): string
    {
        $parts = [];

        foreach (self::SOURCE_FILES as $filename) {
            $path = $this->resolveSourcePath($filename);

            if (!is_readable($path)) {
                $parts[] = $filename . ':missing';
                continue;
            }

            $mtime = filemtime($path);
            $size = filesize($path);

            $parts[] = sprintf(
                '%s:%s:%s',
                $filename,
                $mtime !== false ? (string) $mtime : 'unknown',
                $size !== false ? (string) $size : 'unknown',
            );
        }

        return hash('xxh128', implode('|', $parts));
    }

    /**
     * @param array{data: list<array{id: string, address: string, price: int|float, source: string}>, fingerprint: string, sources_loaded: list<string>}|null $cachedPayload
     */
    private function isCacheValid(?array $cachedPayload, string $fingerprint): bool
    {
        if ($cachedPayload === null) {
            return false;
        }

        if ($cachedPayload['data'] === []) {
            return false;
        }

        return ($cachedPayload['fingerprint'] ?? '') === $fingerprint;
    }

    /**
     * @param list<array{source: string, message: string}> $errors
     *
     * @return array<string, mixed>|null
     */
    private function loadSource(string $filename, string $sourceName, array &$errors): ?array
    {
        $path = $this->resolveSourcePath($filename);

        if (!is_readable($path)) {
            $message = sprintf('Source file "%s" is missing or unreadable.', $filename);
            $errors[] = ['source' => $sourceName, 'message' => $message];
            $this->logger->error($message, ['source' => $sourceName, 'path' => $path]);

            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $message = sprintf('Failed to read source file "%s".', $filename);
            $errors[] = ['source' => $sourceName, 'message' => $message];
            $this->logger->error($message, ['source' => $sourceName, 'path' => $path]);

            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $message = sprintf('Source file "%s" contains invalid JSON: %s', $filename, $exception->getMessage());
            $errors[] = ['source' => $sourceName, 'message' => $message];
            $this->logger->error($message, ['source' => $sourceName, 'path' => $path]);

            return null;
        }

        if (!is_array($decoded)) {
            $message = sprintf('Source file "%s" must decode to a JSON object.', $filename);
            $errors[] = ['source' => $sourceName, 'message' => $message];
            $this->logger->error($message, ['source' => $sourceName]);

            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<array{source: string, message: string}> $errors
     *
     * @return list<array{id: string, address: string, price: int|float, source: string}>
     */
    private function normalizeSourceA(array $source, array &$errors): array
    {
        $properties = $source['properties'] ?? null;
        if (!is_array($properties)) {
            $errors[] = [
                'source' => 'source_a',
                'message' => 'Expected "properties" array in source_a.json.',
            ];
            $this->logger->warning('source_a.json is missing a valid "properties" array.');

            return [];
        }

        $normalized = [];
        foreach ($properties as $index => $record) {
            if (!is_array($record)) {
                $this->logSkippedRecord('source_a', $index, 'Record is not an object.');
                continue;
            }

            $normalizedRecord = $this->buildNormalizedRecord(
                id: $record['property_id'] ?? null,
                address: $this->formatSourceAAddress($record['location'] ?? null),
                price: $record['listing_price'] ?? null,
                source: 'source_a',
            );

            if ($normalizedRecord === null) {
                $this->logSkippedRecord('source_a', $index, 'Missing or invalid required fields.');
                continue;
            }

            $normalized[] = $normalizedRecord;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<array{source: string, message: string}> $errors
     *
     * @return list<array{id: string, address: string, price: int|float, source: string}>
     */
    private function normalizeSourceB(array $source, array &$errors): array
    {
        $listings = $source['listings'] ?? null;
        if (!is_array($listings)) {
            $errors[] = [
                'source' => 'source_b',
                'message' => 'Expected "listings" array in source_b.json.',
            ];
            $this->logger->warning('source_b.json is missing a valid "listings" array.');

            return [];
        }

        $normalized = [];
        foreach ($listings as $index => $record) {
            if (!is_array($record)) {
                $this->logSkippedRecord('source_b', $index, 'Record is not an object.');
                continue;
            }

            $normalizedRecord = $this->buildNormalizedRecord(
                id: $record['id'] ?? null,
                address: $record['full_address'] ?? null,
                price: $record['price_usd'] ?? null,
                source: 'source_b',
            );

            if ($normalizedRecord === null) {
                $this->logSkippedRecord('source_b', $index, 'Missing or invalid required fields.');
                continue;
            }

            $normalized[] = $normalizedRecord;
        }

        return $normalized;
    }

    /**
     * @return array{id: string, address: string, price: int|float, source: string}|null
     */
    private function buildNormalizedRecord(mixed $id, mixed $address, mixed $price, string $source): ?array
    {
        if (!is_string($id) || trim($id) === '') {
            return null;
        }

        if (!is_string($address) || trim($address) === '') {
            return null;
        }

        if (!is_int($price) && !is_float($price)) {
            if (is_string($price) && is_numeric($price)) {
                $price = str_contains($price, '.') ? (float) $price : (int) $price;
            } else {
                return null;
            }
        }

        if ($price < 0) {
            return null;
        }

        return [
            'id' => trim($id),
            'address' => trim($address),
            'price' => $price,
            'source' => $source,
        ];
    }

    private function formatSourceAAddress(mixed $location): ?string
    {
        if (!is_array($location)) {
            return null;
        }

        $street = $location['street'] ?? '';
        $city = $location['city'] ?? '';
        $postcode = $location['postcode'] ?? '';

        if (!is_string($street) || !is_string($city)) {
            return null;
        }

        $parts = array_filter([
            trim($street),
            trim($city),
            is_string($postcode) ? trim($postcode) : '',
        ]);

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    private function logSkippedRecord(string $source, int|string $index, string $reason): void
    {
        $this->logger->notice('Skipped invalid property record.', [
            'source' => $source,
            'index' => $index,
            'reason' => $reason,
        ]);
    }

    /**
     * @param list<array{id: string, address: string, price: int|float, source: string}> $data
     * @param list<string> $sourcesLoaded
     */
    private function cachePayload(array $data, string $fingerprint, array $sourcesLoaded): void
    {
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $item->set([
                'data' => $data,
                'fingerprint' => $fingerprint,
                'sources_loaded' => $sourcesLoaded,
            ]);
            $item->expiresAfter($this->cacheTtl);

            if (!$this->cache->save($item)) {
                $this->logger->warning('Cache save returned false for aggregated properties.');
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to write aggregated properties to cache.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{data: list<array{id: string, address: string, price: int|float, source: string}>, fingerprint: string, sources_loaded: list<string>}|null
     */
    private function getCachedPayload(): ?array
    {
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            if (!$item->isHit()) {
                return null;
            }

            $cached = $item->get();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($cached) || !isset($cached['data']) || !is_array($cached['data'])) {
            return null;
        }

        return [
            'data' => $cached['data'],
            'fingerprint' => is_string($cached['fingerprint'] ?? null) ? $cached['fingerprint'] : '',
            'sources_loaded' => is_array($cached['sources_loaded'] ?? null) ? $cached['sources_loaded'] : [],
        ];
    }

    private function resolveSourcePath(string $filename): string
    {
        return rtrim($this->dataDirectory, '/\\') . DIRECTORY_SEPARATOR . $filename;
    }
}
