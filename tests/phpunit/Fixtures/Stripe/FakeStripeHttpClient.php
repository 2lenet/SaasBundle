<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Fixtures\Stripe;

use LogicException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * HTTP client plugged into the Stripe SDK during tests: replays queued responses and records the requests.
 */
class FakeStripeHttpClient implements ClientInterface
{
    public const REQUEST_ID_PREFIX = 'req_test_';

    /** @var list<array{body: string, status: int, requestId: string}> */
    protected array $responses = [];

    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
    protected array $requests = [];

    /**
     * @param array<string, mixed> $body
     */
    public function queue(array $body, int $status = 200): void
    {
        $this->responses[] = [
            'body' => (string) json_encode($body),
            'status' => $status,
            'requestId' => self::REQUEST_ID_PREFIX . (count($this->responses) + count($this->requests) + 1),
        ];
    }

    /**
     * @param array<mixed> $headers
     * @param array<mixed> $params
     *
     * @return array{string, int, CaseInsensitiveArray}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        /** @var list<string> $headerLines */
        $headerLines = array_values(array_map(static fn (mixed $header): string => (string) $header, $headers));
        /** @var array<string, mixed> $params */
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headerLines, 'params' => $params];

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new LogicException('No Stripe response queued for ' . $method . ' ' . $absUrl);
        }

        return [$response['body'], $response['status'], new CaseInsensitiveArray(['Request-Id' => $response['requestId']])];
    }

    /**
     * @return list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    public function findHeader(int $requestIndex, string $name): ?string
    {
        foreach ($this->requests[$requestIndex]['headers'] as $header) {
            [$headerName, $value] = array_map('trim', explode(':', $header, 2)) + [1 => ''];
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
