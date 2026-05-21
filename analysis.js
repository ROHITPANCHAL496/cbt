/**
 * ExamiPortal — Analysis Page JS
 * Renders all charts and detailed stats
 */

const API_BASE = "https://liproh.com/portal/api";
const attemptId = new URLSearchParams(location.search).get("attempt_id");
let analyticsData = null;
let allResponses = [];

window.addEventListener("load", async () => {
  if (!attemptId) { alert("No attempt ID found."); return; }
  await loadAnalytics();
});

async function loadAnalytics() {
  try {
    const res = await fetch(`${API_BASE}/attempt.php?action=analytics`${API_BASE}/api/analytics/attempt/${attemptId}`attempt_id=${attemptId}`);
    analyticsData = await res.json();
    allResponses = analyticsData.responses || [];
    renderAll();
  } catch (err) {
    // Fallback: use local storage
    const local = JSON.parse(localStorage.getItem("last_attempt") || "{}");
    if (local.responses) {
      renderLocalAnalytics(local);
    } else {
      document.getElementById("page-content").innerHTML =
        '<div style="text-align:center;padding:60px;color:#6b7280">Could not load analytics. Please check your connection.</div>';
    }
  }
}

function renderAll() {
  const { attempt, responses, subject_stats, chapters, weak_chapters, slow_questions } = analyticsData;

  // Header
  document.getElementById("header-test-title").textContent = attempt?.title || "Test Analysis";

  // Score Hero
  const score = parseFloat(attempt?.score || 0);
  const maxMarks = attempt?.total_marks || 720;
  const correct = attempt?.correct_count || 0;
  const wrong = attempt?.wrong_count || 0;
  const skipped = attempt?.unattempted || 0;
  const accuracy = correct + wrong > 0 ? ((correct / (correct + wrong)) * 100).toFixed(1) : "0.0";
  const timeTaken = attempt?.time_taken_min ? `${attempt.time_taken_min}m` : "--";

  document.getElementById("total-score").textContent = score.toFixed(0);
  document.getElementById("total-max").textContent = maxMarks;
  document.getElementById("stat-correct").textContent = correct;
  document.getElementById("stat-wrong").textContent = wrong;
  document.getElementById("stat-skipped").textContent = skipped;
  document.getElementById("stat-accuracy").textContent = accuracy + "%";
  document.getElementById("stat-rank").textContent = attempt?.rank_overall ? `#${attempt.rank_overall}` : "--";
  document.getElementById("stat-percentile").textContent = attempt?.percentile ? `${attempt.percentile}%` : "--";
  document.getElementById("stat-time").textContent = timeTaken;

  renderDonut(correct, wrong, skipped);
  renderSubjectBars(subject_stats || []);
  renderSubjectBarChart(subject_stats || []);
  renderChapters(chapters || []);
  renderWeakAreas(weak_chapters || []);
  renderTimeChart(responses || []);
  renderOptionDist(responses || []);
  renderQTable(responses || []);
  renderSlowQuestions(slow_questions || []);
}

// ── Donut Chart ──────────────────────────────
function renderDonut(correct, wrong, skipped) {
  const ctx = document.getElementById("donut-chart").getContext("2d");
  new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: ["Correct", "Wrong", "Skipped"],
      datasets: [{
        data: [correct, wrong, skipped],
        backgroundColor: ["#16a34a", "#dc2626", "#d1d5db"],
        borderWidth: 0,
        hoverOffset: 4
      }]
    },
    options: {
      responsive: true,
      cutout: "70%",
      plugins: { legend: { position: "bottom", labels: { font: { family: "Nunito", size: 12 } } } }
    }
  });
  const total = correct + wrong + skipped;
  document.getElementById("donut-center").innerHTML =
    `<div style="font-size:13px;color:#6b7280">${total} Qs</div>`;
}

// ── Subject Bars ─────────────────────────────
function renderSubjectBars(subs) {
  const colors = { Physics: "#4f46e5", Chemistry: "#16a34a", Biology: "#0284c7", Mathematics: "#d97706" };
  const container = document.getElementById("subject-bars");

  subs.forEach(sub => {
    const total = sub.total_qs || 0;
    const pct = total > 0 ? Math.round((sub.correct / total) * 100) : 0;
    const color = colors[sub.subject] || "#6366f1";
    const div = document.createElement("div");
    div.className = "sub-row";
    div.innerHTML = `
      <div class="sub-row-header">
        <span class="sub-name">${sub.subject}</span>
        <span class="sub-pct" style="color:${color}">${pct}%</span>
      </div>
      <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:${color}"></div></div>
      <div class="sub-stats-row">
        <span><strong style="color:#16a34a">${sub.correct || 0}</strong> Correct</span>
        <span><strong style="color:#dc2626">${sub.wrong || 0}</strong> Wrong</span>
        <span><strong style="color:#6b7280">${total - (sub.correct||0) - (sub.wrong||0)}</strong> Skipped</span>
        <span><strong>${sub.marks || 0}</strong> Marks</span>
        <span><strong>${sub.time_sec ? Math.round(sub.time_sec/60) : 0}m</strong> spent</span>
      </div>`;
    container.appendChild(div);
  });
}

