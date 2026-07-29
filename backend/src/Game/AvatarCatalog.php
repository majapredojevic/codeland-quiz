<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

final class AvatarCatalog
{
    /**
     * @var string[]
     */
    private array $avatarKeys = [
        'koda-blue',
        'koda-green',
        'koda-orange',
        'koda-pink',
        'koda-purple',
        'koda-red',
        'koda-turquoise',
        'koda-yellow',
    ];

    /**
     * @return string[]
     */
    public function all(): array
    {
        return $this->avatarKeys;
    }

    public function contains(string $avatarKey): bool
    {
        return in_array($avatarKey, $this->avatarKeys, true);
    }
}
