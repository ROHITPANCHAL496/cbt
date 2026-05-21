-- ============================================================
-- ExamiPortal - Database Schema
-- Import this into: u111778052_examiportal
-- (No CREATE DATABASE line — already created in Hostinger)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    email        VARCHAR(200) UNIQUE NOT NULL,
    phone        VARCHAR(20),
    password     VARCHAR(255) NOT NULL,
    role         ENUM('student','admin','teacher') DEFAULT 'student',
    exam_target  ENUM('NEET','JEE','Foundation') DEFAULT 'NEET',
    class        VARCHAR(10),
    batch        VARCHAR(50),
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active    TINYINT(1) DEFAULT 1,
    avatar_url   VARCHAR(500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. SUBJECTS
-- ============================================================
CREATE TABLE IF NOT EXISTS subjects (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    name_hi    VARCHAR(100),
    name_gu    VARCHAR(100),
    exam_type  ENUM('NEET','JEE','Foundation','All') DEFAULT 'All',
    icon       VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. CHAPTERS
-- ============================================================
CREATE TABLE IF NOT EXISTS chapters (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    subject_id  INT NOT NULL,
    name        VARCHAR(200) NOT NULL,
    name_hi     VARCHAR(200),
    name_gu     VARCHAR(200),
    class       VARCHAR(10),
    sort_order  INT DEFAULT 0,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. TOPICS
-- ============================================================
CREATE TABLE IF NOT EXISTS topics (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    chapter_id  INT NOT NULL,
    name        VARCHAR(200) NOT NULL,
    name_hi     VARCHAR(200),
    name_gu     VARCHAR(200),
    FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. TEST SERIES
-- ============================================================
CREATE TABLE IF NOT EXISTS test_series (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(300) NOT NULL,
    title_hi      VARCHAR(300),
    title_gu      VARCHAR(300),
    exam_type     ENUM('NEET','JEE','Foundation') DEFAULT 'NEET',
    test_type     ENUM('Minor','Major','Part','Full','Practice') DEFAULT 'Minor',
    series_name   VARCHAR(100),
    class         VARCHAR(10),
    batch         VARCHAR(50),
    duration_min  INT DEFAULT 180,
    total_marks   INT DEFAULT 720,
    neg_marks     DECIMAL(4,2) DEFAULT 1.00,
    languages     VARCHAR(20) DEFAULT 'en,hi,gu',
    start_time    DATETIME,
    end_time      DATETIME,
    is_published  TINYINT(1) DEFAULT 0,
    instructions  TEXT,
    instructions_hi TEXT,
    instructions_gu TEXT,
    created_by    INT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. QUESTIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS questions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    test_id         INT NOT NULL,
    subject_id      INT,
    chapter_id      INT,
    topic_id        INT,
    q_number        INT NOT NULL,
    q_type          ENUM('MCQ','MSQ','Integer','Assertion') DEFAULT 'MCQ',
    difficulty      ENUM('Easy','Medium','Hard') DEFAULT 'Medium',
    question_en     TEXT NOT NULL,
    opt_a_en        TEXT,
    opt_b_en        TEXT,
    opt_c_en        TEXT,
    opt_d_en        TEXT,
    question_hi     TEXT,
    opt_a_hi        TEXT,
    opt_b_hi        TEXT,
    opt_c_hi        TEXT,
    opt_d_hi        TEXT,
    question_gu     TEXT,
    opt_a_gu        TEXT,
    opt_b_gu        TEXT,
    opt_c_gu        TEXT,
    opt_d_gu        TEXT,
    correct_answer  VARCHAR(10),
    solution_en     TEXT,
    solution_hi     TEXT,
    solution_gu     TEXT,
    marks_correct   DECIMAL(4,2) DEFAULT 4.00,
    marks_wrong     DECIMAL(4,2) DEFAULT 1.00,
    has_image       TINYINT(1) DEFAULT 0,
    image_url       VARCHAR(500),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (test_id) REFERENCES test_series(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. TEST ATTEMPTS
-- ============================================================
CREATE TABLE IF NOT EXISTS test_attempts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    test_id         INT NOT NULL,
    language        ENUM('en','hi','gu') DEFAULT 'en',
    start_time      DATETIME NOT NULL,
    end_time        DATETIME,
    submitted_at    DATETIME,
    status          ENUM('in_progress','submitted','auto_submitted','abandoned') DEFAULT 'in_progress',
    score           DECIMAL(8,2) DEFAULT 0,
    correct_count   INT DEFAULT 0,
    wrong_count     INT DEFAULT 0,
    unattempted     INT DEFAULT 0,
    rank_overall    INT,
    rank_batch      INT,
    percentile      DECIMAL(5,2),
    ip_address      VARCHAR(45),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (test_id) REFERENCES test_series(id),
    UNIQUE KEY unique_attempt (user_id, test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. QUESTION RESPONSES
-- ============================================================
CREATE TABLE IF NOT EXISTS question_responses (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id       INT NOT NULL,
    question_id      INT NOT NULL,
    selected_option  VARCHAR(10),
    is_marked_review TINYINT(1) DEFAULT 0,
    is_correct       TINYINT(1),
    marks_earned     DECIMAL(4,2) DEFAULT 0,
    time_spent_sec   INT DEFAULT 0,
    visit_count      INT DEFAULT 1,
    first_visit_at   DATETIME,
    last_visit_at    DATETIME,
    answered_at      DATETIME,
    FOREIGN KEY (attempt_id)  REFERENCES test_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. UPLOAD LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS upload_logs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    filename         VARCHAR(500),
    test_id          INT,
    uploaded_by      INT,
    status           ENUM('pending','processing','success','failed') DEFAULT 'pending',
    questions_parsed INT DEFAULT 0,
    error_msg        TEXT,
    uploaded_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 10. QUESTION ANALYTICS
-- ============================================================
CREATE TABLE IF NOT EXISTS question_analytics (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    question_id         INT NOT NULL,
    total_attempts      INT DEFAULT 0,
    correct_count       INT DEFAULT 0,
    avg_time_sec        DECIMAL(8,2) DEFAULT 0,
    difficulty_computed DECIMAL(4,3),
    opt_a_count         INT DEFAULT 0,
    opt_b_count         INT DEFAULT 0,
    opt_c_count         INT DEFAULT 0,
    opt_d_count         INT DEFAULT 0,
    skipped_count       INT DEFAULT 0,
    last_updated        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- ============================================================
INSERT IGNORE INTO subjects (id, name, name_hi, name_gu, exam_type, icon) VALUES
(1, 'Physics',        'भौतिकी',         'ભૌતિકશાસ્ત્ર',  'NEET', 'atom'),
(2, 'Chemistry',      'रसायन विज्ञान',  'રસાયણ વિજ્ઞાન', 'NEET', 'flask'),
(3, 'Biology',        'जीव विज्ञान',    'જીવ વિજ્ઞાન',   'NEET', 'dna'),
(4, 'Mathematics',    'गणित',           'ગણિત',           'JEE',  'calculator'),
(5, 'Mental Ability', 'मानसिक योग्यता', 'માનસિક ક્ષમતા', 'Foundation', 'brain');

-- Default admin user (password: Admin@1234)
INSERT IGNORE INTO users (id, name, email, password, role) VALUES
(1, 'Admin', 'admin@liproh.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'admin');
-- NOTE: Default password is "password" — change immediately after login!
-- To set your own: go to phpMyAdmin > SQL and run:
-- UPDATE users SET password='<hash>' WHERE id=1;
-- Generate hash at: https://bcrypt-generator.com
