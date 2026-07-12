<?php

/**
 * Getting-started sample for the Bridgemate Data Connector PHP client.
 *
 * Interactive:  php examples/getting-started.php
 * Unattended:   php examples/getting-started.php --scenario
 *
 * Options: --base-address=http://host:5079  --club-id=...  --licence-key=...  --no-trace
 *
 * The "initialize event" action instructs the Data Connector to START Bridgemate Control
 * Software and create a small test event (1 section, 2 tables, 3 rounds). Watch BCS open,
 * enter a result there (or on a Bridgemate), then use the poll actions here to receive it.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/EchoTransport.php';

use Bridgemate\DataConnector\CurlTransport;
use Bridgemate\DataConnector\DataConnectorClient;
use Bridgemate\DataConnector\Dto\ContinueDTO;
use Bridgemate\DataConnector\Dto\DataConnectorResponseData;
use Bridgemate\DataConnector\Dto\InitDTO;
use Bridgemate\DataConnector\Dto\ParticipationDTO;
use Bridgemate\DataConnector\Dto\PlayerDataDTO;
use Bridgemate\DataConnector\Dto\ResultDTO;
use Bridgemate\DataConnector\Dto\ScoringProgramResponse;
use Bridgemate\DataConnector\Dto\TableDirection;
use Bridgemate\DataConnector\Examples\EchoTransport;

const STATE_FILE = __DIR__ . '/.state.json';

//----------------------------------------------------------------------------------------------
// Setup
//----------------------------------------------------------------------------------------------

$options = getopt('', ['scenario', 'base-address:', 'club-id:', 'licence-key:', 'no-trace']);
$trace = new EchoTransport(new CurlTransport());
$trace->enabled = !isset($options['no-trace']);
$client = new DataConnectorClient(
    clubId: $options['club-id'] ?? '',
    licenceKey: $options['licence-key'] ?? '',
    baseAddress: $options['base-address'] ?? null,
    transport: $trace,
);
echo 'Data Connector address: ' . $client->getBaseAddress() . PHP_EOL;

//----------------------------------------------------------------------------------------------
// Actions. Each one is a plain function of the client, so this file doubles as sample code.
//----------------------------------------------------------------------------------------------

function connectAndPing(DataConnectorClient $client): void
{
    report('Connect', $client->connect());
    report('Ping', $client->ping());
}

/**
 * Creates a fresh event from the template: new event/session guids and today's date.
 * Commands = 7 tells the Data Connector to start BCS (1), reset (2) and start reading (4).
 */
function initializeEvent(DataConnectorClient $client): ScoringProgramResponse
{
    $template = (string)file_get_contents(__DIR__ . '/data/init-template.json');
    $sessionGuid = newGuid();
    $eventGuid = newGuid();
    $data = json_decode(str_replace('REPLACED-AT-RUNTIME', $sessionGuid, $template), true, 512, JSON_THROW_ON_ERROR);
    $data['EventGuid'] = $eventGuid;
    $data['Sessions'][0]['EventGuid'] = $eventGuid;
    $data['Sessions'][0]['Year'] = (int)date('Y');
    $data['Sessions'][0]['Month'] = (int)date('n');
    $data['Sessions'][0]['Day'] = (int)date('j');
    $data['Sessions'][0]['Hour'] = (int)date('G');
    $data['Sessions'][0]['Minute'] = (int)date('i');

    $initDto = InitDTO::fromArray($data);
    $initDto->PlayerData = loadPlayerData($sessionGuid);
    $initDto->Participations = loadParticipations($sessionGuid);

    $response = $client->initialize($initDto);
    saveState(['sessionGuid' => $sessionGuid, 'eventGuid' => $eventGuid]);
    echo "Session guid: $sessionGuid (saved to .state.json)" . PHP_EOL;
    return $response;
}

function continueEvent(DataConnectorClient $client): ScoringProgramResponse
{
    $continueDto = new ContinueDTO();
    $continueDto->EventGuid = state()['eventGuid'];
    //Unlike InitDTO, ContinueDTO must not carry the Reset flag (2): only start BCS (1),
    //start reading (4) and optionally clear data (128), minimize, auto-shutdown or debug logging.
    $continueDto->Commands = 5;
    return $client->continueEvent($continueDto);
}

function sendPlayerData(DataConnectorClient $client): ScoringProgramResponse
{
    $sessionGuid = state()['sessionGuid'];
    return $client->sendPlayerData($sessionGuid, loadPlayerData($sessionGuid));
}

