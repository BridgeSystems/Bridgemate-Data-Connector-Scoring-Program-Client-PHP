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
use Bridgemate\DataConnector\Dto\RoundDTO;
use Bridgemate\DataConnector\Dto\ScoringGroupDTO;
use Bridgemate\DataConnector\Dto\SectionDTO;
use Bridgemate\DataConnector\Dto\SectionUpdateDTO;
use Bridgemate\DataConnector\Dto\SessionDTO;
use Bridgemate\DataConnector\Dto\TableDTO;
use Bridgemate\DataConnector\Dto\TdCallDTO;
use Bridgemate\DataConnector\Validation\DtoValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Asserts that DtoValidator reproduces the .NET SharedDTO Validate() behaviour exactly.
 * The golden fixtures record, for each DTO payload and validation arguments, the boolean
 * result and the precise ValidationMessages (text and order) the .NET client produces.
 */
final class ValidationFixtureTest extends TestCase
{
    private const DTO_CLASSES = [
        'Bridgemate2SettingsDTO' => Bridgemate2SettingsDTO::class,
        'Bridgemate3SettingsDTO' => Bridgemate3SettingsDTO::class,
        'ContinueDTO' => ContinueDTO::class,
        'HandrecordDTO' => HandrecordDTO::class,
        'InitDTO' => InitDTO::class,
        'ParticipationDTO' => ParticipationDTO::class,
        'PlayerDataDTO' => PlayerDataDTO::class,
        'ResultDTO' => ResultDTO::class,
        'RoundDTO' => RoundDTO::class,
        'ScoringGroupDTO' => ScoringGroupDTO::class,
        'SectionDTO' => SectionDTO::class,
        'SectionUpdateDTO' => SectionUpdateDTO::class,
        'SessionDTO' => SessionDTO::class,
        'TableDTO' => TableDTO::class,
        'TdCallDTO' => TdCallDTO::class,
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function validationFixtures(): iterable
    {
        foreach (glob(__DIR__ . '/fixtures/validation/*.json') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('validationFixtures')]
    public function testValidationMatchesFixture(string $path): void
    {
        $name = basename($path);
        $fixture = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $dtoName = $fixture['Dto'];
        self::assertArrayHasKey($dtoName, self::DTO_CLASSES, "Unknown Dto '{$dtoName}' in {$name}");
        $dtoClass = self::DTO_CLASSES[$dtoName];
        $dto = $dtoClass::fromArray($fixture['Payload']);
        $args = $fixture['Args'] ?? [];

        $valid = match ($dtoName) {
            'ParticipationDTO' => DtoValidator::validateParticipationDTO($dto, $args['allowPlayerNumberAndName'] ?? false),
            'SessionDTO' => DtoValidator::validateSessionDTO($dto, $args['forAdding'] ?? false),
            'Bridgemate2SettingsDTO' => DtoValidator::validateBridgemate2SettingsDTO($dto),
            'Bridgemate3SettingsDTO' => DtoValidator::validateBridgemate3SettingsDTO($dto),
            'ContinueDTO' => DtoValidator::validateContinueDTO($dto),
            'HandrecordDTO' => DtoValidator::validateHandrecordDTO($dto),
            'InitDTO' => DtoValidator::validateInitDTO($dto),
            'PlayerDataDTO' => DtoValidator::validatePlayerDataDTO($dto),
            'ResultDTO' => DtoValidator::validateResultDTO($dto),
            'RoundDTO' => DtoValidator::validateRoundDTO($dto),
            'ScoringGroupDTO' => DtoValidator::validateScoringGroupDTO($dto),
            'SectionDTO' => DtoValidator::validateSectionDTO($dto),
            'SectionUpdateDTO' => DtoValidator::validateSectionUpdateDTO($dto),
            'TableDTO' => DtoValidator::validateTableDTO($dto),
            'TdCallDTO' => DtoValidator::validateTdCallDTO($dto),
        };

        self::assertSame($fixture['ExpectedValid'], $valid, "ExpectedValid mismatch for {$name}");
        self::assertSame($fixture['ExpectedMessages'] ?? [], $dto->ValidationMessages, "ValidationMessages mismatch for {$name}");
    }

    /**
     * The AlternativeDataFolder existence rules run against the local file system, so they
     * cannot be covered by the golden fixtures; verify them with a real temporary directory.
     */
    public function testInitDtoAlternativeDataFolderMustExist(): void
    {
        $fixture = json_decode(
            (string)file_get_contents(__DIR__ . '/fixtures/validation/InitDTO.valid.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $missingFolder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bm-dto-validator-missing-' . uniqid('', true);

        $dto = InitDTO::fromArray($fixture['Payload']);
        $dto->AlternativeDataFolder = $missingFolder;
        self::assertFalse(DtoValidator::validateInitDTO($dto));
        self::assertSame(
            ["The specified alternative data folder ('{$missingFolder}' does not exist.)"],
            $dto->ValidationMessages
        );

        $dto = InitDTO::fromArray($fixture['Payload']);
        $dto->AlternativeDataFolder = sys_get_temp_dir();
        self::assertTrue(DtoValidator::validateInitDTO($dto));
        self::assertSame([], $dto->ValidationMessages);
    }

    public function testContinueDtoAlternativeDataFolderMustExist(): void
    {
        $missingFolder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bm-dto-validator-missing-' . uniqid('', true);

        $dto = new ContinueDTO();
        $dto->EventGuid = 'C1B2C3D4E5F60718293A4B5C6D7E8F90';
        $dto->Commands = 1;
        $dto->AlternativeDataFolder = $missingFolder;
        self::assertFalse(DtoValidator::validateContinueDTO($dto));
        self::assertSame(
            ["The specified alternative data folder ('{$missingFolder}' does not exist.)"],
            $dto->ValidationMessages
        );

        $dto->AlternativeDataFolder = sys_get_temp_dir();
        self::assertTrue(DtoValidator::validateContinueDTO($dto));
        self::assertSame([], $dto->ValidationMessages);
    }
}
