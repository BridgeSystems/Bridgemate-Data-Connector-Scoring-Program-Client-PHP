<?php

declare(strict_types=1);

namespace Bridgemate\DataConnector\Validation;

use Bridgemate\DataConnector\Dto\Bridgemate2SettingsDTO;
use Bridgemate\DataConnector\Dto\Bridgemate3SettingsDTO;
use Bridgemate\DataConnector\Dto\BridgemateSettingsDTO;
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

/**
 * Hand-written port of the Validate() methods on the .NET SharedDTO classes.
 *
 * Each method fills the DTO's ValidationMessages property with exactly the same
 * messages (text and order) as the .NET client produces and returns the same
 * boolean result. The parity is asserted against the golden validation fixtures
 * in tests/fixtures/validation. Message texts are copied verbatim from the C#
 * source, including their spelling and spacing quirks - do not "fix" them.
 */
final class DtoValidator
{
    private const SECTION_LETTERS_PATTERN = '/^([A-Z])\1{0,2}$/';

    private const GUID_MESSAGE = 'The guid must be exactly 32 character long and can only contain capital A to F or digits 0 to 9.';

    /**
     * The valid ScoringType_* constants of ScoringGroupDTO.
     */
    private const VALID_SCORING_METHODS = [
        ScoringGroupDTO::ScoringType_Pairs,
        ScoringGroupDTO::ScoringType_Imp2_Weighted,
        ScoringGroupDTO::ScoringType_Imp2_10Percent,
        ScoringGroupDTO::ScoringType_Imp2_NoCorrection,
        ScoringGroupDTO::ScoringType_Imp3_Weighted,
        ScoringGroupDTO::ScoringType_Imp3_10Percent,
        ScoringGroupDTO::ScoringType_Imp3_NoCorrection,
        ScoringGroupDTO::ScoringType_XImp2_Total,
        ScoringGroupDTO::ScoringType_XImp2_Average,
        ScoringGroupDTO::ScoringType_XImp3_Total,
        ScoringGroupDTO::ScoringType_XImp3_Average,
        ScoringGroupDTO::ScoringType_TeamImps,
        ScoringGroupDTO::ScoringType_TeamVPDiscrete,
        ScoringGroupDTO::ScoringType_TeamVPContinuous,
        ScoringGroupDTO::ScoringType_Bam,
        ScoringGroupDTO::ScoringType_Patton,
    ];

    private function __construct()
    {
    }

