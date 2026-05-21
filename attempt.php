<?php
// portal/api/attempt.php
// Handles: start, answer, submit, analytics, tests list
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ─── GET TESTS LIST ──────────────────────────────────
if ($action === 'tests' && $method === 'GET') {
    $type = preg_replace('/[^A-Za-z]/', '', $_GET['exam_type'] ?? 'NEET');
    $stmt = db()->prepare("
        SELECT id, title, title_hi, title_gu, series_name, exam_type, test_type,
               duration_min, total_marks, start_time, end_time,
               (SELECT COUNT(*) FROM questions WHERE test_id = ts.id) AS question_count
        FROM test_series ts
        WHERE exam_type = ? AND is_published = 1
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $rows = $stmt->get_result();
    $tests = [];
    while ($r = $rows->fetch_assoc()) $tests[] = $r;
    echo json_encode(['tests' => $tests], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── START ATTEMPT ───────────────────────────────────
if ($action === 'start' && $method === 'POST') {
    $uid  = (int)($body['user_id'] ?? 0);
    $tid  = (int)($body['test_id'] ?? 0);
    $lang = in_array($body['language'] ?? '', ['hi','gu']) ? $body['language'] : 'en';

    if (!$uid || !$tid) { echo json_encode(['error' => 'user_id and test_id required']); exit; }

    // Check for existing attempt
    $stmt = db()->prepare("SELECT id, status FROM test_attempts WHERE user_id=? AND test_id=?");
    $stmt->bind_param('ii', $uid, $tid);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if ($existing && $existing['status'] === 'submitted') {
        echo json_encode(['error' => 'Already submitted', 'attempt_id' => $existing['id']]); exit;
    }
    if ($existing && $existing['status'] === 'in_progress') {
        echo json_encode(['attempt_id' => $existing['id'], 'resumed' => true]); exit;
    }

    // Create new attempt
    $stmt = db()->prepare("
        INSERT INTO test_attempts (user_id, test_id, language, start_time, status)
        VALUES (?, ?, ?, NOW(), 'in_progress')
    ");
    $stmt->bind_param('iis', $uid, $tid, $lang);
    $stmt->execute();
    $aid = db()->insert_id;

    // Pre-create blank response rows for all questions
    $qs = db()->query("SELECT id FROM questions WHERE test_id = $tid");
    $ins = db()->prepare("INSERT IGNORE INTO question_responses (attempt_id, question_id, first_visit_at) VALUES (?,?,NOW())");
    while ($q = $qs->fetch_assoc()) {
        $ins->bind_param('ii', $aid, $q['id']);
        $ins->execute();
    }

    echo json_encode(['attempt_id' => $aid, 'resumed' => false]);
    exit;
}

// ─── SAVE ANSWER ─────────────────────────────────────
if ($action === 'answer' && $method === 'POST') {
    $aid   = (int)($body['attempt_id'] ?? 0);
    $qid   = (int)($body['question_id'] ?? 0);
    $opt   = strtoupper(preg_replace('/[^A-Da-d]/', '', $body['selected_option'] ?? ''));
    $time  = (int)($body['time_spent_sec'] ?? 0);
    $mark  = (int)(!empty($body['is_marked_review']));

    if (!$aid || !$qid) { echo json_encode(['error' => 'attempt_id and question_id required']); exit; }

    // Get correct answer
    $stmt = db()->prepare("SELECT correct_answer, marks_correct, marks_wrong FROM questions WHERE id=?");
    $stmt->bind_param('i', $qid);
    $stmt->execute();
    $q = $stmt->get_result()->fetch_assoc();

    $is_correct = null;
    $earned     = 0;
    $opt_val    = $opt ?: null;

    if ($opt && $q) {
        $is_correct = (int)($opt === strtoupper($q['correct_answer']));
        $earned     = $is_correct ? (float)$q['marks_correct'] : -(float)$q['marks_wrong'];
    }

    $stmt = db()->prepare("
        UPDATE question_responses SET
            selected_option  = ?,
            is_marked_review = ?,
            is_correct       = ?,
            marks_earned     = ?,
            time_spent_sec   = time_spent_sec + ?,
            visit_count      = visit_count + 1,
            last_visit_at    = NOW(),
            answered_at      = IF(? IS NOT NULL AND answered_at IS NULL, NOW(), answered_at)
        WHERE attempt_id = ? AND question_id = ?
    ");
    $stmt->bind_param('siidisii', $opt_val, $mark, $is_correct, $earned, $time, $opt_val, $aid, $qid);
    $stmt->execute();

    echo json_encode(['saved' => true, 'is_correct' => $is_correct, 'marks' => $earned]);
    exit;
}

// ─── SUBMIT TEST ─────────────────────────────────────
if ($action === 'submit' && $method === 'POST') {
    $aid = (int)($body['attempt_id'] ?? $_GET['attempt_id'] ?? 0);
    if (!$aid) { echo json_encode(['error' => 'attempt_id required']); exit; }

    // Aggregate scores
    $stats = db()->query("
        SELECT
            COALESCE(SUM(marks_earned), 0)                                        AS score,
            COALESCE(SUM(is_correct = 1), 0)                                      AS correct,
            COALESCE(SUM(is_correct = 0 AND selected_option IS NOT NULL), 0)      AS wrong,
            COALESCE(SUM(selected_option IS NULL), 0)                             AS unattempted
        FROM question_responses WHERE attempt_id = $aid
    ")->fetch_assoc();

    $score      = (float)$stats['score'];
    $correct    = (int)$stats['correct'];
    $wrong      = (int)$stats['wrong'];
    $unattempted= (int)$stats['unattempted'];

    $stmt = db()->prepare("
        UPDATE test_attempts SET
            status       = 'submitted',
            submitted_at = NOW(),
            end_time     = NOW(),
            score        = ?,
            correct_count= ?,
            wrong_count  = ?,
            unattempted  = ?
        WHERE id = ?
    ");
    $stmt->bind_param('diiii', $score, $correct, $wrong, $unattempted, $aid);
    $stmt->execute();

    // Calculate rank among all submitted attempts for same test
    $test_id = db()->query("SELECT test_id FROM test_attempts WHERE id=$aid")->fetch_assoc()['test_id'];
    $rank_row = db()->query("
        SELECT COUNT(*)+1 AS rnk FROM test_attempts
        WHERE test_id = $test_id AND score > $score AND status = 'submitted' AND id != $aid
    ")->fetch_assoc();
    $rank = (int)$rank_row['rnk'];

    db()->query("UPDATE test_attempts SET rank_overall=$rank WHERE id=$aid");

    // Update question-level analytics
    $responses = db()->query("
        SELECT question_id, is_correct, time_spent_sec, selected_option
        FROM question_responses WHERE attempt_id = $aid
    ");
    while ($r = $responses->fetch_assoc()) {
        $qid2  = (int)$r['question_id'];
        $ic    = (int)($r['is_correct'] ?? 0);
        $tsec  = (int)$r['time_spent_sec'];
        $selopt= $r['selected_option'] ?? '';
        $a_inc = $selopt === 'A' ? 1 : 0;
        $b_inc = $selopt === 'B' ? 1 : 0;
        $c_inc = $selopt === 'C' ? 1 : 0;
        $d_inc = $selopt === 'D' ? 1 : 0;
        $sk    = empty($selopt) ? 1 : 0;

        db()->query("
            INSERT INTO question_analytics (question_id, total_attempts, correct_count, avg_time_sec,
                opt_a_count, opt_b_count, opt_c_count, opt_d_count, skipped_count)
            VALUES ($qid2, 1, $ic, $tsec, $a_inc, $b_inc, $c_inc, $d_inc, $sk)
            ON DUPLICATE KEY UPDATE
                total_attempts = total_attempts + 1,
                correct_count  = correct_count + $ic,
                avg_time_sec   = (avg_time_sec * (total_attempts - 1) + $tsec) / total_attempts,
                opt_a_count    = opt_a_count + $a_inc,
                opt_b_count    = opt_b_count + $b_inc,
                opt_c_count    = opt_c_count + $c_inc,
                opt_d_count    = opt_d_count + $d_inc,
                skipped_count  = skipped_count + $sk
        ");
    }

    echo json_encode([
        'success'     => true,
        'score'       => $score,
        'correct'     => $correct,
        'wrong'       => $wrong,
        'unattempted' => $unattempted,
        'rank'        => $rank,
        'attempt_id'  => $aid
    ]);
    exit;
}

// ─── GET ANALYTICS ───────────────────────────────────
if ($action === 'analytics' && $method === 'GET') {
    $aid = (int)($_GET['attempt_id'] ?? 0);
    if (!$aid) { echo json_encode(['error' => 'attempt_id required']); exit; }

    $attempt = db()->query("
        SELECT ta.*, ts.title, ts.total_marks, ts.duration_min,
               TIMESTAMPDIFF(MINUTE, ta.start_time, ta.submitted_at) AS time_taken_min
        FROM test_attempts ta
        JOIN test_series ts ON ta.test_id = ts.id
        WHERE ta.id = $aid
    ")->fetch_assoc();

    // Per-question responses with class avg comparison
    $resp_result = db()->query("
        SELECT
            qr.question_id, qr.selected_option, qr.is_correct,
            qr.marks_earned, qr.time_spent_sec, qr.is_marked_review,
            q.q_number, q.correct_answer, q.subject_id,
            s.name     AS subject_name,
            c.name     AS chapter_name,
            qa.avg_time_sec   AS avg_class_time,
            qa.correct_count,
            qa.total_attempts,
            ROUND(qa.correct_count / NULLIF(qa.total_attempts,0) * 100, 1) AS accuracy_pct
        FROM question_responses qr
        JOIN questions q  ON qr.question_id = q.id
        LEFT JOIN subjects s  ON q.subject_id  = s.id
        LEFT JOIN chapters c  ON q.chapter_id  = c.id
        LEFT JOIN question_analytics qa ON qr.question_id = qa.question_id
        WHERE qr.attempt_id = $aid
        ORDER BY q.q_number
    ");
    $responses = [];
    while ($r = $resp_result->fetch_assoc()) $responses[] = $r;

    // Subject-wise stats
    $sub_result = db()->query("
        SELECT s.name AS subject,
               SUM(qr.marks_earned)  AS marks,
               COUNT(qr.id)          AS total_qs,
               SUM(qr.is_correct=1)  AS correct,
               SUM(qr.is_correct=0 AND qr.selected_option IS NOT NULL) AS wrong,
               SUM(qr.time_spent_sec) AS time_sec
        FROM question_responses qr
        JOIN questions q ON qr.question_id = q.id
        JOIN subjects s  ON q.subject_id   = s.id
        WHERE qr.attempt_id = $aid
        GROUP BY s.id
    ");
    $subject_stats = [];
    while ($s = $sub_result->fetch_assoc()) $subject_stats[] = $s;

    // Chapter accuracy
    $ch_result = db()->query("
        SELECT c.name AS chapter,
               COUNT(q.id) AS total,
               SUM(qr.is_correct=1) AS correct,
               ROUND(SUM(qr.is_correct=1)/COUNT(q.id)*100, 1) AS accuracy
        FROM question_responses qr
        JOIN questions q ON qr.question_id = q.id
        LEFT JOIN chapters c ON q.chapter_id = c.id
        WHERE qr.attempt_id = $aid
        GROUP BY c.id
        ORDER BY accuracy ASC
    ");
    $chapters = [];
    while ($c = $ch_result->fetch_assoc()) $chapters[] = $c;

    $weak_chapters = array_values(array_filter($chapters, fn($c) => ($c['accuracy'] ?? 100) < 50));
    $slow_questions= array_values(array_filter($responses, fn($r) =>
        !empty($r['avg_class_time']) && $r['time_spent_sec'] > $r['avg_class_time'] * 1.5
    ));

    echo json_encode([
        'attempt'       => $attempt,
        'responses'     => $responses,
        'subject_stats' => $subject_stats,
        'chapters'      => $chapters,
        'weak_chapters' => $weak_chapters,
        'slow_questions'=> $slow_questions
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── LEADERBOARD ─────────────────────────────────────
if ($action === 'leaderboard' && $method === 'GET') {
    $tid   = (int)($_GET['test_id'] ?? 0);
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $stmt  = db()->prepare("
        SELECT u.name, u.batch, ta.score, ta.correct_count, ta.wrong_count,
               ta.unattempted, ta.rank_overall,
               TIMESTAMPDIFF(MINUTE, ta.start_time, ta.submitted_at) AS time_taken_min
        FROM test_attempts ta
        JOIN users u ON ta.user_id = u.id
        WHERE ta.test_id = ? AND ta.status = 'submitted'
        ORDER BY ta.score DESC, ta.correct_count DESC
        LIMIT ?
    ");
    $stmt->bind_param('ii', $tid, $limit);
    $stmt->execute();
    $rows = $stmt->get_result();
    $board = [];
    while ($r = $rows->fetch_assoc()) $board[] = $r;
    echo json_encode(['leaderboard' => $board]);
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(['error' => 'Unknown action: ' . $action]);
