<?php
if (is_admin()) {
    add_action('admin_footer', 'inject_course_text_extractor');
    add_action('admin_footer', 'inject_manual_quiz_extractors');
}

// For Courses
function inject_course_text_extractor() {
    ?>
    <script>
    window.ldExtractor = window.ldExtractor || {};

    (function(ld) {
        async function sha256(text) {
            const buffer = new TextEncoder().encode(text);
            const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
            return Array.from(new Uint8Array(hashBuffer))
                .map(b => b.toString(16).padStart(2, '0')).join('');
        }

        function containsEnglish(text) {
            if (/^https?:\/\/[^\s]+$/.test(text.trim())) {
                return false;
            }
            return /[A-Za-z]/.test(text);
        }

        function extractTextFromHTML(html) {
            const container = document.createElement('div');
            container.innerHTML = html;
            const forbiddenTags = ['SCRIPT','STYLE','NOSCRIPT','IFRAME','META','LINK'];
            const texts = new Set();

            function traverse(node) {
                if (node.nodeType === Node.TEXT_NODE) {
                    const text = node.textContent.trim();
                    if (text.length > 0 && containsEnglish(text)) {
                        texts.add(text);
                    }
                } else if (node.nodeType === Node.ELEMENT_NODE && !forbiddenTags.includes(node.tagName)) {
                    for (const child of node.childNodes) traverse(child);
                }
            }

            traverse(container);
            return Array.from(texts);
        }

        ld.runCourseExtractor = async function() {
            console.log('📚 Extracting course/module/topic texts...');
            const res = await fetch('/wp-json/custom-translate/v1/courses-content');
            const data = await res.json();
            const foundTexts = new Set();

            data.courses.forEach(item => {
                const title = item.post_title?.trim();
                if (title && containsEnglish(title)) {
                    foundTexts.add(title);
                }

                const texts = extractTextFromHTML(item.post_content);
                texts.forEach(t => {
                    if (containsEnglish(t)) {
                        foundTexts.add(t);
                    }
                });
            });

            data.lessons.forEach(item => {
                const title = item.post_title?.trim();
                if (title && containsEnglish(title)) {
                    foundTexts.add(title);
                }
            });

            data.topics.forEach(item => {
                const title = item.post_title?.trim();
                if (title && containsEnglish(title)) {
                    foundTexts.add(title);
                }
            });

            let inserted = 0, existing = 0, skipped = 0;
            const entries = [];
            const hashToTextMap = {};

            for (const text of foundTexts) {
                if (!text || !text.trim()) {
                    skipped++;
                    continue;
                }
                const hash = await sha256(text);
                hashToTextMap[hash] = text;
            }

            const existingResponse = await fetch('/wp-json/custom-translate/v1/texts?lang=en');
            const existingData = await existingResponse.json();
            const existingHashes = new Set(existingData.map(item => item.text_hash));

            for (const hash of Object.keys(hashToTextMap)) {
                if (!existingHashes.has(hash)) {
                    entries.push({
                        text_hash: hash,
                        original_text: hashToTextMap[hash],
                        source_lang: 'en',
                        target_lang: null,
                        translated_text: null
                    });
                    inserted++;
                } else {
                    existing++;
                }
            }

            if (entries.length > 0) {
                await fetch('/wp-json/custom-translate/v1/save-texts', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(entries)
                });
            }

            console.log(`✅ Stats: inserted=${inserted}, existing=${existing}, skipped=${skipped}`);
            return { inserted, existing, skipped };
        };

        console.log('📦 Course extractor loaded. Use: ldExtractor.runCourseExtractor()');
    })(window.ldExtractor);
    </script>
    <?php
}




// For Quizzes 
function inject_manual_quiz_extractors() {
    ?>
    <script>
    window.ldExtractor = window.ldExtractor || {};

    (function(ld) {
        async function sha256(text) {
            const buffer = new TextEncoder().encode(text);
            const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
            return Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
        }

        function containsEnglish(text) {
            return /[A-Za-z]/.test(text);
        }

        async function saveTexts(textsSet, label) {
            const hashToTextMap = {};
            for (const text of textsSet) {
                const hash = await sha256(text);
                hashToTextMap[hash] = text;
            }

            const res = await fetch('/wp-json/custom-translate/v1/texts?lang=en');
            const existingData = await res.json();
            const existingHashes = new Set(existingData.map(item => item.text_hash));

            const entries = Object.keys(hashToTextMap)
                .filter(hash => !existingHashes.has(hash))
                .map(hash => ({
                    text_hash: hash,
                    original_text: hashToTextMap[hash],
                    source_lang: 'en',
                    target_lang: null,
                    translated_text: null
                }));

            if (entries.length > 0) {
                await fetch('/wp-json/custom-translate/v1/save-texts', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(entries)
                });
                console.log(`✅ Saved ${entries.length} new ${label} texts.`);
            } else {
                console.log(`✅ No new ${label} texts to save.`);
            }
        }

        // 🧠 استدعِ هذه عند الحاجة فقط
        ld.runQuizTitles = async function() {
            console.log('📌 Extracting quiz titles...');
            const res = await fetch('/wp-json/custom-translate/v1/quizzes');
            const data = await res.json();
            const foundTexts = new Set();

            data.forEach(q => {
                if (q.post_title && containsEnglish(q.post_title)) {
                    foundTexts.add(q.post_title.trim());
                }
            });

            await saveTexts(foundTexts, 'quiz titles');
        };

        ld.runQuizQuestions = async function() {
            console.log('📌 Extracting quiz questions...');
            const res = await fetch('/wp-json/custom-translate/v1/quiz-questions');
            const data = await res.json();
            const foundTexts = new Set();

            data.forEach(q => {
                if (q.question && containsEnglish(q.question)) {
                    foundTexts.add(q.question.trim());
                }
            });

            await saveTexts(foundTexts, 'quiz questions');
        };

        ld.runQuizAnswers = async function() {
            console.log('📌 Extracting quiz answers...');
            const res = await fetch('/wp-json/custom-translate/v1/quiz-answers');
            const data = await res.json();
            const foundTexts = new Set();

            data.answers.forEach(ans => {
                if (containsEnglish(ans)) {
                    foundTexts.add(ans.trim());
                }
            });

            await saveTexts(foundTexts, 'quiz answers');
        };

        console.log('✅ Quiz extractors loaded. Use: ldExtractor.runQuizTitles(), runQuizQuestions(), runQuizAnswers()');
    })(window.ldExtractor);
    </script>
    <?php
}