    public static function validateParticipationDTO(ParticipationDTO $dto, bool $allowPlayerNumberAndName): bool
    {
        $messages = [];
        if ($dto->SessionGuid === null || strlen($dto->SessionGuid) !== 32) {
            $messages[] = "Invalid SessionGuid ({$dto->SessionGuid}). The value must be in capitals and be exactly 32 characters long.";
        }
        if (!self::matchesSectionLetters($dto->SectionLetters)) {
            $messages[] = "Invalid SectionLetters ({$dto->SectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
        }
        if ($dto->Direction->value < 1 || $dto->Direction->value > 4) {
            $messages[] = "Invalid Direction ({$dto->Direction->name}). The value must be between 1 and 4.";
        }
        if ($dto->TableNumber < 1) {
            $messages[] = "Invalid TableNumber ({$dto->TableNumber}). The value must be greater than zero.";
        }
        if ($dto->RoundNumber < 0) {
            $messages[] = "Invalid RoundNumber ({$dto->RoundNumber}). The value cannot be negative.";
        }
        if (self::isBlank($dto->LastName) && self::isBlank($dto->PlayerNumber)) {
            $messages[] = 'Either the LastName or the PlayerNumber must be specified.';
        }
        if (!$allowPlayerNumberAndName && !self::isBlank($dto->LastName) && !self::isBlank($dto->PlayerNumber)) {
            $messages[] = 'Either the LastName or the PlayerNumber must be specified, but not both.';
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validatePlayerDataDTO(PlayerDataDTO $dto): bool
    {
        $messages = [];
        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = self::GUID_MESSAGE;
        }
        if (self::isBlank($dto->PlayerNumber)) {
            $messages[] = 'The PlayerNumber is required.';
        }
        if (self::isBlank($dto->LastName)) {
            $messages[] = 'The LastName is required.';
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateRoundDTO(RoundDTO $dto): bool
    {
        $messages = [];
        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = self::GUID_MESSAGE;
        }
        if (!self::matchesSectionLetters($dto->SectionLetters)) {
            $messages[] = "Invalid SectionLetters ({$dto->SectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
        }
        if ($dto->TableNumber <= 0) {
            $messages[] = "TableNumber ({$dto->TableNumber}) must be greater than zero.";
        }
        if ($dto->RoundNumber <= 0) {
            $messages[] = "RoundNumber ({$dto->RoundNumber}) must be greater than zero.";
        }
        if (($dto->PairNS === 0 || $dto->PairEW === 0) && ($dto->LowBoardNumber > 0 || $dto->HighBoardNumber > 0)) {
            $messages[] = 'No boards may be specified for a sit-out round or an empty round.';
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateTableDTO(TableDTO $dto): bool
    {
        $messages = [];
        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = self::GUID_MESSAGE;
        }
        if (!self::matchesSectionLetters($dto->SectionLetters)) {
            $messages[] = "Invalid SectionLetters ({$dto->SectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
        }
        if ($dto->TableNumber <= 0) {
            $messages[] = "TableNumber ({$dto->TableNumber}) must be greater than zero.";
        }
        $rounds = $dto->Rounds ?? [];
        $roundNumbers = array_map(static fn (RoundDTO $round): int => $round->RoundNumber, $rounds);
        sort($roundNumbers);
        if ($roundNumbers !== []) {
            if (count(array_unique($roundNumbers)) !== count($rounds)) {
                $messages[] = 'The roundnumbers on a table must be unique.';
            }
            $differences = [];
            foreach ($roundNumbers as $index => $number) {
                $differences[$number - $index] = true;
            }
            if (count($differences) !== 1) {
                $messages[] = 'The roundnumbers must be consecutive.';
            }
            if (min($roundNumbers) > 1) {
                $messages[] = 'The round numbers must start with 1, but the lowest round is ' . min($roundNumbers);
            }
        }

        foreach ($rounds as $round) {
            if ($round->SessionGuid !== $dto->SessionGuid) {
                $messages[] = "Round {$round->RoundNumber} on table '{$dto->SectionLetters}{$dto->TableNumber}' must have SessionGuid '{$dto->SessionGuid}' " .
                              "but it is '{$round->SessionGuid}'";
            }
            if ($round->SectionLetters !== $dto->SectionLetters) {
                $messages[] = "Round {$round->RoundNumber} on table ' {$dto->SectionLetters} {$dto->TableNumber}' must have SectionLetters '{$dto->SectionLetters}' " .
                              "but it is '{$round->SectionLetters}'";
            }
            if ($round->TableNumber !== $dto->TableNumber) {
                $messages[] = "Round {$round->RoundNumber} on table ' {$dto->SectionLetters} {$dto->TableNumber}' must have TableNumber '{$dto->TableNumber}' " .
                              "but it is '{$round->TableNumber}'";
            }
            if (!self::validateRoundDTO($round)) {
                $errorMessage = implode('; ', $round->ValidationMessages ?? []);
                $messages[] = "Round {$round->RoundNumber} on '{$dto->SectionLetters}{$dto->TableNumber}' has validation errrors: {$errorMessage}.";
            }
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateSectionDTO(SectionDTO $dto): bool
    {
        $messages = [];
        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = self::GUID_MESSAGE;
        }
        if (!self::matchesSectionLetters($dto->Letters)) {
            $messages[] = "Invalid Letters ({$dto->Letters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
        }
        if ($dto->ScoringGroupNumber <= 0) {
            $messages[] = "ScoringGroupNumber ({$dto->ScoringGroupNumber}) must be greater than zero.";
        }
        if ($dto->MissingPair < 0 && $dto->Winners !== 2) {
            $messages[] = "MissingPair ({$dto->MissingPair}) must at least be zero for a one winner section.";
        }
        if ($dto->Winners < 1 || $dto->Winners > 2) {
            $messages[] = "Invalid Winners ({$dto->Winners}). Valid values are 1 or 2.";
        }
        if ($dto->GameType !== 10 && $dto->GameType !== 20 && $dto->GameType !== 30) {
            $messages[] = "Invalid GameType ({$dto->GameType}). Valid values are 10, 20 or 30.";
        }
        if ($dto->IsCombiSection) {
            if (!self::matchesSectionLetters($dto->NorthSouthPairSectionLetters)) {
                $messages[] = "Invalid NorthSouthPairSectionLetters ({$dto->NorthSouthPairSectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
            }
            if (!self::matchesSectionLetters($dto->EastWestPairSectionLetters)) {
                $messages[] = "Invalid EastWestPairSectionLetters ({$dto->EastWestPairSectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
            }
        }
        $tables = $dto->Tables ?? [];
        self::appendTableChecks($messages, $tables, $dto->SessionGuid, $dto->Letters, $dto->EWMoveBeforePlay);

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateSectionUpdateDTO(SectionUpdateDTO $dto): bool
    {
        $messages = [];
        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = self::GUID_MESSAGE;
        }
        if (!self::matchesSectionLetters($dto->Letters)) {
            $messages[] = "Invalid Letters ({$dto->Letters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
        }
        if ($dto->IsDeleted) {
            if ($dto->Tables !== null && $dto->Tables !== []) {
                $messages[] = 'A deleted section cannot contain tables.';
            }
            $dto->ValidationMessages = $messages;
            return $messages === [];
        }
        if ($dto->ScoringGroupNumber <= 0) {
            $messages[] = "ScoringGroupNumber ({$dto->ScoringGroupNumber}) must be greater than zero.";
        }
        if (!in_array($dto->ScoringGroupScoringMethod, self::VALID_SCORING_METHODS, true)) {
            $messages[] = "Invalid ScoringGroupScoringMethod ({$dto->ScoringGroupScoringMethod}). The value must be a multiple of 10 between 10 and 70 or 51. ";
        }
        if ($dto->MissingPair < 0 && $dto->Winners !== 2) {
            $messages[] = "MissingPair ({$dto->MissingPair}) must at least be zero for a one winner section.";
        }
        if ($dto->Winners < 1 || $dto->Winners > 2) {
            $messages[] = "Invalid Winners ({$dto->Winners}). Valid values are 1 or 2.";
        }
        if ($dto->GameType !== 10 && $dto->GameType !== 20 && $dto->GameType !== 30) {
            $messages[] = "Invalid GameType ({$dto->GameType}). Valid values are 10, 20 or 30.";
        }
        if ($dto->IsCombiSection) {
            if (!self::matchesSectionLetters($dto->NorthSouthPairSectionLetters)) {
                $messages[] = "Invalid NorthSouthPairSectionLetters ({$dto->NorthSouthPairSectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
            }
            if (!self::matchesSectionLetters($dto->EastWestPairSectionLetters)) {
                $messages[] = "Invalid EastWestPairSectionLetters ({$dto->EastWestPairSectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
            }
        }
        $tables = $dto->Tables ?? [];
        self::appendTableChecks($messages, $tables, $dto->SessionGuid, $dto->Letters, $dto->EWMoveBeforePlay);

        $participations = $dto->Participations ?? [];
        if ($dto->HasExplicitParticipations && $participations === []) {
            $messages[] = "HasExplicitParticipations is set for section '{$dto->Letters}', but the update does not carry any Participations. " .
                          'An update for such a section must include the complete seating for all rounds.';
        }
        foreach ($participations as $participation) {
            $participationText = self::participationToString($participation);
            if (!self::validateParticipationDTO($participation, allowPlayerNumberAndName: false)) {
                $errorMessage = implode('; ', $participation->ValidationMessages ?? []);
                $messages[] = "Participation '{$participationText}' has validation errors: {$errorMessage}.";
            }
            if ($participation->SessionGuid !== $dto->SessionGuid) {
                $messages[] = "Participation '{$participationText}' must have SessionGuid '{$dto->SessionGuid}' " .
                              "but it is '{$participation->SessionGuid}'.";
            }
            if ($participation->SectionLetters !== $dto->Letters) {
                $messages[] = "Participation '{$participationText}' must have SectionLetters '{$dto->Letters}' " .
                              "but it is '{$participation->SectionLetters}'.";
            }
            if ($participation->RoundNumber > 1 && !$dto->HasExplicitParticipations) {
                $messages[] = "Participation '{$participationText}' has RoundNumber {$participation->RoundNumber}, " .
                              "but section '{$dto->Letters}' does not have HasExplicitParticipations set. " .
                              'Round numbers greater than one are only valid for sections with explicit participations.';
            }
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateScoringGroupDTO(ScoringGroupDTO $dto): bool
    {
        $messages = [];
        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = self::GUID_MESSAGE;
        }
        if ($dto->ScoringGroupNumber <= 0) {
            $messages[] = "ScoringGroupNumber ({$dto->ScoringGroupNumber}) must be greater than zero.";
        }
        if (!in_array($dto->ScoringMethod, self::VALID_SCORING_METHODS, true)) {
            $messages[] = "Invalid ScoringMethod ({$dto->ScoringMethod}). The value must be a multiple of 10 between 10 and 70 or 51. ";
        }
        if ($dto->IsDeleted) {
            if ($dto->Sections !== null && $dto->Sections !== []) {
                $messages[] = 'A scoringgroup marked for deletion must not have any sections defined.';
            }
        } elseif ($dto->Sections === null || $dto->Sections === []) {
            $messages[] = 'The scoringgroup must have at least one section.';
        } else {
            $letters = array_map(static fn (SectionDTO $section): ?string => $section->Letters, $dto->Sections);
            if (count(self::distinct($letters)) !== count($dto->Sections)) {
                $messages[] = 'The sections cannot have the same Letters';
            }

            foreach ($dto->Sections as $section) {
                if ($section->SessionGuid !== $dto->SessionGuid) {
                    $messages[] = "Section '{$section->Letters}' must have SessionGuid '{$dto->SessionGuid}' " .
                                  "but it is '{$section->SessionGuid}'";
                }
                if ($section->ScoringGroupNumber !== $dto->ScoringGroupNumber) {
                    $messages[] = "Section '{$section->Letters}' must have ScoringGroupNumber '{$dto->ScoringGroupNumber}' " .
                                  "but it is '{$section->ScoringGroupNumber}'";
                }
                if (!self::validateSectionDTO($section)) {
                    $errorMessage = implode('; ', $section->ValidationMessages ?? []);
                    $messages[] = "Section '{$section->Letters}' has validation errrors: {$errorMessage}.";
                }
            }
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateSessionDTO(SessionDTO $dto, bool $forAdding): bool
    {
        $messages = [];

        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = self::GUID_MESSAGE;
        }

        if ($forAdding) {
            if ($dto->EventGuid === null || $dto->EventGuid === '') {
                $messages[] = 'When adding a session the EventGuid must not be empty.';
            }
        }

        if ($dto->EventGuid !== null && !self::isStrictGuid($dto->EventGuid)) {
            $messages[] = 'The event guid, if used, must be exactly 32 character long and can only contain capital A to F or digits 0 to 9.';
        }
        if ($dto->ScoringGroups === null || $dto->ScoringGroups === []) {
            $messages[] = 'At least one scoringroup is required.';
            $dto->ValidationMessages = $messages;
            return false;
        }

        $scoringGroupNumbers = array_map(
            static fn (ScoringGroupDTO $group): int => $group->ScoringGroupNumber,
            $dto->ScoringGroups
        );
        if (count(self::distinct($scoringGroupNumbers)) !== count($dto->ScoringGroups)) {
            $messages[] = 'The scoring groups cannot have the same ScoringGroupNumber';
        }

        foreach ($dto->ScoringGroups as $group) {
            if ($group->SessionGuid !== $dto->SessionGuid) {
                $messages[] = "Scoring group with id {$group->ScoringGroupNumber} must have SessionGuid '{$dto->SessionGuid}' " .
                              "but it is '{$group->SessionGuid}'";
            }
            if (!self::validateScoringGroupDTO($group)) {
                $errorMessage = implode('; ', $group->ValidationMessages ?? []);
                $messages[] = "Scoringgroup {$group->ScoringGroupNumber} has validation errrors: {$errorMessage}.";
            }
        }

        /** @var SectionDTO[] $sections */
        $sections = [];
        foreach ($dto->ScoringGroups as $group) {
            foreach ($group->Sections ?? [] as $section) {
                $sections[] = $section;
            }
        }
        foreach ($sections as $section) {
            if (!$section->IsCombiSection) {
                continue;
            }
            $sources = [$section->NorthSouthPairSectionLetters, $section->EastWestPairSectionLetters];
            if (count(self::distinct($sources)) !== 2) {
                $messages[] = "The combisection '{$section->Letters}' must have two different sections as its source, " .
                              "but they are for NS '{$sources[0]}' and for EW '{$sources[1]}'";
            }
            foreach ($sources as $sourceSection) {
                $sourceExists = false;
                foreach ($sections as $candidate) {
                    if ($candidate->Letters === $sourceSection) {
                        $sourceExists = true;
                        break;
                    }
                }
                if (!$sourceExists) {
                    $messages[] = "Combisection '{$section->Letters}' specifies section '{$sourceSection}' as one of its source sections, " .
                                  'but this section does not exist.';
                }
            }
        }

        if ($dto->Name === null || strlen($dto->Name) < 1) {
            $messages[] = 'The name of the session is required.';
        }

        if ($dto->Year < 2000) {
            $messages[] = "The year ({$dto->Year}) for the session must be at least 2000.";
        }

        if ($dto->Month < 1 || $dto->Month > 12) {
            $messages[] = "The month ({$dto->Month}) for the session must be between 1 and 12.";
        }

        if ($dto->Day < 1 || $dto->Day > 31) {
            $messages[] = "The day ({$dto->Day}) for the session must be between 1 and 31.";
        }
        if ($dto->Hour < 0 || $dto->Hour >= 24) {
            $messages[] = "The hour ({$dto->Hour}) of the day must be between 0 and 23";
        }
        if ($dto->Minute < 0 || $dto->Minute >= 60) {
            $messages[] = "The minute ({$dto->Minute})must be between 0 and 59";
        }
        // The C# code tries new DateTime(Year, Month, Day), which accepts years 1-9999.
        if ($dto->Year > 9999 || !checkdate($dto->Month, $dto->Day, $dto->Year)) {
            $messages[] = "The date {$dto->Year}-{$dto->Month}-{$dto->Day} is invalid.";
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateInitDTO(InitDTO $dto): bool
    {
        $messages = [];

        if ($dto->Commands < 0 || $dto->Commands > 255) {
            $messages[] = "The Commands ({$dto->Commands}) must be between 0 and 255.";
        }
        if (!self::isBlank($dto->AlternativeDataFolder)) {
            if (!is_dir((string)$dto->AlternativeDataFolder)) {
                $messages[] = "The specified alternative data folder ('{$dto->AlternativeDataFolder}' does not exist.)";
            }
        }
        if ($dto->Sessions === null || $dto->Sessions === []) {
            $messages[] = 'At least one session is required.';
            $dto->ValidationMessages = $messages;
            return false;
        }

        if (count($dto->Sessions) > 1) {
            if (self::isBlank($dto->EventGuid)) {
                $messages[] = 'If there is more than one session the EventGuid must be specified and it must be identical ' .
                              "to the sessions' EventGuid.";
            } else {
                foreach ($dto->Sessions as $session) {
                    if ($session->EventGuid !== $dto->EventGuid) {
                        $messages[] = "The EventGuid ('{$session->EventGuid}') of session " .
                                      "'{$session->Name}' ({$session->SessionGuid}) are not the same.";
                    }
                }
            }
        }
        if ($dto->EventGuid !== null && !self::isStrictGuid($dto->EventGuid)) {
            $messages[] = 'The event guid, if used, must be exactly 32 character long and can only contain capital A to F or digits 0 to 9.';
        }

        foreach ($dto->Sessions as $session) {
            if (!self::validateSessionDTO($session, forAdding: false)) {
                $joined = implode(', ', $session->ValidationMessages ?? []);
                $messages[] = "Session {$session->SessionGuid} did not validate: {$joined}";
            }
        }

        $allSectionLetters = [];
        $allScoringGroupNumbers = [];
        foreach ($dto->Sessions as $session) {
            foreach ($session->ScoringGroups ?? [] as $scoringGroup) {
                $allScoringGroupNumbers[] = $scoringGroup->ScoringGroupNumber;
                foreach ($scoringGroup->Sections ?? [] as $section) {
                    $allSectionLetters[] = $section->Letters;
                }
            }
        }
        foreach (self::groupCounts($allSectionLetters) as [$letters, $count]) {
            if ($count > 1) {
                $messages[] = "Section '{$letters}' appears {$count} times. Each section letter must be unique.";
            }
        }
        foreach (self::groupCounts($allScoringGroupNumbers) as [$number, $count]) {
            if ($count > 1) {
                $messages[] = "Scoringgroup '{$number}' appears {$count} times. " .
                              'Each scoringgroup number must be unique.';
            }
        }

        if ($dto->PlayerData !== null && $dto->PlayerData !== []) {
            $sessionGuids = array_map(static fn (SessionDTO $session): ?string => $session->SessionGuid, $dto->Sessions);
            foreach ($dto->PlayerData as $data) {
                if (!self::validatePlayerDataDTO($data)) {
                    $errorMessage = implode(', ', $data->ValidationMessages ?? []);
                    $messages[] = "PlayerData '{$data->FirstName} {$data->LastName} ({$data->SessionGuid}-{$data->PlayerNumber})': " .
                                  "{$errorMessage} ";
                }
                if (!in_array($data->SessionGuid, $sessionGuids, true)) {
                    $joinedGuids = implode(', ', $sessionGuids);
                    $messages[] = "PlayerData.SessionGuid ('{$data->SessionGuid}') " .
                                  "must be one of the sessions' guids ({$joinedGuids}).";
                }
            }
            foreach (self::groupBy($dto->PlayerData, static fn (PlayerDataDTO $data): string => $data->PlayerNumber ?? '') as $group) {
                if (count($group['items']) > 1) {
                    $count = count($group['items']);
                    $first = $group['items'][0];
                    $messages[] = "Duplicate ({$count}) entries for player data " .
                                  "'{$first->FirstName}+{$first->LastName}'";
                }
            }
        }
        if ($dto->Participations !== null && $dto->Participations !== []) {
            if ($dto->PlayerData === null) {
                $participationCount = count($dto->Participations);
                $messages[] = 'No PlayerDataDTO defined, but there are ' .
                              "{$participationCount} ParticipationDTOs defined. " .
                              'Each ParticipationDTO with its SessionGuid and PlayerNumber properties set ' .
                              'must have a corresponding PlayerDataDTO that specifies at least its name.';
            }
            foreach ($dto->Participations as $participation) {
                if (!self::validateParticipationDTO($participation, allowPlayerNumberAndName: false)) {
                    $errorMessage = implode(', ', $participation->ValidationMessages ?? []);
                    $messages[] = "ParticipationDTO  '{$participation->SessionGuid}-{$participation->PlayerNumber}': " .
                                  "{$errorMessage} ";
                }
            }
            foreach ($dto->Participations as $participation) {
                $id = ($participation->SessionGuid ?? '') . ($participation->PlayerNumber ?? '');
                if ($id === '' || $id === ($participation->SessionGuid ?? '')) {
                    continue;
                }
                $hasPlayerData = false;
                foreach ($dto->PlayerData ?? [] as $data) {
                    if ((($data->SessionGuid ?? '') . ($data->PlayerNumber ?? '')) === $id) {
                        $hasPlayerData = true;
                        break;
                    }
                }
                if ($hasPlayerData) {
                    continue;
                }
                $messages[] = "ParticipationDTO '{$participation->SessionGuid}-{$participation->PlayerNumber}' " .
                              'has no corresponding PlayerDataDTO';
            }
            $explicitSectionKeys = [];
            foreach ($dto->Sessions as $session) {
                foreach ($session->ScoringGroups ?? [] as $scoringGroup) {
                    foreach ($scoringGroup->Sections ?? [] as $section) {
                        if ($section->HasExplicitParticipations) {
                            $explicitSectionKeys[($section->SessionGuid ?? '') . '-' . ($section->Letters ?? '')] = true;
                        }
                    }
                }
            }
            foreach ($dto->Participations as $participation) {
                if ($participation->RoundNumber <= 1) {
                    continue;
                }
                $key = ($participation->SessionGuid ?? '') . '-' . ($participation->SectionLetters ?? '');
                if (!isset($explicitSectionKeys[$key])) {
                    $participationText = self::participationToString($participation);
                    $messages[] = "ParticipationDTO '{$participationText}' has RoundNumber {$participation->RoundNumber}, " .
                                  "but section '{$participation->SectionLetters}' does not have HasExplicitParticipations set. " .
                                  'Round numbers greater than one are only valid for sections with explicit participations.';
                }
            }
        }
        if ($dto->Handrecords !== null && $dto->Handrecords !== []) {
            foreach ($dto->Handrecords as $handrecord) {
                if (!self::validateHandrecordDTO($handrecord)) {
                    $errorMessage = implode(', ', $handrecord->ValidationMessages ?? []);
                    $messages[] = "HandrecordDTO  '{$handrecord->SectionLetters}-{$handrecord->BoardNumber}': " .
                                  "{$errorMessage} ";
                }
            }
        }
        if ($dto->Bridgemate2Settings !== null && $dto->Bridgemate2Settings !== []) {
            $settingsErrors = false;
            foreach ($dto->Bridgemate2Settings as $settings) {
                if (!self::validateBridgemate2SettingsDTO($settings)) {
                    $settingsErrors = true;
                    $errorMessage = implode(', ', $settings->ValidationMessages ?? []);
                    $messages[] = "Bridgemate2SettingsDTO  '{$settings->SectionLetters}': " .
                                  "{$errorMessage} ";
                }
            }
            if (!$settingsErrors) {
                $sectionLetters = array_map(
                    static fn (Bridgemate2SettingsDTO $settings): ?string => $settings->SectionLetters,
                    $dto->Bridgemate2Settings
                );
                foreach (self::groupCounts($sectionLetters) as [$letters, $count]) {
                    if ($count > 1) {
                        $messages[] = "Duplicate ({$count}) settings for section '{$letters}'";
                    }
                }
            }
        }
        if ($dto->Bridgemate3Settings !== null && $dto->Bridgemate3Settings !== []) {
            foreach ($dto->Bridgemate3Settings as $settings) {
                if (!self::validateBridgemate3SettingsDTO($settings)) {
                    $errorMessage = implode(', ', $settings->ValidationMessages ?? []);
                    $messages[] = "Bridgemate3SettingsDTO  '{$settings->SectionLetters}': " .
                                  "{$errorMessage} ";
                }
            }
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateHandrecordDTO(HandrecordDTO $dto): bool
    {
        $messages = [];
        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = "The guid ({$dto->SessionGuid}) must be exactly 32 character long and can only contain capital A to F or digits 0 to 9.";
        }
        if ($dto->ScoringGroupNumber <= 0) {
            $messages[] = "ScoringGroupNumber ({$dto->ScoringGroupNumber}) must be greater than zero.";
        }
        if (!self::matchesSectionLetters($dto->SectionLetters)) {
            $messages[] = "Invalid SectionLetters ({$dto->SectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
        }
        if ($dto->BoardNumber <= 0) {
            $messages[] = "BoardNumber ({$dto->BoardNumber}) must be greater than zero.";
        }

        // "AKQJT98765432" sorted by character ordinal, as in the C# code.
        $correctCards = '23456789AJKQT';

        $allSuits = [
            'NorthClubs' => $dto->NorthClubs, 'NorthDiamonds' => $dto->NorthDiamonds, 'NorthHearts' => $dto->NorthHearts, 'NorthSpades' => $dto->NorthSpades,
            'EastClubs' => $dto->EastClubs, 'EastDiamonds' => $dto->EastDiamonds, 'EastHearts' => $dto->EastHearts, 'EastSpades' => $dto->EastSpades,
            'SouthClubs' => $dto->SouthClubs, 'SouthDiamonds' => $dto->SouthDiamonds, 'SouthHearts' => $dto->SouthHearts, 'SouthSpades' => $dto->SouthSpades,
            'WestClubs' => $dto->WestClubs, 'WestDiamonds' => $dto->WestDiamonds, 'WestHearts' => $dto->WestHearts, 'WestSpades' => $dto->WestSpades,
        ];

        $invalidSuits = [];
        foreach ($allSuits as $name => $cards) {
            if ($cards === null || preg_match('/^[2-9AJKQT]*$/', $cards) !== 1) {
                $invalidSuits[] = $name;
            }
        }

        if ($invalidSuits !== []) {
            $errorMessage = implode(', ', $invalidSuits);
            $messages[] = "Invalid suits (null value or invalid card) in {$errorMessage}. Valid cards are '{$correctCards}'";
        } else {
            $suitGroups = [
                ['clubs', $dto->NorthClubs . $dto->EastClubs . $dto->SouthClubs . $dto->WestClubs],
                ['Diamonds', $dto->NorthDiamonds . $dto->EastDiamonds . $dto->SouthDiamonds . $dto->WestDiamonds],
                ['Hearts', $dto->NorthHearts . $dto->EastHearts . $dto->SouthHearts . $dto->WestHearts],
                ['Spades', $dto->NorthSpades . $dto->EastSpades . $dto->SouthSpades . $dto->WestSpades],
            ];
            foreach ($suitGroups as [$suitName, $cards]) {
                $count = strlen($cards);
                if ($count !== 13) {
                    $messages[] = "Invalid number of {$suitName} ({$count}). The suit must add up to 13 cards.";
                } else {
                    $sorted = self::sortCharacters($cards);
                    if ($sorted !== $correctCards) {
                        $messages[] = "Duplicate cards in the {$suitName} suit '{$sorted}'";
                    }
                }
            }

            $hands = [
                ['North', $dto->NorthClubs . $dto->NorthDiamonds . $dto->NorthHearts . $dto->NorthSpades],
                ['East', $dto->EastClubs . $dto->EastDiamonds . $dto->EastHearts . $dto->EastSpades],
                ['South', $dto->SouthClubs . $dto->SouthDiamonds . $dto->SouthHearts . $dto->SouthSpades],
                ['West', $dto->WestClubs . $dto->WestDiamonds . $dto->WestHearts . $dto->WestSpades],
            ];
            foreach ($hands as [$handName, $cards]) {
                $count = strlen($cards);
                if ($count !== 13) {
                    $messages[] = "Invalid number of cards for {$handName} ({$count}). The hand must add up to 13 cards.";
                }
            }
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateResultDTO(ResultDTO $dto): bool
    {
        $messages = [];
        if ($dto->SessionGuid === null || $dto->SessionGuid === '' || strlen($dto->SessionGuid) !== 32) {
            $messages[] = "Invalid SessionGuid ({$dto->SessionGuid}). The value must be in capitals and be exactly";
        }
        if (!self::matchesSectionLetters($dto->SectionLetters)) {
            $messages[] = "Invalid SectionLetters ({$dto->SectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
        }
        if ($dto->TableNumber < 1) {
            $messages[] = "Invalid TableNumber ({$dto->TableNumber}). The value must be greater than zero.";
        }
        if ($dto->RoundNumber < 1) {
            $messages[] = "Invalid RoundNumber ({$dto->RoundNumber}). The value must be greater than zero.";
        }
        if ($dto->BoardNumber < 1) {
            $messages[] = "Invalid BoardNumber ({$dto->BoardNumber}). The value must be greater than zero.";
        }
        if ($dto->IsDeleted) {
            $dto->ValidationMessages = $messages;
            return $messages === [];
        }
        if ($dto->PairEastWest < 1) {
            $messages[] = "Invalid PairEastWest ({$dto->PairEastWest}). The value must be greater than zero.";
        }
        if ($dto->PairNorthSouth < 1) {
            $messages[] = "Invalid PairNorthSouth ({$dto->PairNorthSouth}). The value must be greater than zero.";
        }
        if ($dto->DeclaringPair !== $dto->PairNorthSouth && $dto->DeclaringPair !== $dto->PairEastWest) {
            $messages[] = "Invalid DeclaringPair ({$dto->DeclaringPair}). The value must be either {$dto->PairNorthSouth} or {$dto->PairEastWest}.";
        }
        if ($dto->Level >= 1 && ($dto->DeclarerDirection < 1 || $dto->DeclarerDirection > 4)) {
            $messages[] = "Invalid DeclarerDirection ({$dto->DeclarerDirection}). The value must be between 1 and 4.";
        }
        if ($dto->Level >= 1 && ($dto->ScoringDirection < 1 || $dto->ScoringDirection > 3)) {
            $messages[] = "Invalid ScoringDirection ({$dto->ScoringDirection}). The value must be between 1 and 3.";
        }
        if ($dto->Level < -10 || $dto->Level > 7) {
            $messages[] = "Invalid Level ({$dto->Level}). The value must be between -10 and +7.";
        }
        if (($dto->Denomination < 1 || $dto->Denomination > 5) && $dto->Level >= 1) {
            $messages[] = "Invalid Denomination ({$dto->Denomination}). The value must be between 1 and 5.";
        }
        if ($dto->Stake < 0 || $dto->Stake > 2) {
            $messages[] = "Invalid Stake ({$dto->Stake}). The value must be between 0 and 2.";
        }
        if ($dto->TotalTricks < 0 || $dto->TotalTricks > 13) {
            $messages[] = "Invalid TotalTricks ({$dto->TotalTricks}). The value must be between 0 and 13.";
        }
        if (($dto->LeadCardRank < 2 || $dto->LeadCardRank > 14) && $dto->LeadCardRank !== 0) {
            $messages[] = "Invalid LeadCardRank ({$dto->LeadCardRank}). The value must be between 2 and 14 (Ace).";
        } elseif (($dto->LeadCardSuit < 1 || $dto->LeadCardSuit > 4) && $dto->LeadCardRank !== 0) {
            $messages[] = "Invalid LeadCardSuit ({$dto->LeadCardSuit}). If LeadCardRank>0  the value must be between 1 and 4.";
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateTdCallDTO(TdCallDTO $dto): bool
    {
        $messages = [];

        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = self::GUID_MESSAGE;
        }
        if (!self::matchesSectionLetters($dto->SectionLetters)) {
            $messages[] = "Invalid SectionLetters ({$dto->SectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
        }
        if ($dto->TableNumber <= 0) {
            $messages[] = "TableNumber ({$dto->TableNumber}) must be greater than zero.";
        }
        if ($dto->RoundNumber <= 0) {
            $messages[] = "RoundNumber ({$dto->RoundNumber}) must be greater than zero.";
        }
        if ($dto->Status <= 0 || $dto->Status > 4) {
            $messages[] = "Invalide Status:({$dto->Status}). Valid values are 1,2,3 or 4.";
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateContinueDTO(ContinueDTO $dto): bool
    {
        $messages = [];
        $mask = 255 & ~InitDTO::StartBCS & ~InitDTO::Command_StartReading & ~InitDTO::Command_ClearData
                    & ~InitDTO::Command_Minimize & ~InitDTO::Command_AutoShutDownBPC & ~InitDTO::Command_LogLevel_Debug;
        if (($dto->Commands & $mask) !== 0) {
            $messages[] = "Invalid value for Commands ({$dto->Commands}). " .
                          'Valid values are a sum of 0 and/or 1 and/or 4 and/or 128.';
        }
        if (!self::isBlank($dto->AlternativeDataFolder)) {
            if (!is_dir((string)$dto->AlternativeDataFolder)) {
                $messages[] = "The specified alternative data folder ('{$dto->AlternativeDataFolder}' does not exist.)";
            }
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateBridgemate2SettingsDTO(Bridgemate2SettingsDTO $dto): bool
    {
        $messages = self::bridgemateSettingsBaseMessages($dto);
        if (!self::isValidPinCode($dto->BM2PINcode)) {
            $messages[] = "Invalid BM2PINcode ('{$dto->BM2PINcode}'). The pincode must be four digits.";
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    public static function validateBridgemate3SettingsDTO(Bridgemate3SettingsDTO $dto): bool
    {
        $messages = self::bridgemateSettingsBaseMessages($dto);
        if ($dto->BM3ScreenDimMode < 0 || $dto->BM3ScreenDimMode > 15) {
            $messages[] = "Invalid BM3ScreenDimMode ({$dto->BM3ScreenDimMode}). Value must be between 0 and 15";
        }
        if ($dto->BM3ScreenBrightness < 1 || $dto->BM3ScreenBrightness > 7) {
            $messages[] = "Invalid BM3ScreenBrightness ({$dto->BM3ScreenBrightness}). Value must be between 1 and 7";
        }
        if ($dto->BM3SleepMode < 0 || $dto->BM3SleepMode > 120) {
            $messages[] = "Invalid BM3SleepMode ({$dto->BM3SleepMode}). Value must be between 0 and 120";
        }
        if ($dto->BM3AudioVolume < 0 || $dto->BM3AudioVolume > 7) {
            $messages[] = "Invalid BM3AudioVolume ({$dto->BM3AudioVolume}). Value must be between 0 and 7";
        }
        if (!self::isValidPinCode($dto->BM3PINcode)) {
            $messages[] = "Invalid BM3PINcode ('{$dto->BM3PINcode}'). The pincode must be four digits.";
        }

        $dto->ValidationMessages = $messages;
        return $messages === [];
    }

    /**
     * The shared checks of the abstract BridgemateSettingsDTO base class.
     *
     * @return string[]
     */
    private static function bridgemateSettingsBaseMessages(BridgemateSettingsDTO $dto): array
    {
        $messages = [];
        if (!self::isStrictGuid($dto->SessionGuid)) {
            $messages[] = self::GUID_MESSAGE;
        }
        if (!self::matchesSectionLetters($dto->SectionLetters)) {
            $messages[] = "Invalid SectionLetters ({$dto->SectionLetters}). Valid values are: 'A-Z', 'AA-ZZ' or 'AAA','ZZZ'";
        }
        return $messages;
    }

    /**
     * The table checks shared verbatim between SectionDTO.Validate and SectionUpdateDTO.Validate:
     * EWMoveBeforePlay bound, per-table integrity plus cascade, duplicate table numbers.
     *
     * @param string[] $messages
     * @param TableDTO[] $tables
     */
    private static function appendTableChecks(array &$messages, array $tables, ?string $sessionGuid, ?string $letters, int $ewMoveBeforePlay): void
    {
        $tableCount = count($tables);
        if (abs($ewMoveBeforePlay) > $tableCount) {
            $messages[] = "The absolute value of EWMoveBeforePlay ({$ewMoveBeforePlay}) " .
                          "cannot be higher than the number of tables ({$tableCount}).";
        }

        foreach ($tables as $table) {
            if ($table->SessionGuid !== $sessionGuid) {
                $messages[] = "Table '{$letters}{$table->TableNumber}' must have SessionGuid '{$sessionGuid}' " .
                              "but it is '{$table->SessionGuid}'";
            }
            if ($table->SectionLetters !== $letters) {
                $messages[] = "Table ' {$letters} {$table->TableNumber}' must have SectionLetters '{$letters}' " .
                              "but it is '{$table->SectionLetters}'";
            }
            if (!self::validateTableDTO($table)) {
                $errorMessage = implode('; ', $table->ValidationMessages ?? []);
                $messages[] = "Table '{$table->SectionLetters}{$table->TableNumber}' has validation errrors: {$errorMessage}.";
            }
        }
        $tableNumbers = array_map(static fn (TableDTO $table): int => $table->TableNumber, $tables);
        sort($tableNumbers);
        foreach (self::groupCounts($tableNumbers) as [$number, $count]) {
            if ($count > 1) {
                $messages[] = "Tablenumber {$number} occurs {$count} times. ";
            }
        }
    }

    /**
     * Reproduces ParticipationDTO.ToString(), which the C# validators interpolate into messages.
     * C# interpolates null strings as empty strings.
     */
    private static function participationToString(ParticipationDTO $dto): string
    {
        $swap = $dto->IsPlayerSwap ? 'SWAP ' : '';
        return "{$swap}{$dto->SectionLetters}{$dto->TableNumber} {$dto->Direction->name} round {$dto->RoundNumber}: " .
               ($dto->PlayerNumber ?? '') . ' ' . ($dto->FirstName ?? '') . ' ' . ($dto->LastName ?? '');
    }

    /**
     * True when the value is exactly 32 characters, all capital A-F or digits 0-9.
     */
    private static function isStrictGuid(?string $guid): bool
    {
        return $guid !== null && preg_match('/^[A-F0-9]{32}$/', $guid) === 1;
    }

    /**
     * C# string.IsNullOrWhiteSpace.
     */
    private static function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    private static function matchesSectionLetters(?string $letters): bool
    {
        return preg_match(self::SECTION_LETTERS_PATTERN, $letters ?? '') === 1;
    }

    /**
     * C# int.TryParse semantics for the four-character PIN code: leading/trailing whitespace
     * is tolerated and an optional leading sign is accepted ("+123" is a valid PIN).
     */
    private static function isValidPinCode(?string $pinCode): bool
    {
        return !self::isBlank($pinCode)
            && strlen((string)$pinCode) === 4
            && preg_match('/^[+-]?\d+$/', trim((string)$pinCode)) === 1;
    }

    /**
     * Sorts the characters of a single-byte string by ordinal, as LINQ OrderBy over a C# string.
     */
    private static function sortCharacters(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $characters = str_split($value);
        sort($characters);
        return implode('', $characters);
    }

    /**
     * The distinct values of an array using strict comparison, like LINQ Distinct().
     *
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    private static function distinct(array $values): array
    {
        $distinct = [];
        foreach ($values as $value) {
            if (!in_array($value, $distinct, true)) {
                $distinct[] = $value;
            }
        }
        return $distinct;
    }

    /**
     * Groups scalar values and counts them, preserving first-encounter order like LINQ GroupBy.
     *
     * @param array<int, mixed> $values
     * @return array<int, array{0: mixed, 1: int}> [value, count] pairs
     */
    private static function groupCounts(array $values): array
    {
        $keys = [];
        $counts = [];
        foreach ($values as $value) {
            $index = array_search($value, $keys, true);
            if ($index === false) {
                $keys[] = $value;
                $counts[] = 1;
            } else {
                $counts[$index]++;
            }
        }
        $groups = [];
        foreach ($keys as $index => $key) {
            $groups[] = [$key, $counts[$index]];
        }
        return $groups;
    }

    /**
     * Groups items by a scalar key, preserving first-encounter order like LINQ GroupBy.
     *
     * @template T
     * @param array<int, T> $items
     * @param callable(T): mixed $keySelector
     * @return array<int, array{key: mixed, items: array<int, T>}>
     */
    private static function groupBy(array $items, callable $keySelector): array
    {
        $keys = [];
        $groups = [];
        foreach ($items as $item) {
            $key = $keySelector($item);
            $index = array_search($key, $keys, true);
            if ($index === false) {
                $keys[] = $key;
                $groups[] = ['key' => $key, 'items' => [$item]];
            } else {
                $groups[$index]['items'][] = $item;
            }
        }
        return $groups;
    }
}
