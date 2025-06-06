async function sha256(text) {
  const buffer = new TextEncoder().encode(text);
  const hashBuffer = await crypto.subtle.digest("SHA-256", buffer);
  return Array.from(new Uint8Array(hashBuffer))
    .map(b => b.toString(16).padStart(2, "0"))
    .join("");
}

function getSelectedLanguage() {
  return localStorage.getItem("site_lang") || "en";
}

function setSelectedLanguage(lang) {
  localStorage.setItem("site_lang", lang);
  document.cookie = `site_lang=${lang}; path=/; max-age=31536000`;
}

async function fetchTranslations(lang) {
  const res = await fetch(`/wp-json/custom-translate/v1/texts?lang=${lang}`);
  if (!res.ok) return {};
  const translations = await res.json();
  const map = {};
  for (const item of translations) {
    map[item.text_hash] = item.translated_text;
  }
  return map;
}

function applyDirection(lang) {
  const rtlLangs = ["ar", "he", "fa", "ur"];
  const isRTL = rtlLangs.includes(lang);

  document.documentElement.setAttribute("dir", isRTL ? "rtl" : "ltr");
  document.body.style.direction = isRTL ? "rtl" : "ltr";

  if (isRTL) {
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = "/wp-content/plugins/translate-langs/rtl-style.css";
    link.type = "text/css";
    link.onload = () => console.log("✅ RTL style loaded");
    document.head.appendChild(link);
  }
}

async function translateExpandButtons(translations) {
  const buttons = document.querySelectorAll('[data-ld-expand-text][data-ld-collapse-text]');
  for (const el of buttons) {
    const span = el.querySelector('.ld-text');
    if (!span) continue;

    const originalExpand = el.getAttribute('data-ld-expand-text');
    const originalCollapse = el.getAttribute('data-ld-collapse-text');

    const expandHash = await sha256(originalExpand);
    const collapseHash = await sha256(originalCollapse);

    const translatedExpand = translations[expandHash];
    const translatedCollapse = translations[collapseHash];

    if (translatedExpand) el.setAttribute('data-ld-expand-text', translatedExpand);
    if (translatedCollapse) el.setAttribute('data-ld-collapse-text', translatedCollapse);

    const applyTranslation = () => {
      const currentText = span.textContent.trim();
      if (translatedExpand && currentText === originalExpand) {
        span.textContent = translatedExpand;
      } else if (translatedCollapse && currentText === originalCollapse) {
        span.textContent = translatedCollapse;
      }
    };

    applyTranslation();

    const observer = new MutationObserver(() => applyTranslation());
    observer.observe(span, { childList: true, characterData: true, subtree: true });
  }
}

function observeNewTextNodes(translations) {
  const observer = new MutationObserver(async (mutations) => {
    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (node.nodeType === Node.ELEMENT_NODE) {
          await translateNodeAndChildren(node, translations);
        }
      }
    }
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true,
  });

  setInterval(() => {
    translateNodeAndChildren(document.body, translations);
  }, 1500);
}

async function translateNodeAndChildren(root, translations) {
  const nodes = [];
  const attributes = ['title', 'aria-label', 'data-title'];

  function collect(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      const text = node.textContent.trim();
      if (text.length > 0) nodes.push({ node, text });
    } else if (node.nodeType === Node.ELEMENT_NODE) {
      if (node.tagName === "BUTTON") {
        const textContent = Array.from(node.childNodes)
          .filter(n => n.nodeType === Node.TEXT_NODE)
          .map(n => n.textContent.trim())
          .join(" ");
        if (textContent.length > 0) {
          nodes.push({ node, text: textContent, type: 'button' });
        }
      }

      if (
        node.tagName === "INPUT" &&
        ['button', 'submit'].includes(node.type?.toLowerCase())
      ) {
        const value = node.value?.trim();
        if (value && value.length > 0) {
          nodes.push({ node, text: value, type: 'input' });
        }
      }

      for (const attr of attributes) {
        if (node.hasAttribute(attr)) {
          const val = node.getAttribute(attr).trim();
          if (val.length > 0) nodes.push({ node, text: val, attr });
        }
      }

      for (const child of node.childNodes) collect(child);
    }
  }

  collect(root);

  for (const { node, text, type, attr } of nodes) {
    const hash = await sha256(text);
    const translated = translations[hash];
    if (translated) {
      if (attr) {
        node.setAttribute(attr, translated);
      } else if (type === 'button') {
        node.innerText = translated;
      } else if (type === 'input') {
        node.value = translated;
      } else {
        node.textContent = translated;
      }
      console.log("🆕 ترجم:", text, "→", translated);
    }
  }
}

