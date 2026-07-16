<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Auth\Exception\AuthenticatedUserUnavailableException;
use CodeLandQuiz\DTO\ChangePasswordDTO;
use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Repository\RefreshTokenRepository;
use CodeLandQuiz\Repository\UserRepository;
use CodeLandQuiz\Support\TransactionManager;
use InvalidArgumentException;

final readonly class UserService
{
    private const AUDIT_ENTITY_TYPE = 'USER';

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private RefreshTokenRepository $refreshTokens,
        private AuditLogService $auditLogService,
        private TransactionManager $transactionManager,
    ) {
    }

    public function changePassword(
        int $authenticatedUserId,
        ChangePasswordDTO $dto,
    ): void
    {
        $this->validateNewPassword($dto);

        $newPasswordHash = $this->passwordHasher->hash(
            $dto->newPassword,
        );

        $this->transactionManager->transactional(function () use (
            $authenticatedUserId,
            $dto,
            $newPasswordHash,
        ): void {
            $user = $this->users->findById($authenticatedUserId);

            if ($user === null) {
                throw new AuthenticatedUserUnavailableException(
                    'Authenticated user was not found.',
                );
            }

            if (!$user->canUseNormalLogin()) {
                throw new AuthenticatedUserUnavailableException(
                    'User is not allowed to change a normal login password.',
                );
            }

            if (!$user->isActive()) {
                throw new AuthenticatedUserUnavailableException(
                    'Inactive user cannot change password.',
                );
            }

            if (!$this->passwordHasher->verify(
                $dto->currentPassword,
                $user->getPasswordHash(),
            )) {
                throw new InvalidArgumentException(
                    'Current password is invalid.',
                );
            }

            $user->changePasswordHash($newPasswordHash);
            $user->passwordChanged();

            $this->users->save($user);

            $revokedRefreshTokens = $this->refreshTokens->revokeAllForUser(
                $user->getId(),
            );

            $this->auditLogService->log(
                action: AuditAction::PASSWORD_CHANGED,
                userId: $user->getId(),
                entityType: self::AUDIT_ENTITY_TYPE,
                entityId: $user->getId(),
                metadata: [
                    'mustChangePassword' => false,
                    'revokedRefreshTokens' => $revokedRefreshTokens,
                ],
            );
        });
    }

    private function validateNewPassword(ChangePasswordDTO $dto): void
    {
        if ($dto->newPassword !== $dto->newPasswordConfirmation) {
            throw new InvalidArgumentException(
                'New password confirmation does not match.',
            );
        }

        if (strlen($dto->newPassword) < 8) {
            throw new InvalidArgumentException(
                'New password must be at least 8 characters long.',
            );
        }

        if (preg_match('/[A-Z]/', $dto->newPassword) !== 1) {
            throw new InvalidArgumentException(
                'New password must contain at least one uppercase letter.',
            );
        }

        if (preg_match('/[a-z]/', $dto->newPassword) !== 1) {
            throw new InvalidArgumentException(
                'New password must contain at least one lowercase letter.',
            );
        }

        if (preg_match('/[0-9]/', $dto->newPassword) !== 1) {
            throw new InvalidArgumentException(
                'New password must contain at least one number.',
            );
        }

        if (preg_match('/[^A-Za-z0-9\s]/', $dto->newPassword) !== 1) {
            throw new InvalidArgumentException(
                'New password must contain at least one special character.',
            );
        }

        if ($dto->newPassword === $dto->currentPassword) {
            throw new InvalidArgumentException(
                'New password must be different from current password.',
            );
        }
    }
}
