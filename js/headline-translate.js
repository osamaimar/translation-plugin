(async () => {
  const lang = localStorage.getItem("site_lang") || "en";
  if (lang === "en") return;

  // ⬇️ استعلام اتجاه اللغة من قاعدة البيانات
  const langRes = await fetch(`/wp-json/custom-translate/v1/available-languages`);
  if (!langRes.ok) return;
  const availableLangs = await langRes.json();
  const langMeta = availableLangs.find(l => l.code === lang);
  if (!langMeta) return;

  const isRTL = langMeta.direction === 'rtl';

  // ⬇️ تحميل الترجمة للنصوص
  const response = await fetch(`/wp-json/custom-translate/v1/texts?lang=${lang}`);
  if (!response.ok) return;
  const translations = await response.json();
  const map = {};
  for (const row of translations) {
    map[row.text_hash] = row.translated_text;
  }

  const applyTranslation = async () => {
    const container = document.querySelector('.elementor-headline-dynamic-text');
    if (!container) return;

    const letters = container.querySelectorAll('.elementor-headline-dynamic-letter');
    if (letters.length === 0) {
      setTimeout(applyTranslation, 100);
      return;
    }

    const originalText = Array.from(letters).map(el => el.textContent).join('').trim();
    if (!originalText || originalText === '') {
      setTimeout(applyTranslation, 100);
      return;
    }

    const buffer = new TextEncoder().encode(originalText);
    const hashBuffer = await crypto.subtle.digest("SHA-256", buffer);
    const textHash = Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, "0")).join("");

    const translatedText = map[textHash];
    if (!translatedText || translatedText.trim() === container.textContent.trim()) return;

    // ✅ تفريغ الحاوية
    container.innerHTML = '';

    if (isRTL) {
      // 🟠 RTL: عرض النص دفعة واحدة
      const span = document.createElement('span');
      span.className = 'elementor-headline-dynamic-letter';
      span.textContent = translatedText;
      span.style.opacity = 0;
      span.style.display = 'inline-block';
      span.style.transition = 'opacity 0.8s ease';
      container.appendChild(span);

      setTimeout(() => {
        span.style.opacity = 1;
      }, 50);
    } else {
      // 🔵 LTR: حرف بحرف
      [...translatedText].forEach((char, i) => {
        const span = document.createElement('span');
        span.className = 'elementor-headline-dynamic-letter';
        span.textContent = char;
        span.style.opacity = 0;
        span.style.display = 'inline-block';
        span.style.transition = `opacity 0.3s ease ${i * 0.05}s`;
        container.appendChild(span);
      });

      setTimeout(() => {
        container.querySelectorAll('.elementor-headline-dynamic-letter')
          .forEach(span => span.style.opacity = 1);
      }, 50);
    }
  };

  window.addEventListener("load", () => {
    setTimeout(applyTranslation, 200);
  });

  const observer = new MutationObserver(() => applyTranslation());
  observer.observe(document.body, { childList: true, subtree: true });
})();

