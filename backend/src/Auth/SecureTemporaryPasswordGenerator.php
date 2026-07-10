<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use RuntimeException;

final class SecureTemporaryPasswordGenerator implements TemporaryPasswordGenerator
{
    private const PASSWORD_LENGTH = 12;

    private const UPPERCASE_CHARACTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWERCASE_CHARACTERS = 'abcdefghijkmnopqrstuvwxyz';

    private const DIGIT_CHARACTERS = '23456789';

    private const SPECIAL_CHARACTERS = '!@#$%';

    private const ALL_CHARACTERS =
        self::UPPERCASE_CHARACTERS
        . self::LOWERCASE_CHARACTERS
        . self::DIGIT_CHARACTERS
        . self::SPECIAL_CHARACTERS;

    public function generate(): string
    {
        $characters = [
            $this->randomCharacter(self::UPPERCASE_CHARACTERS),
            $this->randomCharacter(self::LOWERCASE_CHARACTERS),
            $this->randomCharacter(self::DIGIT_CHARACTERS),
            $this->randomCharacter(self::SPECIAL_CHARACTERS),
        ];

        while (count($characters) < self::PASSWORD_LENGTH) {
            $characters[] = $this->randomCharacter(self::ALL_CHARACTERS);
        }

        return $this->secureShuffle($characters);
    }

    private function randomCharacter(string $characters): string
    {
        try {
            return $characters[random_int(0, strlen($characters) - 1)];
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Temporary password could not be generated.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param string[] $characters
     */
    private function secureShuffle(array $characters): string
    {
        try {
            for ($index = count($characters) - 1; $index > 0; $index--) {
                $swapIndex = random_int(0, $index);

                [$characters[$index], $characters[$swapIndex]] = [
                    $characters[$swapIndex],
                    $characters[$index],
                ];
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Temporary password could not be shuffled.',
                0,
                $exception,
            );
        }

        return implode('', $characters);
    }
}