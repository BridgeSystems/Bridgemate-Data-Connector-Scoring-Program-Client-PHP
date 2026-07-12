<?php

declare(strict_types=1);

namespace Bridgemate\DataConnector;

use Bridgemate\DataConnector\Dto\Bridgemate2SettingsDTO;
use Bridgemate\DataConnector\Dto\Bridgemate3SettingsDTO;
use Bridgemate\DataConnector\Dto\ContinueDTO;
use Bridgemate\DataConnector\Dto\DataConnectorResponseData;
use Bridgemate\DataConnector\Dto\ErrorType;
use Bridgemate\DataConnector\Dto\HandrecordDTO;
use Bridgemate\DataConnector\Dto\InitDTO;
use Bridgemate\DataConnector\Dto\ParticipationDTO;
use Bridgemate\DataConnector\Dto\PlayerDataDTO;
use Bridgemate\DataConnector\Dto\ResultDTO;
use Bridgemate\DataConnector\Dto\ScoringGroupDTO;
use Bridgemate\DataConnector\Dto\ScoringProgramDataConnectorCommands;
use Bridgemate\DataConnector\Dto\ScoringProgramRequest;
use Bridgemate\DataConnector\Dto\ScoringProgramResponse;
use Bridgemate\DataConnector\Dto\SectionUpdateDTO;
use Bridgemate\DataConnector\Dto\TdCallDTO;

/**
 * Scoring program client for the Bridgemate Data Connector over http.
 *
 * The wire behaviour mirrors the .NET ScoringProgramDataConnectorHttpClient: a JSON serialized
 * ScoringProgramRequest is POSTed to the dc-scoringprogram endpoint and answered with a JSON
 * serialized ScoringProgramResponse. Since http is stateless there is no persistent connection:
 * connect() is a ping and there is no disconnect.
 *
 * Methods never throw on communication problems; they return a ScoringProgramResponse with
 * DataType Error (poll methods return an empty array), exactly like the .NET client.
 */
class DataConnectorClient
{
    /**
     * The default http port of the Data Connector.
     */
    public const DEFAULT_PORT = 5079;

    /**
     * The endpoint that handles scoring program requests.
     */
    public const SCORING_PROGRAM_ENDPOINT = 'dc-scoringprogram';

    /**
     * Part of the expected response body when pinging the Data Connector with a GET request.
     */
    public const API_PING_RESPONSE = 'Bridgemate dataconnector service version';

    private readonly HttpTransport $transport;
    private readonly string $baseAddress;

    /**
     * The id of the last downloaded queue item per data type, cached by the poll methods and
     * used by acceptQueueData.
     *
     * @var array<int, int>
     */
    private array $lastQueueItemIds = [];

    /**
     * @param string $clubId The id of the club that is using the client. Required for http communication.
     * @param string $licenceKey The licence key for the club using the client. Required for http communication.
     * @param string|null $baseAddress The base address of the Data Connector host, e.g. "http://192.168.1.50:5079".
     *        When null the client targets the Data Connector on the local computer, discovering its port
     *        through the Windows registry (default 5079). On non-Windows hosts pass the address explicitly.
     * @param HttpTransport|null $transport Override the http implementation; used by tests.
     */
    public function __construct(
        private readonly string $clubId,
        private readonly string $licenceKey,
        ?string $baseAddress = null,
        ?HttpTransport $transport = null,
    ) {
        if ($baseAddress !== null) {
            $trimmed = rtrim($baseAddress, '/');
            if (!preg_match('#^https?://#i', $trimmed)) {
                throw new \InvalidArgumentException("'$baseAddress' is not an absolute http or https url.");
            }
            $this->baseAddress = $trimmed;
        } else {
            $this->baseAddress = self::discoverLocalBaseAddress();
        }
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * The base address in use, e.g. "http://localhost:5079".
     */
    public function getBaseAddress(): string
    {
        return $this->baseAddress;
    }

    /**
     * The url of the Data Connector on the local computer. On Windows the Data Connector service
     * publishes the port it listens on in the registry (HKEY_CURRENT_USER\Software\Bridge Systems BV\
     * BridgemateDataConnector, value HttpPort); when nothing is published the default port 5079 is
     * assumed.
     */
    public static function discoverLocalBaseAddress(): string
    {
        if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $output = @shell_exec('reg query "HKCU\Software\Bridge Systems BV\BridgemateDataConnector" /v HttpPort 2>nul');
            if (is_string($output) && preg_match('/HttpPort\s+REG_DWORD\s+0x([0-9A-Fa-f]+)/', $output, $matches)) {
                $port = (int)hexdec($matches[1]);
                if ($port > 0 && $port <= 65535) {
                    return 'http://localhost:' . $port;
                }
            }
        }
        return 'http://localhost:' . self::DEFAULT_PORT;
    }