/**
 * Uploads one board result: table 1, round 1, board 1 — 1♣ by North, 7 tricks, lead ♣A.
 */
function sendOneResult(DataConnectorClient $client): ScoringProgramResponse
{
    $sessionGuid = state()['sessionGuid'];
    $result = new ResultDTO();
    $result->SessionGuid = $sessionGuid;
    $result->SectionLetters = 'A';
    $result->TableNumber = 1;
    $result->RoundNumber = 1;
    $result->BoardNumber = 1;
    $result->ScoringDirection = ResultDTO::ScoringDirection_NSEW;
    $result->PairNorthSouth = 1;
    $result->PairEastWest = 2;
    $result->DeclaringPair = 1;
    $result->DeclarerDirection = ResultDTO::Direction_North;
    $result->Level = 1;
    $result->Denomination = ResultDTO::Denomination_Clubs;
    $result->Stake = ResultDTO::Stake_Normal;
    $result->TotalTricks = 7;
    $result->LeadCardRank = 14;
    $result->LeadCardSuit = 1;
    return $client->sendResults($sessionGuid, [$result]);
}

function pollQueue(DataConnectorClient $client, DataConnectorResponseData $dataType, bool $all): void
{
    $sessionGuid = state()['sessionGuid'];
    $items = match ($dataType) {
        DataConnectorResponseData::Results => $client->pollForResults($sessionGuid, $all),
        DataConnectorResponseData::PlayerData => $client->pollForPlayerData($sessionGuid, $all),
        DataConnectorResponseData::Participations => $client->pollForParticipations($sessionGuid, $all),
        DataConnectorResponseData::Handrecords => $client->pollForHandrecords($sessionGuid, $all),
        DataConnectorResponseData::TdCalls => $client->pollForTdCalls($sessionGuid, $all),
        default => [],
    };
    echo "Polled {$dataType->name}: " . count($items) . ' item(s)' . PHP_EOL;
    foreach ($items as $index => $item) {
        echo '  #' . ($index + 1) . PHP_EOL;
        echo indent(indent((string)json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) . PHP_EOL;
    }
    if ($items !== []) {
        echo "Last queue item id: {$client->getLastQueueItemId($dataType)} — use 'accept' so they are not sent again." . PHP_EOL;
    }
}

function acceptQueue(DataConnectorClient $client, DataConnectorResponseData $dataType): void
{
    report("Accept {$dataType->name}", $client->acceptQueueData(state()['sessionGuid'], $dataType));
}

//----------------------------------------------------------------------------------------------
// Scenario mode: the whole flow in one run. Exit code 0 when every step succeeded.
//----------------------------------------------------------------------------------------------

if (isset($options['scenario'])) {
    $ok = true;
    $check = function (string $step, ScoringProgramResponse $response) use (&$ok): void {
        $success = $response->DataType !== DataConnectorResponseData::Error;
        $ok = $ok && $success;
        printf("%-20s %s%s" . PHP_EOL, $step, $success ? 'OK' : 'FAILED: ', $success ? '' : $response->ErrorType->name);
    };
    $check('Connect', $client->connect());
    $check('Ping', $client->ping());
    $check('InitializeEvent', initializeEvent($client));
    $check('PutPlayerData', sendPlayerData($client));
    $check('PutResults', sendOneResult($client));
    pollQueue($client, DataConnectorResponseData::Results, false);
    echo($ok ? 'SCENARIO OK' : 'SCENARIO FAILED') . PHP_EOL;
    exit($ok ? 0 : 1);
}

//----------------------------------------------------------------------------------------------
// Interactive menu
//----------------------------------------------------------------------------------------------

$menu = <<<MENU

 1  Connect + ping
 2  Initialize event (starts BCS, creates a fresh test event)
 3  Continue event (re-open the event from .state.json)
 4  Send player data
 5  Send a board result (A1, round 1, board 1)
 6  Poll results            7  Accept results
 8  Poll player data        9  Accept player data
10  Poll participations    11  Accept participations
12  Poll handrecords       13  Accept handrecords
14  Poll TD calls          15  Accept TD calls
16  Toggle 'poll all' (currently: %s)
17  Toggle wire trace (currently: %s)
 0  Quit

MENU;

$pollAll = false;
while (true) {
    printf($menu, $pollAll ? 'all items' : 'new items only', $trace->enabled ? 'on' : 'off');
    $choice = trim((string)readlineCompat('Choice: '));
    try {
        switch ($choice) {
            case '1': connectAndPing($client); break;
            case '2': report('InitializeEvent', initializeEvent($client)); break;
            case '3': report('ContinueEvent', continueEvent($client)); break;
            case '4': report('PutPlayerData', sendPlayerData($client)); break;
            case '5': report('PutResults', sendOneResult($client)); break;
            case '6': pollQueue($client, DataConnectorResponseData::Results, $pollAll); break;
            case '7': acceptQueue($client, DataConnectorResponseData::Results); break;
            case '8': pollQueue($client, DataConnectorResponseData::PlayerData, $pollAll); break;
            case '9': acceptQueue($client, DataConnectorResponseData::PlayerData); break;
            case '10': pollQueue($client, DataConnectorResponseData::Participations, $pollAll); break;
            case '11': acceptQueue($client, DataConnectorResponseData::Participations); break;
            case '12': pollQueue($client, DataConnectorResponseData::Handrecords, $pollAll); break;
            case '13': acceptQueue($client, DataConnectorResponseData::Handrecords); break;
            case '14': pollQueue($client, DataConnectorResponseData::TdCalls, $pollAll); break;
            case '15': acceptQueue($client, DataConnectorResponseData::TdCalls); break;
            case '16': $pollAll = !$pollAll; break;
            case '17': $trace->enabled = !$trace->enabled; break;
            case '0': exit(0);
            default: echo 'Unknown choice.' . PHP_EOL;
        }
    } catch (\RuntimeException $exception) {
        echo 'Error: ' . $exception->getMessage() . PHP_EOL;
    }
}

//----------------------------------------------------------------------------------------------
// Helpers
//----------------------------------------------------------------------------------------------

function report(string $action, ScoringProgramResponse $response): void
{
    printf("%s -> DataType=%s ErrorType=%s" . PHP_EOL, $action, $response->DataType->name, $response->ErrorType->name);
    echo indent(prettyData($response->SerializedData)) . PHP_EOL;
}

/**
 * Renders the (JSON string) payload of a response in full, pretty-printed. \r\n sequences
 * inside message strings become real line breaks so validation messages read naturally.
 */
function prettyData(?string $serializedData): string
{
    if ($serializedData === null || trim($serializedData) === '') {
        return '(no data)';
    }
    $decoded = json_decode($serializedData);
    if (is_string($decoded)) {
        return str_replace(["\r\n", "\r"], "\n", $decoded);
    }
    return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function indent(string $text): string
{
    return '  ' . str_replace("\n", "\n  ", $text);
}

function newGuid(): string
{
    return strtoupper(bin2hex(random_bytes(16)));
}

/**
 * @return PlayerDataDTO[]
 */
function loadPlayerData(string $sessionGuid): array
{
    $players = json_decode((string)file_get_contents(__DIR__ . '/data/players.json'), true, 512, JSON_THROW_ON_ERROR);
    return array_map(static function (array $player) use ($sessionGuid): PlayerDataDTO {
        $dto = new PlayerDataDTO();
        $dto->SessionGuid = $sessionGuid;
        $dto->PlayerNumber = $player['PlayerNumber'];
        $dto->FirstName = $player['FirstName'];
        $dto->LastName = $player['LastName'];
        $dto->CountryCode = $player['CountryCode'];
        return $dto;
    }, $players);
}

/**
 * Round-1 seating for the players in players.json.
 *
 * @return ParticipationDTO[]
 */
function loadParticipations(string $sessionGuid): array
{
    $players = json_decode((string)file_get_contents(__DIR__ . '/data/players.json'), true, 512, JSON_THROW_ON_ERROR);
    return array_map(static function (array $player) use ($sessionGuid): ParticipationDTO {
        $dto = new ParticipationDTO();
        $dto->SessionGuid = $sessionGuid;
        $dto->SectionLetters = $player['SectionLetters'];
        $dto->TableNumber = $player['TableNumber'];
        $dto->Direction = TableDirection::from($player['Direction']);
        $dto->RoundNumber = 1;
        $dto->PlayerNumber = $player['PlayerNumber'];
        return $dto;
    }, $players);
}

/**
 * @return array{sessionGuid: string, eventGuid: string}
 */
function state(): array
{
    if (!file_exists(STATE_FILE)) {
        throw new \RuntimeException('No event yet: run "Initialize event" first.');
    }
    return json_decode((string)file_get_contents(STATE_FILE), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param array{sessionGuid: string, eventGuid: string} $state
 */
function saveState(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

function readlineCompat(string $prompt): string
{
    echo $prompt;
    return (string)fgets(STDIN);
}
