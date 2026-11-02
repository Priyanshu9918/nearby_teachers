# Push notifications: app and admin implementation guide

Updated: 31 October 2026. This feature uses Firebase Cloud Messaging (FCM), Laravel bearer authentication, and the admin web session. It does not depend on chat/feed Socket.IO.

## App team: Flutter requirements

### 1. Firebase project and platform setup

- Use the same Firebase project as this backend's `FIREBASE_PROJECT_ID`.
- Confirm the Android package name and iOS bundle ID are registered in that Firebase project. Keep `google-services.json` and `GoogleService-Info.plist` in their normal app build locations.
- Add/initialize `firebase_messaging` after Firebase initialization.
- Configure iOS APNs credentials in Firebase and enable Push Notifications / Background Modes in the iOS target.
- Request notification permission at a user-visible point. On Android 13+, request the runtime notification permission. Register the app with the backend only after permission is granted and the user is signed in.
- Use Firebase Installation IDs (FIDs), not the deprecated `getToken()` registration-token flow. Do not submit both identifier formats.

Firebase's current Flutter guide recommends FID registration and says not to mix FID APIs with deprecated token APIs. Set `firebase_messaging_installation_id_enabled=true` in Android app metadata and `FirebaseMessagingInstallationIdEnabled=true` in iOS `Info.plist`, then subscribe to `FirebaseMessaging.instance.onRegistered` before calling `register()`. On iOS, wait for the APNs token before FCM registration calls. See [FCM Flutter setup](https://firebase.google.com/docs/cloud-messaging/flutter/get-started) and [FCM registration management](https://firebase.google.com/docs/cloud-messaging/manage-tokens).

### 2. Register or refresh a device

Use the deployed API base already configured for the app:

```text
http://157.245.125.79/nearby_teachers/public
```

Append `/push/devices` to that base. Do not use a local address. Send JSON with the active Laravel bearer token:

```http
POST /push/devices
Authorization: Bearer <Laravel token>
Accept: application/json
Content-Type: application/json
```

```json
{
  "firebaseInstallationId": "<FID from firebase_messaging.onRegistered>",
  "notificationsEnabled": true,
  "platform": "android",
  "locationConsent": false
}
```

Expected response:

```json
{"deviceId":"<backend UUID>","registered":true}
```

The backend encrypts the FID at rest and keeps only a keyed hash for matching. Never send the Firebase service-account key, Firebase Google sign-in ID token, or the user's Laravel token as an FID.

Upload the FID whenever `onRegistered` emits, after sign-in, and at app startup when signed in. FIDs can change, and routine uploads refresh the server's active registration. If the app gets a FID before login, keep it only in app memory/local secure state and upload it once a Laravel bearer is available.

### 3. Location targeting and privacy

Teacher and institute location filters come from their saved backend profile address. For learner accounts, there is no saved city/state profile field today. If the app has a user-selected/consented coarse location, it may send city/state/country fields with `locationConsent:true`:

```json
{
  "firebaseInstallationId": "<FID>",
  "notificationsEnabled": true,
  "platform": "android",
  "locationConsent": true,
  "locationCity": "Kanpur",
  "locationState": "Uttar Pradesh",
  "locationCountry": "India"
}
```

Only city/state/country are used; do not send GPS coordinates. If location consent is false, omit all three location fields. A learner who has not opted in to a coarse location can still receive all-user, user-specific, and learner-account campaigns, but cannot match learner location campaigns.

Location matching is case-insensitive exact text matching. Keep submitted values consistent with the user's selected/profile address so the admin's city/state/country filter matches.

### 4. Logout and notification handling

Before calling `POST /auth/logout`, unregister this FID while the current Laravel bearer still works:

```http
DELETE /push/devices/current
Authorization: Bearer <Laravel token>
Accept: application/json
Content-Type: application/json
```

```json
{"firebaseInstallationId":"<current FID>"}
```

This disables that FID for the signed-in user and clears its encrypted FID and learner location. If the user disables notifications in app settings, call the same endpoint. On a later sign-in or permission re-enable, register again. When the app switches accounts on one installation, unregister the previous account first, then register the same current FID with the new account.

Handle foreground messages with `FirebaseMessaging.onMessage`. Handle notification taps from both background and terminated states with `onMessageOpenedApp` and `getInitialMessage`. The optional `data.deep_link` is an in-app path. Validate it against the app's own route allowlist before navigating; never open arbitrary external URLs. Show an in-app banner while the app is in the foreground if desired. On iOS, configure foreground presentation options for banner/list/sound as required by the product.

FCM acceptance does not confirm OS display or user visibility. The backend records the provider's acceptance/error response. A network timeout can leave an outcome unknown because FCM may have accepted the request before the connection ended. FCM may not deliver to disabled, offline, stale, or restricted devices.

## Admin workflow

1. Open the admin portal and sign in with the existing admin browser session.
2. Select **Send push notification**.
3. Choose one audience:
   - **All active devices**: every registered, enabled device on a non-suspended, non-admin account.
   - **Account type or location**: combine learner/teacher/institute with any city/state/country filters. A location-only filter is allowed.
   - **Every device for one user**: paste the user's UUID from the user report.
   - **One specific device**: open **Registered push devices**, find the target, and paste its backend `deviceId`. That report never shows the FID.
4. Check the live active-device count, set title/message, and optionally add an internal app path such as `/feed`.
5. Type `SEND` and queue the campaign.
6. Review **Campaign history** and **Delivery attempts**. `accepted` means the FCM HTTP request was accepted; `failed` means the provider rejected it or the worker could not send it. An FCM `UNREGISTERED` result disables the stale device and clears its encrypted FID.

Campaign creation, device registration and send outcomes have append-only allowlisted business-activity events. Reports and exports use explicit columns and admin access logging. FIDs, Firebase credentials, bearer tokens and full user objects are never stored in business-activity snapshots or shown in the admin device report.

## Backend deployment checklist

1. In Firebase/Google Cloud, enable the Firebase Cloud Messaging API for the target project. Grant the service account the Firebase Cloud Messaging API Admin role (or equivalent `cloudmessaging.messages.create` permission).
2. Set the existing server environment values using the target Firebase project and service account:

   ```dotenv
   FIREBASE_PROJECT_ID=<Firebase project ID>
   FIREBASE_SERVICE_ACCOUNT_PATH=storage/app/private/firebase/nearby_teacher.json
   FIREBASE_MESSAGING_ENABLED=true
   ```

   Keep the private JSON outside the public web root and readable only by the app service user. Do not commit it or paste it into chat/logs. This backend currently requires a service-account JSON/path for FCM sends.

3. Deploy the code, then run the new migration and clear cached config:

   ```bash
   php artisan migrate --force
   php artisan config:clear
   php artisan config:cache
   ```

4. Install/restart the dedicated push worker using the repository deployment setup:

   ```bash
   bash scripts/setup-deploy-services.sh /var/www/html/nearby_teachers www-data
   systemctl status nearby-teacher-push --no-pager
   journalctl -u nearby-teacher-push -f
   ```

   The worker listens to the isolated `push` queue with a 75-second timeout. The database queue's `retry_after` must remain greater than that timeout (the current default is 90 seconds). Do not route push to the email `notifications` queue.

5. After deploy, verify the app registration endpoint with a real signed-in app and granted notification permission. Then send a small test to one test device from the admin portal before broadcasting. Never send a production-wide test to validate configuration.

The API endpoint is authenticated by a Laravel user bearer. Admin sending uses the admin browser session; no public/mobile admin campaign API is exposed. The audience preview and final send both resolve filters on the backend, so clients cannot supply SQL/table names or raw FID lists.

