<?php
// portal/api/upload.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/docx_parser.php';

$test_id     = (int)($_POST['test_id'] ?? 0);
$upload_type = $_POST['upload_type'] ?? 'questions'; // 'questions' or 'answers'
$uploaded_by = (int)($_POST['uploaded_by'] ?? 1);

if (!$test_id) {
    echo json_encode(['error' => 'test_id required']); exit;
}
if (!isset($_FILES['docx_file']) || $_FILES['docx_file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['docx_file']['error'] ?? 'no file';
    echo json_encode(['error' => "File upload failed (code: $err)"]); exit;
}

$filename = basename($_FILES['docx_file']['name']);
$ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if ($ext !== 'docx') {
    echo json_encode(['error' => 'Only .docx files are accepted']); exit;
}

$tmp = $_FILES['docx_file']['tmp_name'];

// Log the upload
$fn_esc = db()->real_escape_string($filename);
db()->query("INSERT INTO upload_logs (filename, test_id, uploaded_by, status)
             VALUES ('$fn_esc', $test_id, $uploaded_by, 'processing')");
$log_id = db()->insert_id;

try {
    if ($upload_type === 'questions') {
        $questions = parseQuestionDocx($tmp);

        if (empty($questions)) {
            db()->query("UPDATE upload_logs SET status='failed', error_msg='No questions parsed' WHERE id=$log_id");
            echo json_encode(['error' => 'Could not parse any questions. Check file format.']); exit;
        }

        $count = 0;
        $stmt  = db()->prepare("
            INSERT INTO questions
              (test_id, q_number, question_en, question_gu,
               opt_a_en, opt_b_en, opt_c_en, opt_d_en,
               opt_a_gu, opt_b_gu, opt_c_gu, opt_d_gu)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              question_en = VALUES(question_en),
              question_gu = VALUES(question_gu)
        ");

        foreach ($questions as $q) {
            $stmt->bind_param('iissssssssss',
                $test_id,
                $q['q_number'],
                $q['question_en'], $q['question_gu'],
                $q['opt_a_en'],    $q['opt_b_en'],
                $q['opt_c_en'],    $q['opt_d_en'],
                $q['opt_a_gu'],    $q['opt_b_gu'],
                $q['opt_c_gu'],    $q['opt_d_gu']
            );
            if ($stmt->execute()) $count++;
        }

        db()->query("UPDATE upload_logs SET status='success', questions_parsed=$count WHERE id=$log_id");
        echo json_encode(['success' => true, 'questions_imported' => $count,
                          'message' => "$count questions imported successfully"]);

    } elseif ($upload_type === 'answers') {
        $answers = parseAnswerKeyDocx($tmp);

        if (empty($answers)) {
            db()->query("UPDATE upload_logs SET status='failed', error_msg='No answers parsed' WHERE id=$log_id");
            echo json_encode(['error' => 'Could not parse any answers. Check file format.']); exit;
        }

        $updated = 0;
        $stmt    = db()->prepare("UPDATE questions SET correct_answer=? WHERE test_id=? AND q_number=?");
        foreach ($answers as $q_num => $ans) {
            $stmt->bind_param('sii', $ans, $test_id, $q_num);
            $stmt->execute();
            $updated += db()->affected_rows;
        }

        db()->query("UPDATE upload_logs SET status='success', questions_parsed=$updated WHERE id=$log_id");
        echo json_encode(['success' => true, 'answers_updated' => $updated,
                          'message' => "$updated answer keys updated"]);
    }

} catch (Exception $e) {
    $msg = db()->real_escape_string($e->getMessage());
    db()->query("UPDATE upload_logs SET status='failed', error_msg='$msg' WHERE id=$log_id");
    echo json_encode(['error' => $e->getMessage()]);
}
