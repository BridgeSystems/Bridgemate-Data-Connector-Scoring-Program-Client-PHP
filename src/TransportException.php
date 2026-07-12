<?php

declare(strict_types=1);

namespace Bridgemate\DataConnector;

/**
 * Thrown by an HttpTransport when the request could not be completed (connection failure,
 * timeout or a non-2xx status). The DataConnectorClient catches it and retries; it never
 * escapes to calling code.
 */
class TransportException extends \RuntimeException
{
}
