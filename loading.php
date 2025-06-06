<?php
add_filter('body_class', function($classes) {
    $classes[] = 'loading-translate';
    return $classes;
});

//Hide Elements
add_action('wp_head', function () {
    echo <<<HTML
<style id="pretranslate-style">
  body.loading-translate {
    opacity: 0 !important;
    pointer-events: none !important;
  }
</style>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    document.body.classList.add("loading-translate");
  });
</script>
HTML;
}, 0);


//Loader page style
add_action('wp_head', function () {
    echo <<<HTML
<style id="pretranslate-style">
  body.loading-translate {
    overflow: hidden !important;
  }
  #translate-loader {
    position: fixed;
    top: 0; left: 0;
    width: 100vw;
    height: 100vh;
    background-color: #0a2540;
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .translate-spinner {
    width: 50px;
    height: 50px;
    border: 5px solid #ffffff;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 1s linear infinite;
  }
  @keyframes spin {
    to { transform: rotate(360deg); }
  }
</style>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    document.body.classList.add("loading-translate");
  });
</script>
<div id="translate-loader">
  <div class="translate-spinner"></div>
</div>
HTML;
}, 0);


//Display Elements
add_action('wp_footer', function () {
    ?>
    <script>
    (function() {
      const lang = localStorage.getItem("site_lang") || "en";

      const failSafeTimeout = setTimeout(showPage, 12000);

      Promise.all([
        waitForCSS(),
        waitForFonts(),
        waitForDOMReady(),
        waitForIdle(),
        lang === "en" ? Promise.resolve() : fetchTranslationsAndApply()
      ])
      .then(() => {
        clearTimeout(failSafeTimeout);
        showPage();
        if (lang !== 'en') observeNewNodesAndTranslate(); // ✅ راقب العناصر الجديدة بعد ظهور الصفحة
      })
      .catch((err) => {
        console.error("⚠️ خطأ أثناء التحميل أو الترجمة", err);
        showPage();
      });

      function showPage() {
        document.body.classList.remove("loading-translate");
        const loader = document.getElementById("translate-loader");
        if (loader) loader.remove();
        console.log("✅ الصفحة أصبحت مرئية");
      }

      function waitForCSS() {
        return new Promise((resolve) => {
          if (document.readyState === "complete") {
            resolve();
          } else {
            window.addEventListener("load", resolve);
          }
        });
      }

      function waitForFonts() {
        if (document.fonts && document.fonts.ready) {
          return document.fonts.ready;
        }
        return Promise.resolve();
      }

      function waitForDOMReady() {
        return new Promise((resolve) => {
          if (document.readyState === "interactive" || document.readyState === "complete") {
            resolve();
          } else {
            document.addEventListener("DOMContentLoaded", resolve);
          }
        });
      }

      function waitForIdle() {
        return new Promise((resolve) => {
          if ('requestIdleCallback' in window) {
            requestIdleCallback(resolve);
          } else {
            setTimeout(resolve, 200);
          }
        });
      }

      async function fetchTranslationsAndApply() {
        const res = await fetch(`/wp-json/custom-translate/v1/texts?lang=${lang}`);
        const data = await res.json();

        const map = {};
        for (const row of data) {
          map[row.text_hash] = row.translated_text;
        }

        await translateTextNodes(document.body, map);
        applyRTLDirection(lang);
        console.log("✅ الترجمة وتطبيق RTL تمت بنجاح");
      }

      async function translateTextNodes(root, translations) {
        const elements = root.querySelectorAll("*");

        for (const el of elements) {
          for (const child of el.childNodes) {
            if (child.nodeType === Node.TEXT_NODE) {
              const text = child.textContent.trim();
              if (text.length > 0) {
                const buffer = new TextEncoder().encode(text);
                const hashBuffer = await crypto.subtle.digest("SHA-256", buffer);
                const hash = Array.from(new Uint8Array(hashBuffer))
                  .map(b => b.toString(16).padStart(2, "0")).join("");
                const translated = translations[hash];
                if (translated) {
                  child.textContent = translated;
                }
              }
            }
          }
        }
      }

      function applyRTLDirection(lang) {
        const rtlLangs = ["ar", "he", "fa", "ur"];
        const isRTL = rtlLangs.includes(lang);

        document.documentElement.setAttribute("dir", isRTL ? "rtl" : "ltr");
        document.body.style.direction = isRTL ? "rtl" : "ltr";

        if (isRTL && !document.getElementById('rtl-style')) {
          const link = document.createElement("link");
          link.id = "rtl-style";
          link.rel = "stylesheet";
          link.href = "/wp-content/plugins/translate-langs/rtl-style.css";
          document.head.appendChild(link);
        }
      }

      function observeNewNodesAndTranslate() {
        const observer = new MutationObserver(async (mutations) => {
          const res = await fetch(`/wp-json/custom-translate/v1/texts?lang=${lang}`);
          const data = await res.json();
          const map = {};
          for (const row of data) {
            map[row.text_hash] = row.translated_text;
          }

          for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
              if (node.nodeType === Node.ELEMENT_NODE) {
                await translateTextNodes(node, map);
                applyRTLDirection(lang);
              }
            }
          }
        });

        observer.observe(document.body, {
          childList: true,
          subtree: true
        });

        console.log("👁️ بدأ مراقبة المحتوى الديناميكي للترجمة وRTL");
      }

    })();
    </script>
    <?php
});
