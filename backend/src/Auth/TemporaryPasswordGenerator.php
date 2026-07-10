<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

interface TemporaryPasswordGenerator
{
    public function generate(): string;
}