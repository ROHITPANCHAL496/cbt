<?php
/**
 * ExamiPortal - PHP Admin Panel
 * Single-file admin (split into pages via ?page=)
 * Requirements: PHP 7.4+, MySQL
 */

session_start();

// ─── Config ────────────────────────────────────────
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'examiportal');
define('API_URL', 'http://localhost:8000');
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// ─── DB Connect ─────────────────────────────────────
function db(): mysqli {
    static $conn;
    if (!$conn) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
        if ($conn->connect_error) die("DB Error: " . $conn->connect_error);
    }
    return $conn;
}

// ─── Auth ───────────────────────────────────────────
if (!isset($_SESSION['admin'])) {
    if ($_POST['action'] ?? '' === 'login') {
        $email = $_POST['email'] ?? '';
        $pass  = $_POST['password'] ?? '';
        $stmt = db()->prepare("SELECT id, name, password FROM users WHERE email=? AND role='admin'");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && password_verify($pass, $row['password'])) {
            $_SESSION['admin'] = $row;
            header("Location: admin.php");
            exit;
        }
        $loginError = "Invalid credentials.";
    }
    // Show Login
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login — ExamiPortal</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#1e1b4b;display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-card{background:white;border-radius:16px;padding:40px;width:380px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.login-logo{font-size:24px;font-weight:800;color:#3730a3;text-align:center;margin-bottom:8px}
.login-logo span{color:#a5b4fc}
.login-sub{text-align:center;font-size:13px;color:#6b7280;margin-bottom:28px}
.form-group{margin-bottom:16px}
label{font-size:13px;font-weight:600;color:#374151;display:block;margin-bottom:6px}
input{width:100%;padding:10px 14px;border:2px solid #e5e7eb;border-radius:8px;font-size:14px;font-family:inherit;transition:border .15s}
input:focus{outline:none;border-color:#4f46e5}
.btn-login{width:100%;padding:12px;background:#4f46e5;color:white;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;margin-top:8px}
.btn-login:hover{background:#3730a3}
.error{color:#dc2626;font-size:13px;text-align:center;margin-top:12px;background:#fee2e2;padding:8px;border-radius:6px}
</style>
</head>
<body>
<div class="login-card">
  <div class="login-logo">exami<span>portal</span></div>
  <div class="login-sub">Admin Control Panel</div>
  <form method="POST">
    <input type="hidden" name="action" value="login">
    <div class="form-group">
      <label>Email</label>
      <input type="email" name="email" placeholder="admin@examiportal.com" required>
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" placeholder="••••••••" required>
    </div>
    <button class="btn-login">Sign In</button>
    <?php if (isset($loginError)) echo '<div class="error">'.$loginError.'</div>'; ?>
  </form>
</div>
</body>
</html>
    <?php exit;
}

$admin = $_SESSION['admin'];
$page = $_GET['page'] ?? 'dashboard';

// ─── Handle POST Actions ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create Test Series
    if ($action === 'create_test') {
        $stmt = db()->prepare("INSERT INTO test_series
            (title, title_hi, title_gu, exam_type, test_type, series_name, class, batch,
             duration_min, total_marks, neg_marks, languages, start_time, end_time, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssssssssiidsssi',
            $_POST['title'], $_POST['title_hi'], $_POST['title_gu'],
            $_POST['exam_type'], $_POST['test_type'], $_POST['series_name'],
            $_POST['class'], $_POST['batch'],
            (int)$_POST['duration_min'], (int)$_POST['total_marks'],
            (float)$_POST['neg_marks'], $_POST['languages'],
            $_POST['start_time'], $_POST['end_time'], $admin['id']
        );
        $stmt->execute();
        $newTestId = db()->insert_id;
        $msg = "Test created! ID: $newTestId";
    }

    // Publish/Unpublish
    if ($action === 'toggle_publish') {
        $id = (int)$_POST['test_id'];
        db()->query("UPDATE test_series SET is_published = 1-is_published WHERE id=$id");
    }

    // Upload DOCX via Python API
    if ($action === 'upload_docx' && isset($_FILES['docx_file'])) {
        $test_id = (int)$_POST['test_id'];
        $upload_type = $_POST['upload_type']; // 'questions' or 'answers'
        $ch = curl_init(API_URL . "/api/upload/" . $upload_type);
        $cfile = new CURLFile($_FILES['docx_file']['tmp_name'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $_FILES['docx_file']['name']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['test_id' => $test_id, 'file' => $cfile]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $apiResp = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($apiResp, true);
        $msg = $result['success'] ? "✓ Imported " . ($result['questions_imported'] ?? $result['answers_updated']) . " records." : "Error: " . ($result['detail'] ?? "Unknown");
    }

    // Logout
    if ($action === 'logout') { session_destroy(); header("Location: admin.php"); exit; }
}

// ─── Fetch Stats ─────────────────────────────────────
$stats = [];
if ($page === 'dashboard') {
    $stats['tests']    = db()->query("SELECT COUNT(*) c FROM test_series")->fetch_assoc()['c'];
    $stats['students'] = db()->query("SELECT COUNT(*) c FROM users WHERE role='student'")->fetch_assoc()['c'];
    $stats['attempts'] = db()->query("SELECT COUNT(*) c FROM test_attempts WHERE status='submitted'")->fetch_assoc()['c'];
    $stats['questions']= db()->query("SELECT COUNT(*) c FROM questions")->fetch_assoc()['c'];
}

// ─── Layout ──────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — ExamiPortal</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Nunito,sans-serif;background:#f1f5f9;color:#0f172a;display:flex;min-height:100vh}
/* Sidebar */
.sidebar{width:240px;background:#1e1b4b;color:white;display:flex;flex-direction:column;flex-shrink:0;position:sticky;top:0;height:100vh}
.sb-logo{padding:22px 20px;font-size:20px;font-weight:800;border-bottom:1px solid rgba(255,255,255,.1)}
.sb-logo span{color:#a5b4fc}
.sb-nav{flex:1;padding:14px 0}
.sb-link{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.7);font-size:13px;font-weight:600;text-decoration:none;transition:all .15s;border-left:3px solid transparent}
.sb-link:hover,.sb-link.active{color:white;background:rgba(255,255,255,.08);border-left-color:#818cf8}
.sb-icon{font-size:16px;width:20px;text-align:center}
.sb-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.1);font-size:12px;color:rgba(255,255,255,.5)}
.sb-admin-name{font-weight:700;color:white;margin-bottom:4px;font-size:13px}
/* Main */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.topbar{background:white;padding:14px 28px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.topbar h1{font-size:18px;font-weight:700;color:#1e293b}
.main-content{flex:1;overflow-y:auto;padding:28px}
/* Cards */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-card{background:white;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.07)}
.stat-card .sval{font-size:32px;font-weight:800;margin-top:6px}
.stat-card .slabel{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b}
.stat-card .sicon{font-size:24px;margin-bottom:6px}
.card{background:white;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:20px}
.card-title{font-size:15px;font-weight:700;margin-bottom:18px;color:#1e293b}
/* Table */
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#f8fafc;text-align:left;padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;border-bottom:2px solid #e2e8f0}
td{padding:10px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:hover td{background:#f8fafc}
/* Forms */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid.three{grid-template-columns:1fr 1fr 1fr}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.3px}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;transition:border .15s;background:white}
.form-group input:focus,.form-group select:focus{outline:none;border-color:#4f46e5}
.btn{padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s}
.btn-primary{background:#4f46e5;color:white}.btn-primary:hover{background:#3730a3}
.btn-success{background:#16a34a;color:white}.btn-success:hover{background:#15803d}
.btn-danger{background:#dc2626;color:white}.btn-danger:hover{background:#b91c1c}
.btn-sm{padding:5px 12px;font-size:11px}
.badge{padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700}
.badge-green{background:#dcfce7;color:#16a34a}.badge-red{background:#fee2e2;color:#dc2626}
.badge-blue{background:#dbeafe;color:#1d4ed8}.badge-yellow{background:#fef9c3;color:#854d0e}
.alert{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;font-weight:500}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.alert-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.upload-area{border:2px dashed #c7d2fe;border-radius:12px;padding:30px;text-align:center;background:#f5f3ff;transition:border .15s}
.upload-area:hover{border-color:#4f46e5}
.upload-area input{display:none}
.upload-area label{cursor:pointer;color:#4f46e5;font-weight:700;font-size:14px}
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
  <div class="sb-logo">exami<span>portal</span><div style="font-size:10px;color:rgba(255,255,255,.4);font-weight:400;margin-top:2px">Admin Panel</div></div>
  <nav class="sb-nav">
    <a href="?page=dashboard"     class="sb-link <?= $page==='dashboard'?'active':'' ?>"><span class="sb-icon">📊</span> Dashboard</a>
    <a href="?page=tests"         class="sb-link <?= $page==='tests'?'active':'' ?>"><span class="sb-icon">📝</span> Test Series</a>
    <a href="?page=create_test"   class="sb-link <?= $page==='create_test'?'active':'' ?>"><span class="sb-icon">➕</span> Create Test</a>
    <a href="?page=upload"        class="sb-link <?= $page==='upload'?'active':'' ?>"><span class="sb-icon">📤</span> Upload DOCX</a>
    <a href="?page=students"      class="sb-link <?= $page==='students'?'active':'' ?>"><span class="sb-icon">👥</span> Students</a>
    <a href="?page=analytics"     class="sb-link <?= $page==='analytics'?'active':'' ?>"><span class="sb-icon">📈</span> Analytics</a>
    <a href="?page=leaderboard"   class="sb-link <?= $page==='leaderboard'?'active':'' ?>"><span class="sb-icon">🏆</span> Leaderboard</a>
  </nav>
  <div class="sb-footer">
    <div class="sb-admin-name"><?= htmlspecialchars($admin['name']) ?></div>
    <form method="POST" style="margin-top:8px"><input type="hidden" name="action" value="logout">
    <button class="btn btn-danger btn-sm">Logout</button></form>
  </div>
</div>

<!-- Main -->
<div class="main">
  <div class="topbar">
    <h1><?= ucfirst(str_replace('_', ' ', $page)) ?></h1>
    <div style="font-size:13px;color:#64748b"><?= date('D, d M Y') ?></div>
  </div>
  <div class="main-content">

    <?php if (isset($msg)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- ── DASHBOARD ── -->
    <?php if ($page === 'dashboard'): ?>
    <div class="stats-grid">
      <div class="stat-card"><div class="sicon">📝</div><div class="slabel">Total Tests</div><div class="sval" style="color:#4f46e5"><?= $stats['tests'] ?></div></div>
      <div class="stat-card"><div class="sicon">👥</div><div class="slabel">Students</div><div class="sval" style="color:#16a34a"><?= $stats['students'] ?></div></div>
      <div class="stat-card"><div class="sicon">✅</div><div class="slabel">Attempts</div><div class="sval" style="color:#0284c7"><?= $stats['attempts'] ?></div></div>
      <div class="stat-card"><div class="sicon">❓</div><div class="slabel">Questions</div><div class="sval" style="color:#d97706"><?= $stats['questions'] ?></div></div>
    </div>
    <div class="card">
      <div class="card-title">Recent Test Attempts</div>
      <table>
        <tr><th>Student</th><th>Test</th><th>Score</th><th>Correct</th><th>Wrong</th><th>Rank</th><th>Submitted</th></tr>
        <?php
        $rows = db()->query("SELECT u.name, ts.title, ta.score, ta.correct_count, ta.wrong_count, ta.rank_overall, ta.submitted_at
            FROM test_attempts ta JOIN users u ON ta.user_id=u.id JOIN test_series ts ON ta.test_id=ts.id
            WHERE ta.status='submitted' ORDER BY ta.submitted_at DESC LIMIT 20");
        while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
          <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['title']) ?></td>
          <td><strong><?= $r['score'] ?></strong></td>
          <td style="color:#16a34a"><strong><?= $r['correct_count'] ?></strong></td>
          <td style="color:#dc2626"><?= $r['wrong_count'] ?></td>
          <td><?= $r['rank_overall'] ? '#'.$r['rank_overall'] : '—' ?></td>
          <td style="color:#64748b;font-size:12px"><?= $r['submitted_at'] ? date('d M, H:i', strtotime($r['submitted_at'])) : '—' ?></td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <!-- ── TESTS LIST ── -->
    <?php elseif ($page === 'tests'): ?>
    <div class="card">
      <div class="card-title">All Test Series</div>
      <table>
        <tr><th>ID</th><th>Title</th><th>Type</th><th>Duration</th><th>Questions</th><th>Status</th><th>Actions</th></tr>
        <?php
        $rows = db()->query("SELECT ts.*, (SELECT COUNT(*) FROM questions WHERE test_id=ts.id) qcount
            FROM test_series ts ORDER BY ts.created_at DESC");
        while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td style="color:#64748b"><?= $r['id'] ?></td>
          <td><strong><?= htmlspecialchars($r['title']) ?></strong><br><small style="color:#64748b"><?= $r['series_name'] ?> · <?= $r['exam_type'] ?></small></td>
          <td><span class="badge badge-blue"><?= $r['test_type'] ?></span></td>
          <td><?= $r['duration_min'] ?> min</td>
          <td><?= $r['qcount'] ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle_publish">
              <input type="hidden" name="test_id" value="<?= $r['id'] ?>">
              <button class="btn btn-sm <?= $r['is_published'] ? 'btn-success' : 'btn-danger' ?>">
                <?= $r['is_published'] ? '✓ Published' : '✗ Draft' ?>
              </button>
            </form>
          </td>
          <td>
            <a href="?page=upload&test_id=<?= $r['id'] ?>" class="btn btn-primary btn-sm">Upload DOCX</a>
            <a href="../frontend/pages/analysis.html?test_id=<?= $r['id'] ?>" class="btn btn-sm" style="background:#e2e8f0;color:#334155">View Results</a>
          </td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <!-- ── CREATE TEST ── -->
    <?php elseif ($page === 'create_test'): ?>
    <div class="card">
      <div class="card-title">Create New Test Series</div>
      <form method="POST">
        <input type="hidden" name="action" value="create_test">
        <div class="form-grid">
          <div class="form-group full"><label>Title (English)</label><input name="title" required placeholder="NEET Minor Test - 01"></div>
          <div class="form-group"><label>Title (Hindi)</label><input name="title_hi" placeholder="NEET लघु परीक्षा - 01"></div>
          <div class="form-group"><label>Title (Gujarati)</label><input name="title_gu" placeholder="NEET માઈનર ટેસ્ટ - 01"></div>
          <div class="form-group"><label>Exam Type</label>
            <select name="exam_type"><option>NEET</option><option>JEE</option><option>Foundation</option></select></div>
          <div class="form-group"><label>Test Type</label>
            <select name="test_type"><option>Minor</option><option>Major</option><option>Part</option><option>Full</option><option>Practice</option></select></div>
          <div class="form-group"><label>Series Name</label><input name="series_name" placeholder="MT-01"></div>
          <div class="form-group"><label>Class</label><input name="class" placeholder="XI / XII / Dropper"></div>
          <div class="form-group"><label>Batch</label><input name="batch" placeholder="2025-26 Lakshay"></div>
          <div class="form-group"><label>Duration (minutes)</label><input name="duration_min" type="number" value="180"></div>
          <div class="form-group"><label>Total Marks</label><input name="total_marks" type="number" value="720"></div>
          <div class="form-group"><label>Negative Marks</label><input name="neg_marks" type="number" step="0.25" value="1.00"></div>
          <div class="form-group"><label>Languages</label>
            <select name="languages"><option value="en,hi,gu">English + Hindi + Gujarati</option><option value="en">English Only</option><option value="en,hi">English + Hindi</option></select></div>
          <div class="form-group"><label>Start Time</label><input name="start_time" type="datetime-local"></div>
          <div class="form-group"><label>End Time</label><input name="end_time" type="datetime-local"></div>
        </div>
        <div style="margin-top:20px"><button class="btn btn-primary">Create Test Series</button></div>
      </form>
    </div>

    <!-- ── UPLOAD DOCX ── -->
    <?php elseif ($page === 'upload'): ?>
    <div class="card">
      <div class="card-title">Upload Question Paper (.docx)</div>
      <p style="font-size:13px;color:#64748b;margin-bottom:20px">
        Supported formats: Bilingual (English + Gujarati), Single language, with sections per subject.<br>
        The parser auto-detects question numbers, options (1)(2)(3)(4) or (A)(B)(C)(D), and subjects.
      </p>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_docx">
        <div class="form-grid three">
          <div class="form-group">
            <label>Select Test</label>
            <select name="test_id">
              <?php $tests = db()->query("SELECT id, title, series_name FROM test_series ORDER BY created_at DESC");
              while ($t = $tests->fetch_assoc()): ?>
              <option value="<?= $t['id'] ?>"><?= $t['series_name'] ?> — <?= htmlspecialchars(substr($t['title'],0,40)) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Upload Type</label>
            <select name="upload_type">
              <option value="questions">Question Paper</option>
              <option value="answers">Answer Key</option>
            </select>
          </div>
        </div>
        <div class="upload-area" style="margin-top:16px">
          <input type="file" id="docx-file" name="docx_file" accept=".docx" onchange="document.getElementById('file-label').textContent=this.files[0].name">
          <label for="docx-file">📄 <span id="file-label">Click to choose .docx file</span></label>
          <div style="font-size:11px;color:#94a3b8;margin-top:8px">Only .docx format accepted</div>
        </div>
        <div style="margin-top:16px"><button class="btn btn-success">Upload & Parse</button></div>
      </form>
    </div>
    <div class="card">
      <div class="card-title">Upload History</div>
      <table>
        <tr><th>File</th><th>Test</th><th>Status</th><th>Parsed</th><th>Time</th></tr>
        <?php
        $logs = db()->query("SELECT ul.*, ts.title FROM upload_logs ul LEFT JOIN test_series ts ON ul.test_id=ts.id ORDER BY ul.uploaded_at DESC LIMIT 20");
        while ($l = $logs->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($l['filename'] ?? '—') ?></td>
          <td><?= htmlspecialchars($l['title'] ?? '—') ?></td>
          <td><span class="badge <?= $l['status']==='success'?'badge-green':'badge-red' ?>"><?= $l['status'] ?></span></td>
          <td><?= $l['questions_parsed'] ?></td>
          <td style="color:#94a3b8;font-size:12px"><?= $l['uploaded_at'] ?></td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <!-- ── STUDENTS ── -->
    <?php elseif ($page === 'students'): ?>
    <div class="card">
      <div class="card-title">Registered Students</div>
      <table>
        <tr><th>Name</th><th>Email</th><th>Exam</th><th>Batch</th><th>Tests</th><th>Avg Score</th><th>Joined</th></tr>
        <?php
        $rows = db()->query("SELECT u.*, 
            (SELECT COUNT(*) FROM test_attempts WHERE user_id=u.id AND status='submitted') attempts,
            (SELECT ROUND(AVG(score),1) FROM test_attempts WHERE user_id=u.id AND status='submitted') avg_score
            FROM users u WHERE u.role='student' ORDER BY u.created_at DESC");
        while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
          <td style="color:#64748b"><?= htmlspecialchars($r['email']) ?></td>
          <td><span class="badge badge-blue"><?= $r['exam_target'] ?></span></td>
          <td><?= htmlspecialchars($r['batch'] ?? '—') ?></td>
          <td><?= $r['attempts'] ?></td>
          <td><?= $r['avg_score'] ?? '—' ?></td>
          <td style="color:#94a3b8;font-size:12px"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <!-- ── ANALYTICS ── -->
    <?php elseif ($page === 'analytics'): ?>
    <div class="stats-grid">
      <?php
      $tests = db()->query("SELECT ts.id, ts.title, ts.series_name,
          COUNT(ta.id) total_attempts,
          ROUND(AVG(ta.score),1) avg_score,
          MAX(ta.score) top_score,
          ROUND(AVG(ta.correct_count),1) avg_correct
          FROM test_series ts LEFT JOIN test_attempts ta ON ts.id=ta.test_id AND ta.status='submitted'
          GROUP BY ts.id ORDER BY ts.created_at DESC");
      while ($t = $tests->fetch_assoc()): ?>
      <div class="stat-card" style="grid-column:span 2">
        <div class="slabel"><?= htmlspecialchars($t['series_name'] ?? '') ?></div>
        <div style="font-size:13px;font-weight:600;margin:4px 0;color:#1e293b"><?= htmlspecialchars(substr($t['title'],0,50)) ?></div>
        <div style="display:flex;gap:20px;margin-top:8px;font-size:12px">
          <div><strong style="color:#4f46e5"><?= $t['total_attempts'] ?></strong> attempts</div>
          <div><strong style="color:#16a34a"><?= $t['avg_score'] ?? '—' ?></strong> avg score</div>
          <div><strong style="color:#d97706"><?= $t['top_score'] ?? '—' ?></strong> top score</div>
          <div><strong><?= $t['avg_correct'] ?? '—' ?></strong> avg correct</div>
        </div>
      </div>
      <?php endwhile; ?>
    </div>

    <!-- ── LEADERBOARD ── -->
    <?php elseif ($page === 'leaderboard'): ?>
    <div class="card">
      <div class="card-title">All Tests Leaderboard</div>
      <table>
        <tr><th>#</th><th>Student</th><th>Test</th><th>Score</th><th>Correct</th><th>Wrong</th><th>Time</th></tr>
        <?php
        $rows = db()->query("SELECT u.name, u.batch, ts.title, ts.series_name,
            ta.score, ta.correct_count, ta.wrong_count, ta.rank_overall,
            TIMESTAMPDIFF(MINUTE, ta.start_time, ta.submitted_at) time_min
            FROM test_attempts ta
            JOIN users u ON ta.user_id=u.id JOIN test_series ts ON ta.test_id=ts.id
            WHERE ta.status='submitted' ORDER BY ta.score DESC, ta.correct_count DESC LIMIT 50");
        $rank = 1;
        while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><strong><?= $rank++ ?></strong></td>
          <td><strong><?= htmlspecialchars($r['name']) ?></strong><br><small style="color:#94a3b8"><?= htmlspecialchars($r['batch']??'') ?></small></td>
          <td><?= htmlspecialchars($r['series_name']??'') ?></td>
          <td><strong style="font-size:16px;color:#4f46e5"><?= $r['score'] ?></strong></td>
          <td style="color:#16a34a"><strong><?= $r['correct_count'] ?></strong></td>
          <td style="color:#dc2626"><?= $r['wrong_count'] ?></td>
          <td style="color:#64748b"><?= $r['time_min'] ?> min</td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
