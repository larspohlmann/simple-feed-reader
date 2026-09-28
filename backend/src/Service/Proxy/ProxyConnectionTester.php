<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\EgressOptions;
use App\Service\Fetch\ProxyHandshakeFailure;
use App\Service\Proxy\ConfiguredProxySource\ConfiguredProxySourceInterface;
use App\Service\Proxy\Model\ProxyTestFailure;
use App\Service\Proxy\Model\ProxyTestResultModel;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Probes the SAVED proxy by fetching a fixed IP-echo endpoint through it. Uses
 * configuredProxy() (not egressProxy()) so the admin can test before flipping
 * the enable switch on.
 */
final readonly class ProxyConnectionTester
{
    public const string EGRESS_ECHO_URL = 'https://api.ipify.org';

    private const float TIMEOUT_SECONDS = 10.0;
    private const int MAX_BYTES = 1024;

    public function __construct(
        private ConfiguredProxySourceInterface $proxySource,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function test(): ProxyTestResultModel
    {
        try {
            $proxy = $this->proxySource->configuredProxy();
        } catch (SecretUnreadableException $e) {
            // Diagnosing exactly this is what the Test button is for, so it
            // reports the unreadable secret rather than crashing on it.
            return ProxyTestResultModel::failed(ProxyTestFailure::SecretUnreadable, $e->getMessage());
        }

        if (null === $proxy) {
            return ProxyTestResultModel::failed(ProxyTestFailure::NotConfigured);
        }

        try {
            $response = $this->httpClient->request('GET', self::EGRESS_ECHO_URL, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
                'headers' => ['Accept' => 'text/plain'],
                ...EgressOptions::proxied($proxy),
            ]);
            $status = $response->getStatusCode();
            $body = substr($response->getContent(false), 0, self::MAX_BYTES);
        } catch (ExceptionInterface $e) {
            return ProxyTestResultModel::failed(
                ProxyTestFailure::Unreachable,
                ProxyHandshakeFailure::explain($e->getMessage()),
            );
        }

        if ($status < 200 || $status >= 300) {
            return ProxyTestResultModel::failed(ProxyTestFailure::UnexpectedStatus, sprintf('HTTP %d', $status));
        }

        return ProxyTestResultModel::ok(trim($body));
    }
}
