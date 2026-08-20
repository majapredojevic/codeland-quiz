<?php

declare(strict_types=1);

use CodeLandQuiz\Support\Database;
use CodeLandQuiz\Support\Environment;

require dirname(__DIR__) . '/vendor/autoload.php';

function databaseStateAssertion(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$mode = $argv[1] ?? '';
$expectedEmail = $argv[2] ?? '';

if (!in_array($mode, ['fresh', 'password-change-required', 'normal'], true)) {
    fwrite(
        STDERR,
        "Usage: php verify-bootstrap-database-state.php <fresh|password-change-required|normal> [expected-email]\n",
    );
    exit(2);
}

try {
    $database = new Database(new Environment(dirname(__DIR__)));
    $connection = $database->getConnection();
    $usersTableExists = (int) $connection->query(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = 'users'",
    )->fetchColumn();

    databaseStateAssertion(
        $usersTableExists === 1,
        'Production schema did not create the users table.',
    );

    if ($mode === 'fresh') {
        $userCount = (int) $connection->query(
            'SELECT COUNT(*) FROM users',
        )->fetchColumn();
        databaseStateAssertion(
            $userCount === 0,
            'Fresh production database unexpectedly contains a user.',
        );
        echo "Fresh production database contains schema and zero users.\n";
        exit(0);
    }

    if ($expectedEmail === '') {
        throw new InvalidArgumentException('Expected Admin email is required.');
    }

    $passwordLine = fgets(STDIN);

    if (!is_string($passwordLine)) {
        throw new InvalidArgumentException(
            'Expected password must be supplied through stdin.',
        );
    }

    $password = rtrim($passwordLine, "\r\n");
    $statement = $connection->prepare(
        "SELECT email, password_hash, must_change_password, is_active
         FROM users
         WHERE role = 'ADMIN'",
    );
    $statement->execute();
    $administrators = $statement->fetchAll();

    databaseStateAssertion(
        count($administrators) === 1,
        'Production database does not contain exactly one administrator.',
    );

    $administrator = $administrators[0];
    databaseStateAssertion(
        hash_equals($expectedEmail, (string) $administrator['email']),
        'Administrator email differs from operator input.',
    );
    databaseStateAssertion(
        (bool) (int) $administrator['is_active'],
        'Bootstrapped administrator is inactive.',
    );
    databaseStateAssertion(
        (bool) (int) $administrator['must_change_password']
            === ($mode === 'password-change-required'),
        'Administrator required-password state is incorrect.',
    );
    databaseStateAssertion(
        !hash_equals($password, (string) $administrator['password_hash'])
            && password_verify(
                $password,
                (string) $administrator['password_hash'],
            ),
        'Administrator password was not persisted with the application hasher.',
    );

    $password = '';
    echo "Administrator database state verification passed.\n";
} catch (InvalidArgumentException | RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Database state verification failed.\n");
    exit(1);
}
