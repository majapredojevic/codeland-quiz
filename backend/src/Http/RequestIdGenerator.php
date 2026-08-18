<?php

declare(strict_types=1);

namespace CodeLandQuiz\Http;

final class RequestIdGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(12));
    }
}
