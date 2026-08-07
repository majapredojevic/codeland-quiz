<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\ParticipantType;
use DateTimeImmutable;

final readonly class SessionReportParticipantDTO
{
    /**
     * @param SessionReportParticipantAnswerDTO[] $answers
     */
    public function __construct(
        public int $participantId,
        public ParticipantType $participantType,
        public ?int $studentId,
        public ?string $studentFirstName,
        public ?string $studentLastName,
        public ?string $studentUsername,
        public string $nickname,
        public string $avatarKey,
        public int $totalScore,
        public bool $isRemoved,
        public ?DateTimeImmutable $removedAt,
        public ?int $finalRank,
        public int $answerCount,
        public int $correctAnswerCount,
        public array $answers,
    ) {
    }
}
