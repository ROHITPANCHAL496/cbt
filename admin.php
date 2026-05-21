<?php
/**
 * ExamiPortal — Admin Panel
 * liproh.com — Hostinger Shared Hosting
 */
session_start();

// ── Config (pre-filled for liproh.com) ────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'u111778052_examiuser');
define('DB_PASS', 'Sarthi@admin654321');          // ← CHANGE THIS to your actual password
define('DB_NAME', 'u111778052_examiportal');
define('API_BASE', '/portal/api');
define('SITE_URL', 'https://liproh.com/portal');

function db(): mysqli {
    static $conn;
    if (!$conn) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
        if ($conn->connect_error) die("DB Error: " . $conn->connect_error);
    }
    return $conn;
}

// ── Auth ──────────────────────────────────────────
if (!isset($_SESSION['admin'])) {
    if (($_POST['action'] ?? '') === 'login') {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $stmt  = db()->prepare("SELECT id, name, password FROM users WHERE email=? AND role='admin' AND is_active=1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && password_verify($pass, $row['password'])) {
            $_SESSION['admin'] = $row;
            header("Location: " . $_SERVER['PHP_SELF']); exit;
        }
        $loginError = "Invalid email or password.";
    }
    ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login — ExamiPortal</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:linear-gradient(135deg,#1e1b4b,#312e81);min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:white;border-radius:20px;padding:44px 40px;width:400px;box-shadow:0 24px 60px rgba(0,0,0,.4)}
.logo{font-size:26px;font-weight:800;color:#3730a3;text-align:center;margin-bottom:6px}
.logo span{color:#6366f1}.sub{text-align:center;font-size:13px;color:#9ca3af;margin-bottom:32px}
.fg{margin-bottom:18px}label{font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px}
input{width:100%;padding:11px 14px;border:2px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;outline:none;transition:border .15s}
input:focus{border-color:#4f46e5}
.btn{width:100%;padding:13px;background:#4f46e5;color:white;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;margin-top:6px;font-family:inherit}
.btn:hover{background:#3730a3}
.err{background:#fee2e2;color:#dc2626;padding:10px 14px;border-radius:8px;font-size:13px;text-align:center;margin-top:14px;border:1px solid #fecaca}
</style></head><body>
<div class="card">
  <div class="logo">exami<span>portal</span></div>
  <div class="sub">liproh.com · Admin Panel</div>
  <form method="POST">
    <input type="hidden" name="action" value="login">
    <div class="fg"><label>Email</label><input type="email" name="email" placeholder="admin@liproh.com" required autofocus></div>
    <div class="fg"><label>Password</label><input type="password" name="password" placeholder="••••••••" required></div>
    <button class="btn">Sign In →</button>
    <?php if (!empty($loginError)): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
  </form>
</div></body></html>
    <?php exit;
}

$admin = $_SESSION['admin'];
$page  = $_GET['page'] ?? 'dashboard';
$msg   = '';
$msg_type = 'success';

// ── POST Actions ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'logout') { session_destroy(); header("Location: " . $_SERVER['PHP_SELF']); exit; }

    if ($action === 'create_test') {
        $stmt = db()->prepare("INSERT INTO test_series
            (title,title_hi,title_gu,exam_type,test_type,series_name,class,batch,
             duration_min,total_marks,neg_marks,languages,start_time,end_time,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $dur  = (int)$_POST['duration_min'];
        $marks= (int)$_POST['total_marks'];
        $neg  = (float)$_POST['neg_marks'];
        $cid  = (int)$admin['id'];
        $st   = $_POST['start_time'] ?: null;
        $et   = $_POST['end_time']   ?: null;
        $stmt->bind_param('ssssssssiidsssi',
            $_POST['title'],$_POST['title_hi'],$_POST['title_gu'],
            $_POST['exam_type'],$_POST['test_type'],$_POST['series_name'],
            $_POST['class'],$_POST['batch'],
            $dur,$marks,$neg,$_POST['languages'],$st,$et,$cid
        );
        if ($stmt->execute()) {
            $msg = "✓ Test created! ID: " . db()->insert_id . ". Now upload questions via Upload DOCX.";
        } else {
            $msg = "Error: " . db()->error; $msg_type = 'danger';
        }
    }

    if ($action === 'toggle_publish') {
        $id = (int)$_POST['test_id'];
        db()->query("UPDATE test_series SET is_published=1-is_published WHERE id=$id");
        $msg = "Test status updated.";
    }

    if ($action === 'upload_docx' && isset($_FILES['docx_file'])) {
        $test_id     = (int)$_POST['test_id'];
        $upload_type = $_POST['upload_type'] ?? 'questions';

        // POST to our own upload.php API
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => 'https://liproh.com/portal/api/upload.php',
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POSTFIELDS     => [
                'test_id'     => $test_id,
                'upload_type' => $upload_type,
                'uploaded_by' => $admin['id'],
                'docx_file'   => new CURLFile(
                    $_FILES['docx_file']['tmp_name'],
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    $_FILES['docx_file']['name']
                )
            ]
        ]);
        $resp   = curl_exec($curl);
        $result = json_decode($resp, true);
        curl_close($curl);

        if (!empty($result['success'])) {
            $msg = "✓ " . ($result['message'] ?? "Upload successful");
        } else {
            $msg = "✗ " . ($result['error'] ?? "Upload failed"); $msg_type = 'danger';
        }
    }
}

// ── Stats ─────────────────────────────────────────
$stats = [];
if ($page === 'dashboard') {
    $stats['tests']     = db()->query("SELECT COUNT(*) c FROM test_series")->fetch_assoc()['c'];
    $stats['students']  = db()->query("SELECT COUNT(*) c FROM users WHERE role='student'")->fetch_assoc()['c'];
    $stats['attempts']  = db()->query("SELECT COUNT(*) c FROM test_attempts WHERE status='submitted'")->fetch_assoc()['c'];
    $stats['questions'] = db()->query("SELECT COUNT(*) c FROM questions")->fetch_assoc()['c'];
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — ExamiPortal · liproh.com</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f1f5f9;color:#0f172a;display:flex;min-height:100vh;font-size:14px}
.sb{width:230px;background:#1e1b4b;color:white;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;flex-shrink:0}
.sb-logo{padding:20px 18px 16px;font-size:18px;font-weight:800;border-bottom:1px solid rgba(255,255,255,.1)}
.sb-logo span{color:#a5b4fc}.sb-logo small{display:block;font-size:10px;color:rgba(255,255,255,.4);font-weight:400;margin-top:1px}
.sb-nav{flex:1;padding:10px 0;overflow-y:auto}
.sb-a{display:flex;align-items:center;gap:9px;padding:9px 18px;color:rgba(255,255,255,.65);font-size:13px;font-weight:600;text-decoration:none;transition:all .15s;border-left:3px solid transparent}
.sb-a:hover,.sb-a.on{color:white;background:rgba(255,255,255,.08);border-left-color:#818cf8}
.sb-icon{font-size:15px;width:18px;text-align:center}
.sb-foot{padding:14px 18px;border-top:1px solid rgba(255,255,255,.1);font-size:12px}
.sb-uname{font-weight:700;color:white;margin-bottom:6px}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
.topbar{background:white;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;flex-shrink:0}
.topbar h1{font-size:17px;font-weight:700;color:#1e293b}
.mc{flex:1;overflow-y:auto;padding:24px}
.sg{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.sc{background:white;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.07)}
.sc .sv{font-size:30px;font-weight:800;margin-top:4px}
.sc .sl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b}
.card{background:white;border-radius:12px;padding:22px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:18px}
.ct{font-size:15px;font-weight:700;margin-bottom:16px;color:#1e293b}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#f8fafc;text-align:left;padding:9px 13px;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;border-bottom:2px solid #e2e8f0;white-space:nowrap}
td{padding:9px 13px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:hover td{background:#f8fafc}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.fg.half{width:48%}.row2{display:flex;gap:14px;flex-wrap:wrap}
.fg label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.3px}
.fg input,.fg select,.fg textarea{padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;transition:border .15s;background:white;width:100%}
.fg input:focus,.fg select:focus{outline:none;border-color:#4f46e5}
.btn{padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s;text-decoration:none;display:inline-block}
.bp{background:#4f46e5;color:white}.bp:hover{background:#3730a3}
.bs{background:#16a34a;color:white}.bs:hover{background:#15803d}
.bd{background:#dc2626;color:white}.bd:hover{background:#b91c1c}
.bg{background:#e2e8f0;color:#334155}.bg:hover{background:#cbd5e1}
.sm{padding:5px 11px;font-size:11px}
.badge{padding:3px 9px;border-radius:50px;font-size:11px;font-weight:700}
.b-green{background:#dcfce7;color:#16a34a}.b-red{background:#fee2e2;color:#dc2626}
.b-blue{background:#dbeafe;color:#1d4ed8}.b-yellow{background:#fef9c3;color:#854d0e}
.alert{padding:11px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;font-weight:500}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.alert-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.upload-box{border:2px dashed #c7d2fe;border-radius:12px;padding:28px;text-align:center;background:#f5f3ff;cursor:pointer;transition:border .15s}
.upload-box:hover{border-color:#4f46e5}.upload-box input{display:none}
.upload-box label{cursor:pointer;color:#4f46e5;font-weight:700;font-size:14px}
.upload-box small{display:block;color:#9ca3af;font-size:11px;margin-top:6px}
.info-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;font-size:12px;color:#1e40af;margin-bottom:16px;line-height:1.6}
</style>
</head>
<body>

<div class="sb">
  <div class="sb-logo">exami<span>portal</span><small>liproh.com</small></div>
  <nav class="sb-nav">
    <?php
    $links = [
      'dashboard'   => ['📊','Dashboard'],
      'tests'       => ['📝','All Tests'],
      'create_test' => ['➕','Create Test'],
      'upload'      => ['📤','Upload DOCX'],
      'students'    => ['👥','Students'],
      'analytics'   => ['📈','Analytics'],
      'leaderboard' => ['🏆','Leaderboard'],
    ];
    foreach ($links as $p => [$icon, $label]):
    ?>
    <a href="?page=<?= $p ?>" class="sb-a <?= $page===$p?'on':'' ?>">
      <span class="sb-icon"><?= $icon ?></span> <?= $label ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sb-foot">
    <div class="sb-uname"><?= htmlspecialchars($admin['name']) ?></div>
    <form method="POST"><input type="hidden" name="action" value="logout">
    <button class="btn bd sm">Logout</button></form>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <h1><?= $links[$page][1] ?? ucfirst($page) ?></h1>
    <span style="font-size:12px;color:#94a3b8"><?= date('D, d M Y') ?> · liproh.com</span>
  </div>
  <div class="mc">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- ── DASHBOARD ── -->
    <?php if ($page === 'dashboard'): ?>
    <div class="sg">
      <div class="sc"><div class="sl">Tests</div><div class="sv" style="color:#4f46e5"><?= $stats['tests'] ?></div></div>
      <div class="sc"><div class="sl">Students</div><div class="sv" style="color:#16a34a"><?= $stats['students'] ?></div></div>
      <div class="sc"><div class="sl">Attempts</div><div class="sv" style="color:#0284c7"><?= $stats['attempts'] ?></div></div>
      <div class="sc"><div class="sl">Questions</div><div class="sv" style="color:#d97706"><?= $stats['questions'] ?></div></div>
    </div>
    <div class="card">
      <div class="ct">Recent Attempts</div>
      <table>
        <tr><th>Student</th><th>Test</th><th>Score</th><th>Correct</th><th>Wrong</th><th>Rank</th><th>When</th></tr>
        <?php
        $rows = db()->query("SELECT u.name, ts.title, ts.series_name, ta.score,
            ta.correct_count, ta.wrong_count, ta.rank_overall, ta.submitted_at
            FROM test_attempts ta JOIN users u ON ta.user_id=u.id
            JOIN test_series ts ON ta.test_id=ts.id
            WHERE ta.status='submitted' ORDER BY ta.submitted_at DESC LIMIT 20");
        while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
          <td><span class="badge b-blue"><?= htmlspecialchars($r['series_name']??'') ?></span> <?= htmlspecialchars(mb_substr($r['title'],0,35)) ?></td>
          <td><strong style="color:#4f46e5;font-size:15px"><?= $r['score'] ?></strong></td>
          <td style="color:#16a34a"><strong><?= $r['correct_count'] ?></strong></td>
          <td style="color:#dc2626"><?= $r['wrong_count'] ?></td>
          <td><?= $r['rank_overall'] ? '#'.$r['rank_overall'] : '—' ?></td>
          <td style="color:#94a3b8"><?= $r['submitted_at'] ? date('d M, H:i', strtotime($r['submitted_at'])) : '—' ?></td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <!-- ── ALL TESTS ── -->
    <?php elseif ($page === 'tests'): ?>
    <div class="card">
      <div class="ct">All Test Series</div>
      <table>
        <tr><th>ID</th><th>Test</th><th>Type</th><th>Duration</th><th>Questions</th><th>Status</th><th>Actions</th></tr>
        <?php
        $rows = db()->query("SELECT ts.*,
            (SELECT COUNT(*) FROM questions WHERE test_id=ts.id) qcount
            FROM test_series ts ORDER BY ts.created_at DESC");
        while ($r = $rows->fetch_assoc()):
        $typeColors = ['Minor'=>'b-blue','Major'=>'b-yellow','Full'=>'b-green','Practice'=>'b-red','Part'=>'b-yellow'];
        $tc = $typeColors[$r['test_type']] ?? 'b-blue';
        ?>
        <tr>
          <td style="color:#94a3b8"><?= $r['id'] ?></td>
          <td>
            <strong><?= htmlspecialchars(mb_substr($r['title'],0,45)) ?></strong>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px"><?= htmlspecialchars($r['series_name']??'') ?> · <?= $r['exam_type'] ?> · <?= $r['class']??'' ?></div>
          </td>
          <td><span class="badge <?= $tc ?>"><?= $r['test_type'] ?></span></td>
          <td><?= $r['duration_min'] ?> min</td>
          <td><strong><?= $r['qcount'] ?></strong></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle_publish">
              <input type="hidden" name="test_id" value="<?= $r['id'] ?>">
              <button class="btn sm <?= $r['is_published'] ? 'bs' : 'bd' ?>">
                <?= $r['is_published'] ? '✓ Live' : '✗ Draft' ?>
              </button>
            </form>
          </td>
          <td style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="?page=upload&tid=<?= $r['id'] ?>" class="btn bp sm">Upload DOCX</a>
            <a href="https://liproh.com/portal/frontend/pages/test.html?test_id=<?= $r['id'] ?>" target="_blank" class="btn bg sm">Preview</a>
          </td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <!-- ── CREATE TEST ── -->
    <?php elseif ($page === 'create_test'): ?>
    <div class="card">
      <div class="ct">Create New Test Series</div>
      <div class="info-box">
        After creating the test, go to <strong>Upload DOCX</strong> to upload your question paper and answer key.
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="create_test">
        <div class="fg"><label>Title — English *</label><input name="title" required placeholder="NEET Minor Test 01 — Physics + Chemistry"></div>
        <div class="row2">
          <div class="fg half"><label>Title — Hindi</label><input name="title_hi" placeholder="NEET माइनर टेस्ट 01"></div>
          <div class="fg half"><label>Title — Gujarati</label><input name="title_gu" placeholder="NEET માઈનર ટેસ્ટ 01"></div>
        </div>
        <div class="row2">
          <div class="fg half"><label>Exam Type</label>
            <select name="exam_type"><option>NEET</option><option>JEE</option><option>Foundation</option></select></div>
          <div class="fg half"><label>Test Type</label>
            <select name="test_type"><option>Minor</option><option>Major</option><option>Part</option><option>Full</option><option>Practice</option></select></div>
        </div>
        <div class="row2">
          <div class="fg half"><label>Series Name</label><input name="series_name" placeholder="MT-01"></div>
          <div class="fg half"><label>Class / Target</label><input name="class" placeholder="XI / XII / Dropper"></div>
        </div>
        <div class="row2">
          <div class="fg half"><label>Batch</label><input name="batch" placeholder="2025-26 Lakshay Batch"></div>
          <div class="fg half"><label>Languages</label>
            <select name="languages">
              <option value="en,hi,gu">English + Hindi + Gujarati</option>
              <option value="en">English Only</option>
              <option value="en,hi">English + Hindi</option>
              <option value="en,gu">English + Gujarati</option>
            </select></div>
        </div>
        <div class="row2">
          <div class="fg half"><label>Duration (minutes)</label><input name="duration_min" type="number" value="180"></div>
          <div class="fg half"><label>Total Marks</label><input name="total_marks" type="number" value="720"></div>
        </div>
        <div class="row2">
          <div class="fg half"><label>Negative Marks (per wrong)</label><input name="neg_marks" type="number" step="0.25" value="1.00"></div>
        </div>
        <div class="row2">
          <div class="fg half"><label>Start Date &amp; Time</label><input name="start_time" type="datetime-local"></div>
          <div class="fg half"><label>End Date &amp; Time</label><input name="end_time" type="datetime-local"></div>
        </div>
        <button class="btn bp" style="margin-top:8px">Create Test →</button>
      </form>
    </div>

    <!-- ── UPLOAD DOCX ── -->
    <?php elseif ($page === 'upload'): ?>
    <div class="card">
      <div class="ct">Upload Question Paper (.docx)</div>
      <div class="info-box">
        <strong>Supported formats:</strong><br>
        • <strong>Bilingual table</strong>: English + Gujarati side-by-side in a table (like your sample files)<br>
        • <strong>Single language</strong>: Questions as numbered paragraphs<br>
        Options can be <code>(1)(2)(3)(4)</code> or <code>(A)(B)(C)(D)</code><br>
        Upload <strong>questions first</strong>, then the <strong>answer key</strong> separately.
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_docx">
        <div class="row2">
          <div class="fg half">
            <label>Select Test *</label>
            <select name="test_id" required>
              <option value="">— Choose a test —</option>
              <?php
              $pre = $_GET['tid'] ?? 0;
              $tests = db()->query("SELECT id, title, series_name FROM test_series ORDER BY created_at DESC");
              while ($t = $tests->fetch_assoc()): ?>
              <option value="<?= $t['id'] ?>" <?= $pre==$t['id']?'selected':'' ?>>
                [<?= htmlspecialchars($t['series_name']??'') ?>] <?= htmlspecialchars(mb_substr($t['title'],0,50)) ?>
              </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="fg half">
            <label>Upload Type *</label>
            <select name="upload_type">
              <option value="questions">📄 Question Paper</option>
              <option value="answers">🔑 Answer Key</option>
            </select>
          </div>
        </div>
        <div class="upload-box" onclick="document.getElementById('dfile').click()">
          <input type="file" id="dfile" name="docx_file" accept=".docx"
                 onchange="document.getElementById('flabel').textContent=this.files[0].name">
          <label for="dfile">📄 <span id="flabel">Click here to choose .docx file</span></label>
          <small>Only .docx files · Max 20MB</small>
        </div>
        <button class="btn bs" style="margin-top:14px">Upload &amp; Parse →</button>
      </form>
    </div>

    <div class="card">
      <div class="ct">Upload History</div>
      <table>
        <tr><th>File</th><th>Test</th><th>Status</th><th>Parsed</th><th>Error</th><th>Time</th></tr>
        <?php
        $logs = db()->query("SELECT ul.*, ts.title, ts.series_name
            FROM upload_logs ul LEFT JOIN test_series ts ON ul.test_id=ts.id
            ORDER BY ul.uploaded_at DESC LIMIT 30");
        while ($l = $logs->fetch_assoc()): ?>
        <tr>
          <td style="font-size:12px"><?= htmlspecialchars(mb_substr($l['filename']??'—',0,30)) ?></td>
          <td style="font-size:12px"><?= htmlspecialchars($l['series_name']??'') ?></td>
          <td><span class="badge <?= $l['status']==='success'?'b-green':'b-red' ?>"><?= $l['status'] ?></span></td>
          <td><strong><?= $l['questions_parsed'] ?></strong></td>
          <td style="font-size:11px;color:#dc2626"><?= htmlspecialchars(mb_substr($l['error_msg']??'',0,40)) ?></td>
          <td style="color:#94a3b8;font-size:11px"><?= date('d M, H:i', strtotime($l['uploaded_at'])) ?></td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <!-- ── STUDENTS ── -->
    <?php elseif ($page === 'students'): ?>
    <div class="card">
      <div class="ct">Registered Students</div>
      <table>
        <tr><th>Name</th><th>Email</th><th>Phone</th><th>Exam</th><th>Batch</th><th>Tests</th><th>Avg Score</th><th>Joined</th></tr>
        <?php
        $rows = db()->query("SELECT u.*,
            (SELECT COUNT(*) FROM test_attempts WHERE user_id=u.id AND status='submitted') attempts,
            (SELECT ROUND(AVG(score),0) FROM test_attempts WHERE user_id=u.id AND status='submitted') avg_score
            FROM users u WHERE u.role='student' ORDER BY u.created_at DESC LIMIT 100");
        while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
          <td style="font-size:12px;color:#64748b"><?= htmlspecialchars($r['email']) ?></td>
          <td style="font-size:12px"><?= htmlspecialchars($r['phone']??'—') ?></td>
          <td><span class="badge b-blue"><?= $r['exam_target'] ?></span></td>
          <td style="font-size:12px"><?= htmlspecialchars($r['batch']??'—') ?></td>
          <td><strong><?= $r['attempts'] ?></strong></td>
          <td><?= $r['avg_score'] ?? '—' ?></td>
          <td style="color:#94a3b8;font-size:11px"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <!-- ── ANALYTICS ── -->
    <?php elseif ($page === 'analytics'): ?>
    <div class="card">
      <div class="ct">Test-wise Performance Summary</div>
      <table>
        <tr><th>Test</th><th>Attempts</th><th>Avg Score</th><th>Top Score</th><th>Avg Correct</th><th>Pass %</th></tr>
        <?php
        $rows = db()->query("SELECT ts.title, ts.series_name, ts.total_marks,
            COUNT(ta.id) attempts,
            ROUND(AVG(ta.score),1) avg_score,
            MAX(ta.score) top_score,
            ROUND(AVG(ta.correct_count),1) avg_correct,
            ROUND(SUM(ta.score >= ts.total_marks*0.5)/COUNT(ta.id)*100,1) pass_pct
            FROM test_series ts
            LEFT JOIN test_attempts ta ON ts.id=ta.test_id AND ta.status='submitted'
            GROUP BY ts.id ORDER BY ts.created_at DESC");
        while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars(mb_substr($r['title'],0,40)) ?></strong>
            <div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($r['series_name']??'') ?></div>
          </td>
          <td><strong><?= $r['attempts'] ?></strong></td>
          <td><strong style="color:#4f46e5"><?= $r['avg_score']??'—' ?></strong></td>
          <td style="color:#16a34a"><strong><?= $r['top_score']??'—' ?></strong></td>
          <td><?= $r['avg_correct']??'—' ?></td>
          <td><?= $r['pass_pct']??'—' ?>%</td>
        </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <!-- ── LEADERBOARD ── -->
    <?php elseif ($page === 'leaderboard'): ?>
    <div class="card">
      <div class="ct">All India Leaderboard</div>
      <table>
        <tr><th>Rank</th><th>Student</th><th>Test</th><th>Score</th><th>Correct</th><th>Wrong</th><th>Time</th></tr>
        <?php
        $rows = db()->query("SELECT u.name, u.batch, ts.series_name, ts.total_marks,
            ta.score, ta.correct_count, ta.wrong_count,
            TIMESTAMPDIFF(MINUTE, ta.start_time, ta.submitted_at) time_min
            FROM test_attempts ta
            JOIN users u ON ta.user_id=u.id JOIN test_series ts ON ta.test_id=ts.id
            WHERE ta.status='submitted' ORDER BY ta.score DESC, ta.correct_count DESC LIMIT 100");
        $rank = 1;
        while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td>
            <?php if ($rank<=3): ?>
            <span style="font-size:18px"><?= ['🥇','🥈','🥉'][$rank-1] ?></span>
            <?php else: ?><strong>#<?= $rank ?></strong><?php endif; ?>
          </td>
          <td><strong><?= htmlspecialchars($r['name']) ?></strong>
            <?php if ($r['batch']): ?><div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($r['batch']) ?></div><?php endif; ?>
          </td>
          <td><span class="badge b-blue"><?= htmlspecialchars($r['series_name']??'') ?></span></td>
          <td><strong style="font-size:16px;color:#4f46e5"><?= $r['score'] ?></strong><span style="color:#94a3b8;font-size:11px">/<?= $r['total_marks'] ?></span></td>
          <td style="color:#16a34a"><strong><?= $r['correct_count'] ?></strong></td>
          <td style="color:#dc2626"><?= $r['wrong_count'] ?></td>
          <td style="color:#64748b"><?= $r['time_min'] ?> min</td>
        </tr>
        <?php $rank++; endwhile; ?>
      </table>
    </div>
    <?php endif; ?>

  </div>
</div>
</body></html>
