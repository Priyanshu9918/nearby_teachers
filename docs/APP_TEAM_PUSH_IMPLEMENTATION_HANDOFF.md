# Flutter push notifications: implementation handoff

Updated: 2 November 2026

This document is intended to be copied into the Flutter app repository and
given to the app team or to Codex. It is the implementation contract for push
notifications between the Nearby Teacher Flutter app and the Laravel backend.

The backend endpoints already exist. The app team must implement Firebase
Cloud Messaging (FCM), device registration, notification handling, and safe
logout integration. Do not add Firebase service-account credentials to the
mobile app.

## Goal

After this work:

1. A signed-in user who grants notification permission registers the current
   Firebase Installation ID (FID) with the Laravel backend.
2. The registration is refreshed on app startup and whenever Firebase emits a
   new or refreshed FID.
3. Foreground, background, and terminated-state notifications are handled.
4. An allowlisted internal `deep_link` can open the appropriate app screen.
5. The device is unregistered before logout, when notifications are disabled,
   and before switching accounts.
6. Push-registration failures never prevent login or normal app use.

## Important terminology

- `firebaseInstallationId`: The FID emitted by
  `FirebaseMessaging.instance.onRegistered`. This is sent to the backend.
- `deviceId`: The backend-generated numeric ID returned after registration.
  It is used by administrators when targeting one specific registered device.
- Laravel bearer token: The app's normal Nearby Teacher API login token. It is
  required for both push-device endpoints.

Do not use an IMEI, Android hardware ID, advertising ID, serial number, or a
random app-generated UUID. Do not mix the recommended FID flow with the legacy
FCM `getToken()` / `onTokenRefresh` flow.

## Configuration supplied outside this document

The app team must receive these through the normal secure project setup:

- Android `google-services.json` for the same Firebase project as the backend.
- iOS `GoogleService-Info.plist` for the same Firebase project as the backend.
- The existing app API base URL configuration.

Current deployed API base:

```text
http://157.245.125.79/nearby_teachers/public
```

Do not hardcode that URL in multiple source files. Use the app's existing API
base configuration so a future domain or server change requires one update.

Never place the backend Firebase service-account JSON/private key in the app,
source control, logs, screenshots, fixtures, or test data.

## Backend API contract

### Register or refresh the current installation

Call this only after the user is signed in with a valid Laravel bearer and has
granted notification permission.

```http
POST /push/devices
Authorization: Bearer <Laravel bearer token>
Accept: application/json
Content-Type: application/json
```

Android example:

```json
{
  "firebaseInstallationId": "<FID from onRegistered>",
  "notificationsEnabled": true,
  "platform": "android",
  "locationConsent": false
}
```

iOS uses the same request with `"platform":"ios"`.

Success response:

```json
{
  "deviceId": 123,
  "registered": true
}
```

The request is idempotent for the same FID. Calling it again refreshes the
existing backend registration, moves it to the currently signed-in account if
appropriate, and re-enables a previously disabled registration.

Do not make login success depend on this request. If registration fails because
the device is offline or the backend is temporarily unavailable, keep the user
signed in and retry later with bounded backoff.

### Optional learner location targeting

Teacher and institute locations come from their backend profiles. Only a
learner who has explicitly consented may submit coarse location fields:

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

Do not send latitude, longitude, precise location, or background location.
When consent is false, omit all three location values.

### Unregister the current installation

```http
DELETE /push/devices/current
Authorization: Bearer <Laravel bearer token>
Accept: application/json
Content-Type: application/json
```

```json
{
  "firebaseInstallationId": "<current FID>"
}
```

Success response:

```json
{
  "registered": false
}
```

Call this endpoint:

- before `POST /auth/logout`;
- when the user disables notifications in app settings; and
- before changing from one signed-in account to another on the same install.

Unregistration must be best-effort. A network failure must not trap the user in
the signed-in state. Record a local pending-cleanup flag if the app architecture
supports it, continue logout, and reconcile safely on the next authenticated
session. Never send the old user's FID using the new user's bearer merely to
perform cleanup.

### Validate the saved app session

The app may use the existing endpoint during startup:

```http
GET /auth/session
Authorization: Bearer <Laravel bearer token>
Accept: application/json
```

- A successful response means the app may refresh the push registration.
- `401` or `403` means the bearer must not be used for device registration;
  route the user through the existing login flow.

