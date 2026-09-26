<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Dto\Admin\InstanceSettingsRequest;
use App\Dto\Admin\MailSettingsRequest;
use App\Dto\Admin\ProxySettingsRequest;
use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Mail\Settings\MailConnection;
use App\Service\Proxy\ProxyConnection;

/**
 * The settings requests require every setting; these carry the old defaults so a test names only what it is about.
 */
final class SettingsRequests
{
    public static function proxy(
        bool $enabled = false,
        bool $directFallback = true,
        string $type = ProxyType::Socks5->value,
        string $host = '',
        int $port = ProxyConnection::DEFAULT_PORT,
        ?string $username = null,
        bool $remoteDns = false,
        ?string $password = null,
        bool $removePassword = false,
    ): ProxySettingsRequest {
        return new ProxySettingsRequest(
            enabled: $enabled,
            directFallback: $directFallback,
            type: $type,
            host: $host,
            port: $port,
            username: $username,
            remoteDns: $remoteDns,
            password: $password,
            removePassword: $removePassword,
        );
    }

    public static function grafana(
        ?string $lokiPushUrl = null,
        ?string $lokiUsername = null,
        ?string $grafanaUrl = null,
        ?string $pyroscopePushUrl = null,
        bool $profilingEnabled = false,
        ?string $token = null,
        bool $removeToken = false,
    ): GrafanaSettingsRequest {
        return new GrafanaSettingsRequest(
            lokiPushUrl: $lokiPushUrl,
            lokiUsername: $lokiUsername,
            grafanaUrl: $grafanaUrl,
            pyroscopePushUrl: $pyroscopePushUrl,
            profilingEnabled: $profilingEnabled,
            token: $token,
            removeToken: $removeToken,
        );
    }

    public static function mail(
        bool $enabled = false,
        string $host = '',
        int $port = MailConnection::DEFAULT_PORT,
        ?string $username = null,
        string $encryption = MailEncryption::Starttls->value,
        string $fromAddress = '',
        string $fromName = '',
        bool $useProxy = false,
        ?string $password = null,
        bool $removePassword = false,
    ): MailSettingsRequest {
        return new MailSettingsRequest(
            enabled: $enabled,
            host: $host,
            port: $port,
            username: $username,
            encryption: $encryption,
            fromAddress: $fromAddress,
            fromName: $fromName,
            useProxy: $useProxy,
            password: $password,
            removePassword: $removePassword,
        );
    }

    public static function instance(
        bool $requireEmailConfirmation = true,
        bool $requireApproval = true,
        ?string $publicBaseUrl = null,
        ?string $passkeyRpId = null,
        ?string $passkeyRpName = null,
        bool $passkeySignInEnabled = false,
        bool $invalidateExistingPasskeys = false,
    ): InstanceSettingsRequest {
        return new InstanceSettingsRequest(
            requireEmailConfirmation: $requireEmailConfirmation,
            requireApproval: $requireApproval,
            publicBaseUrl: $publicBaseUrl,
            passkeyRpId: $passkeyRpId,
            passkeyRpName: $passkeyRpName,
            passkeySignInEnabled: $passkeySignInEnabled,
            invalidateExistingPasskeys: $invalidateExistingPasskeys,
        );
    }
}
