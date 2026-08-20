<?php

declare(strict_types=1);

use CodeLandQuiz\Admin\Exception\AdministratorAlreadyExistsException;
use CodeLandQuiz\Admin\InitialAdministratorBootstrapService;
use CodeLandQuiz\Auth\BcryptPasswordHasher;
use CodeLandQuiz\Repository\MySqlUserRepository;
use CodeLandQuiz\Support\Database;
use CodeLandQuiz\Support\Environment;
use CodeLandQuiz\Support\PdoTransactionManager;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return array{name: string, email: string}
 */
function bootstrapArguments(array $arguments): array
{
    $values = [];

    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException(
                'Use --name=<name> and --email=<email>.',
            );
        }

        [$key, $value] = explode('=', substr($argument, 2), 2);

        if (!in_array($key, ['name', 'email'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported bootstrap option: --%s.',
                $key,
            ));
        }

        if (array_key_exists($key, $values)) {
            throw new InvalidArgumentException(sprintf(
                'Bootstrap option was supplied more than once: --%s.',
                $key,
            ));
        }

        $values[$key] = $value;
    }

    foreach (['name', 'email'] as $required) {
        if (!isset($values[$required]) || trim($values[$required]) === '') {
            throw new InvalidArgumentException(sprintf(
                'Missing required bootstrap option: --%s.',
                $required,
            ));
        }
    }

    return [
        'name' => $values['name'],
        'email' => $values['email'],
    ];
}

function bootstrapReadLine(string $prompt, bool $hidden): string
{
    $isInteractive = function_exists('stream_isatty') && stream_isatty(STDIN);

    if ($isInteractive) {
        fwrite(STDOUT, $prompt);
    }

    $echoDisabled = false;

    if ($isInteractive && $hidden) {
        $output = [];
        $exitCode = 1;
        exec('stty -echo', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Terminal echo could not be disabled; use non-interactive stdin instead.',
            );
        }

        $echoDisabled = true;
    }

    try {
        $line = fgets(STDIN);
    } finally {
        if ($echoDisabled) {
            exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }

    if (!is_string($line)) {
        throw new InvalidArgumentException('Password input was not provided.');
    }

    return rtrim($line, "\r\n");
}

try {
    $arguments = bootstrapArguments($argv);
    $environment = new Environment(dirname(__DIR__));

    if ($environment->get('APP_ENV') !== 'production') {
        throw new InvalidArgumentException(
            'Initial administrator bootstrap is available only in APP_ENV=production.',
        );
    }

    $password = bootstrapReadLine('Bootstrap password: ', true);
    $passwordConfirmation = bootstrapReadLine('Confirm bootstrap password: ', true);

    if (!hash_equals($password, $passwordConfirmation)) {
        throw new InvalidArgumentException(
            'Password confirmation does not match.',
        );
    }

    $database = new Database($environment);
    $administratorId = (new InitialAdministratorBootstrapService(
        users: new MySqlUserRepository($database),
        passwordHasher: new BcryptPasswordHasher(),
        transactionManager: new PdoTransactionManager($database),
    ))->bootstrap(
        name: $arguments['name'],
        email: $arguments['email'],
        password: $password,
    );

    $password = '';
    $passwordConfirmation = '';

    fwrite(STDOUT, sprintf(
        "Initial administrator created with ID %d. Password change is required at first login.\n",
        $administratorId,
    ));
    exit(0);
} catch (InvalidArgumentException | AdministratorAlreadyExistsException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
} catch (Throwable) {
    fwrite(
        STDERR,
        "Initial administrator bootstrap failed; no account was created.\n",
    );
    exit(1);
}