Laravel app tokens currently last 30 days. The backend does not currently send
an event at the instant a stateless token expires. Successful login followed by
registration restores the device association.

## Firebase client implementation

Use Firebase Core and Firebase Messaging versions compatible with the existing
Flutter project. Preserve the app's existing dependency injection, networking,
secure storage, navigation, and state-management conventions.

### Android

Inside the `<application>` element of `AndroidManifest.xml`, enable FID-based
messaging registration:

```xml
<meta-data
    android:name="firebase_messaging_installation_id_enabled"
    android:value="true" />
```

Request the notification runtime permission on Android 13 and later at an
appropriate user-visible point. Preserve any existing notification channel
setup and create a suitable default channel if the app does not have one.

### iOS

Add this to `Info.plist`:

```xml
<key>FirebaseMessagingInstallationIdEnabled</key>
<true/>
```

Enable Push Notifications and the required Background Modes capability in the
iOS target. APNs credentials must be configured in the Firebase project. Wait
until the APNs token is available before making Firebase Messaging registration
calls that require it.

### Registration listener

Subscribe before any explicit `register()` call:

```dart
final messaging = FirebaseMessaging.instance;

messaging.onRegistered.listen(
  (installationId) {
    // If permission is granted and a Laravel bearer is available,
    // POST installationId to /push/devices.
  },
  onError: (Object error, StackTrace stackTrace) {
    // Report only a sanitized failure. Do not log the FID or bearer token.
  },
);

await messaging.register();
```

With auto-initialization enabled, Firebase also emits registration updates
during startup/routine sync. Keep one subscription for the application
lifecycle and avoid duplicate listeners after rebuilds or repeated login.

If the FID arrives before login, retain it only using the app's established
secure/local state policy, then upload it after a Laravel bearer is available.
Do not send it to the backend without authentication.

## Required app lifecycle

### Successful login or registration

1. Complete the existing Laravel login/registration flow.
2. Store the Laravel bearer using the app's existing secure-token mechanism.
3. Ask for notification permission at the product-approved point.
4. Start/confirm FCM registration and listen for `onRegistered`.
5. Upload the FID with `POST /push/devices`.
6. Treat upload failure as non-fatal and retry later.

### App startup or resume

1. Initialize Firebase once.
2. Attach messaging listeners once.
3. Restore the existing Laravel session.
4. If the user is authenticated and permission is granted, ensure Firebase is
   registered; upload every FID emitted by `onRegistered`.
5. Do not repeatedly display the permission prompt after denial.

### Logout

Perform this order:

1. Read the current known FID.
2. Best-effort `DELETE /push/devices/current` using the still-valid bearer.
3. Call the existing `POST /auth/logout` endpoint.
4. Clear the saved Laravel bearer and user state.
5. Sign out from Firebase/Google if the existing authentication flow requires
   it.
6. Disconnect Socket.IO and clear private cached data as already required.

The backend logout endpoint revokes all API sessions for that user. Do not call
logout before device unregistration because the unregister request would then
lose authorization.

### Notification setting disabled/enabled

- Disabled: unregister the current FID, then update local notification state.
  Do not log the user out.
- Enabled again: request/check permission, obtain a registered FID, and call
  `POST /push/devices` again.

### Account switch

Unregister with the old account's bearer, complete old-account logout, complete
new-account login, then register the current FID with the new bearer.

## Receiving notifications

Implement all three states:

- Foreground: listen to `FirebaseMessaging.onMessage`; show an in-app banner or
  local presentation consistent with the product UI.
- Background: handle `FirebaseMessaging.onMessageOpenedApp`.
- Terminated: call `FirebaseMessaging.instance.getInitialMessage()` once during
  startup after navigation is ready.

The backend payload contains a notification plus string data similar to:

```json
{
  "notification": {
    "title": "Nearby Teacher",
    "body": "Open the app to see this update"
  },
  "data": {
    "campaign_id": "123",
    "deep_link": "/feed"
  }
}
```

`deep_link` is optional. It is an internal app path, not an arbitrary URL.
Create an explicit route allowlist/mapping. Reject unknown paths, schemes,
hosts, protocol-relative values, path traversal, and routes the current user is
not authorized to open. If authentication is required, defer navigation until
the existing session/login gate has completed.

