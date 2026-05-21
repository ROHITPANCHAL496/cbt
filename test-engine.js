/**
 * ExamiPortal — Test Engine
 * Handles: loading, navigation, timing, saving, submitting
 */

const API_BASE = "https://liproh.com/portal/api";

// ─── STATE ───────────────────────────────────────
const state = {
  testId: new URLSearchParams(location.search).get("test_id") || 1,
  userId: parseInt(localStorage.getItem("user_id")) || 1,
  attemptId: null,
  language: "en",
  test: null,
  questions: [],
  currentIndex: 0,
  currentSubject: null,
  subjects: [],          // unique subjects in order
  responses: {},         // { q_id: { option, status, timeSpent, marked } }
  timerSeconds: 0,
  timerInterval: null,
  questionStartTime: null,
  savingQueue: [],
  showSecondaryLang: false,
};

// Status enum
const STATUS = {
  NOT_VISITED: "not-visited",
  NOT_ANSWERED: "not-answered",
  ANSWERED: "answered",
  MARKED: "marked",
  ANSWERED_MARKED: "answered-marked"
};

// ─── INIT ─────────────────────────────────────────
window.addEventListener("load", async () => {
  setMsg("Loading test details...");
  document.getElementById("agree-check").addEventListener("change", e => {
    document.getElementById("btn-start").disabled = !e.target.checked;
  });
  await loadTest();
});

async function loadTest() {
  try {
    const res = await fetch(`${API_BASE}/test.php?test_id=${state.testId}&lang=${state.language}`);
    const data = await res.json();
    state.test = data.test;
    state.questions = data.questions;
    state.timerSeconds = (data.test.duration_min || 180) * 60;

    // Init responses
    state.questions.forEach(q => {
      state.responses[q.id] = {
        option: null,
        status: STATUS.NOT_VISITED,
        timeSpent: 0,
        marked: false
      };
    });

    // Extract subjects in order
    const seen = new Set();
    state.questions.forEach(q => {
      if (q.subject_name && !seen.has(q.subject_name)) {
        seen.add(q.subject_name);
        state.subjects.push(q.subject_name);
      }
    });

    showInstructions();
  } catch (err) {
    setMsg("Failed to load test. Check your connection.");
    console.error(err);
  }
}

function showInstructions() {
  document.getElementById("loading-screen").classList.add("hidden");
  document.getElementById("instructions-screen").classList.remove("hidden");
  document.getElementById("test-title").textContent = state.test.title || "NEET Mock Test";
  document.getElementById("inst-duration").textContent = state.test.duration_min || 180;
  document.getElementById("inst-questions").textContent = state.questions.length;
  document.getElementById("inst-marks").textContent = state.test.total_marks || 720;
}

async function startTest() {
  setMsg("Starting test...");
  document.getElementById("instructions-screen").classList.add("hidden");
  document.getElementById("loading-screen").classList.remove("hidden");

  try {
    const res = await fetch(`${API_BASE}/attempt.php?action=start`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ user_id: state.userId, test_id: parseInt(state.testId), language: state.language })
    });
    const data = await res.json();
    state.attemptId = data.attempt_id;
  } catch {
    // Offline mode - use local attempt
    state.attemptId = Date.now();
  }

  buildTestUI();
  loadQuestion(0);
  startTimer();

  document.getElementById("loading-screen").classList.add("hidden");
  document.getElementById("test-screen").classList.remove("hidden");
}

// ─── BUILD UI ─────────────────────────────────────
function buildTestUI() {
  const user = JSON.parse(localStorage.getItem("user_data") || "{}");
  document.getElementById("student-name").textContent = user.name || "Student";
  document.getElementById("student-roll").textContent = user.roll || "Roll #001";
  document.getElementById("student-avatar").textContent = (user.name || "S")[0].toUpperCase();
  document.getElementById("test-name-bar").textContent = state.test.title || "";

  // Subject tabs
  const tabsEl = document.getElementById("subject-tabs");
  state.subjects.forEach((sub, i) => {
    const tab = document.createElement("div");
    tab.className = "subject-tab" + (i === 0 ? " active" : "");
    tab.textContent = sub;
    tab.dataset.subject = sub;
    tab.onclick = () => switchToSubject(sub);
    tabsEl.appendChild(tab);
  });

  buildQGrid();
  updateStats();
}

function buildQGrid() {
  const grid = document.getElementById("q-grid");
  grid.innerHTML = "";
  state.questions.forEach((q, i) => {
    const btn = document.createElement("button");
    btn.className = `q-btn ${state.responses[q.id].status}`;
    btn.textContent = q.q_number || (i + 1);
    btn.dataset.idx = i;
    btn.onclick = () => { recordTime(); loadQuestion(i); };
    grid.appendChild(btn);
  });
}

