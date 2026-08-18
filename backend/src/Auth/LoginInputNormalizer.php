<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use InvalidArgumentException;

final readonly class LoginInputNormalizer
{
    public const MAXIMUM_EMAIL_LENGTH = 180;
    public const MAXIMUM_USER_AGENT_LENGTH = 255;

    public function email(string $email): string
    {
        $email = strtolower(trim($email));

        if (
            $email === ''
            || strlen($email) > self::MAXIMUM_EMAIL_LENGTH
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                'Email ili lozinka nisu ispravni.',
            );
        }

        return $email;
    }

    public function userAgent(mixed $userAgent): ?string
    {
        if (!is_string($userAgent)) {
            return null;
        }

        $userAgent = trim($userAgent);

        if ($userAgent === '') {
            return null;
        }

        // HTTP user agents are expected to be printable ASCII. Replacing other
        // bytes keeps truncation deterministic and avoids invalid UTF-8 writes.
        $printableUserAgent = preg_replace('/[^\x20-\x7E]/', '?', $userAgent);

        if ($printableUserAgent === null) {
            return null;
        }

        return substr(
            $printableUserAgent,
            0,
            self::MAXIMUM_USER_AGENT_LENGTH,
        );
    }
}