// ── Subject Bar Chart ─────────────────────────
function renderSubjectBarChart(subs) {
  const ctx = document.getElementById("subject-bar-chart").getContext("2d");
  new Chart(ctx, {
    type: "bar",
    data: {
      labels: subs.map(s => s.subject),
      datasets: [
        { label: "Correct", data: subs.map(s => s.correct || 0), backgroundColor: "#bbf7d0" },
        { label: "Wrong", data: subs.map(s => s.wrong || 0), backgroundColor: "#fecaca" },
        { label: "Skipped", data: subs.map(s => (s.total_qs||0) - (s.correct||0) - (s.wrong||0)), backgroundColor: "#e5e7eb" }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { labels: { font: { family: "Nunito", size: 11 } } } },
      scales: {
        x: { stacked: true, grid: { display: false }, ticks: { font: { family: "Nunito", size: 11 } } },
        y: { stacked: true, grid: { color: "#f3f4f6" }, ticks: { font: { family: "Nunito" } } }
      }
    }
  });
}

// ── Chapters ──────────────────────────────────
function renderChapters(chapters) {
  const list = document.getElementById("chapter-list");
  chapters.sort((a, b) => (b.accuracy || 0) - (a.accuracy || 0));
  chapters.forEach(ch => {
    const pct = parseFloat(ch.accuracy || 0);
    const color = pct >= 70 ? "#16a34a" : pct >= 40 ? "#d97706" : "#dc2626";
    const div = document.createElement("div");
    div.className = "ch-row";
    div.innerHTML = `
      <span class="ch-name">${ch.chapter || "Unknown"}</span>
      <div class="ch-bar-wrap"><div class="ch-bar-fill" style="width:${pct}%;background:${color}"></div></div>
      <span class="ch-pct" style="color:${color}">${pct}%</span>
      <span class="ch-count">${ch.correct||0}/${ch.total||0}</span>`;
    list.appendChild(div);
  });
}

// ── Weak Areas ────────────────────────────────
function renderWeakAreas(weakChapters) {
  const list = document.getElementById("weak-list");
  if (!weakChapters.length) {
    list.innerHTML = '<div style="color:#16a34a;font-size:13px;padding:8px">🎉 No weak chapters! Great performance.</div>';
    return;
  }
  weakChapters.forEach(ch => {
    const div = document.createElement("div");
    div.className = "weak-item";
    div.innerHTML = `<div class="wt">${ch.chapter || "Unknown"}</div>
      <div class="ws">Accuracy: ${ch.accuracy||0}% — ${ch.correct||0}/${ch.total||0} correct — Needs revision</div>`;
    list.appendChild(div);
  });
}

// ── Time Chart ────────────────────────────────
function renderTimeChart(responses) {
  const ctx = document.getElementById("time-chart").getContext("2d");
  const labels = responses.map(r => `Q${r.q_number}`);
  const yourTimes = responses.map(r => r.time_spent_sec || 0);
  const avgTimes = responses.map(r => Math.round(r.avg_class_time || 0));

  new Chart(ctx, {
    type: "bar",
    data: {
      labels,
      datasets: [
        {
          label: "Your Time (sec)", data: yourTimes,
          backgroundColor: responses.map(r =>
            r.avg_class_time && r.time_spent_sec > r.avg_class_time * 1.5 ? "#fca5a5" : "#c7d2fe"
          )
        },
        { label: "Class Avg (sec)", data: avgTimes, backgroundColor: "#fde68a", type: "line",
          borderColor: "#d97706", borderWidth: 2, pointRadius: 2, fill: false }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { labels: { font: { family: "Nunito", size: 11 } } } },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 20, font: { family: "Nunito", size: 9 } } },
        y: { grid: { color: "#f3f4f6" }, title: { display: true, text: "Seconds", font: { family: "Nunito" } },
             ticks: { font: { family: "Nunito" } } }
      }
    }
  });
}

