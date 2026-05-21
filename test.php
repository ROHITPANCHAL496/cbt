<?php
// portal/api/test.php
// Returns test details + questions for a given test_id and language
require_once __DIR__ . '/db.php';

$test_id = (int)($_GET['test_id'] ?? 0);
$lang    = in_array($_GET['lang'] ?? '', ['hi','gu']) ? $_GET['lang'] : 'en';

if (!$test_id) {
    echo json_encode(['error' => 'test_id required']); exit;
}

// Get test metadata
$test = db()->query("
    SELECT * FROM test_series
    WHERE id = $test_id AND is_published = 1
")->fetch_assoc();

if (!$test) {
    http_response_code(404);
    echo json_encode(['error' => 'Test not found or not published']); exit;
}

// Get questions in requested language
$stmt = db()->prepare("
    SELECT
        q.id, q.q_number, q.q_type, q.marks_correct, q.marks_wrong,
        q.has_image, q.image_url, q.subject_id,
        s.name AS subject_name,
        q.question_{$lang}  AS question,
        q.opt_a_{$lang}     AS opt_a,
        q.opt_b_{$lang}     AS opt_b,
        q.opt_c_{$lang}     AS opt_c,
        q.opt_d_{$lang}     AS opt_d
    FROM questions q
    LEFT JOIN subjects s ON q.subject_id = s.id
    WHERE q.test_id = ?
    ORDER BY q.q_number ASC
");
$stmt->bind_param('i', $test_id);
$stmt->execute();
$result = $stmt->get_result();

$questions = [];
while ($row = $result->fetch_assoc()) {
    // Fallback to English if translation is empty
    if (empty($row['question']) && $lang !== 'en') {
        $row['question'] = $row["question_en"] ?? '';
    }
    $questions[] = $row;
}

echo json_encode([
    'test'      => $test,
    'questions' => $questions,
    'total'     => count($questions)
], JSON_UNESCAPED_UNICODE);
