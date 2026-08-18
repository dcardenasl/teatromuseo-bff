<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\AdminRead\Contracts\AdminFileUsageSourceInterface;
use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/** Cross-domain, fail-closed file-usage projection for the Admin. */
final class AdminFileUsagesController extends BaseProxyController
{
    public function index(int $fileId): ResponseInterface
    {
        $context = ContextHolder::get();
        $bearer  = $this->extractBearerToken();

        if ($context?->user_id === null || $bearer === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        /** @var AdminFileUsageSourceInterface $source */
        $source      = Services::adminReadFileUsages();
        $permissions = $context->permissions;
        $partial     = $this->aggregatePartialData([
            'hub' => static fn (): array => $source->readHub($fileId, $bearer, $permissions),
            'cms' => static fn (): array => $source->readCms($fileId, $permissions),
        ]);

        $hubUsages = $partial['hub']['state'] === 'ok' ? $this->usageRows($partial['hub']['data']) : [];
        $cmsUsages = $partial['cms']['state'] === 'ok' ? $this->usageRows($partial['cms']['data']) : [];
        $states    = [$partial['hub']['state'], $partial['cms']['state']];
        $sourceState = [
            'hub'   => $partial['hub']['state'],
            'cms'   => $partial['cms']['state'],
            'state' => $this->overallState($states),
        ];

        return $this->response->setJSON(ApiResponse::success([
            'version'      => 1,
            'generated_at' => date(DATE_ATOM),
            'source'       => $sourceState,
            'complete'     => $sourceState['state'] === 'ok',
            'sections'     => [
                'usages' => $source->merge($hubUsages, $cmsUsages),
            ],
        ]));
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function usageRows(array $data): array
    {
        $rows = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
