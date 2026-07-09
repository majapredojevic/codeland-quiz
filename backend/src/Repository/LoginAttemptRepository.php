<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\LoginAttempt;
use DateTimeImmutable;

interface LoginAttemptRepository
{
    public function save(LoginAttempt $attempt): void;

    public function countFailedAttemptsSince(
        string $email,
        DateTimeImmutable $since,
    ): int;

    public function clearAttempts(string $email): void;
}