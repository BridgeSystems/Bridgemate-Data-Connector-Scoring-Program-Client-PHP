<?php

declare(strict_types=1);

namespace Bridgemate\DataConnector;

/**
 * The http layer used by the DataConnectorClient. The default implementation is CurlTransport;
 * tests inject a fake.
 */
interface HttpTransport
{
    /**
     * Performs a GET request and returns the response body.
     *
     * @throws TransportException when the request fails or returns a non-2xx status.
     */
    public function get(string $url): string;

    /**
     * POSTs the given JSON body with content type application/json and returns the response body.
     *
     * @throws TransportException when the request fails or returns a non-2xx status.
     */
    public function post(string $url, string $jsonBody): string;
}
