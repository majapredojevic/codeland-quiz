<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuestionImage;

final readonly class QuestionImageAsset
{
    public function __construct(
        public string $fileName,
        public string $path,
    ) {
    }
}
