const express = require('express');
const cors = require('cors');
const cookieParser = require('cookie-parser');
const { detectLanguage } = require('./middleware/language');
const path = require('path');

const authRoutes = require('./routes/auth.routes');
const profilesRoutes = require('./routes/profiles.routes');
const searchRoutes = require('./routes/search.routes');
const messagesRoutes = require('./routes/messages.routes');
const reviewsRoutes = require('./routes/reviews.routes');
const feedRoutes = require('./routes/feed.routes');
const safetyRoutes = require('./routes/safety.routes');
const subjectsRoutes = require('./routes/subjects.routes');
const suggestionsRoutes = require('./routes/suggestions.routes');
const supportRoutes = require('./routes/support.routes');
const referralsRoutes = require('./routes/referrals.routes');
const moderationRoutes = require('./routes/moderation.routes');
const i18nRoutes = require('./routes/i18n.routes');
const websiteRoutes = require('./routes/website.routes');
const analyticsRoutes = require('./routes/analytics.routes');
const { logPageView, detectPlatform } = require('./utils/analytics');

const app = express();

app.use(cors());
app.use(cookieParser());
app.use(detectLanguage);
app.use(express.json());

// Public, SEO-indexable website: server-rendered pages, no auth required.
// An institute's page (src/views/institute.ejs) goes live automatically
// the first time they publish their profile and get a slug (see
// profiles.routes.js). City feed pages (src/views/feed.ejs) are
// read-only — interacting still requires the app.
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));
app.use('/site', websiteRoutes);

app.get('/health', (req, res) => res.json({ ok: true }));

// Stub homepage — replace with a real marketing homepage before launch.
app.get('/', (req, res) => res.send('Nearby Teacher — homepage placeholder. Replace before launch. See /site for the public directory.'));

// Deep-link-aware download route. Every "Download the app" link on the
// public site points here with ?type=institute|teacher|feed&slug=... so
// that after detecting the visitor's platform we can (a) send them to
// the right store and (b) carry the destination through so the app can
// deep-link straight to that profile/feed on first open, rather than
// dropping them on the generic home screen.
//
// iOS and Android app store listings are both live — set their URLs in
// .env (IOS_APP_STORE_URL, ANDROID_PLAY_STORE_URL). Android supports
// carrying the deep-link target through the install via the Play
// Install Referrer API (the &referrer= param below); the app reads it
// on first open via the Play Install Referrer library. iOS has no
// equivalent generic mechanism — true deep-link-through-install on iOS
// needs Universal Links (an apple-app-site-association file on this
// domain) or a service like Branch/Firebase Dynamic Links; the TODO
// below marks where that integration goes.
app.get('/download', (req, res) => {
  const { type, slug, ref } = req.query;
  const platform = detectPlatform(req.get('user-agent'));
  const target = type && slug ? `${type}:${slug}` : null;

  logPageView({ pageType: 'download', target, platform, req });

  const deepLinkPath = type && slug ? `/${type}/${encodeURIComponent(slug)}` : '/';
  // A referral code rides through the same deep-link mechanism as a
  // profile/city target — no separate carrying mechanism needed. The
  // app reads this on first open and calls POST /referrals/redeem once,
  // right after the new account is created.
  const referrerParams = ref ? `deep_link_path=${deepLinkPath}&ref=${encodeURIComponent(ref)}` : `deep_link_path=${deepLinkPath}`;

  if (platform === 'android' && process.env.ANDROID_PLAY_STORE_URL) {
    const referrer = encodeURIComponent(referrerParams);
    return res.redirect(`${process.env.ANDROID_PLAY_STORE_URL}&referrer=${referrer}`);
  }

  if (platform === 'ios' && process.env.IOS_APP_STORE_URL) {
    // TODO: once Universal Links (apple-app-site-association) or a
    // deferred-deep-linking service is wired up, carry deepLinkPath and
    // ref through here the way the Android branch does with &referrer=.
    // Until then, an iOS referral link still logs the click (above) but
    // the code itself doesn't survive a fresh install on iOS.
    return res.redirect(process.env.IOS_APP_STORE_URL);
  }

  // Desktop or undetected platform: show both store links rather than
  // guessing — this also covers the case where the env vars above
  // aren't set yet in a given environment.
  res.render('download', {
    iosUrl: process.env.IOS_APP_STORE_URL || null,
    androidUrl: process.env.ANDROID_PLAY_STORE_URL || null,
    deepLinkPath,
    ref: ref || null,
    t: req.t,
    lang: req.lang,
    languages: require('./i18n').listLanguages(),
  });
});

// Internal admin dashboard: reports, suggestions, and verification review.
// Served at /admin — the page itself calls the API endpoints below, which
// are protected by requireAdmin (see src/middleware/admin.js).
app.use('/admin', express.static(path.join(__dirname, '..', 'public', 'admin')));

app.use('/auth', authRoutes);
app.use('/profiles', profilesRoutes);
app.use('/search', searchRoutes);
app.use('/messages', messagesRoutes);
// Booking API is temporarily paused. Keep the historical admin audit route.
// app.use('/bookings', bookingsRoutes);
app.use('/reviews', reviewsRoutes);
app.use('/feed', feedRoutes);
app.use('/safety', safetyRoutes);
app.use('/subjects', subjectsRoutes);
app.use('/suggestions', suggestionsRoutes);
app.use('/support', supportRoutes);
app.use('/referrals', referralsRoutes);
app.use('/moderation', moderationRoutes);
app.use('/i18n', i18nRoutes);
app.use('/analytics', analyticsRoutes);

// Basic error handler — keeps a bad query from crashing the process and
// leaking stack traces to the client.
app.use((err, req, res, next) => {
  console.error(err);
  res.status(500).json({ error: 'Internal server error' });
});

module.exports = app;
