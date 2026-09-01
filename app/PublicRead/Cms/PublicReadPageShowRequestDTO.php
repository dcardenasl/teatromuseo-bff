<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Validation\ValidationInterface;
use dcardenasl\Ci4ApiCore\Dto\BaseRequestDTO;

/** Query contract for signed CMS page previews. */
readonly class PublicReadPageShowRequestDTO extends BaseRequestDTO
{
    public bool $previewRequested;
    public ?string $previewExpires;
    public ?string $previewSig;

    public function __construct(array $data, ?ValidationInterface $validation = null)
    {
        parent::__construct($data, $validation);
        $this->previewRequested = ($data['preview'] ?? null) === '1';
        $this->previewExpires = isset($data['preview_expires']) && is_string($data['preview_expires'])
            ? $data['preview_expires']
            : null;
        $this->previewSig = isset($data['preview_sig']) && is_string($data['preview_sig'])
            ? $data['preview_sig']
            : null;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'preview' => 'permit_empty|in_list[0,1]',
            'preview_expires' => 'permit_empty|string|max_length[20]',
            'preview_sig' => 'permit_empty|regex_match[/^[a-f0-9]{64}$/i]',
        ];
    }

    protected function map(array $data): void
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'preview' => $this->previewRequested,
            'preview_expires' => $this->previewExpires,
            'preview_sig' => $this->previewSig,
        ];
    }
}
