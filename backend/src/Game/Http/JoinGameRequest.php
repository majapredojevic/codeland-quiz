<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game\Http;

use CodeLandQuiz\DTO\JoinGameDTO;
use CodeLandQuiz\Game\AvatarCatalog;
use CodeLandQuiz\Http\JsonRequest;
use CodeLandQuiz\Model\ParticipantType;
use CodeLandQuiz\Student\Http\StudentFieldValidator;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class JoinGameRequest
{
    private const GAME_PIN_PATTERN = '/^[0-9]{6}$/';
    private const MIN_NICKNAME_LENGTH = 2;
    private const MAX_NICKNAME_LENGTH = 30;

    public static function from(Request $request): JoinGameDTO
    {
        $body = JsonRequest::from($request);
        $data = $body->all();

        if (!array_key_exists('participantType', $data)) {
            throw new InvalidArgumentException('Participant type is required.');
        }

        if (!array_key_exists('gamePin', $data)) {
            throw new InvalidArgumentException('Game PIN is required.');
        }

        if (!array_key_exists('nickname', $data)) {
            throw new InvalidArgumentException('Participant nickname is required.');
        }

        if (!array_key_exists('avatarKey', $data)) {
            throw new InvalidArgumentException('Participant avatar is required.');
        }

        $participantType = self::participantTypeValue($data['participantType']);

        return new JoinGameDTO(
            participantType: $participantType,
            gamePin: self::gamePinValue($data['gamePin']),
            username: self::usernameValue(
                $participantType,
                $data,
            ),
            nickname: self::nicknameValue($data['nickname']),
            avatarKey: self::avatarKeyValue($data['avatarKey']),
        );
    }

    private static function participantTypeValue(mixed $value): ParticipantType
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Participant type must be a string.');
        }

        return ParticipantType::tryFrom($value)
            ?? throw new InvalidArgumentException('Participant type is invalid.');
    }

    private static function gamePinValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Game PIN must be a string.');
        }

        if (preg_match(self::GAME_PIN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Game PIN must contain exactly six digits.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function usernameValue(
        ParticipantType $participantType,
        array $data,
    ): ?string {
        $hasUsername = array_key_exists('username', $data);

        if ($participantType === ParticipantType::GUEST) {
            if ($hasUsername) {
                throw new InvalidArgumentException(
                    'Student username must not be provided for guest participants.',
                );
            }

            return null;
        }

        if (!$hasUsername) {
            throw new InvalidArgumentException(
                'Student username is required for registered participants.',
            );
        }

        return StudentFieldValidator::username($data['username']);
    }

    private static function nicknameValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Participant nickname must be a string.');
        }

        $hasControlCharacters = preg_match('/\p{C}/u', $value);

        if ($hasControlCharacters !== 0) {
            throw new InvalidArgumentException(
                'Participant nickname contains invalid characters.',
            );
        }

        $nickname = preg_replace('/\s+/u', ' ', trim($value));

        if ($nickname === null) {
            throw new InvalidArgumentException(
                'Participant nickname contains invalid characters.',
            );
        }

        $length = mb_strlen($nickname, 'UTF-8');

        if (
            $length < self::MIN_NICKNAME_LENGTH
            || $length > self::MAX_NICKNAME_LENGTH
        ) {
            throw new InvalidArgumentException(
                'Participant nickname must contain between 2 and 30 characters.',
            );
        }

        return $nickname;
    }

    private static function avatarKeyValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Participant avatar must be a string.');
        }

        $avatarKey = trim($value);

        if (!(new AvatarCatalog())->contains($avatarKey)) {
            throw new InvalidArgumentException(
                'Selected participant avatar is invalid.',
            );
        }

        return $avatarKey;
    }
}
