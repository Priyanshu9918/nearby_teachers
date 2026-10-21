const fs = require('fs');
const path = require('path');

const SUPPORTED_LANGUAGES = ['en', 'hi', 'es', 'fr', 'ar', 'zh', 'pt', 'ru'];
const DEFAULT_LANGUAGE = 'en';

// Loaded once at startup, not per-request — these are small static
// files, no reason to hit disk on every call.
const translations = {};
for (const lang of SUPPORTED_LANGUAGES) {
  const filePath = path.join(__dirname, `${lang}.json`);
  translations[lang] = JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function isSupported(lang) {
  return SUPPORTED_LANGUAGES.includes(lang);
}

function getTranslations(lang) {
  return translations[isSupported(lang) ? lang : DEFAULT_LANGUAGE];
}

function listLanguages() {
  return SUPPORTED_LANGUAGES.map((code) => ({
    code,
    name: translations[code]._meta.name,
    nativeName: translations[code]._meta.nativeName,
    rtl: translations[code]._meta.rtl,
  }));
}

module.exports = { SUPPORTED_LANGUAGES, DEFAULT_LANGUAGE, isSupported, getTranslations, listLanguages };
