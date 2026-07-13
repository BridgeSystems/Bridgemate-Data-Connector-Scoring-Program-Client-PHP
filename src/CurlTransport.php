<?php

declare(strict_types=1);

namespace Bridgemate\DataConnector;

/**
 * ext-curl implementation of HttpTransport. Timeouts mirror the .NET client's HttpClient
 * defaults closely enough: 10 seconds to connect, 100 seconds for the whole request.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct(
        private readonly int $connectTimeoutSeconds = 10,
        private readonly int $timeoutSeconds = 100,
    ) {
    }

    public function get(string $url): string
    {
        return $this->execute($url, null);
    }

    public function post(string $url, string $jsonBody): string
    {
        return $this->execute($url, $jsonBody);
    }

    private function execute(string $url, ?string $jsonBody): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException("Could not initialize curl for '$url'.");
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                // The data connector lives on localhost or the LAN: a proxy from the http_proxy
                // environment variables would hijack the request (503), so disable any proxy.
                CURLOPT_PROXY => '',
            ]);
            if ($jsonBody !== null) {
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $jsonBody,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
                ]);
            }
            $body = curl_exec($handle);
            if ($body === false) {
                throw new TransportException(curl_error($handle) ?: "Request to '$url' failed.");
            }
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($status < 200 || $status >= 300) {
                throw new TransportException("Request to '$url' returned status $status.");
            }
            return (string)$body;
        } finally {
            curl_close($handle);
        }
    }
}
