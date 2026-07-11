<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class TeacherListResultDTO
{
    /**
     * @param UserListItemDTO[] $teachers
     */
    public function __construct(
        public array $teachers,
        public int $pageIndex,
        public int $pageSize,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}
