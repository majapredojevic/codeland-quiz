<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession;

final class SecureGamePinGenerator implements GamePinGenerator
{
    public function generate(): string
    {
        return str_pad(
            (string) random_int(0, 999999),
            6,
            '0',
            STR_PAD_LEFT,
        );
    }
}
