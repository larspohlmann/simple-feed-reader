<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Service\Outbound\Fetch\Model\ApiRequestModel;

final readonly class LemmySessionModel
{
    public function __construct(
        public string $instanceUrl,
        #[\SensitiveParameter] private string $token,
    ) {
    }

    public function url(string $path): string
    {
        return $this->instanceUrl . $path;
    }

    public function get(string $path): ApiRequestModel
    {
        return ApiRequestModel::get($this->url($path), $this->authorization());
    }

    /** @param array<string, mixed> $json */
    public function postJson(string $path, array $json): ApiRequestModel
    {
        return ApiRequestModel::postJson($this->url($path), $json, $this->authorization());
    }

    /** @return array<string, string> */
    private function authorization(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }
}
