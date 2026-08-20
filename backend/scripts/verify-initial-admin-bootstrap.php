<?php

declare(strict_types=1);

use CodeLandQuiz\Admin\Exception\AdministratorAlreadyExistsException;
use CodeLandQuiz\Admin\InitialAdministratorBootstrapService;
use CodeLandQuiz\Auth\BcryptPasswordHasher;
use CodeLandQuiz\Model\NewUser;
use CodeLandQuiz\Model\User;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Repository\UserRepository;
use CodeLandQuiz\Support\TransactionManager;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertBootstrap(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param class-string<Throwable> $exceptionClass
 * @param callable(): void $operation
 */
function assertBootstrapThrows(
    string $exceptionClass,
    callable $operation,
    string $message,
): void {
    try {
        $operation();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $exceptionClass) {
            return;
        }

        throw $throwable;
    }

    throw new RuntimeException($message);
}

final class BootstrapTestUserRepository implements UserRepository
{
    /** @var User[] */
    public array $users = [];

    public function create(NewUser $user): int
    {
        $id = count($this->users) + 1;
        $this->users[] = new User(
            id: $id,
            name: $user->getName(),
            email: $user->getEmail(),
            passwordHash: $user->getPasswordHash(),
            mustChangePassword: $user->mustChangePassword(),
            role: $user->getRole(),
            isActive: $user->isActive(),
        );

        return $id;
    }

    public function findAdministratorForUpdate(): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getRole() === UserRole::ADMIN) {
                return $user;
            }
        }

        return null;
    }

    public function findByEmailIncludingInactive(string $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getEmail() === $email) {
                return $user;
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id - 1] ?? null;
    }

    public function findByIdForUpdate(int $id): ?User
    {
        return $this->findById($id);
    }

    public function findTeacherById(int $id): ?User
    {
        return null;
    }

    public function findTeacherByIdForUpdate(int $id): ?User
    {
        return null;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findByEmailIncludingInactive($email);
    }

    public function save(User $user): void
    {
    }

    public function updateTeacherProfile(User $user): void
    {
    }

    public function updateTeacherStatus(User $user): void
    {
    }

    public function findTeachersPage(int $limit, int $offset): array
    {
        return [];
    }

    public function countTeachers(): int
    {
        return 0;
    }
}

final class BootstrapTestTransactionManager implements TransactionManager
{
    public int $transactions = 0;

    public function transactional(callable $operation): mixed
    {
        $this->transactions++;

        return $operation();
    }
}

$users = new BootstrapTestUserRepository();
$transactions = new BootstrapTestTransactionManager();
$hasher = new BcryptPasswordHasher();
$service = new InitialAdministratorBootstrapService(
    users: $users,
    passwordHasher: $hasher,
    transactionManager: $transactions,
);
$password = 'Aa1!' . bin2hex(random_bytes(12));
$administratorId = $service->bootstrap(
    name: '  Initial Administrator  ',
    email: '  INITIAL.ADMIN@EXAMPLE.TEST  ',
    password: $password,
);
$administrator = $users->findById($administratorId);

assertBootstrap($administrator !== null, 'Administrator was not created.');
assertBootstrap(
    $administrator->getName() === 'Initial Administrator'
        && $administrator->getEmail() === 'initial.admin@example.test',
    'Administrator identity did not use normal staff normalization.',
);
assertBootstrap(
    $administrator->getRole() === UserRole::ADMIN
        && $administrator->isActive()
        && $administrator->mustChangePassword(),
    'Administrator privilege or initial password state is unsafe.',
);
assertBootstrap(
    $administrator->getPasswordHash() !== $password
        && $hasher->verify($password, $administrator->getPasswordHash()),
    'Bootstrap did not use the application bcrypt hasher.',
);

assertBootstrapThrows(
    AdministratorAlreadyExistsException::class,
    fn () => $service->bootstrap(
        name: 'Second Administrator',
        email: 'second.admin@example.test',
        password: 'Aa1!' . bin2hex(random_bytes(12)),
    ),
    'Repeated bootstrap did not refuse an existing administrator.',
);
assertBootstrap(
    count($users->users) === 1 && $transactions->transactions === 2,
    'Repeated bootstrap modified administrator state.',
);
assertBootstrapThrows(
    InvalidArgumentException::class,
    fn () => (new InitialAdministratorBootstrapService(
        users: new BootstrapTestUserRepository(),
        passwordHasher: $hasher,
        transactionManager: new BootstrapTestTransactionManager(),
    ))->bootstrap('Administrator', 'admin@example.test', 'weak'),
    'Bootstrap accepted a password below the normal application policy.',
);

$repositoryRoot = dirname(__DIR__, 2);
$productionCompose = file_get_contents(
    $repositoryRoot . '/compose.production.yaml',
);
$developmentCompose = file_get_contents($repositoryRoot . '/docker-compose.yml');
$bootstrapCli = file_get_contents(
    $repositoryRoot . '/backend/scripts/bootstrap-initial-admin.php',
);
$application = file_get_contents($repositoryRoot . '/backend/src/Application.php');

assertBootstrap(
    is_string($productionCompose)
        && str_contains(
            $productionCompose,
            'source: ./docker/mysql/init/001_schema.sql',
        )
        && !str_contains($productionCompose, '002_seed_admin.sql'),
    'Production Compose can still load the development administrator seed.',
);
assertBootstrap(
    is_string($developmentCompose)
        && str_contains(
            $developmentCompose,
            './docker/mysql/init:/docker-entrypoint-initdb.d',
        ),
    'Development administrator seed behavior changed.',
);
assertBootstrap(
    is_string($bootstrapCli)
        && str_contains($bootstrapCli, "exec('stty -echo'")
        && !str_contains($bootstrapCli, "'password' =>")
        && !str_contains($bootstrapCli, "['name', 'email', 'password']"),
    'Bootstrap CLI does not keep password input out of process arguments.',
);
assertBootstrap(
    is_string($application)
        && !str_contains($application, 'bootstrap-initial-admin')
        && !str_contains($application, '/api/bootstrap'),
    'Administrator bootstrap was exposed through the application router.',
);

echo "Initial administrator bootstrap verification passed.\n";
