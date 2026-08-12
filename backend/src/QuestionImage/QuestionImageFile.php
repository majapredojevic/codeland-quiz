<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuestionImage;

final readonly class QuestionImageFile
{
    public function __construct(
        public string $physicalPath,
        public string $contentType,
    ) {
    }
}
