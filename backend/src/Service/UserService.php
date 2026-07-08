<?php

declare(strict_types=1);

namespace CodeLandQuiz\Service;

use CodeLandQuiz\DTO\ChangePasswordDTO;
use CodeLandQuiz\Repository\UserRepository;
use InvalidArgumentException;
use RuntimeException;

final class UserService
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function changePassword(ChangePasswordDTO $dto): void
    {
        $user = $this->users->findById($dto->authenticatedUserId);

        if ($user === null) {
            throw new RuntimeException('Authenticated user was not found.');
        }

        if (!$user->canUseNormalLogin()) {
            throw new RuntimeException('User is not allowed to change a normal login password.');
        }

        if (!$user->isActive()) {
            throw new RuntimeException('Inactive user cannot change password.');
        }

        if ($dto->newPassword !== $dto->newPasswordConfirmation) {
            throw new InvalidArgumentException('New password confirmation does not match.');
        }

        if (strlen($dto->newPassword) < 8) {
            throw new InvalidArgumentException('New password must be at least 8 characters long.');
        }

        if ($dto->newPassword === $dto->currentPassword) {
            throw new InvalidArgumentException('New password must be different from current password.');
        }

        if (!password_verify($dto->currentPassword, $user->getPasswordHash())) {
            throw new InvalidArgumentException('Current password is invalid.');
        }

        $newPasswordHash = password_hash($dto->newPassword, PASSWORD_DEFAULT);

        if ($newPasswordHash === false) {
            throw new RuntimeException('Password could not be hashed.');
        }

        $user->changePasswordHash($newPasswordHash);

        $this->users->save($user);
    }
}