// ─── QUESTION LOADING ─────────────────────────────
function loadQuestion(idx) {
  if (idx < 0 || idx >= state.questions.length) return;

  // Mark previous as visited
  if (state.currentIndex !== idx && state.questions[state.currentIndex]) {
    const prev = state.questions[state.currentIndex];
    if (state.responses[prev.id].status === STATUS.NOT_VISITED) {
      state.responses[prev.id].status = STATUS.NOT_ANSWERED;
    }
  }

  state.currentIndex = idx;
  state.questionStartTime = Date.now();
  const q = state.questions[idx];
  const resp = state.responses[q.id];

  // Mark as at least visited
  if (resp.status === STATUS.NOT_VISITED) {
    resp.status = STATUS.NOT_ANSWERED;
  }

  // Display question
  const langKey = state.language === "en" ? "" : `_${state.language}`;
  document.getElementById("q-current-num").textContent = q.q_number || (idx + 1);
  document.getElementById("q-marks-correct").textContent = q.marks_correct || 4;
  document.getElementById("q-marks-wrong").textContent = q.marks_wrong || 1;
  document.getElementById("q-text-primary").innerHTML = q.question || "(Question text)";

  const secLang = q[`question_${state.language === "en" ? "gu" : "en"}`] || "";
  document.getElementById("q-text-secondary").innerHTML = secLang;
  document.getElementById("q-text-secondary").classList.toggle("hidden", !state.showSecondaryLang || !secLang);

  // Options
  ["A", "B", "C", "D"].forEach(opt => {
    const el = document.getElementById(`opt-text-${opt}`);
    const key = `opt_${opt.toLowerCase()}_${state.language}`;
    el.innerHTML = q[key] || q[`opt_${opt.toLowerCase()}_en`] || "";
    const item = document.getElementById(`opt-${opt}`);
    item.classList.toggle("selected", resp.option === opt);
  });

  // Image
  const imgBox = document.getElementById("q-image");
  if (q.has_image && q.image_url) {
    document.getElementById("q-img-el").src = q.image_url;
    imgBox.classList.remove("hidden");
  } else {
    imgBox.classList.add("hidden");
  }

  updateQGrid();
  updateSubjectTab(q);
  updateStats();
}

function updateSubjectTab(q) {
  const subName = q.subject_name || state.subjects[0];
  document.querySelectorAll(".subject-tab").forEach(t => {
    t.classList.toggle("active", t.dataset.subject === subName);
  });
  document.getElementById("nav-subject-label").textContent = subName || "";
}

function switchToSubject(sub) {
  const idx = state.questions.findIndex(q => q.subject_name === sub);
  if (idx >= 0) { recordTime(); loadQuestion(idx); }
}

// ─── ANSWER SELECTION ─────────────────────────────
function selectOption(opt) {
  const q = state.questions[state.currentIndex];
  const resp = state.responses[q.id];
  resp.option = (resp.option === opt) ? null : opt; // toggle

  if (resp.option) {
    resp.status = resp.marked ? STATUS.ANSWERED_MARKED : STATUS.ANSWERED;
  } else {
    resp.status = resp.marked ? STATUS.MARKED : STATUS.NOT_ANSWERED;
  }

  ["A","B","C","D"].forEach(o => {
    document.getElementById(`opt-${o}`).classList.toggle("selected", resp.option === o);
  });
  updateQGrid();
  updateStats();
  autoSave(q.id);
}

function clearResponse() {
  const q = state.questions[state.currentIndex];
  const resp = state.responses[q.id];
  resp.option = null;
  resp.status = resp.marked ? STATUS.MARKED : STATUS.NOT_ANSWERED;
  ["A","B","C","D"].forEach(o => document.getElementById(`opt-${o}`).classList.remove("selected"));
  updateQGrid();
  updateStats();
  autoSave(q.id);
}

function markForReview() {
  const q = state.questions[state.currentIndex];
  const resp = state.responses[q.id];
  resp.marked = true;
  resp.status = resp.option ? STATUS.ANSWERED_MARKED : STATUS.MARKED;
  autoSave(q.id);
  recordTime();
  goNext();
}

function saveAndNext() {
  const q = state.questions[state.currentIndex];
  const resp = state.responses[q.id];
  if (!resp.option) resp.status = STATUS.NOT_ANSWERED;
  autoSave(q.id);
  recordTime();
  goNext();
}

function goNext() {
  if (state.currentIndex < state.questions.length - 1) {
    loadQuestion(state.currentIndex + 1);
  }
}