    /**
     * Checks if the Data Connector can be reached by sending a GET request to its base address.
     */
    public function connect(): ScoringProgramResponse
    {
        try {
            $body = $this->transport->get($this->baseAddress . '/');
            $success = str_contains($body, self::API_PING_RESPONSE);
        } catch (TransportException $exception) {
            $body = $exception->getMessage();
            $success = false;
        }
        $response = new ScoringProgramResponse();
        $response->RequestCommand = ScoringProgramDataConnectorCommands::Connect;
        $response->DataType = $success ? DataConnectorResponseData::OK : DataConnectorResponseData::Error;
        $response->ErrorType = $success ? ErrorType::None : ErrorType::NoConnection;
        $response->SerializedData = json_encode($body, JSON_THROW_ON_ERROR);
        return $response;
    }

    /**
     * Checks that the Data Connector is responsive by sending it a piece of data that it must echo.
     */
    public function ping(): ScoringProgramResponse
    {
        $requestTicks = (string)(int)(microtime(true) * 10_000_000);
        $response = $this->send('', ScoringProgramDataConnectorCommands::Ping, json_encode($requestTicks, JSON_THROW_ON_ERROR));
        if ($response->RequestCommand !== ScoringProgramDataConnectorCommands::Ping) {
            return $this->errorResponse(
                ScoringProgramDataConnectorCommands::Ping,
                ErrorType::Unknown,
                "Invalid command in response to Ping: '{$response->RequestCommand->name}'",
            );
        }
        if ($response->DataType !== DataConnectorResponseData::OK) {
            return $response;
        }
        $responseTicks = json_decode($response->SerializedData ?? 'null');
        $error = $responseTicks !== $requestTicks;
        $result = new ScoringProgramResponse();
        $result->RequestCommand = ScoringProgramDataConnectorCommands::Ping;
        $result->DataType = $error ? DataConnectorResponseData::Error : DataConnectorResponseData::OK;
        $result->ErrorType = $error ? ErrorType::Validation : ErrorType::None;
        $result->SerializedData = $response->SerializedData;
        return $result;
    }

    /**
     * Instructs BCS to create a new event with the provided sessions, scoring groups, sections,
     * tables and rounds. Player data, participations and handrecords can be included.
     */
    public function initialize(InitDTO $initDTO): ScoringProgramResponse
    {
        return $this->send('', ScoringProgramDataConnectorCommands::InitializeEvent, $this->encode($initDTO));
    }

    /**
     * Instructs BCS to continue working with a previously created event.
     */
    public function continueEvent(ContinueDTO $continueDTO): ScoringProgramResponse
    {
        return $this->send('', ScoringProgramDataConnectorCommands::ContinueEvent, $this->encode($continueDTO));
    }

    /**
     * Updates the movement for a section, or deletes the section.
     */
    public function updateMovement(SectionUpdateDTO $updatedSection): ScoringProgramResponse
    {
        return $this->send($updatedSection->SessionGuid ?? '', ScoringProgramDataConnectorCommands::UpdateMovement, $this->encode($updatedSection));
    }

