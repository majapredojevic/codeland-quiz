<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

interface QuestionImageReferenceRepository
{
    public function isReferenced(string $imagePath): bool;
}
