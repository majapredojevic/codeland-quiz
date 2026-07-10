CREATE DATABASE IF NOT EXISTS codeland_quiz
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE codeland_quiz;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(180) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('ADMIN', 'TEACHER') NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_email (email),
    KEY idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refresh_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    replaced_by_token_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_refresh_tokens_user_id (user_id),
    KEY idx_refresh_tokens_expires_at (expires_at),
    KEY idx_refresh_tokens_revoked_at (revoked_at),
    CONSTRAINT fk_refresh_tokens_user_id
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_refresh_tokens_replaced_by_token_id
        FOREIGN KEY (replaced_by_token_id) REFERENCES refresh_tokens (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    username VARCHAR(80) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_students_username (username),
    KEY idx_students_username (username),
    KEY idx_students_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE topics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_topics_name (name),
    KEY idx_topics_created_by (created_by),
    KEY idx_topics_updated_by (updated_by),
    KEY idx_topics_created_at (created_at),
    CONSTRAINT fk_topics_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_topics_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quizzes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    topic_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    version INT NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_quizzes_title_version (title, version),
    KEY idx_quizzes_topic_id (topic_id),
    KEY idx_quizzes_title (title),
    KEY idx_quizzes_created_by (created_by),
    KEY idx_quizzes_updated_by (updated_by),
    KEY idx_quizzes_created_at (created_at),
    CONSTRAINT fk_quizzes_topic_id
        FOREIGN KEY (topic_id) REFERENCES topics (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_quizzes_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_quizzes_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id BIGINT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('TRUE_FALSE', 'SINGLE_CHOICE', 'MULTIPLE_CHOICE') NOT NULL,
    image_path VARCHAR(255) NULL,
    time_limit_seconds INT NOT NULL,
    max_points INT NOT NULL,
    question_order INT NOT NULL,
    is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_questions_quiz_order (quiz_id, question_order),
    KEY idx_questions_quiz_id (quiz_id),
    KEY idx_questions_created_at (created_at),
    CONSTRAINT fk_questions_quiz_id
        FOREIGN KEY (quiz_id) REFERENCES quizzes (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE question_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    option_text VARCHAR(255) NOT NULL,
    is_correct BOOLEAN NOT NULL DEFAULT FALSE,
    option_order INT NOT NULL,
    UNIQUE KEY uq_question_options_question_order (question_id, option_order),
    KEY idx_question_options_question_id (question_id),
    CONSTRAINT fk_question_options_question_id
        FOREIGN KEY (question_id) REFERENCES questions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id BIGINT UNSIGNED NOT NULL,
    host_user_id BIGINT UNSIGNED NOT NULL,
    game_pin CHAR(6) NOT NULL,
    status ENUM('WAITING', 'ACTIVE', 'FINISHED') NOT NULL DEFAULT 'WAITING',
    current_question_id BIGINT UNSIGNED NULL,
    join_deadline TIMESTAMP NULL DEFAULT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    ended_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quiz_sessions_game_pin (game_pin),
    KEY idx_quiz_sessions_game_pin (game_pin),
    KEY idx_quiz_sessions_quiz_id (quiz_id),
    KEY idx_quiz_sessions_host_user_id (host_user_id),
    KEY idx_quiz_sessions_current_question_id (current_question_id),
    KEY idx_quiz_sessions_created_at (created_at),
    CONSTRAINT fk_quiz_sessions_quiz_id
        FOREIGN KEY (quiz_id) REFERENCES quizzes (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_quiz_sessions_host_user_id
        FOREIGN KEY (host_user_id) REFERENCES users (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_quiz_sessions_current_question_id
        FOREIGN KEY (current_question_id) REFERENCES questions (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_participants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    nickname VARCHAR(100) NOT NULL,
    avatar_key VARCHAR(80) NOT NULL,
    total_score INT NOT NULL DEFAULT 0,
    is_connected BOOLEAN NOT NULL DEFAULT TRUE,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    disconnected_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_session_participants_session_student (session_id, student_id),
    KEY idx_session_participants_session_id (session_id),
    KEY idx_session_participants_student_id (student_id),
    KEY idx_session_participants_joined_at (joined_at),
    CONSTRAINT fk_session_participants_session_id
        FOREIGN KEY (session_id) REFERENCES quiz_sessions (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_session_participants_student_id
        FOREIGN KEY (student_id) REFERENCES students (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participant_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_participant_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    selected_option_ids JSON NOT NULL,
    is_correct BOOLEAN NOT NULL,
    response_time_ms INT NOT NULL,
    points_awarded INT NOT NULL DEFAULT 0,
    answered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_participant_answers_participant_question (session_participant_id, question_id),
    KEY idx_participant_answers_session_participant_id (session_participant_id),
    KEY idx_participant_answers_question_id (question_id),
    KEY idx_participant_answers_answered_at (answered_at),
    CONSTRAINT fk_participant_answers_session_participant_id
        FOREIGN KEY (session_participant_id) REFERENCES session_participants (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_participant_answers_question_id
        FOREIGN KEY (question_id) REFERENCES questions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(180) NOT NULL,
    successful BOOLEAN NOT NULL DEFAULT FALSE,
    user_agent VARCHAR(255) NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_email (email),
    KEY idx_login_attempts_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_logs_user_id (user_id),
    KEY idx_audit_logs_entity (entity_type, entity_id),
    KEY idx_audit_logs_created_at (created_at),
    CONSTRAINT fk_audit_logs_user_id
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