// ── Slow Questions ─────────────────────────────
function renderSlowQuestions(slowQs) {
  if (!slowQs.length) return;
  const box = document.getElementById("slow-qs-box");
  const list = document.getElementById("slow-qs-list");
  box.style.display = "block";
  slowQs.forEach(sq => {
    const div = document.createElement("div");
    div.className = "slow-q-item";
    div.textContent = `Q${sq.q_number} — You took ${sq.time_spent_sec}s (Class avg: ${Math.round(sq.avg_class_time)}s)`;
    list.appendChild(div);
  });
}

// ── Option Distribution ────────────────────────
function renderOptionDist(responses) {
  const container = document.getElementById("option-dist");
  const wrong = responses.filter(r => r.is_correct === 0);
  if (!wrong.length) {
    container.innerHTML = '<div style="color:#16a34a;font-size:13px;padding:8px">All attempted questions answered correctly!</div>';
    return;
  }
  wrong.slice(0, 20).forEach(r => {
    const div = document.createElement("div");
    div.className = "od-row";
    const isW = r.selected_option && r.selected_option !== r.correct_answer;
    div.innerHTML = `
      <span class="od-qnum">${r.q_number}</span>
      <span class="od-label">You:</span>
      <span class="od-opt od-your ${isW ? 'wrong' : ''}">${r.selected_option || "—"}</span>
      <span class="od-label">Correct:</span>
      <span class="od-opt od-correct">${r.correct_answer || "—"}</span>
      <span style="margin-left:auto;font-size:11px;color:#6b7280">${r.subject_name||""}</span>`;
    container.appendChild(div);
  });
}

// ── Question Review Table ──────────────────────
function renderQTable(responses) {
  const body = document.getElementById("q-review-body");
  body.innerHTML = "";
  responses.forEach(r => {
    const status = r.is_correct === 1 ? "correct" : r.selected_option ? "wrong" : "skipped";
    const isSlow = r.avg_class_time && r.time_spent_sec > r.avg_class_time * 1.5;
    const div = document.createElement("div");
    div.className = `q-row q-row-data ${status}-row${isSlow ? " slow-row" : ""}`;
    div.dataset.status = status;
    div.dataset.slow = isSlow ? "1" : "0";
    div.innerHTML = `
      <div>${r.q_number}</div>
      <div style="font-size:11px">${r.subject_name||""}</div>
      <div style="font-size:11px;color:#6b7280">${(r.chapter_name||"").substring(0,20)}</div>
      <div><span class="od-opt od-your ${status === 'wrong' ? 'wrong' : status === 'correct' ? 'correct' : ''}">${r.selected_option||"—"}</span></div>
      <div><span class="od-opt od-correct">${r.correct_answer||"—"}</span></div>
      <div><span class="${r.marks_earned > 0 ? 'marks-pos' : r.marks_earned < 0 ? 'marks-neg' : ''}">${r.marks_earned || 0}</span></div>
      <div class="${isSlow ? 'time-over' : ''}">${r.time_spent_sec||0}s</div>
      <div>${r.avg_class_time ? Math.round(r.avg_class_time)+'s' : '—'}</div>
      <div><span class="badge ${status}${isSlow ? ' slow' : ''}">${isSlow ? "Slow" : status}</span></div>`;
    body.appendChild(div);
  });
}

function filterQs(type, btn) {
  document.querySelectorAll(".filter-btn").forEach(b => b.classList.remove("active"));
  btn.classList.add("active");
  document.querySelectorAll(".q-row-data").forEach(row => {
    if (type === "all") row.style.display = "";
    else if (type === "slow") row.style.display = row.dataset.slow === "1" ? "" : "none";
    else row.style.display = row.dataset.status === type ? "" : "none";
  });
}

// ── Offline/Local fallback ─────────────────────
function renderLocalAnalytics(local) {
  const { responses, questions } = local;
  const respArr = Object.entries(responses).map(([qId, resp]) => {
    const q = questions.find(q => q.id == qId) || {};
    return { ...resp, ...q };
  });
  let correct = 0, wrong = 0, skipped = 0;
  respArr.forEach(r => {
    if (!r.option) skipped++;
    else if (r.option === r.correct_answer) correct++;
    else wrong++;
  });
  document.getElementById("total-score").textContent = correct * 4 - wrong;
  document.getElementById("stat-correct").textContent = correct;
  document.getElementById("stat-wrong").textContent = wrong;
  document.getElementById("stat-skipped").textContent = skipped;
  renderDonut(correct, wrong, skipped);
}