async function translatePage() {
  const lang = getSelectedLanguage();
  if (lang === "en") return;

  const forbiddenTags = ["SCRIPT", "STYLE", "NOSCRIPT", "IFRAME", "META", "LINK"];
  const forbiddenClasses = ["Unload", "ld-user-welcome-text"];
  const forbiddenIds = ["HeadTitle1", "HeadTitle2"];

  const nodes = [];
  function collectTextNodes(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      const text = node.textContent.trim();
      if (text.length > 0) {
        nodes.push({ node, text });
      }
    } else if (
      node.nodeType === Node.ELEMENT_NODE &&
      !forbiddenTags.includes(node.tagName) &&
      !forbiddenIds.includes(node.id) &&
      !Array.from(node.classList).some(cls => forbiddenClasses.includes(cls))
    ) {
      for (const child of node.childNodes) {
        collectTextNodes(child);
      }
    }
  }

  collectTextNodes(document.body);

  const hashMap = {};
  for (const { text } of nodes) {
    hashMap[text] = await sha256(text);
  }

  const translations = await fetchTranslations(lang);

  for (const { node, text } of nodes) {
    const hash = hashMap[text];
    if (translations[hash]) {
      node.textContent = translations[hash];
    }
  }

  // ✅ ترجمة عناصر مخصصة ديناميكية مثل "65% Complete"
  const dynamicSelectors = [
    {
      selector: ".ld-progress-percentage, .ld-lesson-list-progress",
      regex: /^(\d{1,3})%\s+(Complete)$/i,
      extract: match => match[2],
      build: (match, trans) => `${match[1]}% ${trans}`
    },
    {
      selector: ".ld-progress-steps",
      regex: /^Last activity on\s+(.+)$/i,
      extract: () => "Last activity on",
      build: (match, trans) => `${trans} ${match[1]}`
    },
    {
      selector: ".ld-lesson-list-steps",
      regex: /^(\d+)\/(\d+)\s+(Steps)$/i,
      extract: match => match[3],
      build: (match, trans) => `${match[1]}/${match[2]} ${trans}`
    },
    {
      selector: ".ld-progress-steps",
      regex: /^(\d+)\/(\d+)\s+(Steps)$/i,
      extract: match => match[3],
      build: (match, trans) => `${match[1]}/${match[2]} ${trans}`
    }
  ];

  for (const rule of dynamicSelectors) {
    document.querySelectorAll(rule.selector).forEach(async el => {
      const raw = el.textContent.trim();
      const match = raw.match(rule.regex);
      if (match) {
        const staticText = rule.extract(match);
        const hash = await sha256(staticText);
        if (translations[hash]) {
          el.textContent = rule.build(match, translations[hash]);
          console.log("✅ Dynamic translated:", el.textContent);
        }
      }
    });
  }

  await translateExpandButtons(translations);
  observeNewTextNodes(translations);
  console.log(`✅ Page translated to ${lang}`);
}

