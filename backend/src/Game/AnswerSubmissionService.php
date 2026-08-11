<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

use CodeLandQuiz\DTO\AnswerSubmissionResultDTO;
use CodeLandQuiz\DTO\SubmitAnswerDTO;
use CodeLandQuiz\Game\Exception\AnswerAlreadySubmittedException;
use CodeLandQuiz\Game\Exception\AnswerDeadlineExpiredException;
use CodeLandQuiz\Game\Exception\AnswerQuestionClosedException;
use CodeLandQuiz\Game\Exception\AnswerSubmissionNotAllowedException;
use CodeLandQuiz\Game\Exception\InvalidSelectedOptionsException;
use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Model\QuizSessionOverview;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\Model\SessionQuestionOverview;
use CodeLandQuiz\Repository\ParticipantAnswerRepository;
use CodeLandQuiz\Repository\QuizSessionRepository;
use CodeLandQuiz\Repository\SessionParticipantRepository;
use CodeLandQuiz\Repository\SessionQuestionRepository;
use CodeLandQuiz\Support\TransactionManager;
use DateTimeImmutable;
use RuntimeException;

final readonly class AnswerSubmissionService
{
    public function __construct(
        private QuizSessionRepository $sessions,
        private SessionQuestionRepository $sessionQuestions,
        private SessionParticipantRepository $participants,
        private ParticipantAnswerRepository $answers,
        private AnswerScoreCalculator $scoreCalculator,
        private TransactionManager $transactionManager,
    ) {
    }

    public function submitAnswer(
        int $sessionId,
        int $participantId,
        SubmitAnswerDTO $dto,
    ): AnswerSubmissionResultDTO {
        return $this->transactionManager->transactional(
            function () use ($sessionId, $participantId, $dto): AnswerSubmissionResultDTO {
                $session = $this->sessions->findOverviewByIdForShare(
                    $sessionId,
                );

                $this->ensureSessionAcceptsAnswers($session);

                if ($session->currentQuestionClosedAt !== null) {
                    throw new AnswerQuestionClosedException(
                        'The current question is closed.',
                    );
                }

                $participant =
                    $this->participants->findOverviewByIdForUpdate(
                        $participantId,
                    );

                if (
                    $participant === null
                    || $participant->isRemoved
                    || $participant->sessionId !== $sessionId
                ) {
                    throw new AnswerSubmissionNotAllowedException(
                        'Answers can only be submitted while the game is active.',
                    );
                }

                $question = $this->sessionQuestions->findBySessionAndOrder(
                    sessionId: $sessionId,
                    questionOrder: $session->currentQuestionOrder,
                );

                if ($question === null) {
                    throw new RuntimeException(
                        'Active session current question was not found.',
                    );
                }

                $answeredAt = new DateTimeImmutable('now');

                if ($answeredAt > $session->currentQuestionDeadline) {
                    throw new AnswerDeadlineExpiredException(
                        'The answer deadline has expired.',
                    );
                }

                if (
                    $session->currentQuestionStartedAt
                        > $session->currentQuestionDeadline
                ) {
                    throw new RuntimeException(
                        'Active session question timing is invalid.',
                    );
                }

                if (
                    $this->answers->findByParticipantAndQuestion(
                        participantId: $participantId,
                        sessionQuestionId: $question->id,
                    ) !== null
                ) {
                    throw new AnswerAlreadySubmittedException(
                        'An answer has already been submitted for this question.',
                    );
                }

                $selectedOptionIds = $this->validateSelectedOptions(
                    question: $question,
                    selectedOptionIds: $dto->selectedOptionIds,
                );
                $isCorrect = $this->isCorrect(
                    question: $question,
                    selectedOptionIds: $selectedOptionIds,
                );
                $responseTimeMs = max(
                    0,
                    $this->milliseconds($answeredAt)
                        - $this->milliseconds(
                            $session->currentQuestionStartedAt,
                        ),
                );
                $pointsAwarded = $this->scoreCalculator->calculate(
                    isCorrect: $isCorrect,
                    maxPoints: $question->maxPoints,
                    responseTimeMs: $responseTimeMs,
                    timeLimitSeconds: $question->timeLimitSeconds,
                );

                $this->answers->create(
                    participantId: $participantId,
                    sessionQuestionId: $question->id,
                    selectedOptionIds: $selectedOptionIds,
                    isCorrect: $isCorrect,
                    responseTimeMs: $responseTimeMs,
                    pointsAwarded: $pointsAwarded,
                    answeredAt: $answeredAt,
                );

                return new AnswerSubmissionResultDTO(
                    questionOrder: $session->currentQuestionOrder,
                    responseTimeMs: $responseTimeMs,
                    answeredAt: $answeredAt,
                );
            },
        );
    }

    private function ensureSessionAcceptsAnswers(
        ?QuizSessionOverview $session,
    ): void {
        if (
            $session === null
            || $session->status !== QuizSessionStatus::ACTIVE
            || $session->currentQuestionOrder === null
            || $session->currentQuestionStartedAt === null
            || $session->currentQuestionDeadline === null
        ) {
            throw new AnswerSubmissionNotAllowedException(
                'Answers can only be submitted while the game is active.',
            );
        }
    }

    /**
     * @param int[] $selectedOptionIds
     *
     * @return int[]
     */
    private function validateSelectedOptions(
        SessionQuestionOverview $question,
        array $selectedOptionIds,
    ): array {
        if ($selectedOptionIds === []) {
            throw new InvalidSelectedOptionsException(
                'Selected options are invalid for the current question.',
            );
        }

        $optionIds = [];

        foreach ($question->options as $option) {
            $optionIds[$option->id] = true;
        }

        $uniqueSelectedOptionIds = [];

        foreach ($selectedOptionIds as $selectedOptionId) {
            if (
                !is_int($selectedOptionId)
                || $selectedOptionId < 1
                || !isset($optionIds[$selectedOptionId])
                || isset($uniqueSelectedOptionIds[$selectedOptionId])
            ) {
                throw new InvalidSelectedOptionsException(
                    'Selected options are invalid for the current question.',
                );
            }

            $uniqueSelectedOptionIds[$selectedOptionId] = true;
        }

        $this->validateSelectionCount(
            questionType: $question->questionType,
            selectedCount: count($selectedOptionIds),
            optionCount: count($question->options),
        );

        return $selectedOptionIds;
    }

    private function validateSelectionCount(
        QuestionType $questionType,
        int $selectedCount,
        int $optionCount,
    ): void {
        if (
            (
                $questionType === QuestionType::TRUE_FALSE
                || $questionType === QuestionType::SINGLE_CHOICE
            )
            && $selectedCount !== 1
        ) {
            throw new InvalidSelectedOptionsException(
                'Selected options are invalid for the current question.',
            );
        }

        if (
            $questionType === QuestionType::MULTIPLE_CHOICE
            && ($selectedCount < 1 || $selectedCount > $optionCount)
        ) {
            throw new InvalidSelectedOptionsException(
                'Selected options are invalid for the current question.',
            );
        }
    }

    /**
     * @param int[] $selectedOptionIds
     */
    private function isCorrect(
        SessionQuestionOverview $question,
        array $selectedOptionIds,
    ): bool {
        $selected = $selectedOptionIds;
        sort($selected, SORT_NUMERIC);

        $correct = [];

        foreach ($question->options as $option) {
            if ($option->isCorrect) {
                $correct[] = $option->id;
            }
        }

        sort($correct, SORT_NUMERIC);

        return $selected === $correct;
    }

    private function milliseconds(DateTimeImmutable $dateTime): int
    {
        return ((int) $dateTime->format('U') * 1000)
            + intdiv((int) $dateTime->format('u'), 1000);
    }
}
