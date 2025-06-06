<div class="wrap" style="font-family:Arial, sans-serif; padding:20px; max-width:800px;">
  <h2 style="margin-bottom:10px;">🧠 Text Auto Extractor</h2>
  <p style="margin-bottom:20px;">Choose whether to enable automatic text extraction, and run manual extractors below.</p>

  <div style="margin-bottom:20px;">
    <button id="toggle-extractor" class="button button-primary">⏳ Loading...</button>
    <p id="status" style="margin-top:10px;"></p>
  </div>

  <hr style="margin:30px 0;">

  <div style="margin-bottom:20px;">
    <h3>🎓 Extract LearnDash Content</h3>
    <button id="run-courses" class="button button-primary">📚 Extract Courses</button>
    <div id="courses-output" style="margin-top:10px;color:green;"></div>
  </div>

  <div style="margin-bottom:20px;">
    <h3>🧪 Extract Quizzes</h3>
    <button id="run-all-quizzes" class="button">🧩 Extract All Quiz Data</button>
    <div id="quizzes-output" style="margin-top:10px;color:blue;"></div>
  </div>
</div>

<script>
const toggleButton = document.getElementById('toggle-extractor');
const statusText = document.getElementById('status');
const extractorKey = 'enable_auto_extractor';

function updateUI() {
  const isEnabled = localStorage.getItem(extractorKey) === 'true';
  toggleButton.textContent = isEnabled ? '🛑 Disable Extractor' : '✅ Enable Extractor';
  statusText.textContent = isEnabled
    ? '✅ Texts will be automatically extracted from the site pages.'
    : '⛔ Text extraction is disabled.';
}

toggleButton.addEventListener('click', () => {
  const current = localStorage.getItem(extractorKey) === 'true';
  localStorage.setItem(extractorKey, (!current).toString());
  updateUI();
});

updateUI();

// Handle course extraction
document.getElementById('run-courses').addEventListener('click', () => {
  const output = document.getElementById('courses-output');
  output.innerHTML = '⏳ Extracting courses...';

  if (window.ldExtractor?.runCourseExtractor) {
    const result = window.ldExtractor.runCourseExtractor();
    Promise.resolve(result).then(res => {
      if (res && typeof res === 'object') {
        output.innerHTML = `
          <div>✅ Courses extracted successfully.</div>
          <ul style="margin-top:10px;">
            <li><strong>🆕 Texts:</strong> ${res.inserted || 0}</li>
            <li><strong>❌ Skipped/Empty:</strong> ${res.skipped || 0}</li>
          </ul>
        `;
      } else {
        output.textContent = '✅ Courses extracted successfully.';
      }
    }).catch(err => {
      console.error('❌ Error extracting courses:', err);
      output.textContent = '❌ Failed to extract courses.';
    });
  } else {
    output.textContent = '❌ Course extractor not available.';
  }
});

// Handle quiz extraction
document.getElementById('run-all-quizzes').addEventListener('click', () => {
  const output = document.getElementById('quizzes-output');
  output.innerHTML = '⏳ Extracting quizzes...';

  const runPart = (name, fn) => {
    if (typeof fn !== 'function') {
      output.innerHTML += `<div style="color:red;">❌ ${name} extractor not available.</div>`;
      return Promise.resolve();
    }
    return Promise.resolve(fn()).then(() => {
      output.innerHTML += `<div>✅ ${name} extracted.</div>`;
    }).catch(() => {
      output.innerHTML += `<div style="color:red;">❌ ${name} extraction failed.</div>`;
    });
  };

  Promise.resolve()
    .then(() => runPart("Quiz Titles", window.ldExtractor?.runQuizTitles))
    .then(() => runPart("Quiz Questions", window.ldExtractor?.runQuizQuestions))
    .then(() => runPart("Quiz Answers", window.ldExtractor?.runQuizAnswers))
    .then(() => {
      output.innerHTML += `<div><strong>🎉 Quiz extraction complete.</strong></div>`;
    });
});
</script>
