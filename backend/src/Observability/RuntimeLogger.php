<?php

declare(strict_types=1);

namespace CodeLandQuiz\Observability;

use DateTimeImmutable;
use JsonException;

final readonly class RuntimeLogger
{
    private const MAXIMUM_EVENT_LENGTH = 96;
    private const MAXIMUM_STRING_FIELD_LENGTH = 256;

    /**
     * Only explicitly approved, bounded diagnostic fields may reach logs.
     * Request bodies, credentials, tokens, cookies and answer content are
     * intentionally absent.
     */
    private const ALLOWED_CONTEXT_FIELDS = [
        'requestId',
        'route',
        'method',
        'status',
        'durationMs',
        'coroutineId',
        'workerId',
        'workerPid',
        'port',
        'exitCode',
        'signal',
        'fd',
        'connectionId',
        'sessionId',
        'participantId',
        'idleMs',
        'staleAfterSeconds',
        'count',
        'exception',
        'reason',
    ];

    public function __construct(
        private bool $debugEnabled,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $event, array $context = []): void
    {
        if (!$this->debugEnabled) {
            return;
        }

        $this->write('DEBUG', $event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->write('INFO', $event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->write('WARNING', $event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->write('ERROR', $event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $event, array $context): void
    {
        $entry = [
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            'level' => $level,
            'event' => substr($event, 0, self::MAXIMUM_EVENT_LENGTH),
        ];

        foreach (self::ALLOWED_CONTEXT_FIELDS as $field) {
            $value = $context[$field] ?? null;

            if (is_string($value)) {
                $entry[$field] = substr(
                    $value,
                    0,
                    self::MAXIMUM_STRING_FIELD_LENGTH,
                );

                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value)) {
                $entry[$field] = $value;
            }
        }

        try {
            error_log(json_encode(
                $entry,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } catch (JsonException) {
            error_log('{"level":"ERROR","event":"runtime.log_encoding_failed"}');
        }
    }
}
