# Share links for the mobile team

Use the final HTTPS host that serves this Laravel app (the same host must serve the association files). The current local `.env` uses an HTTP IP address; iOS Universal Links and verified Android App Links require a real HTTPS domain. Set the production public URL accordingly before distributing links.

| Shared item | URL path | Mobile destination |
| --- | --- | --- |
| Teacher profile | `/share/teacher/{teacherUserId}` | Open teacher profile by numeric `users.id` |
| City feed | `/share/city-feed/{city}` | Open the specified city's feed; URL-encode the city name as one path segment |

Examples on the eventual HTTPS host: `/share/teacher/123`, `/share/city-feed/delhi`, and `/share/city-feed/New%20Delhi`. Share the HTTPS URL from the app. The web fallback shows only Android and iPhone store buttons; it does not fetch or display a profile or feed. `/download` and its referral behavior are independent and unchanged.

Android: add an `https` auto-verified intent filter for the production host and `/share/` path prefix. Package: `com.bsl_nearbyteacher`. Server verification JSON is at `/.well-known/assetlinks.json` and includes the Play Store and supplied local-build SHA-256 fingerprints. Make sure the actual installed build is signed by one of those certificates.

iOS: add `applinks:<production-host>` to Associated Domains. App ID: `6A5V7Y4R6A.com.bsl.nearbyteacher`. Server association JSON is at `/.well-known/apple-app-site-association`. The app should parse the two paths above and route to their screens. If login is required, preserve the link and open the destination after login.

Both association files must be publicly reachable over HTTPS on the exact link host, without authentication or redirects. The web server must route `/.well-known/` requests to Laravel and allow those dot paths. Test both installed-app and browser fallback behavior on real devices after deployment.

This feature only serves links and association files. It does not create business state or historical reporting events.
