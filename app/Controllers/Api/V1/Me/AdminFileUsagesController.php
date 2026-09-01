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

/** Hub-authoritative, source-aware file-usage projection for the Admin. */
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
        $partial = $this->aggregatePartialData([
            'hub' => static fn (): array => $source->readSnapshot($fileId, $bearer, $permissions),
        ]);

        $snapshot = $partial['hub']['state'] === 'ok' ? $partial['hub']['data'] : [];
        $sourceState = is_array($snapshot['source'] ?? null) ? $snapshot['source'] : [];
        if ($partial['hub']['state'] !== 'ok') {
            $sourceState['hub'] = 'unavailable';
        }
        $sourceState['state'] = $partial['hub']['state'] !== 'ok'
            ? 'unavailable'
            : (($snapshot['complete'] ?? false) === true ? 'ok' : 'partial');

        return $this->response->setJSON(ApiResponse::success([
            'version'      => 1,
            'generated_at' => date(DATE_ATOM),
            'source'       => $sourceState,
            'complete'     => $partial['hub']['state'] === 'ok' && ($snapshot['complete'] ?? false) === true,
            'sections'     => [
                'usages' => is_array($snapshot['usages'] ?? null) ? $snapshot['usages'] : [],
            ],
        ]));
    }
}
