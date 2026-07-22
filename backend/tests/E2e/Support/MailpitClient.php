<?php

declare(strict_types=1);

namespace App\Tests\E2e\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads what the app actually mailed. The e2e suite never has the verification
 * or reset token any other way — the token exists only in the email body, which
 * is exactly the property real users depend on.
 */
final readonly class MailpitClient
{
    private HttpClientInterface $http;

    public function __construct(private string $baseUrl)
    {
        $this->http = HttpClient::create();
    }

    /**
     * The plaintext body of the most recent message sent to $recipient.
     * Polls briefly: mail is queued and flushed on kernel.terminate, so it can
     * land a beat after the HTTP response the test already received.
     */
    public function latestBodyTo(string $recipient): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $messageId = $this->newestMessageIdTo($recipient);

            if (null !== $messageId) {
                return $this->messageText($messageId);
            }

            usleep(100_000); // 100 ms
        }

        throw new \RuntimeException('No Mailpit message to ' . $recipient . ' within timeout.');
    }

    public function deleteAll(): void
    {
        $this->http->request('DELETE', $this->baseUrl . '/api/v1/messages');
    }

    /** Whether Mailpit currently holds any message addressed to $recipient. */
    public function hasMessageTo(string $recipient): bool
    {
        return null !== $this->newestMessageIdTo($recipient);
    }

    private function newestMessageIdTo(string $recipient): ?string
    {
        $search = $this->http->request('GET', $this->baseUrl . '/api/v1/search', [
            'query' => ['query' => 'to:' . $recipient],
        ])->toArray();

        if (!isset($search['messages']) || !is_array($search['messages'])) {
            return null;
        }

        // Mailpit's /api/v1/search returns messages newest-first, so index 0
        // is the most recent — that is the property latestBodyTo relies on.
        $messages = $search['messages'];
        if (!isset($messages[0]) || !is_array($messages[0])) {
            return null;
        }

        $newest = $messages[0];
        if (!isset($newest['ID']) || !is_string($newest['ID'])) {
            return null;
        }

        return $newest['ID'];
    }

    private function messageText(string $messageId): string
    {
        $message = $this->http
            ->request('GET', $this->baseUrl . '/api/v1/message/' . $messageId)
            ->toArray()
        ;

        if (!isset($message['Text']) || !is_string($message['Text'])) {
            throw new \RuntimeException('Mailpit message ' . $messageId . ' has no Text body.');
        }

        return $message['Text'];
    }
}
