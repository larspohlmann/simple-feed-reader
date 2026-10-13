<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Fetch\Model\ApiRequestModel;

final readonly class MastodonSessionModel
{
    public function __construct(
        public string $instanceUrl,
        private string $accessToken,
    ) {
    }

    public function url(string $path): string
    {
        return $this->instanceUrl . $path;
    }

    public function get(string $url): ApiRequestModel
    {
        return $this->authorized(ApiRequestModel::get($url));
    }

    public function authorized(ApiRequestModel $request): ApiRequestModel
    {
        return $request->withHeaders(['Authorization' => 'Bearer ' . $this->accessToken]);
    }

    public function host(): string
    {
        $port = parse_url($this->instanceUrl, \PHP_URL_PORT);

        return (string) parse_url($this->instanceUrl, \PHP_URL_HOST) . (null === $port ? '' : ':' . $port);
    }
}
