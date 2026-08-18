<?php

declare(strict_types=1);

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\QuestionImage\Exception\QuestionImageNotFoundException;
use CodeLandQuiz\QuestionImage\Exception\QuestionImageUploadTooLargeException;
use CodeLandQuiz\QuestionImage\QuestionImagePath;
use CodeLandQuiz\QuestionImage\QuestionImageStorage;
use CodeLandQuiz\Support\Environment;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$verificationRoot = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'codeland-question-images-verify-'
    . bin2hex(random_bytes(8));
$temporaryUploads = [];

$_ENV['QUESTION_IMAGE_STORAGE_PATH'] = $verificationRoot;
$_SERVER['QUESTION_IMAGE_STORAGE_PATH'] = $verificationRoot;

$securityEnvironment = [
    'LOGIN_IP_ATTEMPT_LIMIT' => '100',
    'WS_ALLOWED_ORIGINS' => 'http://localhost:4200',
    'WS_GAMEPLAY_MAX_FRAME_BYTES' => '16384',
    'WS_AUTH_ATTEMPT_LIMIT' => '3',
    'WS_AUTH_IP_ATTEMPT_LIMIT' => '1000',
    'WS_AUTH_IP_WINDOW_SECONDS' => '60',
    'WS_ANSWER_ATTEMPT_LIMIT' => '8',
    'WS_ANSWER_ATTEMPT_WINDOW_SECONDS' => '10',
    'WS_CONNECTION_LIMIT' => '2000',
    'WS_PENDING_CONNECTION_LIMIT' => '750',
    'WS_CONNECTION_PER_IP_LIMIT' => '750',
];