    /**
     * Updates the scoring method of the scoring groups and/or rearranges the assignment of the
     * sections to them. Sends one request per session, like the .NET client.
     *
     * @param ScoringGroupDTO[] $scoringGroups
     */
    public function updateScoringGroups(array $scoringGroups): ScoringProgramResponse
    {
        $groups = [];
        foreach ($scoringGroups as $scoringGroup) {
            $groups[$scoringGroup->SessionGuid ?? ''][] = $scoringGroup;
        }
        foreach ($groups as $sessionGuid => $groupsForSession) {
            $response = $this->send((string)$sessionGuid, ScoringProgramDataConnectorCommands::UpdateScoringGroups, $this->encode($groupsForSession));
            if ($response->DataType !== DataConnectorResponseData::OK) {
                return $response;
            }
        }
        $response = new ScoringProgramResponse();
        $response->RequestCommand = ScoringProgramDataConnectorCommands::UpdateScoringGroups;
        $response->DataType = DataConnectorResponseData::OK;
        $response->SerializedData = json_encode('Scoring groups updated.', JSON_THROW_ON_ERROR);
        return $response;
    }

    /**
     * Sends board results to the BCS queue. Only DTOs whose SessionGuid matches are sent.
     *
     * @param ResultDTO[] $results
     */
    public function sendResults(string $sessionGuid, array $results): ScoringProgramResponse
    {
        return $this->sendForSession($sessionGuid, ScoringProgramDataConnectorCommands::PutResults, $results);
    }

    /**
     * Sends player data to the BCS queue. Only DTOs whose SessionGuid matches are sent.
     *
     * @param PlayerDataDTO[] $playerData
     */
    public function sendPlayerData(string $sessionGuid, array $playerData): ScoringProgramResponse
    {
        return $this->sendForSession($sessionGuid, ScoringProgramDataConnectorCommands::PutPlayerData, $playerData);
    }

    /**
     * Sends participations to the BCS queue. Only DTOs whose SessionGuid matches are sent;
     * when none match a NoData error is returned without calling the Data Connector.
     *
     * @param ParticipationDTO[] $participations
     */
    public function sendParticipations(string $sessionGuid, array $participations): ScoringProgramResponse
    {
        $forSession = self::filterBySession($sessionGuid, $participations);
        if ($forSession === []) {
            return $this->errorResponse(ScoringProgramDataConnectorCommands::PutParticipations, ErrorType::NoData, 'Empty data');
        }
        return $this->send($sessionGuid, ScoringProgramDataConnectorCommands::PutParticipations, $this->encode($forSession));
    }

    /**
     * Sends handrecords to the BCS queue. Only DTOs whose SessionGuid matches are sent.
     *
     * @param HandrecordDTO[] $handrecords
     */
    public function sendHandrecords(string $sessionGuid, array $handrecords): ScoringProgramResponse
    {
        return $this->sendForSession($sessionGuid, ScoringProgramDataConnectorCommands::PutHandrecords, $handrecords);
    }

    /**
     * Sends TD calls to the BCS queue. Only DTOs whose SessionGuid matches are sent.
     *
     * @param TdCallDTO[] $tdCalls
     */
    public function sendTdCalls(string $sessionGuid, array $tdCalls): ScoringProgramResponse
    {
        return $this->sendForSession($sessionGuid, ScoringProgramDataConnectorCommands::PutTdCalls, $tdCalls);
    }

    /**
     * Adds or updates the Bridgemate 2 settings for the given sections. One DTO per section.
     *
     * @param Bridgemate2SettingsDTO[] $settings
     */
    public function sendBridgemate2Settings(string $sessionGuid, array $settings): ScoringProgramResponse
    {
        return $this->sendForSession($sessionGuid, ScoringProgramDataConnectorCommands::PutBridgemate2Settings, $settings);
    }

    /**
     * Adds or updates the Bridgemate 3 settings for the given sections. One DTO per section.
     *
     * @param Bridgemate3SettingsDTO[] $settings
     */
    public function sendBridgemate3Settings(string $sessionGuid, array $settings): ScoringProgramResponse
    {
        return $this->sendForSession($sessionGuid, ScoringProgramDataConnectorCommands::PutBridgemate3Settings, $settings);
    }

