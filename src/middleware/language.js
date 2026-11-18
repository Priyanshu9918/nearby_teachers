const { isSupported, getTranslations, DEFAULT_LANGUAGE, listLanguages } = require('../i18n');

const COOKIE_NAME = 'nt_lang';
const COOKIE_MAX_AGE = 365 * 24 * 60 * 60 * 1000; // 1 year

// Resolution order, matching how most localized websites behave:
//   1. ?lang= in the URL (explicit, always wins — e.g. a shared link
//      with a language already chosen)
//   2. nt_lang cookie (a returning visitor's previous choice)
//   3. Accept-Language browser header (first visit, no choice made yet)
//   4. English default
//
// Sets req.lang (the resolved code) and req.t (that language's full
// translation dictionary) for every route — EJS templates read these
// directly, e.g. <%= t.common.appName %>. Also sets the cookie when a
// ?lang= override is present, so the choice persists on the next page
// without needing to repeat the query param.
function detectLanguage(req, res, next) {
  let lang = null;

  if (req.query.lang && isSupported(req.query.lang)) {
    lang = req.query.lang;
    res.cookie(COOKIE_NAME, lang, { maxAge: COOKIE_MAX_AGE, sameSite: 'lax' });
  } else if (req.cookies && isSupported(req.cookies[COOKIE_NAME])) {
    lang = req.cookies[COOKIE_NAME];
  } else if (req.headers['accept-language']) {
    const candidates = req.headers['accept-language'].split(',').map((tag, index) => {
      const [name, ...parameters] = tag.trim().split(';');
      const quality = parameters.find(value => value.trim().startsWith('q='));
      return { code: name.split(/[-_]/)[0].toLowerCase(), quality: quality ? Number(quality.trim().slice(2)) : 1, index };
    }).filter(candidate => candidate.quality > 0).sort((a, b) => b.quality - a.quality || a.index - b.index);
    lang = candidates.find(candidate => isSupported(candidate.code))?.code || null;
  }

  req.lang = lang || DEFAULT_LANGUAGE;
  req.t = getTranslations(req.lang);
  res.locals.languageOptions = listLanguages();
  next();
}

module.exports = { detectLanguage, COOKIE_NAME };