foreach ($securityEnvironment as $name => $value) {
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$storage = new QuestionImageStorage(
    new AppConfig(new Environment($projectRoot)),
);

/**
 * @param mixed $condition
 */
function verify(mixed $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param class-string<Throwable> $exceptionClass
 * @param callable(): void $operation
 */
function verifyThrows(
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

/**
 * @return array<string, mixed>
 */
function uploadedFile(
    string $name,
    string $contents,
    array &$temporaryUploads,
): array {
    $path = tempnam(sys_get_temp_dir(), 'codeland-upload-');

    if ($path === false || file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Verification upload could not be created.');
    }

    $temporaryUploads[] = $path;

    return [
        'name' => $name,
        'type' => 'application/octet-stream',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($contents),
    ];
}

function removeVerificationDirectory(string $directory): void
{
    if (
        !str_starts_with(
            basename($directory),
            'codeland-question-images-verify-',
        )
        || !is_dir($directory)
    ) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());

            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($directory);
}

$fixtures = [
    'photo.jpg' => base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQE'
        . 'BQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/'
        . 'wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAA'
        . 'AAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
        true,
    ),
    'graphic.png' => base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk'
        . '+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    ),
    'picture.webp' => base64_decode(
        'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJQBOgCHwAP7+2AAA',
        true,
    ),
];

try {
    foreach ($fixtures as $originalName => $contents) {
        verify(
            is_string($contents),
            sprintf('The %s image fixture is invalid.', $originalName),
        );
        $image = $storage->store(
            42,
            uploadedFile($originalName, $contents, $temporaryUploads),
        );
        verify(
            preg_match(
                '/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/D',
                $image->fileName,
            ) === 1,
            'Stored image name is not randomly generated and canonical.',
        );
        verify(
            !str_contains($image->fileName, pathinfo($originalName, PATHINFO_FILENAME)),
            'Stored image name exposes the client file name.',
        );
        verify(
            $image->path === sprintf(
                '/media/question-images/42/%s',
                $image->fileName,
            ),
            'Upload returned a non-canonical media path.',
        );
        verify(
            !str_contains($image->path, $verificationRoot),
            'Upload response exposes the physical storage path.',
        );

        $publicFile = $storage->publicFile(42, $image->fileName);
        verify(is_file($publicFile->physicalPath), 'Stored image is missing.');
        verify(
            str_starts_with(
                (string) realpath($publicFile->physicalPath),
                (string) realpath($verificationRoot),
            ),
            'Stored image escaped the controlled storage root.',
        );
        $expectedContentType = match (
            strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION))
        ) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        };
        verify(
            $publicFile->contentType === $expectedContentType,
            'Public image content type does not match its verified format.',
        );

        $storage->assertManagedImageExists(42, $image->path);
        verifyThrows(
            InvalidArgumentException::class,
            fn () => $storage->assertManagedImageExists(43, $image->path),
            'Cross-quiz image attachment was accepted.',
        );

        $storage->delete(42, $image->fileName);
        verifyThrows(
            QuestionImageNotFoundException::class,
            fn () => $storage->publicFile(42, $image->fileName),
            'Deleted image remained publicly resolvable.',
        );
    }

    verifyThrows(
        InvalidArgumentException::class,
        fn () => QuestionImagePath::fromFileName(42, '../secret.jpg'),
        'Traversal file name was accepted.',
    );
    verifyThrows(
        InvalidArgumentException::class,
        fn () => QuestionImagePath::fromFileName(42, '%2e%2e%2fsecret.jpg'),
        'Encoded traversal file name was accepted.',
    );
    verifyThrows(
        InvalidArgumentException::class,
        fn () => QuestionImagePath::nullableRequestValue(
            'https://example.test/image.jpg',
        ),
        'External image path was accepted.',
    );
    verifyThrows(
        InvalidArgumentException::class,
        fn () => QuestionImagePath::nullableRequestValue('   '),
        'Blank image path was silently normalized to null.',
    );
    verify(
        QuestionImagePath::nullableRequestValue(null) === null,
        'Explicit null image path was not preserved.',
    );
    verifyThrows(
        InvalidArgumentException::class,
        fn () => $storage->assertManagedImageExists(
            42,
            '/media/question-images/42/00000000000000000000000000000000.png',
        ),
        'Syntactically valid but nonexistent image path was accepted.',
    );

    $fakeImage = uploadedFile(
        'fake.jpg',
        'This is not an image.',
        $temporaryUploads,
    );
    verifyThrows(
        InvalidArgumentException::class,
        fn () => $storage->store(42, $fakeImage),
        'Fake JPEG content was accepted.',
    );

    $unsupportedExtension = uploadedFile(
        'graphic.gif',
        (string) $fixtures['graphic.png'],
        $temporaryUploads,
    );
    verifyThrows(
        InvalidArgumentException::class,
        fn () => $storage->store(42, $unsupportedExtension),
        'Unsupported client extension was accepted.',
    );

    $mismatchedExtension = uploadedFile(
        'graphic.jpg',
        (string) $fixtures['graphic.png'],
        $temporaryUploads,
    );
    verifyThrows(
        InvalidArgumentException::class,
        fn () => $storage->store(42, $mismatchedExtension),
        'Client extension incompatible with verified MIME was accepted.',
    );

    verifyThrows(
        InvalidArgumentException::class,
        fn () => $storage->store(42, []),
        'Missing image upload was accepted.',
    );

    $emptyUpload = uploadedFile('empty.png', '', $temporaryUploads);
    verifyThrows(
        InvalidArgumentException::class,
        fn () => $storage->store(42, $emptyUpload),
        'Zero-byte image was accepted.',
    );

    $oversizedUpload = uploadedFile(
        'large.png',
        str_repeat('x', (5 * 1024 * 1024) + 1),
        $temporaryUploads,
    );
    verifyThrows(
        QuestionImageUploadTooLargeException::class,
        fn () => $storage->store(42, $oversizedUpload),
        'Oversized image was accepted.',
    );

    $traversalNameImage = $storage->store(
        42,
        uploadedFile(
            '../../client-name.png',
            (string) $fixtures['graphic.png'],
            $temporaryUploads,
        ),
    );
    $traversalFile = $storage->publicFile(42, $traversalNameImage->fileName);
    verify(
        str_starts_with(
            (string) realpath($traversalFile->physicalPath),
            (string) realpath($verificationRoot),
        ),
        'Client traversal name escaped the storage root.',
    );
    $storage->delete(42, $traversalNameImage->fileName);

    echo "Question image storage verification passed.\n";
} finally {
    foreach ($temporaryUploads as $temporaryUpload) {
        if (is_file($temporaryUpload)) {
            unlink($temporaryUpload);
        }
    }

    removeVerificationDirectory($verificationRoot);
}
