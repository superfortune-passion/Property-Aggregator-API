<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PropertyControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPropertiesEndpointReturnsPaginatedPayload(): void
    {
        $this->client->request('GET', '/properties?page=1&limit=5');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $payload = json_decode($this->client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('meta', $payload);
        self::assertCount(5, $payload['data']);
        self::assertSame(1, $payload['meta']['page']);
        self::assertSame(5, $payload['meta']['limit']);
        self::assertGreaterThanOrEqual(5, $payload['meta']['total']);
        self::assertGreaterThanOrEqual(2, $payload['meta']['total_pages']);
    }

    public function testPropertiesEndpointReturnsSecondPage(): void
    {
        $this->client->request('GET', '/properties?page=2&limit=5');

        self::assertResponseIsSuccessful();

        $payload = json_decode($this->client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $payload['meta']['page']);
        self::assertSame(5, $payload['meta']['limit']);
        self::assertLessThanOrEqual(5, count($payload['data']));
    }

    public function testPropertiesEndpointUsesDefaultPagination(): void
    {
        $this->client->request('GET', '/properties');

        self::assertResponseIsSuccessful();

        $payload = json_decode($this->client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['meta']['page']);
        self::assertSame(20, $payload['meta']['limit']);
    }
}