    /**
     * Polls the queue for board results.
     *
     * @param bool $all Also return results that were polled before.
     * @return ResultDTO[]
     */
    public function pollForResults(string $sessionGuid, bool $all = false): array
    {
        return $this->poll(
            $sessionGuid,
            $all ? ScoringProgramDataConnectorCommands::PollQueueForAllResults : ScoringProgramDataConnectorCommands::PollQueueForNewResults,
            DataConnectorResponseData::Results,
            ResultDTO::class,
        );
    }

    /**
     * Polls the queue for player data.
     *
     * @param bool $all Also return player data that was polled before.
     * @return PlayerDataDTO[]
     */
    public function pollForPlayerData(string $sessionGuid, bool $all = false): array
    {
        return $this->poll(
            $sessionGuid,
            $all ? ScoringProgramDataConnectorCommands::PollQueueForAllPlayerData : ScoringProgramDataConnectorCommands::PollQueueForNewPlayerData,
            DataConnectorResponseData::PlayerData,
            PlayerDataDTO::class,
        );
    }

    /**
     * Polls the queue for participations.
     *
     * @param bool $all Also return participations that were polled before.
     * @return ParticipationDTO[]
     */
    public function pollForParticipations(string $sessionGuid, bool $all = false): array
    {
        return $this->poll(
            $sessionGuid,
            $all ? ScoringProgramDataConnectorCommands::PollQueueForAllParticipations : ScoringProgramDataConnectorCommands::PollQueueForNewParticipations,
            DataConnectorResponseData::Participations,
            ParticipationDTO::class,
        );
    }

    /**
     * Polls the queue for handrecords.
     *
     * @param bool $all Also return handrecords that were polled before.
     * @return HandrecordDTO[]
     */
    public function pollForHandrecords(string $sessionGuid, bool $all = false): array
    {
        return $this->poll(
            $sessionGuid,
            $all ? ScoringProgramDataConnectorCommands::PollQueueForAllHandrecords : ScoringProgramDataConnectorCommands::PollQueueForNewHandrecords,
            DataConnectorResponseData::Handrecords,
            HandrecordDTO::class,
        );
    }

    /**
     * Polls the queue for TD calls.
     *
     * @param bool $all Also return TD calls that were polled before.
     * @return TdCallDTO[]
     */
    public function pollForTdCalls(string $sessionGuid, bool $all = false): array
    {
        return $this->poll(
            $sessionGuid,
            $all ? ScoringProgramDataConnectorCommands::PollQueueForAllTdCalls : ScoringProgramDataConnectorCommands::PollQueueForNewTdCalls,
            DataConnectorResponseData::TdCalls,
            TdCallDTO::class,
        );
    }

    /**
     * Signals to the Data Connector that queue data of the given type, up to and including the
     * last polled item, does not need to be sent again.
     *
     * @param DataConnectorResponseData $dataType Results, PlayerData, Participations, Handrecords or TdCalls.
     */
    public function acceptQueueData(string $sessionGuid, DataConnectorResponseData $dataType): ScoringProgramResponse
    {
        $command = match ($dataType) {
            DataConnectorResponseData::Results => ScoringProgramDataConnectorCommands::AcceptResultQueueItems,
            DataConnectorResponseData::PlayerData => ScoringProgramDataConnectorCommands::AcceptPlayerDataQueueItems,
            DataConnectorResponseData::Participations => ScoringProgramDataConnectorCommands::AcceptParticipantQueueItems,
            DataConnectorResponseData::Handrecords => ScoringProgramDataConnectorCommands::AcceptHandrecordQueueItems,
            DataConnectorResponseData::TdCalls => ScoringProgramDataConnectorCommands::AcceptTdCallQueueItems,
            default => null,
        };
        if ($command === null) {
            return $this->errorResponse(
                ScoringProgramDataConnectorCommands::None,
                ErrorType::Validation,
                "Invalid datatype '{$dataType->name}'",
            );
        }
        $lastQueueItemId = $this->lastQueueItemIds[$dataType->value] ?? 0;
        return $this->send($sessionGuid, $command, json_encode($lastQueueItemId, JSON_THROW_ON_ERROR));
    }

