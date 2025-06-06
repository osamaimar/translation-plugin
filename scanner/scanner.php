<?php
add_action('wp_footer', 'inject_auto_extractor_script');
function inject_auto_extractor_script() {
    ?>
    <script>
    (function() {
      if (localStorage.getItem('enable_auto_extractor') !== 'true') return;

      async function sha256(text) {
        const buffer = new TextEncoder().encode(text);
        const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
        return Array.from(new Uint8Array(hashBuffer))
          .map(b => b.toString(16).padStart(2, '0')).join('');
      }

      async function extractEnglishTextsAndSend() {
        const forbiddenTags = ['SCRIPT','STYLE','NOSCRIPT','IFRAME','META','LINK'];
        const forbiddenClasses = ["nojq", "wpadminbar"];
        const forbiddenIds = ["wpadminbar", "HeadTitle2"];
        const englishPattern = /[A-Za-z]/;
        const foundTexts = new Set();

        function traverse(node) {
          if (node.nodeType === Node.TEXT_NODE) {
            const text = node.textContent.trim();
            if (text.length > 0 && englishPattern.test(text)) {
              foundTexts.add(text);
            }
          } else if (node.nodeType === Node.ELEMENT_NODE && !forbiddenTags.includes(node.tagName)) {
            const classList = Array.from(node.classList || []);
            const nodeId = node.id || '';
            if (classList.some(cls => forbiddenClasses.includes(cls)) || forbiddenIds.includes(nodeId)) return;

            // زر BUTTON أو زر INPUT
            if (node.tagName === 'BUTTON' || (node.tagName === 'INPUT' && node.type === 'button' && node.value)) {
              const btnText = node.innerText || node.value || '';
              if (englishPattern.test(btnText.trim())) {
                foundTexts.add(btnText.trim());
              }

              ['aria-label', 'data-ld-tooltip', 'title', 'data-title'].forEach(attr => {
                if (node.hasAttribute(attr)) {
                  const val = node.getAttribute(attr).trim();
                  if (val && englishPattern.test(val)) {
                    foundTexts.add(val);
                  }
                }
              });
            }

            // عناصر أخرى
            ['aria-label', 'data-ld-tooltip', 'title', 'data-title'].forEach(attr => {
              if (node.hasAttribute(attr)) {
                const val = node.getAttribute(attr).trim();
                if (val && englishPattern.test(val)) {
                  foundTexts.add(val);
                }
              }
            });

            for (const child of node.childNodes) traverse(child);
          }
        }

        traverse(document.body);

        // إدخال يدوي للنصوص المهمة
        ['Collapse', 'Collapse All', 'Mark Complete'].forEach(text => {
          if (!Array.from(foundTexts).includes(text)) {
            foundTexts.add(text);
          }
        });

        const texts = Array.from(foundTexts);
        const entries = [];
        const hashToTextMap = {};

        for (const text of texts) {
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
              source_lang: 'en'
            });
          }
        }

        if (entries.length > 0) {
          await fetch('/wp-json/custom-translate/v1/save-texts', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(entries)
          });
          console.log(`✅ Sent ${entries.length} new texts to the server including manual additions.`);
        }
      }

      extractEnglishTextsAndSend();
    })();
    </script>
    <?php
}