Deduplicate tap handling so the same notification is not navigated twice when
startup and opened-app callbacks race. Use `campaign_id` only as a correlation
value; do not treat it as authorization.

## Error handling requirements

- `401`/`403` from a push-device endpoint: do not retry with the same invalid
  bearer. Follow the existing session-expired flow.
- `422`: treat as an app/request-contract bug and record a sanitized diagnostic.
- `429`: retry later using bounded exponential backoff with jitter.
- `5xx`/network failure: keep login working and retry later with bounded backoff.
- Permission denied: do not register; show the app's normal disabled state and
  allow the user to open operating-system settings if the product supports it.
- Never log the Laravel bearer, FID, Firebase configuration files, notification
  body containing user data, or complete HTTP request/response headers.

Do not retry forever. Coalesce duplicate uploads so repeated lifecycle events do
not create a request storm. The backend request itself is idempotent for a FID.

## Out of scope

- Do not build an in-app notification inbox/read-unread database unless it is a
  separate approved feature.
- Do not replace chat Socket.IO with FCM. Socket.IO remains live connected-app
  delivery; FCM is operating-system push delivery.
- Do not expose an admin-send API in the mobile app.
- Do not put Firebase service-account credentials in the mobile repository.
- Do not implement device tracking with hardware identifiers.

## Acceptance checklist

The app implementation is complete only when all of the following pass:

- [ ] Android and iOS use the Firebase project supplied by the backend team.
- [ ] FID-based registration is enabled in Android and iOS configuration.
- [ ] Notification permission is handled without blocking login.
- [ ] `onRegistered` is subscribed once before `register()` is called.
- [ ] A signed-in permitted device successfully calls `POST /push/devices`.
- [ ] Repeating registration refreshes the device without an app-visible error.
- [ ] FID refresh/re-registration is uploaded to the backend.
- [ ] Foreground notification presentation works.
- [ ] Background notification tap navigation works.
- [ ] Terminated-app notification tap navigation works.
- [ ] Unknown or unauthorized deep links are rejected safely.
- [ ] Notification disable calls the unregister endpoint without logging out.
- [ ] Logout calls unregister before the auth logout endpoint.
- [ ] Account switching does not leave the old account registered on the device.
- [ ] Expired bearer responses route through the existing login/session flow.
- [ ] Push-registration network failures do not break login or app startup.
- [ ] Tests do not contain real FIDs, bearer tokens, or Firebase secrets.

## Minimum tests to add in the Flutter repository

Use the project's existing test tools and architecture. At minimum test:

1. Registration request shape for Android and iOS.
2. Registration is skipped when permission is denied or no bearer exists.
3. Successful login triggers registration without depending on its success.
4. App startup refreshes registration for a valid authenticated session.
5. `401`/`403` stops retry and invokes the session-expired behavior.
6. Logout attempts unregister before auth logout and still completes when
   unregister has a network failure.
7. Account switch preserves old-user/new-user request ordering.
8. Foreground message handling.
9. Background and terminated tap handling with deduplication.
10. Deep-link allowlist accepts known internal routes and rejects unsafe input.

Perform the final manual test against a non-production test account and one
specific backend `deviceId`. The backend administrator should first target that
single device, verify foreground/background/terminated behavior, and inspect
the delivery-attempt report before sending to any broader audience.

## Instructions for Codex in the Flutter repository

Use this section as the implementation request:

> Inspect the Flutter repository's existing authentication, API client, secure
> storage, Firebase initialization, navigation, state management, lifecycle,
> and test conventions. Implement the complete push-notification contract in
> this document using those conventions. Do not create a parallel auth or HTTP
> stack. Keep login usable when push registration fails. Implement FID-based
> Firebase Messaging registration, the authenticated register/unregister API
> calls, correct logout/account-switch ordering, all three receive/tap states,
> safe deep-link allowlisting, bounded retry behavior, and meaningful tests.
> Preserve unrelated work and do not add any real secret or credential to the
> repository. If Firebase platform files or the exact route allowlist are not
> present, implement everything that is safe, clearly report the missing input,
> and do not invent credentials or navigation routes.

Backend implementation details and admin/deployment operations are documented
separately in `docs/APP_TEAM_PUSH_NOTIFICATIONS.md` in the backend repository.
