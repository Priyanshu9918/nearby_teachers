const admin = require('firebase-admin');

// The mobile app authenticates with Firebase Auth directly (Google sign-in
// or phone/OTP). It then sends the resulting Firebase ID token to our API
// on every request; we verify it here rather than re-implementing OTP
// delivery or Google's OAuth flow ourselves.
if (!admin.apps.length) {
  const serviceAccount = JSON.parse(process.env.FIREBASE_SERVICE_ACCOUNT_JSON || '{}');
  admin.initializeApp({
    credential: admin.credential.cert(serviceAccount),
  });
}

module.exports = admin;
