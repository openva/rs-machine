<?php

declare(strict_types=1);

namespace RsMachine;

use RuntimeException;
use UnexpectedValueException;

/**
 * The Internet Archive's Save Page Now 2 (SPN2) API, authenticated with the account's S3-style
 * keys (IA_ACCESS_KEY and IA_SECRET_KEY).
 *
 * Documentation: https://docs.google.com/document/d/1Nsv52MvSjbLb2PCpHlat0gkzw0EvtSgpKHu4mk0MnrA
 */
class WaybackMachine
{
    private const BASE_URL = 'https://web.archive.org/save';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey
    ) {
    }

    /**
     * How many more captures this account may start now. SPN2 limits each account's concurrent
     * captures, and rejects requests beyond that.
     */
    public function availableSessions(): int
    {
        $status = $this->request('GET', '/status/user?_t=' . time());

        if (!isset($status['available'])) {
            throw new RuntimeException('Unexpected response from SPN2 user status: '
                . json_encode($status));
        }

        return (int) $status['available'];
    }

    /**
     * Ask the Wayback Machine to archive a page and the (up to 100) pages it links to, and return
     * the capture's job ID. The capture runs afterward; this doesn't wait for it, or report
     * whether it succeeded.
     *
     * @throws WaybackAuthenticationException when SPN2 rejects the keys
     * @throws UnexpectedValueException when SPN2 refuses the request
     * @throws RuntimeException when SPN2 can't be reached
     */
    public function saveWithOutlinks(string $url): string
    {
        $response = $this->request('POST', '', [
            'url'              => $url,
            'capture_outlinks' => '1',
        ]);

        if (empty($response['job_id'])) {
            throw new UnexpectedValueException('SPN2 did not accept ' . $url . ': '
                . ($response['status_ext'] ?? '') . ' ' . ($response['message'] ?? json_encode($response)));
        }

        return (string) $response['job_id'];
    }

    /**
     * @param array<string, string> $fields Form fields, for a POST.
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $fields = []): array
    {
        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: LOW ' . $this->accessKey . ':' . $this->secretKey,
            ],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($body === false) {
            throw new RuntimeException('Could not reach SPN2: ' . $error);
        }

        // SPN2 says "You need to be logged in" when a key is missing or the header is malformed,
        // and returns an HTML error page when the keys are wrong.
        if ($status === 401 || $status === 403) {
            $decoded = json_decode((string) $body, true);
            throw new WaybackAuthenticationException('SPN2 rejected IA_ACCESS_KEY and IA_SECRET_KEY '
                . '(HTTP ' . $status . '): '
                . ($decoded['message'] ?? substr(trim(strip_tags((string) $body)), 0, 200)));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('SPN2 returned HTTP ' . $status . ': '
                . substr(trim(strip_tags((string) $body)), 0, 200));
        }

        return $decoded;
    }
}
