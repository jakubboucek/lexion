<?php declare(strict_types=1);

namespace App\Model\Http;


/**
 * Minimal curl-based JSON HTTP client shared by every path that talks to the
 * justice services (InfosoudClient, the infoJednani scanner) - one home for
 * the User-Agent, the timeouts and the retry policy.
 *
 * Retries apply to transport failures and 5xx answers only. A 4xx is a
 * meaningful answer (infosoud reports "not found" as HTTP 400) and is never
 * retried.
 */
final readonly class JsonHttpClient
{
    private const string UserAgent = 'Lexion (https://lex.ion.cz)';
    private const int ConnectTimeoutSeconds = 15;
    private const int TimeoutSeconds = 30;
    private const int Retries = 2;         // extra attempts after the first
    private const int BackoffSeconds = 5;


    /**
     * A single attempt, no retry - for callers running their own retry loop
     * with per-attempt logging (the scanner). GET when $jsonBody is null,
     * JSON POST otherwise.
     *
     * @param array<string, mixed>|null $jsonBody
     * @return array{status: int, body: ?string, error: string}
     */
    public function attempt(string $url, ?array $jsonBody = null): array
    {
        $handle = curl_init($url);
        $headers = ['Accept: application/json'];
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => self::UserAgent,
            CURLOPT_TIMEOUT => self::TimeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => self::ConnectTimeoutSeconds,
        ]);
        if ($jsonBody !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode(
                $jsonBody,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        return ['status' => $status, 'body' => $body === false ? null : (string) $body, 'error' => $error];
    }


    /**
     * Request with the shared retry policy.
     *
     * @param array<string, mixed>|null $jsonBody
     * @return array{int, string} [status, body]
     * @throws HttpTransportException when every attempt fails at transport level
     */
    public function request(string $url, ?array $jsonBody = null): array
    {
        for ($try = 0; ; $try++) {
            $response = $this->attempt($url, $jsonBody);
            $retryable = $response['body'] === null || $response['status'] >= 500;
            if (!$retryable || $try >= self::Retries) {
                if ($response['body'] === null) {
                    throw new HttpTransportException("Request to $url failed: {$response['error']}");
                }
                return [$response['status'], $response['body']];
            }
            sleep(self::BackoffSeconds);
        }
    }
}