    /**
     * The id of the last queue item downloaded for the given data type, as cached by the poll
     * methods. Zero when nothing has been polled yet.
     */
    public function getLastQueueItemId(DataConnectorResponseData $dataType): int
    {
        return $this->lastQueueItemIds[$dataType->value] ?? 0;
    }

    /**
     * Sends a raw request. The ClubId and LicenceKey are set on the request by this method.
     * All higher level methods funnel through here; use it directly for commands this client
     * does not wrap yet.
     */
    public function sendRequest(ScoringProgramRequest $request): ScoringProgramResponse
    {
        $request->ClubId = $this->clubId;
        $request->LicenceKey = $this->licenceKey;
        $json = json_encode($request, JSON_THROW_ON_ERROR);
        $url = $this->baseAddress . '/' . self::SCORING_PROGRAM_ENDPOINT;

        $lastErrorMessage = 'No connection';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $body = $this->transport->post($url, $json);
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    return $this->errorResponse($request->Command, ErrorType::EmptyResponse, 'Empty response');
                }
                return ScoringProgramResponse::fromArray($decoded);
            } catch (TransportException | \JsonException $exception) {
                $lastErrorMessage = $exception->getMessage();
                //The .NET client backs off 200/400/600/800 ms between its five attempts.
                if ($attempt < 4) {
                    usleep((200 + $attempt * 200) * 1000);
                }
            }
        }
        return $this->errorResponse($request->Command, ErrorType::NoConnection, $lastErrorMessage);
    }

    private function send(string $sessionGuid, ScoringProgramDataConnectorCommands $command, string $serializedData): ScoringProgramResponse
    {
        $request = new ScoringProgramRequest();
        $request->Command = $command;
        $request->SessionGuid = $sessionGuid;
        $request->SerializedData = $serializedData;
        return $this->sendRequest($request);
    }

    /**
     * @param object[] $dtos
     */
    private function sendForSession(string $sessionGuid, ScoringProgramDataConnectorCommands $command, array $dtos): ScoringProgramResponse
    {
        return $this->send($sessionGuid, $command, $this->encode(self::filterBySession($sessionGuid, $dtos)));
    }

    /**
     * @template T of object
     * @param T[] $dtos
     * @return T[]
     */
    private static function filterBySession(string $sessionGuid, array $dtos): array
    {
        return array_values(array_filter($dtos, static fn (object $dto): bool => $dto->SessionGuid === $sessionGuid));
    }

    /**
     * @param class-string $dtoClass
     * @return object[]
     */
    private function poll(string $sessionGuid, ScoringProgramDataConnectorCommands $command, DataConnectorResponseData $expectedDataType, string $dtoClass): array
    {
        $response = $this->send($sessionGuid, $command, '');
        if ($response->DataType !== $expectedDataType) {
            return [];
        }
        if ($response->SerializedData === null || trim($response->SerializedData) === '') {
            return [];
        }
        $items = json_decode($response->SerializedData, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($items)) {
            return [];
        }
        $dtos = array_map(static fn (array $item): object => $dtoClass::fromArray($item), $items);
        if ($dtos !== []) {
            $this->lastQueueItemIds[$expectedDataType->value] = $response->LastQueueItemId;
        }
        return $dtos;
    }

    /**
     * @param mixed $data An object or array of objects implementing \JsonSerializable.
     */
    private function encode(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function errorResponse(ScoringProgramDataConnectorCommands $command, ErrorType $errorType, string $message): ScoringProgramResponse
    {
        $response = new ScoringProgramResponse();
        $response->RequestCommand = $command;
        $response->DataType = DataConnectorResponseData::Error;
        $response->ErrorType = $errorType;
        $response->SerializedData = json_encode($message, JSON_THROW_ON_ERROR);
        return $response;
    }
}
