<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession;

interface GamePinGenerator
{
    public function generate(): string;
}
