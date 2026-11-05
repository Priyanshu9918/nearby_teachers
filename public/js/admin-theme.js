(() => {
    'use strict';
    const key = 'nearby-admin-theme';
    const root = document.documentElement;
    let saved = null;
    try { saved = localStorage.getItem(key); } catch (_) { /* Use the system setting when storage is unavailable. */ }
    const valid = saved === 'dark' || saved === 'light';
    const systemDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
    root.dataset.themePreference = valid ? 'stored' : 'system';
    root.dataset.theme = valid ? saved : (systemDark ? 'dark' : 'light');
})();
