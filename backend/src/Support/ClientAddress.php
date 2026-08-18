<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

final readonly class ClientAddress
{
    public function identifier(mixed $remoteAddress): string
    {
        if (!is_string($remoteAddress)) {
            return 'unknown';
        }

        $remoteAddress = trim($remoteAddress);

        if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }

        $packedAddress = inet_pton($remoteAddress);

        return $packedAddress === false
            ? 'unknown'
            : bin2hex($packedAddress);
    }
}
