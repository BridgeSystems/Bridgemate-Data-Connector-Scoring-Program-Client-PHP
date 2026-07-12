<?php

declare(strict_types=1);

namespace Bridgemate\DataConnector\Tests;

use Bridgemate\DataConnector\DataConnectorClient;
use Bridgemate\DataConnector\Dto\DataConnectorResponseData;
use Bridgemate\DataConnector\Dto\ErrorType;
use Bridgemate\DataConnector\Dto\ResultDTO;
use Bridgemate\DataConnector\Dto\ScoringProgramDataConnectorCommands;
use Bridgemate\DataConnector\HttpTransport;
use Bridgemate\DataConnector\TransportException;
use PHPUnit\Framework\TestCase;

final class FakeTransport implements HttpTransport
{
    /** @var array<int, array{url: string, body: ?string}> */
    public array $calls = [];

    /** @var array<int, string|TransportException> */
    public array $responses = [];

    public function get(string $url): string
    {
        return $this->record($url, null);
    }

    public function post(string $url, string $jsonBody): string
    {
        return $this->record($url, $jsonBody);
    }

    private function record(string $url, ?string $body): string
    {
        $this->calls[] = ['url' => $url, 'body' => $body];
        $response = array_shift($this->responses) ?? new TransportException('No scripted response.');
        if ($response instanceof TransportException) {
            throw $response;
        }
        return $response;
    }
}

final class DataConnectorClientTest extends TestCase
{
    private const GUID = 'A1B2C3D4E5F60718293A4B5C6D7E8F90';

    private FakeTransport $transport;
    private DataConnectorClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new DataConnectorClient('CLUB001', 'LICENCE-KEY-123', 'http://localhost:5079', $this->transport);
    }

    public function testConnectPingsTheBaseAddress(): void
    {
        $this->transport->responses[] = 'Bridgemate dataconnector service version 1.2.3';
        $response = $this->client->connect();
        self::assertSame(DataConnectorResponseData::OK, $response->DataType);
        self::assertSame('http://localhost:5079/', $this->transport->calls[0]['url']);
        self::assertNull($this->transport->calls[0]['body']);
    }

    public function testConnectFailureReturnsNoConnection(): void
    {
        $this->transport->responses[] = new TransportException('refused');
        $response = $this->client->connect();
        self::assertSame(DataConnectorResponseData::Error, $response->DataType);
        self::assertSame(ErrorType::NoConnection, $response->ErrorType);
    }

    public function testSendResultsBuildsTheEnvelopeAndFiltersBySession(): void
    {
        $this->transport->responses[] = $this->okResponseJson(ScoringProgramDataConnectorCommands::PutResults);

        $mine = new ResultDTO();
        $mine->SessionGuid = self::GUID;
        $mine->SectionLetters = 'A';
        $other = new ResultDTO();
        $other->SessionGuid = str_repeat('F', 32);

        $response = $this->client->sendResults(self::GUID, [$mine, $other]);
        self::assertSame(DataConnectorResponseData::OK, $response->DataType);

        $envelope = json_decode((string)$this->transport->calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('http://localhost:5079/dc-scoringprogram', $this->transport->calls[0]['url']);
        self::assertSame(ScoringProgramDataConnectorCommands::PutResults->value, $envelope['Command']);
        self::assertSame('CLUB001', $envelope['ClubId']);
        self::assertSame('LICENCE-KEY-123', $envelope['LicenceKey']);
        self::assertSame(self::GUID, $envelope['SessionGuid']);
        $payload = json_decode($envelope['SerializedData'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame('A', $payload[0]['SectionLetters']);
    }

    public function testPollForResultsUsesFixtureResponseAndCachesLastQueueItemId(): void
    {
        $fixture = (string)file_get_contents(__DIR__ . '/fixtures/responses/PollQueueForNewResults.json');
        $this->transport->responses[] = $fixture;

        $results = $this->client->pollForResults(self::GUID);
        self::assertCount(2, $results);
        self::assertContainsOnlyInstancesOf(ResultDTO::class, $results);
        self::assertSame(42, $this->client->getLastQueueItemId(DataConnectorResponseData::Results));

        $envelope = json_decode((string)$this->transport->calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(ScoringProgramDataConnectorCommands::PollQueueForNewResults->value, $envelope['Command']);
        self::assertSame('', $envelope['SerializedData']);
    }

    public function testAcceptQueueDataSendsTheCachedId(): void
    {
        $this->transport->responses[] = (string)file_get_contents(__DIR__ . '/fixtures/responses/PollQueueForNewResults.json');
        $this->transport->responses[] = $this->okResponseJson(ScoringProgramDataConnectorCommands::AcceptResultQueueItems);

        $this->client->pollForResults(self::GUID);
        $response = $this->client->acceptQueueData(self::GUID, DataConnectorResponseData::Results);
        self::assertSame(DataConnectorResponseData::OK, $response->DataType);

        $envelope = json_decode((string)$this->transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(ScoringProgramDataConnectorCommands::AcceptResultQueueItems->value, $envelope['Command']);
        self::assertSame('42', $envelope['SerializedData']);
    }

    public function testAcceptQueueDataRejectsInvalidDataType(): void
    {
        $response = $this->client->acceptQueueData(self::GUID, DataConnectorResponseData::EventInfo);
        self::assertSame(ErrorType::Validation, $response->ErrorType);
        self::assertCount(0, $this->transport->calls);
    }

    public function testTransportFailuresAreRetriedFiveTimesThenReportedAsNoConnection(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->transport->responses[] = new TransportException('refused');
        }
        $response = $this->client->sendResults(self::GUID, []);
        self::assertSame(ErrorType::NoConnection, $response->ErrorType);
        self::assertCount(5, $this->transport->calls);
    }

    public function testPollReturnsEmptyArrayOnErrorResponse(): void
    {
        $this->transport->responses[] = (string)file_get_contents(__DIR__ . '/fixtures/responses/Error.json');
        self::assertSame([], $this->client->pollForResults(self::GUID));
    }

    public function testInvalidBaseAddressIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataConnectorClient('c', 'l', 'ftp://example.com');
    }

    private function okResponseJson(ScoringProgramDataConnectorCommands $command): string
    {
        return json_encode([
            'RequestCommand' => $command->value,
            'DataType' => DataConnectorResponseData::OK->value,
            'LastQueueItemId' => 0,
            'ErrorType' => ErrorType::None->value,
            'SessionGuid' => self::GUID,
            'SerializedData' => json_encode('OK'),
        ], JSON_THROW_ON_ERROR);
    }
}
