<?php

declare(strict_types=1);

namespace Bridgemate\DataConnector\Tests;

use Bridgemate\DataConnector\Dto\Bridgemate2SettingsDTO;
use Bridgemate\DataConnector\Dto\Bridgemate3SettingsDTO;
use Bridgemate\DataConnector\Dto\ContinueDTO;
use Bridgemate\DataConnector\Dto\HandrecordDTO;
use Bridgemate\DataConnector\Dto\InitDTO;
use Bridgemate\DataConnector\Dto\ParticipationDTO;
use Bridgemate\DataConnector\Dto\PlayerDataDTO;
use Bridgemate\DataConnector\Dto\ResultDTO;
use Bridgemate\DataConnector\Dto\ScoringGroupDTO;
use Bridgemate\DataConnector\Dto\ScoringProgramRequest;
use Bridgemate\DataConnector\Dto\ScoringProgramResponse;
use Bridgemate\DataConnector\Dto\SectionUpdateDTO;
use Bridgemate\DataConnector\Dto\TdCallDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The golden fixtures are the exact JSON the .NET client produces. Decoding a fixture into the
 * generated DTOs and re-encoding it must yield a structurally identical document (including the
 * nested SerializedData payload); any missing, extra or mistyped property fails this test.
 */
final class FixtureRoundTripTest extends TestCase
{
    /**
     * Maps a request fixture to the payload DTO class inside SerializedData.
     * Null means the payload is not a DTO (a bare JSON string or number, or empty).
     */
    private const REQUEST_PAYLOADS = [
        'Ping' => null,
        'InitializeEvent' => InitDTO::class,
        'ContinueEvent' => ContinueDTO::class,
        'UpdateMovement' => SectionUpdateDTO::class,
        'UpdateScoringGroups' => [ScoringGroupDTO::class],
        'PutResults' => [ResultDTO::class],
        'PutPlayerData' => [PlayerDataDTO::class],
        'PutParticipations' => [ParticipationDTO::class],
        'PutHandrecords' => [HandrecordDTO::class],
        'PutTdCalls' => [TdCallDTO::class],
        'PutBridgemate2Settings' => [Bridgemate2SettingsDTO::class],
        'PutBridgemate3Settings' => [Bridgemate3SettingsDTO::class],
    ];

    private const RESPONSE_PAYLOADS = [
        'PollQueueForNewResults' => [ResultDTO::class],
        'PollQueueForNewPlayerData' => [PlayerDataDTO::class],
        'PollQueueForNewParticipations' => [ParticipationDTO::class],
        'PollQueueForNewHandrecords' => [HandrecordDTO::class],
        'PollQueueForNewTdCalls' => [TdCallDTO::class],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function requestFixtures(): iterable
    {
        foreach (glob(__DIR__ . '/fixtures/requests/*.json') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function responseFixtures(): iterable
    {
        foreach (glob(__DIR__ . '/fixtures/responses/*.json') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('requestFixtures')]
    public function testRequestEnvelopeRoundTrips(string $path): void
    {
        $original = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $request = ScoringProgramRequest::fromArray($original);
        $reEncoded = json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertEnvelopeEquals($original, $reEncoded);
    }

    #[DataProvider('requestFixtures')]
    public function testRequestPayloadRoundTrips(string $path): void
    {
        $name = basename($path, '.json');
        $payloadType = self::REQUEST_PAYLOADS[$name] ?? null;
        if ($payloadType === null) {
            self::assertTrue(true);
            return;
        }
        $envelope = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertPayloadRoundTrips($payloadType, $envelope['SerializedData']);
    }

    #[DataProvider('responseFixtures')]
    public function testResponseEnvelopeRoundTrips(string $path): void
    {
        $original = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $response = ScoringProgramResponse::fromArray($original);
        $reEncoded = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertEnvelopeEquals($original, $reEncoded);
    }

    #[DataProvider('responseFixtures')]
    public function testResponsePayloadRoundTrips(string $path): void
    {
        $name = basename($path, '.json');
        $payloadType = self::RESPONSE_PAYLOADS[$name] ?? null;
        if ($payloadType === null) {
            self::assertTrue(true);
            return;
        }
        $envelope = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertPayloadRoundTrips($payloadType, $envelope['SerializedData']);
    }

    /**
     * @param class-string|array{class-string} $payloadType
     */
    private static function assertPayloadRoundTrips(string|array $payloadType, string $serializedData): void
    {
        $payload = json_decode($serializedData, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($payloadType)) {
            $dtoClass = $payloadType[0];
            $dtos = array_map(static fn (array $item): object => $dtoClass::fromArray($item), $payload);
            $reEncoded = json_decode(json_encode($dtos, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } else {
            $dto = $payloadType::fromArray($payload);
            $reEncoded = json_decode(json_encode($dto, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        self::assertEquals($payload, $reEncoded);
    }

    /**
     * Compares two decoded envelopes. The SerializedData strings are compared as parsed JSON
     * because the .NET serializer escapes differently (e.g. ") than PHP.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private static function assertEnvelopeEquals(array $expected, array $actual): void
    {
        $normalize = static function (array $envelope): array {
            if (isset($envelope['SerializedData']) && $envelope['SerializedData'] !== '') {
                $envelope['SerializedData'] = json_decode($envelope['SerializedData'], true, 512, JSON_THROW_ON_ERROR);
            }
            return $envelope;
        };
        self::assertEquals($normalize($expected), $normalize($actual));
    }
}
