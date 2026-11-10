<?php

declare(strict_types=1);

namespace App\Service\Fetch\Support;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ResponseSizeLimit;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The body of a response requested with ACCEPT_ENCODING, decoded under its size limit. The wire cap bounds only the
 * compressed bytes, so inflation stops once the output passes the limit: a gzip bomb is never inflated whole.
 */
final class ResponseBody
{
    public const string ACCEPT_ENCODING = 'gzip, deflate';
    // Deflate expands at most about 1032:1, so one slice overshoots the limit by about 1 MB at worst.
    private const int SLICE_BYTES = 1_024;
    private const array UNDECODABLE = ['br', 'zstd', 'compress', 'x-compress'];
    private const array ZLIB_ENCODINGS = [
        'gzip' => \ZLIB_ENCODING_GZIP,
        'x-gzip' => \ZLIB_ENCODING_GZIP,
        'deflate' => \ZLIB_ENCODING_DEFLATE,
    ];

    /**
     * @throws ExceptionInterface        when the body cannot be read
     * @throws ResponseTooLargeException when the decoded body exceeds the limit
     * @throws FeedUnreachableException  when the encoding is unsupported or the stream corrupt
     */
    public static function decoded(ResponseInterface $response, ResponseSizeLimit $limit): string
    {
        $body = $response->getContent(false);
        $url = self::urlOf($response);
        $encoding = strtolower(trim(ResponseHeader::first($response, 'content-encoding') ?? ''));
        if (isset(self::ZLIB_ENCODINGS[$encoding]) || self::isUndecodable($encoding)) {
            try {
                $body = self::inflatedPast($body, $encoding, $limit->bytes());
            } catch (\UnexpectedValueException $refused) {
                throw new FeedUnreachableException(sprintf('%s: %s', $url, $refused->getMessage()), previous: $refused);
            }
        }
        ResponseTooLargeException::throwIfExceeded($limit, \strlen($body), $url);

        return $body;
    }

    /** @throws \UnexpectedValueException when the encoding is unsupported or the stream corrupt */
    private static function inflatedPast(string $compressed, string $encoding, int $maxBytes): string
    {
        $zlibEncoding = self::ZLIB_ENCODINGS[$encoding]
            ?? throw new \UnexpectedValueException(sprintf('unsupported Content-Encoding "%s"', $encoding));
        $context = inflate_init($zlibEncoding);
        \assert($context instanceof \InflateContext);
        set_error_handler(static function (int $severity, string $message) use ($encoding): never {
            throw new \UnexpectedValueException(sprintf('corrupt %s body: %s', $encoding, $message));
        });
        try {
            return self::inflateSlices($context, $compressed, $maxBytes);
        } finally {
            restore_error_handler();
        }
    }

    /** A stream cut short keeps what it inflated, as a browser does. */
    private static function inflateSlices(\InflateContext $context, string $compressed, int $maxBytes): string
    {
        $inflated = '';
        $length = \strlen($compressed);
        for ($offset = 0; $offset < $length && \strlen($inflated) <= $maxBytes; $offset += self::SLICE_BYTES) {
            $piece = inflate_add($context, substr($compressed, $offset, self::SLICE_BYTES));
            if ($piece === false) {
                throw new \UnexpectedValueException('corrupt compressed body');
            }
            $inflated .= $piece;
            if (inflate_get_status($context) === \ZLIB_STREAM_END) {
                break;
            }
        }

        return $inflated;
    }

    private static function isUndecodable(string $encoding): bool
    {
        return \in_array($encoding, self::UNDECODABLE, true) || str_contains($encoding, ',');
    }

    private static function urlOf(ResponseInterface $response): string
    {
        $url = $response->getInfo('url');

        return \is_string($url) ? $url : '';
    }

    private function __construct()
    {
    }
}
