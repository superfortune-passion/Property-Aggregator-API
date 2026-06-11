<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PropertyAggregatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PropertyController extends AbstractController
{
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly PropertyAggregatorService $propertyAggregator,
    ) {
    }

    #[Route('/properties', name: 'properties_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(self::DEFAULT_PAGE, $request->query->getInt('page', self::DEFAULT_PAGE));
        $limit = min(
            self::MAX_LIMIT,
            max(1, $request->query->getInt('limit', self::DEFAULT_LIMIT)),
        );

        $result = $this->propertyAggregator->aggregate();
        $allData = $result['data'];
        $total = count($allData);
        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;
        $offset = ($page - 1) * $limit;

        $response = [
            'data' => array_slice($allData, $offset, $limit),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
                'cached' => $result['cached'],
                'sources_loaded' => $result['sources_loaded'],
            ],
        ];

        if ($result['errors'] !== []) {
            $response['errors'] = $result['errors'];
        }

        return $this->json($response);
    }
}
