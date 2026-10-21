const express = require('express');
const { listLanguages, getTranslations, isSupported } = require('../i18n');

const router = express.Router();

// The list of supported languages with native names and RTL flags —
// what a language-picker screen renders from. Native names (हिन्दी,
// العربية, 中文...) are included so the picker itself doesn't need its
// own translation — a language's own name is always shown in that
// language, a standard convention for language switchers.
router.get('/languages', (req, res) => {
  res.json({ languages: listLanguages() });
});

// The full translation dictionary for one language — this is what the
// mobile app (once built) fetches once at startup or on language
// change, and what the public website's EJS templates use directly
// (see website.routes.js). Falls back to English for an unsupported
// code rather than erroring, since a client sending a bad/stale code
// shouldn't be left with no translations at all.
router.get('/:lang', (req, res) => {
  const lang = req.params.lang;
  res.json({
    requested: lang,
    served: isSupported(lang) ? lang : 'en',
    translations: getTranslations(lang),
  });
});

module.exports = router;