async function createLanguageSwitcher() {
  const res = await fetch("/wp-json/custom-translate/v1/available-languages");
  if (!res.ok) return;
  const languages = await res.json();

  const selectedLangCode = getSelectedLanguage();
  const selectedLang = languages.find(lang => lang.code === selectedLangCode) || languages[0];

  // ✅ الحاوية الخارجية
  const wrapper = document.createElement("div");
  wrapper.style.position = "fixed";
  wrapper.style.bottom = "20px";
  wrapper.style.left = "20px";
  wrapper.style.zIndex = 9999;
  wrapper.style.fontSize = "14px";
  wrapper.style.userSelect = "none";

  // ✅ الزر الرئيسي الذي يظهر اللغة الحالية
  const button = document.createElement("div");
  button.style.display = "flex";
  button.style.alignItems = "center";
  button.style.gap = "8px";
  button.style.padding = "6px 12px";
  button.style.backgroundColor = "#1e3a5f";
  button.style.color = "#fff";
  button.style.borderRadius = "8px";
  button.style.cursor = "pointer";
  button.style.boxShadow = "0 4px 10px rgba(0,0,0,0.3)";

  const flag = document.createElement("img");
  flag.src = selectedLang.flag_url;
  flag.alt = selectedLang.code;
  flag.style.width = "18px";
  flag.style.height = "12px";
  flag.style.borderRadius = "2px";

  const label = document.createElement("span");
  label.textContent = selectedLang.name;

  button.appendChild(flag);
  button.appendChild(label);

  // ✅ القائمة المنسدلة
  const dropdown = document.createElement("ul");
  dropdown.style.position = "absolute";
  dropdown.style.bottom = "110%";
  dropdown.style.left = "0";
  dropdown.style.backgroundColor = "#1e3a5f";
  dropdown.style.borderRadius = "6px";
  dropdown.style.boxShadow = "0 4px 10px rgba(0,0,0,0.3)";
  dropdown.style.margin = "0";
  dropdown.style.padding = "6px 0";
  dropdown.style.listStyle = "none";
  dropdown.style.display = "none";
  dropdown.style.minWidth = "120px";

  // ✅ تعبئة العناصر
  languages.forEach(lang => {
    const li = document.createElement("li");
    li.style.display = "flex";
    li.style.alignItems = "center";
    li.style.gap = "8px";
    li.style.padding = "6px 12px";
    li.style.cursor = "pointer";
    li.style.color = "#fff";

    li.addEventListener("mouseenter", () => {
      li.style.backgroundColor = "#375a7f";
    });
    li.addEventListener("mouseleave", () => {
      li.style.backgroundColor = "transparent";
    });

    li.addEventListener("click", () => {
      setSelectedLanguage(lang.code);
      location.reload();
    });

    const img = document.createElement("img");
    img.src = lang.flag_url;
    img.alt = lang.code;
    img.style.width = "18px";
    img.style.height = "12px";
    img.style.borderRadius = "2px";

    const name = document.createElement("span");
    name.textContent = lang.name;

    li.appendChild(img);
    li.appendChild(name);
    dropdown.appendChild(li);
  });

  // ✅ عرض/إخفاء القائمة عند الضغط
  button.addEventListener("click", () => {
    dropdown.style.display = dropdown.style.display === "none" ? "block" : "none";
  });

  // ✅ إغلاق القائمة إذا ضغط المستخدم خارجها
  document.addEventListener("click", (e) => {
    if (!wrapper.contains(e.target)) {
      dropdown.style.display = "none";
    }
  });

  wrapper.appendChild(button);
  wrapper.appendChild(dropdown);
  document.body.appendChild(wrapper);
}
createLanguageSwitcher()

document.addEventListener("DOMContentLoaded", async () => {
  const lang = getSelectedLanguage();
  await createLanguageSwitcher();
  applyDirection(lang);
  await translatePage();
});



////////////////////////////////////////////////////
document.addEventListener("DOMContentLoaded", async () => {
  const container = document.querySelector('.elementor-headline-dynamic-text');
  if (!container) return;

  const originalText = Array.from(container.querySelectorAll('.elementor-headline-dynamic-letter'))
    .map(el => el.textContent).join('').trim();

  const buffer = new TextEncoder().encode(originalText);
  const hashBuffer = await crypto.subtle.digest("SHA-256", buffer);
  const textHash = Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, "0")).join("");

  const lang = localStorage.getItem("site_lang") || "en";
  if (lang === "en") return;

  const res = await fetch(`/wp-json/custom-translate/v1/texts?lang=${lang}`);
  if (!res.ok) return;
  const data = await res.json();
  const translationMap = {};
  for (const row of data) {
    translationMap[row.text_hash] = row.translated_text;
  }

  const translatedText = translationMap[textHash];
  if (!translatedText) return;

  // إفراغ الحاوية
  container.innerHTML = '';

  // عرض النص العربي بطريقة سليمة
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
});



/////////////////////////////////////////////////////////