// ─── AUTO SAVE ────────────────────────────────────
async function autoSave(qId) {
  const q = state.questions.find(x => x.id === qId);
  const resp = state.responses[qId];
  if (!state.attemptId) return;

  try {
    await fetch(`${API_BASE}/attempt.php?action=answer`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        attempt_id: state.attemptId,
        question_id: qId,
        selected_option: resp.option,
        time_spent_sec: resp.timeSpent || 0,
        is_marked_review: resp.marked
      })
    });
  } catch {
    // Queue for retry
    state.savingQueue.push({ qId, resp });
  }
}

// ─── TIME TRACKING ────────────────────────────────
function recordTime() {
  const q = state.questions[state.currentIndex];
  if (!q || !state.questionStartTime) return;
  const elapsed = Math.round((Date.now() - state.questionStartTime) / 1000);
  state.responses[q.id].timeSpent = (state.responses[q.id].timeSpent || 0) + elapsed;
  state.questionStartTime = Date.now();
}

// ─── TIMER ────────────────────────────────────────
function startTimer() {
  state.timerInterval = setInterval(() => {
    state.timerSeconds--;
    updateTimerDisplay();

    if (state.timerSeconds <= 0) {
      clearInterval(state.timerInterval);
      submitTest(true);
    }
  }, 1000);
}

function updateTimerDisplay() {
  const h = Math.floor(state.timerSeconds / 3600);
  const m = Math.floor((state.timerSeconds % 3600) / 60);
  const s = state.timerSeconds % 60;
  document.getElementById("timer-display").textContent =
    `${pad(h)}:${pad(m)}:${pad(s)}`;

  const box = document.getElementById("timer-box");
  box.classList.remove("warning", "danger");
  if (state.timerSeconds < 300) box.classList.add("danger");
  else if (state.timerSeconds < 900) box.classList.add("warning");
}

function pad(n) { return String(n).padStart(2, "0"); }

// ─── UI UPDATES ───────────────────────────────────
function updateQGrid() {
  state.questions.forEach((q, i) => {
    const btn = document.querySelector(`[data-idx="${i}"]`);
    if (!btn) return;
    const resp = state.responses[q.id];
    btn.className = `q-btn ${resp.status}`;
    if (i === state.currentIndex) btn.classList.add("current");
  });
}

function updateStats() {
  const vals = Object.values(state.responses);
  const ans   = vals.filter(r => r.status === STATUS.ANSWERED || r.status === STATUS.ANSWERED_MARKED).length;
  const notAns= vals.filter(r => r.status === STATUS.NOT_ANSWERED).length;
  const marked= vals.filter(r => r.status === STATUS.MARKED || r.status === STATUS.ANSWERED_MARKED).length;
  const notVis= vals.filter(r => r.status === STATUS.NOT_VISITED).length;

  document.getElementById("stat-answered").textContent = ans;
  document.getElementById("stat-not-answered").textContent = notAns;
  document.getElementById("stat-marked").textContent = marked;
  document.getElementById("stat-not-visited").textContent = notVis;

  document.getElementById("m-answered").textContent = ans;
  document.getElementById("m-not-answered").textContent = notAns;
  document.getElementById("m-marked").textContent = marked;
  document.getElementById("m-not-visited").textContent = notVis;
}

// ─── LANGUAGE TOGGLE ──────────────────────────────
function changeLanguage(lang) {
  state.language = lang;
  const btnToggle = document.getElementById("btn-lang-toggle");
  btnToggle.textContent = lang === "en" ? "View in Gujarati" : "View in English";
  loadQuestion(state.currentIndex);
}

function toggleLangPanel() {
  state.showSecondaryLang = !state.showSecondaryLang;
  document.getElementById("q-text-secondary").classList.toggle("hidden", !state.showSecondaryLang);
  document.getElementById("btn-lang-toggle").textContent = state.showSecondaryLang ? "Hide Translation" : "View Translation";
}

// ─── SUBMIT ───────────────────────────────────────
function confirmSubmit() {
  recordTime();
  updateStats();
  document.getElementById("submit-modal").classList.remove("hidden");
}

function closeModal() {
  document.getElementById("submit-modal").classList.add("hidden");
}

async function submitTest(autoSubmit = false) {
  if (state.timerInterval) clearInterval(state.timerInterval);
  recordTime();

  // Save all pending responses
  for (const q of state.questions) {
    await autoSave(q.id);
  }

  try {
    const res = await fetch(`${API_BASE}/attempt.php?action=submit`, { method: "POST" });
    const result = await res.json();
    window.location.href = `analysis.html?attempt_id=${state.attemptId}`;
  } catch {
    // Fallback: save to local storage and go to local analysis
    localStorage.setItem("last_attempt", JSON.stringify({
      attemptId: state.attemptId,
      responses: state.responses,
      questions: state.questions
    }));
    window.location.href = `analysis.html?attempt_id=${state.attemptId}&offline=1`;
  }
}

function setMsg(msg) {
  document.getElementById("loader-msg").textContent = msg;
}
