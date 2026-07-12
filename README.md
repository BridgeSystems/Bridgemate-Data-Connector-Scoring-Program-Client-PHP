# Bridgemate Data Connector scoring program client for PHP

[![Packagist](https://img.shields.io/packagist/v/bridgemate/dataconnector-client)](https://packagist.org/packages/bridgemate/dataconnector-client)
[![CI](https://github.com/BridgeSystems/Bridgemate-Data-Connector-Scoring-Program-Client-PHP/actions/workflows/ci.yml/badge.svg)](https://github.com/BridgeSystems/Bridgemate-Data-Connector-Scoring-Program-Client-PHP/actions/workflows/ci.yml)

PHP client for scoring programs to communicate with the **Bridgemate Data Connector** over http.
Bridgemate Control Software (BCS 5) is needed to receive, process and return data from the Data
Connector. This package is the PHP counterpart of the
[.NET client](https://github.com/BridgeSystems/Bridgemate-Data-Connector-Scoring-Program-Client);
its wire format is generated from the .NET source and verified against golden fixtures, so the two
clients speak an identical protocol.

## Requirements

- PHP 8.1 or later with `ext-curl` and `ext-json`
- A reachable Bridgemate Data Connector (installed with BCS 5). When the scoring program runs on a
  different computer than BCS, enable listening on the local network in BCS and allow the port
  (default 5079) through the firewall on the BCS computer.

## Installation

```
composer require bridgemate/dataconnector-client
```

## Quick start

```php
use Bridgemate\DataConnector\DataConnectorClient;
use Bridgemate\DataConnector\Dto\DataConnectorResponseData;
use Bridgemate\DataConnector\Dto\InitDTO;

// Data Connector on the same computer (port discovered through the registry, default 5079):
$client = new DataConnectorClient('YourClubId', 'YourLicenceKey');

// Data Connector on another computer on the local network:
$client = new DataConnectorClient('YourClubId', 'YourLicenceKey', 'http://192.168.1.50:5079');

// Check the connection.
$response = $client->connect();

// Create an event in BCS (see the developer's guide for how to build the InitDTO).
$initDto = new InitDTO();
// ... fill sessions, scoring groups, sections, tables, rounds ...
$response = $client->initialize($initDto);

// Poll for new board results and accept them once processed.
$results = $client->pollForResults($sessionGuid);
foreach ($results as $result) {
    // store the result in your scoring program
}
if ($results !== []) {
    $client->acceptQueueData($sessionGuid, DataConnectorResponseData::Results);
}
```

All methods return a `ScoringProgramResponse` (poll methods return arrays of DTOs) and never throw
on communication problems: inspect `DataType`/`ErrorType` on the response, exactly like with the
.NET client.

### Polling model

Http is stateless and the client keeps no connection open, so it fits both a long-running CLI
worker (poll in a loop) and a web application (poll on demand during a request). The id of the
last polled queue item per data type is cached on the client instance and used by
`acceptQueueData()`; in a web application poll and accept within the same request, or track the
queue item ids yourself via `getLastQueueItemId()`.

## Getting started sample

[examples/getting-started.php](examples/getting-started.php) is a small console application that
exercises the whole workflow against a live Data Connector — use it as a template for your own
scoring program:

```
composer install
php examples/getting-started.php               # interactive menu
php examples/getting-started.php --scenario    # unattended full flow
```

Mind that **"Initialize event" starts Bridgemate Control Software** and creates a small test
event (1 section, 2 tables, 3 rounds, 8 players). The poll queues only carry data once BCS
produces it: enter a result in BCS (or on a Bridgemate) and then poll for results here. The
sample prints every request and response envelope (wire trace, toggleable), which is the fastest
way to learn the protocol.

### Debugging in Visual Studio Code

Open this folder in VS Code and install the recommended extensions (Intelephense + PHP Debug,
suggested automatically). With PHP and [Xdebug](https://xdebug.org/docs/install) installed,
press <kbd>F5</kbd> — launch configurations for the sample and for PHPUnit are provided in
`.vscode/launch.json`. Set a breakpoint in `src/DataConnectorClient.php::sendRequest()` to watch
every envelope being built and sent.

## Documentation

The protocol, the procedures (initializing an event, updating movements, the queues) and all DTOs
are described in the
[Bridgemate Data Connector developer's guide](https://github.com/BridgeSystems/Bridgemate-Data-Connector-Scoring-Program-Client/blob/master/Documentation/MD/index.md).
The DTO classes in `src/Dto` carry the same names and property names as the guide.

## Scope

This first release covers the core workflow: `connect`/`ping`, `initialize`, `continueEvent`,
`updateMovement`, `updateScoringGroups`, the `send*` methods (results, player data,
participations, handrecords, TD calls, Bridgemate 2/3 settings), the `pollFor*` methods and
`acceptQueueData`. BCS management commands are not wrapped yet; `sendRequest()` is public for
anything the client does not cover.

## Compatibility

| Package version | Data Connector / BCS |
| --- | --- |
| 1.x | BCS 5.x (Data Connector with http support) |

## Development

The `src/Dto` classes and `tests/fixtures` are **generated** from the .NET client repository
(`tools/DtoGenerator` there) — do not edit them by hand. The fixture tests assert structural JSON
equality with the exact bytes the .NET client produces.

```
composer install
composer test
```

## Other platforms and support

The same client exists for
[.NET](https://github.com/BridgeSystems/Bridgemate-Data-Connector-Scoring-Program-Client) (the
reference implementation, including the scoring program emulator) and
[Java](https://github.com/BridgeSystems/Bridgemate-Data-Connector-Scoring-Program-Client-Java).
Questions are welcome in the
[Discussions](https://github.com/BridgeSystems/Bridgemate-Data-Connector-Scoring-Program-Client/discussions)
of the main repository; see [SUPPORT.md](SUPPORT.md).

## License

LGPL-3.0-only, like the .NET client.
