<?php

declare(strict_types=1);

namespace Bridgemate\DataConnector\Examples;

use Bridgemate\DataConnector\HttpTransport;

/**
 * Decorates another HttpTransport and echoes every request and response to the console,
 * with the nested SerializedData expanded, so you can trace the wire traffic of the
 * DataConnectorClient without a debugger.
 */
final class EchoTransport implements HttpTransport
{
    public bool $enabled = true;

    public function __construct(private readonly HttpTransport $inner)
    {
    }

    public function get(string $url): string
    {
        $this->echoLine(">> GET  $url");
        $body = $this->inner->get($url);
        $this->echoLine("<< " . $body);
        return $body;
    }

    public function post(string $url, string $jsonBody): string
    {
        $this->echoLine(">> POST $url");
        $this->echoJson($jsonBody);
        $body = $this->inner->post($url, $jsonBody);
        $this->echoLine("<<");
        $this->echoJson($body);
        return $body;
    }

    private function echoLine(string $line): void
    {
        if ($this->enabled) {
            echo "\033[36m$line\033[0m" . PHP_EOL;
        }
    }

    /**
     * Pretty-prints an envelope. The SerializedData property is itself a JSON string
     * ("double serialization"); expand it so the payload is readable.
     */
    private function echoJson(string $json): void
    {
        if (!$this->enabled) {
            return;
        }
        $decoded = json_decode($json, true);
        if (is_array($decoded) && isset($decoded['SerializedData']) && $decoded['SerializedData'] !== '') {
            $decoded['SerializedData'] = json_decode($decoded['SerializedData'], true);
        }
        $this->echoLine(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
