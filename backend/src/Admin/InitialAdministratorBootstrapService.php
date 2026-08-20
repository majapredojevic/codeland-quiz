<?php

declare(strict_types=1);

namespace CodeLandQuiz\Admin;

use CodeLandQuiz\Admin\Exception\AdministratorAlreadyExistsException;
use CodeLandQuiz\Auth\PasswordHasher;
use CodeLandQuiz\Auth\PasswordPolicy;
use CodeLandQuiz\Model\NewUser;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Repository\UserRepository;
use CodeLandQuiz\Support\TransactionManager;
use InvalidArgumentException;

final readonly class InitialAdministratorBootstrapService
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private TransactionManager $transactionManager,
    ) {
    }

    public function bootstrap(
        string $name,
        string $email,
        string $password,
    ): int {
        $name = StaffIdentityNormalizer::name($name, 'Administrator');
        $email = StaffIdentityNormalizer::email($email, 'Administrator');
        PasswordPolicy::validate($password);

        return $this->transactionManager->transactional(function () use (
            $email,
            $name,
            $password,
        ): int {
            if ($this->users->findAdministratorForUpdate() !== null) {
                throw new AdministratorAlreadyExistsException(
                    'An administrator already exists; bootstrap was refused.',
                );
            }

            if ($this->users->findByEmailIncludingInactive($email) !== null) {
                throw new InvalidArgumentException(
                    'A user with the supplied email already exists.',
                );
            }

            return $this->users->create(new NewUser(
                name: $name,
                email: $email,
                passwordHash: $this->passwordHasher->hash($password),
                mustChangePassword: true,
                role: UserRole::ADMIN,
                isActive: true,
            ));
        });
    }
}
