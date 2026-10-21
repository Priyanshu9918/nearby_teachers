# API mock response examples

Fictional examples for all 69 requests in the [app-team Postman collection](postman/app-team.postman_collection.json). These illustrate the current controller response shapes; they are not live API results or an exhaustive list of failures. Mock tokens are deliberately unusable. IDs, counts, names and lookup metadata are illustrative. Database decimals/booleans and timestamps can vary by deployment as explained in the [integration guide](APP_TEAM_API.md).

In Postman, expand a request and select a saved **Mock** example to preview its status, headers and JSON body without sending it. Importing examples does not start a mock server.

The examples below include successful results, selected empty states, validation errors and business errors. Protected routes also enforce the authentication and phone-verification requirements in the integration guide.

## 01 Authentication

### POST /auth/otp/request

Mock — 200 OTP requested (`Content-Type: application/json`)

```json
{
  "ok": true,
  "expiresInMinutes": 10
}
```

Mock — 200 OTP requested — local/testing only (`Content-Type: application/json`)

```json
{
  "ok": true,
  "expiresInMinutes": 10,
  "testMode": true,
  "otp": "483921"
}
```

Mock — 429 Too many codes requested. Try again in a few minutes. (`Content-Type: application/json`)

```json
{
  "error": "Too many codes requested. Try again in a few minutes."
}
```

Mock — 503 Unable to send a verification code right now. Please try again later. (`Content-Type: application/json`)

```json
{
  "error": "Unable to send a verification code right now. Please try again later."
}
```

### POST /auth/otp/verify

Mock — 200 Phone verified — new registration (`Content-Type: application/json`)

```json
{
  "token": "MOCK_LARAVEL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "New user",
    "email": null,
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": null,
    "profile_completed_at": null,
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": false
  },
  "mobileVerified": true
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "Incorrect code",
  "errors": {
    "code": [
      "Incorrect code"
    ]
  }
}
```

Mock — 409 This account is already registered. Log in with your email and password. (`Content-Type: application/json`)

```json
{
  "error": "This account is already registered. Log in with your email and password.",
  "loginRequired": true,
  "loginEndpoint": "/auth/login"
}
```

### POST /auth/google

Mock — 200 Google sign-in — phone linking required (`Content-Type: application/json`)

```json
{
  "token": "MOCK_LARAVEL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": null,
    "google_id": "mock-google-user-id",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": null,
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": "2026-10-21 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "mobileVerified": false,
  "phoneVerificationRequired": true
}
```

Mock — 200 Google sign-in — phone already verified (`Content-Type: application/json`)

```json
{
  "token": "MOCK_LARAVEL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "mobileVerified": true,
  "phoneVerificationRequired": false
}
```

Mock — 400 That Google sign-in could not be verified. Try again. (`Content-Type: application/json`)

```json
{
  "error": "That Google sign-in could not be verified. Try again."
}
```

### PATCH /auth/link-phone

Mock — 200 Phone linked (`Content-Type: application/json`)

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "mobileVerified": true
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "Incorrect code",
  "errors": {
    "code": [
      "Incorrect code"
    ]
  }
}
```

Mock — 409 This phone number is already linked to a different account. Log in with that account to manage it, or contact BSL for help combining accounts. (`Content-Type: application/json`)

```json
{
  "error": "This phone number is already linked to a different account. Log in with that account to manage it, or contact BSL for help combining accounts."
}
```

### POST /auth/login

Mock — 200 Signed in (`Content-Type: application/json`)

```json
{
  "token": "MOCK_LARAVEL_BEARER_TOKEN_NOT_VALID_FOR_AUTHENTICATION",
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "mobileVerified": true,
  "phoneVerificationRequired": false
}
```

Mock — 401 Incorrect email or password. (`Content-Type: application/json`)

```json
{
  "error": "Incorrect email or password."
}
```

### GET /auth/session

Mock — 200 Current session (`Content-Type: application/json`)

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  },
  "mobileVerified": true
}
```


## 02 Registration

### GET /auth/onboarding

Mock — 200 Registration complete (`Content-Type: application/json`)

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-21 10:00:00",
  "nextStep": "age_verification",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

Mock — 200 New phone identity — complete profile (`Content-Type: application/json`)

```json
{
  "mobileVerified": true,
  "profileCompleted": false,
  "registrationCompleted": false,
  "autosaveEnabled": false,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [
    "fullName",
    "email",
    "password",
    "termsAccepted",
    "acknowledgeTeachersNotBackgroundChecked"
  ],
  "savedAt": "2026-10-21 10:00:00",
  "nextStep": "complete_profile",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "New user",
    "email": null,
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": false,
    "termsAccepted": false,
    "teachersNotBackgroundCheckedAcknowledged": false,
    "acknowledgeTeachersNotBackgroundChecked": false
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "New user",
    "email": null,
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": null,
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": null,
    "profile_completed_at": null,
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

### POST /auth/register — Student

Mock — 200 Registration complete — learner (`Content-Type: application/json`)

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-21 10:00:00",
  "nextStep": "age_verification",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  },
  "nextStepEndpoint": "/auth/date-of-birth"
}
```

Mock — 409 Registration is already complete. Use PATCH /auth/onboarding to update your profile. (`Content-Type: application/json`)

```json
{
  "error": "Registration is already complete. Use PATCH /auth/onboarding to update your profile.",
  "registrationCompleted": true
}
```

Mock — 403 OTP verification required (`Content-Type: application/json`)

```json
{
  "error": "Please verify your mobile number with OTP before registering.",
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/otp/verify"
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "This email is already linked to a different account.",
  "errors": {
    "email": [
      "This email is already linked to a different account."
    ]
  }
}
```

### POST /auth/register — Teacher

Mock — 200 Registration complete — teacher (`Content-Type: application/json`)

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-21 10:00:00",
  "nextStep": "home",
  "draft": {
    "accountType": "teacher",
    "role": "teacher",
    "fullName": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "city": "Delhi",
    "primarySubjectId": 1,
    "primarySubjectName": "Mathematics",
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000002",
    "account_type": "teacher",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  },
  "nextStepEndpoint": "/profiles/me"
}
```

Mock — 409 Registration is already complete. Use PATCH /auth/onboarding to update your profile. (`Content-Type: application/json`)

```json
{
  "error": "Registration is already complete. Use PATCH /auth/onboarding to update your profile.",
  "registrationCompleted": true
}
```

Mock — 403 OTP verification required (`Content-Type: application/json`)

```json
{
  "error": "Please verify your mobile number with OTP before registering.",
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/otp/verify"
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "This email is already linked to a different account.",
  "errors": {
    "email": [
      "This email is already linked to a different account."
    ]
  }
}
```

### POST /auth/register — Institute

Mock — 200 Registration complete — institute (`Content-Type: application/json`)

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-21 10:00:00",
  "nextStep": "home",
  "draft": {
    "accountType": "institute",
    "role": "institute",
    "fullName": "Example Learning Centre",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "city": "Delhi",
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": "Asha Sharma",
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000002",
    "account_type": "institute",
    "full_name": "Example Learning Centre",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  },
  "nextStepEndpoint": "/profiles/me"
}
```

Mock — 409 Registration is already complete. Use PATCH /auth/onboarding to update your profile. (`Content-Type: application/json`)

```json
{
  "error": "Registration is already complete. Use PATCH /auth/onboarding to update your profile.",
  "registrationCompleted": true
}
```

Mock — 403 OTP verification required (`Content-Type: application/json`)

```json
{
  "error": "Please verify your mobile number with OTP before registering.",
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/otp/verify"
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "This email is already linked to a different account.",
  "errors": {
    "email": [
      "This email is already linked to a different account."
    ]
  }
}
```

### POST /auth/onboarding/complete

Mock — 200 Registration complete — learner (`Content-Type: application/json`)

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-21 10:00:00",
  "nextStep": "age_verification",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  },
  "nextStepEndpoint": "/auth/date-of-birth"
}
```

Mock — 409 Registration is already complete. Use PATCH /auth/onboarding to update your profile. (`Content-Type: application/json`)

```json
{
  "error": "Registration is already complete. Use PATCH /auth/onboarding to update your profile.",
  "registrationCompleted": true
}
```

Mock — 403 OTP verification required (`Content-Type: application/json`)

```json
{
  "error": "Please verify your mobile number with OTP before registering.",
  "message": "Please verify your mobile number with OTP before registering.",
  "code": "OTP_VERIFICATION_REQUIRED",
  "mobileVerified": false,
  "phoneVerificationRequired": true,
  "canSubmit": false,
  "nextStep": "mobile_verification",
  "requestOtpEndpoint": "/auth/otp/request",
  "verifyPhoneEndpoint": "/auth/otp/verify"
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "This email is already linked to a different account.",
  "errors": {
    "email": [
      "This email is already linked to a different account."
    ]
  }
}
```

### PATCH /auth/onboarding

Mock — 200 Profile draft saved (`Content-Type: application/json`)

```json
{
  "mobileVerified": true,
  "profileCompleted": true,
  "registrationCompleted": true,
  "autosaveEnabled": true,
  "canSubmit": false,
  "blockers": [],
  "missingFields": [],
  "savedAt": "2026-10-21 10:00:00",
  "nextStep": "age_verification",
  "draft": {
    "accountType": "learner",
    "role": "student",
    "fullName": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "city": null,
    "primarySubjectId": null,
    "primarySubjectName": null,
    "contactPerson": null,
    "passwordSet": true,
    "termsAccepted": true,
    "teachersNotBackgroundCheckedAcknowledged": true,
    "acknowledgeTeachersNotBackgroundChecked": true
  },
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": null,
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": null,
    "auth_version": 0,
    "auth_tokens_invalid_before": null
  }
}
```

Mock — 409 Complete registration before using autosave. (`Content-Type: application/json`)

```json
{
  "error": "Complete registration before using autosave.",
  "registrationRequired": true,
  "autosaveEnabled": false,
  "registerEndpoint": "/auth/register"
}
```


## 03 Email recovery

### POST /auth/forgot-password

Mock — 200 Generic recovery acknowledgement (`Content-Type: application/json`)

```json
{
  "ok": true,
  "message": "If an eligible account exists, a password reset code will be sent to its email.",
  "expiresInMinutes": 10
}
```

### POST /auth/reset-password

Mock — 200 Password reset — sign in again (`Content-Type: application/json`)

```json
{
  "ok": true,
  "message": "Password reset successfully. Log in with your email and new password."
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "Invalid or expired code. Request a new code and try again.",
  "errors": {
    "code": [
      "Invalid or expired code. Request a new code and try again."
    ]
  }
}
```

### POST /auth/email/verification/request

Mock — 200 Verification email requested (`Content-Type: application/json`)

```json
{
  "ok": true,
  "message": "An email verification code has been requested.",
  "expiresInMinutes": 10
}
```

### POST /auth/email/verification/verify

Mock — 200 Email verified (`Content-Type: application/json`)

```json
{
  "ok": true,
  "emailVerified": true
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "Invalid or expired code. Request a new code and try again.",
  "errors": {
    "code": [
      "Invalid or expired code. Request a new code and try again."
    ]
  }
}
```


## 04 Account and consent

### POST /auth/account-type

Mock — 200 Account type saved (`Content-Type: application/json`)

```json
{
  "ok": true
}
```

### PATCH /auth/preferred-language

Mock — 200 Language saved (`Content-Type: application/json`)

```json
{
  "ok": true,
  "preferredLanguage": "en"
}
```

### PATCH /auth/avatar

Mock — 200 Avatar awaiting moderation (`Content-Type: application/json`)

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "avatar_url": "https://example.com/avatar.jpg",
    "avatar_status": "pending"
  },
  "message": "Photo submitted — it will appear on your profile once reviewed."
}
```

### PATCH /auth/link-google

Mock — 200 Google account linked (`Content-Type: application/json`)

```json
{
  "user": {
    "id": "10000000-0000-4000-8000-000000000001",
    "account_type": "learner",
    "full_name": "Asha Sharma",
    "email": "asha@example.com",
    "phone": "+919876543210",
    "google_id": "mock-google-user-id",
    "avatar_url": null,
    "is_admin": 0,
    "date_of_birth": null,
    "is_minor": 0,
    "guardian_name": null,
    "guardian_contact": null,
    "parental_consent_token": null,
    "parental_consent_confirmed_at": null,
    "safety_disclaimer_acknowledged_at": "2026-10-21 10:00:00",
    "avatar_status": "approved",
    "preferred_language": "en",
    "suspended_at": null,
    "suspended_reason": null,
    "suspended_by": null,
    "phone_verified_at": "2026-10-21 10:00:00",
    "referral_code": null,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "terms_accepted_at": "2026-10-21 10:00:00",
    "profile_completed_at": "2026-10-21 10:00:00",
    "email_verified_at": "2026-10-21 10:00:00",
    "auth_version": 0,
    "auth_tokens_invalid_before": null,
    "password_set": true
  }
}
```

### POST /auth/date-of-birth

Mock — 200 Adult age recorded (`Content-Type: application/json`)

```json
{
  "isMinor": false
}
```

Mock — 200 Minor — guardian consent pending (`Content-Type: application/json`)

```json
{
  "isMinor": true,
  "parentalConsentPending": true,
  "message": "A consent request has been sent to your parent/guardian. Messaging and booking are unavailable until they confirm."
}
```

Mock — 400 You must be at least 13 to use Nearby Teacher (`Content-Type: application/json`)

```json
{
  "error": "You must be at least 13 to use Nearby Teacher"
}
```

### POST /auth/parental-consent/confirm

Mock — 200 Guardian consent confirmed (`Content-Type: application/json`)

```json
{
  "ok": true,
  "confirmedFor": "Asha Sharma"
}
```

Mock — 404 This consent link is invalid or has already been used. (`Content-Type: application/json`)

```json
{
  "error": "This consent link is invalid or has already been used."
}
```

### GET /auth/safety-disclaimer

Mock — 200 Safety disclaimer (`Content-Type: application/json`)

```json
{
  "text": "Nearby Teacher does not conduct criminal background checks, identity verification, or credential checks on teachers or institutes — except the \"BSL Verified\" badge, which only confirms an institute is directly operated by BSL and is not a safety certification. You are solely responsible for evaluating the suitability and safety of any teacher or institute before engaging their services, especially for a minor in your care. We recommend independently verifying credentials, checking references, and meeting before committing to lessons — particularly for in-person or unsupervised sessions involving a minor."
}
```

### POST /auth/acknowledge-safety-disclaimer

Mock — 200 Safety acknowledgement saved (`Content-Type: application/json`)

```json
{
  "ok": true
}
```


## 05 Catalog and localization

### GET /subjects

Mock — 200 Subject domains and featured subjects (`Content-Type: application/json`)

```json
{
  "bslFeatured": [
    {
      "id": 2,
      "name": "English Speaking",
      "domain_id": 1
    }
  ],
  "domains": [
    {
      "id": "instant-teacher-available",
      "name": "Instant Teacher Available",
      "icon": "ti-bolt",
      "isVirtual": true,
      "subjects": [
        {
          "id": 2,
          "name": "English Speaking",
          "domain_id": 1
        }
      ]
    },
    {
      "id": 1,
      "name": "Academic subjects (mock)",
      "icon": "ti-book",
      "subjects": [
        {
          "id": 1,
          "name": "Mathematics",
          "domain_id": 1,
          "is_bsl": false
        },
        {
          "id": 2,
          "name": "English Speaking",
          "domain_id": 1,
          "is_bsl": true
        }
      ]
    }
  ]
}
```

### GET /i18n/languages

Mock — 200 Supported languages (`Content-Type: application/json`)

```json
{
  "languages": [
    {
      "code": "en",
      "name": "English",
      "nativeName": "English",
      "rtl": false
    },
    {
      "code": "hi",
      "name": "Hindi",
      "nativeName": "हिन्दी",
      "rtl": false
    },
    {
      "code": "es",
      "name": "Spanish",
      "nativeName": "Español",
      "rtl": false
    },
    {
      "code": "fr",
      "name": "French",
      "nativeName": "Français",
      "rtl": false
    },
    {
      "code": "ar",
      "name": "Arabic",
      "nativeName": "العربية",
      "rtl": true
    },
    {
      "code": "zh",
      "name": "Mandarin Chinese",
      "nativeName": "中文",
      "rtl": false
    },
    {
      "code": "pt",
      "name": "Portuguese",
      "nativeName": "Português",
      "rtl": false
    },
    {
      "code": "ru",
      "name": "Russian",
      "nativeName": "Русский",
      "rtl": false
    }
  ]
}
```

### GET /i18n/{{lang}}

Mock — 200 English translations (`Content-Type: application/json`)

```json
{
  "requested": "en",
  "served": "en",
  "translations": {
    "_meta": {
      "code": "en",
      "name": "English",
      "nativeName": "English",
      "rtl": false
    },
    "nav": {
      "home": "Home",
      "search": "Search",
      "messages": "Messages",
      "feed": "Feed",
      "profile": "Profile"
    },
    "common": {
      "appName": "Nearby Teacher",
      "byBsl": "by British School of Language",
      "search": "Search",
      "message": "Message",
      "requestBooking": "Request booking",
      "call": "Call",
      "whatsapp": "WhatsApp",
      "submit": "Submit",
      "cancel": "Cancel",
      "save": "Save",
      "edit": "Edit profile",
      "logOut": "Log out",
      "free": "Free",
      "verified": "BSL Verified",
      "loading": "Loading…",
      "seeMore": "See more",
      "back": "Back",
      "download": "Download the app",
      "openInApp": "Open in app"
    },
    "home": {
      "nearby": "Nearby",
      "otherCity": "Other city / online",
      "radius3": "3km",
      "radius7": "7km",
      "radius14": "14km",
      "freelancers": "Freelancers nearby",
      "institutes": "Institutes nearby"
    },
    "search": {
      "placeholder": "Search subject, teacher…",
      "teachersFoundNearby": "{count} teachers found nearby",
      "distance": "{km}km away",
      "rating": "{rating} ({count} reviews)",
      "lessonsCompleted": "{count} lessons completed"
    },
    "profile": {
      "trialNone": "No trial",
      "trial1Day": "1-day trial",
      "trialWeek": "1-week trial",
      "bio": "Bio",
      "subjects": "Subjects",
      "lessonModes": "Lesson modes",
      "ratePerHour": "Rate per hour",
      "website": "Visit website",
      "linkedIn": "View LinkedIn",
      "instructors": "Instructors"
    },
    "booking": {
      "confirmed": "Lesson confirmed",
      "addToCalendar": "Add to calendar",
      "status_requested": "Requested",
      "status_accepted": "Accepted",
      "status_declined": "Declined",
      "status_completed": "Completed"
    },
    "review": {
      "prompt": "How was your lesson?",
      "commentPlaceholder": "Add a comment (optional)",
      "submitReview": "Submit review"
    },
    "settings": {
      "title": "Settings",
      "location": "Location",
      "notifications": "Notifications",
      "privacySafety": "Privacy and safety",
      "reportProblem": "Report a problem",
      "contactBsl": "Contact BSL",
      "referFriend": "Refer a friend",
      "language": "Language"
    },
    "safety": {
      "disclaimerTitle": "Before you message or book",
      "acknowledge": "I understand"
    },
    "feed": {
      "title": "City feed",
      "lookingFor": "Looking for",
      "newPost": "New post",
      "like": "Like",
      "comment": "Comment",
      "share": "Share"
    },
    "referral": {
      "title": "Refer a friend",
      "subtitle": "Help a friend find a nearby teacher",
      "shareLink": "Share your link",
      "friendsJoined": "{count} friends joined using your link"
    },
    "contactBsl": {
      "title": "Contact BSL",
      "subject": "Subject",
      "messagePlaceholder": "How can we help?",
      "pastTickets": "Your past tickets"
    },
    "website": {
      "cityDirectory": "All cities",
      "teachersIn": "Teachers in {city}",
      "institutesIn": "Institutes in {city}",
      "contactDetailsInApp": "Full contact details, reviews, and booking are available in the app.",
      "notFound": "Page not found",
      "downloadPrompt": "Get the app to message, book, and see full profiles",
      "openingApp": "Opening the app…",
      "findNearYou": "Find teachers and institutes near you",
      "citiesOnPlatform": "{count} cities on Nearby Teacher",
      "noCitiesYet": "No cities listed yet — check back soon.",
      "listingsCount": "{count} listings",
      "teachersAndInstitutesIn": "Teachers and institutes in {city}",
      "subjectsCovered": "{count} subjects covered",
      "subjectsTaughtIn": "Subjects taught in {city}",
      "institutesHeading": "Institutes",
      "teachersHeading": "Teachers",
      "noInstitutesIn": "No institutes listed in {city} yet.",
      "noTeachersIn": "No individual teachers listed in {city} yet.",
      "noReviewsYet": "No reviews yet",
      "seeWhatsHappening": "See what's happening in {city}",
      "since": "since {date}",
      "fromPriceHour": "From {price}/hour",
      "priceHour": "{price}/hour",
      "downloadToMessage": "Download the app to message or book",
      "poweredBy": "Powered by",
      "allTeachersInstitutesIn": "All teachers and institutes in {city}",
      "cityFeedTitle": "{city} city feed",
      "feedSubtitle": "What teachers, institutes, and learners in {city} are posting right now.",
      "seeAllHere": "See all teachers and institutes here",
      "noPostsYet": "No posts yet in {city}. Be the first — open the app to post.",
      "replyLikePost": "Reply, like, or post your own — open the Nearby Teacher app.",
      "getApp": "Get the app"
    },
    "errors": {
      "notFound": "Not found",
      "somethingWrong": "Something went wrong",
      "tooManyRequests": "Too many requests. Please slow down and try again shortly.",
      "invalidToken": "Invalid or expired token"
    }
  }
}
```


## 06 Search and profiles

### GET /search

Mock — 200 Nearby providers (`Content-Type: application/json`)

```json
{
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "distance_km": 1.25
    }
  ]
}
```

Mock — 200 No nearby matches (`Content-Type: application/json`)

```json
{
  "results": []
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "The lat field is required.",
  "errors": {
    "lat": [
      "The lat field is required."
    ]
  }
}
```

### GET /search/counts

Mock — 200 Providers in radius (`Content-Type: application/json`)

```json
{
  "teacher_count": 12,
  "institute_count": 3
}
```

### GET /search/other-city

Mock — 200 Online providers in matching city/country (`Content-Type: application/json`)

```json
{
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "city": "Delhi",
      "country": "India"
    }
  ]
}
```

### GET /search/by-subject/{{subjectId}} — nearby

Mock — 200 Subject providers — nearby (`Content-Type: application/json`)

```json
{
  "tier": "nearby",
  "totalCount": 1,
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "distance_km": 1.25
    }
  ]
}
```

### GET /search/by-subject/{{subjectId}} — city

Mock — 200 Subject providers — city (`Content-Type: application/json`)

```json
{
  "tier": "city",
  "totalCount": 1,
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "city": "Delhi",
      "state": "Delhi",
      "country": "India"
    }
  ]
}
```

### GET /search/by-subject/{{subjectId}} — state

Mock — 200 Subject providers — state (`Content-Type: application/json`)

```json
{
  "tier": "state",
  "totalCount": 1,
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "city": "Delhi",
      "state": "Delhi",
      "country": "India"
    }
  ]
}
```

### GET /search/by-subject/{{subjectId}} — country

Mock — 200 Subject providers — country (`Content-Type: application/json`)

```json
{
  "tier": "country",
  "totalCount": 1,
  "results": [
    {
      "profile_type": "teacher",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "price": "500.00",
      "rating_avg": "4.50",
      "rating_count": 10,
      "is_verified": 0,
      "trial_days": 1,
      "city": "Delhi",
      "state": "Delhi",
      "country": "India"
    }
  ]
}
```

### GET /search/by-subject/{{subjectId}} — other-countries

Mock — 200 Countries with matching providers (`Content-Type: application/json`)

```json
{
  "tier": "other-countries",
  "countries": [
    {
      "country": "India",
      "trainer_count": 12
    },
    {
      "country": "United Kingdom",
      "trainer_count": 4
    }
  ]
}
```

### GET /profiles/{{profileType}}/{{providerId}}

Mock — 200 Provider profile — anonymous (`Content-Type: application/json`)

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ]
}
```

Mock — 200 Institute profile (`Content-Type: application/json`)

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000018",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "slug": "example-learning-centre-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Example Learning Centre",
    "avatar_url": null,
    "lessons_completed": 2,
    "contact_person": "Asha Sharma",
    "description": "Lessons for school students",
    "starting_rate": "500.00",
    "instructors_text": null,
    "website_url": null,
    "institute_type": "independent",
    "is_bsl_official": 0,
    "bsl_official_since": null
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ]
}
```

Mock — 404 Not found (`Content-Type: application/json`)

```json
{
  "error": "Not found"
}
```

### GET /profiles/{{profileType}}/{{providerId}} — with bearer

Mock — 200 Provider profile — signed in (`Content-Type: application/json`)

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2,
    "phone": "+919876543210"
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneRevealsRemainingToday": 4
}
```

Mock — 200 Daily phone reveal quota reached (`Content-Type: application/json`)

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000008",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "primary_subject_id": 1,
    "bio": "Maths lessons for school students",
    "rate_per_hour": "500.00",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "linkedin_url": null,
    "slug": "asha-sharma-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Asha Sharma",
    "avatar_url": null,
    "lessons_completed": 2
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneLimitReached": true,
  "phoneRevealsRemainingToday": 0
}
```

Mock — 200 Institute profile (`Content-Type: application/json`)

```json
{
  "profile": {
    "id": "10000000-0000-4000-8000-000000000018",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "currency": "INR",
    "lesson_modes": "[\"in_person\", \"online\"]",
    "latitude": "28.6139000",
    "longitude": "77.2090000",
    "address_text": "Example address, Delhi",
    "city": "Delhi",
    "state": "Delhi",
    "country": "India",
    "country_code": "IN",
    "slug": "example-learning-centre-delhi",
    "is_verified": 0,
    "verified_at": null,
    "trial_days": 1,
    "rating_avg": "4.50",
    "rating_count": 10,
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00",
    "full_name": "Example Learning Centre",
    "avatar_url": null,
    "lessons_completed": 2,
    "contact_person": "Asha Sharma",
    "description": "Lessons for school students",
    "starting_rate": "500.00",
    "instructors_text": null,
    "website_url": null,
    "institute_type": "independent",
    "is_bsl_official": 0,
    "bsl_official_since": null,
    "phone": "+919876543210"
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "is_bsl": false
    }
  ],
  "phoneRevealsRemainingToday": 4
}
```

Mock — 404 Not found (`Content-Type: application/json`)

```json
{
  "error": "Not found"
}
```

### PUT /profiles/me — teacher

Mock — 200 Listing saved (`Content-Type: application/json`)

```json
{
  "ok": true
}
```

Mock — 409 Complete registration before updating your listing profile. (`Content-Type: application/json`)

```json
{
  "error": "Complete registration before updating your listing profile.",
  "registrationRequired": true,
  "registerEndpoint": "/auth/register"
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "Latitude and longitude must be provided together",
  "errors": {
    "location": [
      "Latitude and longitude must be provided together"
    ]
  }
}
```

### PUT /profiles/me — institute

Mock — 200 Listing saved (`Content-Type: application/json`)

```json
{
  "ok": true
}
```

Mock — 409 Complete registration before updating your listing profile. (`Content-Type: application/json`)

```json
{
  "error": "Complete registration before updating your listing profile.",
  "registrationRequired": true,
  "registerEndpoint": "/auth/register"
}
```

Mock — 422 Validation error (`Content-Type: application/json`)

```json
{
  "message": "Latitude and longitude must be provided together",
  "errors": {
    "location": [
      "Latitude and longitude must be provided together"
    ]
  }
}
```

### GET /profiles/me/stats

Mock — 200 Provider dashboard stats (`Content-Type: application/json`)

```json
{
  "sinceDays": 30,
  "newRequests": 2,
  "profileViews": 45
}
```


## 07 Bookings

### POST /bookings

Mock — 200 Booking requested (`Content-Type: application/json`)

```json
{
  "booking": {
    "id": "10000000-0000-4000-8000-000000000004",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "10000000-0000-4000-8000-000000000002",
    "subject_id": 1,
    "mode": "online",
    "requested_time": "2026-11-01 10:00:00",
    "price": "500.00",
    "status": "requested",
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00"
  }
}
```

### GET /bookings/mine

Mock — 200 Bookings (`Content-Type: application/json`)

```json
{
  "bookings": [
    {
      "id": "10000000-0000-4000-8000-000000000004",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "subject_id": 1,
      "mode": "online",
      "requested_time": "2026-11-01 10:00:00",
      "price": "500.00",
      "status": "requested",
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00",
      "provider_name": "Asha Sharma",
      "subject_name": "Mathematics"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

Mock — 200 No bookings (`Content-Type: application/json`)

```json
{
  "bookings": [],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

### GET /bookings/incoming

Mock — 200 Bookings (`Content-Type: application/json`)

```json
{
  "bookings": [
    {
      "id": "10000000-0000-4000-8000-000000000004",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "subject_id": 1,
      "mode": "online",
      "requested_time": "2026-11-01 10:00:00",
      "price": "500.00",
      "status": "requested",
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00",
      "learner_name": "Asha Sharma",
      "subject_name": "Mathematics"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

Mock — 200 No bookings (`Content-Type: application/json`)

```json
{
  "bookings": [],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

### PATCH /bookings/{{bookingId}}/status

Mock — 200 Booking accepted (`Content-Type: application/json`)

```json
{
  "booking": {
    "id": "10000000-0000-4000-8000-000000000004",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "10000000-0000-4000-8000-000000000002",
    "subject_id": 1,
    "mode": "online",
    "requested_time": "2026-11-01 10:00:00",
    "price": "500.00",
    "status": "accepted",
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00"
  }
}
```

Mock — 404 Booking not found (`Content-Type: application/json`)

```json
{
  "error": "Booking not found"
}
```


## 08 Messages

### GET /messages

Mock — 200 Conversation inbox (`Content-Type: application/json`)

```json
{
  "conversations": [
    {
      "id": "10000000-0000-4000-8000-000000000003",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "other_name": "Asha Sharma",
      "other_avatar": null,
      "last_message": "Is this lesson slot available?",
      "last_message_at": "2026-10-21 10:00:00",
      "activity_at": "2026-10-21 10:00:00"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

### POST /messages/start

Mock — 200 Conversation started or reused (`Content-Type: application/json`)

```json
{
  "conversationId": "10000000-0000-4000-8000-000000000003"
}
```

### GET /messages/{{conversationId}}

Mock — 200 Message history (`Content-Type: application/json`)

```json
{
  "messages": [
    {
      "id": "10000000-0000-4000-8000-000000000007",
      "conversation_id": "10000000-0000-4000-8000-000000000003",
      "sender_id": "10000000-0000-4000-8000-000000000001",
      "body": "Is this lesson slot available?",
      "created_at": "2026-10-21 10:00:00"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

Mock — 404 Conversation not found (`Content-Type: application/json`)

```json
{
  "error": "Conversation not found"
}
```

### POST /messages/{{conversationId}}

Mock — 200 Message saved (`Content-Type: application/json`)

```json
{
  "message": {
    "id": "10000000-0000-4000-8000-000000000007",
    "conversation_id": "10000000-0000-4000-8000-000000000003",
    "sender_id": "10000000-0000-4000-8000-000000000001",
    "body": "Is this lesson slot available?",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

Mock — 404 Conversation not found (`Content-Type: application/json`)

```json
{
  "error": "Conversation not found"
}
```

### POST /chat/socket/authorize

Mock — 200 Conversation room authorized (`Content-Type: application/json`)

```json
{
  "userId": "10000000-0000-4000-8000-000000000001",
  "room": "private-conversation.10000000-0000-4000-8000-000000000003",
  "expiresInSeconds": 900,
  "authVersion": 0
}
```

Mock — 404 Conversation not found (`Content-Type: application/json`)

```json
{
  "error": "Conversation not found"
}
```


## 09 Feed and reviews

### GET /feed

Mock — 200 City feed (`Content-Type: application/json`)

```json
{
  "posts": [
    {
      "id": "10000000-0000-4000-8000-000000000005",
      "author_id": "10000000-0000-4000-8000-000000000001",
      "post_type": "request",
      "city": "Delhi",
      "subject_id": 1,
      "body": "Looking for maths lessons in Delhi.",
      "image_url": null,
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Asha Sharma",
      "author_avatar": null,
      "like_count": 2,
      "comment_count": 1,
      "liked_by_me": 0
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

### POST /feed

Mock — 200 Post created (`Content-Type: application/json`)

```json
{
  "post": {
    "id": "10000000-0000-4000-8000-000000000005",
    "author_id": "10000000-0000-4000-8000-000000000001",
    "post_type": "request",
    "city": "Delhi",
    "subject_id": 1,
    "body": "Looking for maths lessons in Delhi.",
    "image_url": null,
    "created_at": "2026-10-21 10:00:00"
  }
}
```

Mock — 429 You can post once every 7 days (`Content-Type: application/json`)

```json
{
  "error": "You can post once every 7 days",
  "nextAllowedAt": "2026-10-28T10:00:00+00:00"
}
```

### POST /feed/{{postId}}/like

Mock — 200 Post liked (`Content-Type: application/json`)

```json
{
  "ok": true
}
```

### DELETE /feed/{{postId}}/like

Mock — 200 Like removed (`Content-Type: application/json`)

```json
{
  "ok": true
}
```

### GET /feed/{{postId}}/comments

Mock — 200 Comments (`Content-Type: application/json`)

```json
{
  "comments": [
    {
      "id": "10000000-0000-4000-8000-000000000006",
      "post_id": "10000000-0000-4000-8000-000000000005",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "parent_comment_id": null,
      "body": "Please share more details.",
      "created_at": "2026-10-21 10:00:00",
      "author_name": "Asha Sharma"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```

### POST /feed/{{postId}}/comments

Mock — 200 Comment created (`Content-Type: application/json`)

```json
{
  "comment": {
    "id": "10000000-0000-4000-8000-000000000006",
    "post_id": "10000000-0000-4000-8000-000000000005",
    "user_id": "10000000-0000-4000-8000-000000000002",
    "parent_comment_id": null,
    "body": "Please share more details.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

### POST /reviews

Mock — 200 Review created (`Content-Type: application/json`)

```json
{
  "review": {
    "id": "10000000-0000-4000-8000-000000000009",
    "booking_id": "10000000-0000-4000-8000-000000000004",
    "learner_id": "10000000-0000-4000-8000-000000000001",
    "provider_id": "10000000-0000-4000-8000-000000000002",
    "rating": 5,
    "comment": "Helpful lesson.",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

Mock — 403 Not allowed to review this booking (`Content-Type: application/json`)

```json
{
  "error": "Not allowed to review this booking"
}
```

### GET /reviews/for/{{providerId}}

Mock — 200 Provider reviews (`Content-Type: application/json`)

```json
{
  "reviews": [
    {
      "id": "10000000-0000-4000-8000-000000000009",
      "booking_id": "10000000-0000-4000-8000-000000000004",
      "learner_id": "10000000-0000-4000-8000-000000000001",
      "provider_id": "10000000-0000-4000-8000-000000000002",
      "rating": 5,
      "comment": "Helpful lesson.",
      "created_at": "2026-10-21 10:00:00",
      "learner_name": "Asha Sharma"
    }
  ],
  "pagination": {
    "perPage": 50,
    "hasMore": false,
    "nextCursor": null,
    "previousCursor": null
  }
}
```


## 10 Support and operations

### POST /safety/reports

Mock — 200 Report created (`Content-Type: application/json`)

```json
{
  "report": {
    "id": "10000000-0000-4000-8000-000000000011",
    "reporter_id": "10000000-0000-4000-8000-000000000001",
    "target_type": "user",
    "target_id": "10000000-0000-4000-8000-000000000002",
    "reason_category": "spam",
    "reason": "Repeated unwanted contact.",
    "status": "open",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

### POST /safety/blocks/{{userId}}

Mock — 200 User blocked (`Content-Type: application/json`)

```json
{
  "ok": true
}
```

### DELETE /safety/blocks/{{userId}}

Mock — 200 User unblocked (`Content-Type: application/json`)

```json
{
  "ok": true
}
```

### POST /suggestions

Mock — 200 Suggestion submitted (`Content-Type: application/json`)

```json
{
  "suggestion": {
    "id": "10000000-0000-4000-8000-000000000012",
    "submitted_by": "10000000-0000-4000-8000-000000000001",
    "suggestion_type": "course",
    "name": "Robotics",
    "notes": "Please add robotics lessons.",
    "status": "open",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

### POST /support

Mock — 200 Support ticket created (`Content-Type: application/json`)

```json
{
  "ticket": {
    "id": "10000000-0000-4000-8000-000000000010",
    "user_id": "10000000-0000-4000-8000-000000000001",
    "subject": "Booking help",
    "message": "Please help me with my booking.",
    "status": "open",
    "created_at": "2026-10-21 10:00:00",
    "updated_at": "2026-10-21 10:00:00"
  }
}
```

### GET /support/mine

Mock — 200 My support tickets (`Content-Type: application/json`)

```json
{
  "tickets": [
    {
      "id": "10000000-0000-4000-8000-000000000010",
      "user_id": "10000000-0000-4000-8000-000000000001",
      "subject": "Booking help",
      "message": "Please help me with my booking.",
      "status": "open",
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00"
    }
  ]
}
```

### GET /referrals/mine

Mock — 200 My referral code and referrals (`Content-Type: application/json`)

```json
{
  "code": "MOCK7ABC",
  "link": "https://example.com/download?ref=MOCK7ABC",
  "referredCount": 1,
  "referred": [
    {
      "id": "10000000-0000-4000-8000-000000000002",
      "full_name": "Asha Sharma",
      "created_at": "2026-10-21 10:00:00"
    }
  ]
}
```

### POST /referrals/redeem

Mock — 200 Referral redeemed (`Content-Type: application/json`)

```json
{
  "referral": {
    "id": "10000000-0000-4000-8000-000000000013",
    "referrer_id": "10000000-0000-4000-8000-000000000002",
    "referred_id": "10000000-0000-4000-8000-000000000001",
    "created_at": "2026-10-21 10:00:00"
  }
}
```

Mock — 409 This account has already been credited to a referrer (`Content-Type: application/json`)

```json
{
  "error": "This account has already been credited to a referrer"
}
```

### POST /analytics/page-view

Mock — 200 Page view recorded (`Content-Type: application/json`)

```json
{
  "ok": true
}
```


## 11 Health and location

### GET /health

Mock — 200 Healthy (`Content-Type: application/json`)

```json
{
  "ok": true
}
```

### POST /location-lookup/reverse

Mock — 200 Reverse-geocoded location (`Content-Type: application/json`)

```json
{
  "location": {
    "place_id": 123456,
    "licence": "Data © OpenStreetMap contributors, ODbL 1.0. http://osm.org/copyright",
    "osm_type": "node",
    "osm_id": 123456,
    "lat": "28.6139000",
    "lon": "77.2090000",
    "class": "place",
    "type": "city",
    "place_rank": 16,
    "importance": 0.8,
    "addresstype": "city",
    "name": "New Delhi",
    "display_name": "New Delhi, Delhi, India",
    "address": {
      "city": "New Delhi",
      "state": "Delhi",
      "country": "India",
      "country_code": "in"
    },
    "boundingbox": [
      "28.60",
      "28.63",
      "77.19",
      "77.22"
    ],
    "namedetails": {
      "name": "New Delhi"
    },
    "extratags": {}
  }
}
```

Mock — 429 Lookup too frequent (`Content-Type: application/json`)

```json
{
  "message": "Please wait a moment before looking up another location.",
  "retryAfterSeconds": 1
}
```

Mock — 404 Address not found (`Content-Type: application/json`)

```json
{
  "message": "No address details were found for these coordinates."
}
```


## Home and map dashboard

### GET /search/dashboard

Public query: `lat=28.6139&lng=77.2090&radiusKm=7`. All matching eligible
providers, no 50-result cap. Numeric coordinates can be used as map markers.

Mock — 200 Teachers and institutes for map

```json
{
  "center": {
    "latitude": 28.6139,
    "longitude": 77.209
  },
  "radiusKm": 7,
  "teacherCount": 1,
  "instituteCount": 1,
  "totalCount": 2,
  "results": [
    {
      "id": "10000000-0000-4000-8000-000000000008",
      "user_id": "10000000-0000-4000-8000-000000000002",
      "profile_type": "teacher",
      "full_name": "Asha Sharma",
      "avatar_url": null,
      "currency": "INR",
      "lesson_modes": [
        "in_person",
        "online"
      ],
      "latitude": 28.6139,
      "longitude": 77.209,
      "address_text": "Example address, Delhi",
      "city": "Delhi",
      "state": "Delhi",
      "country": "India",
      "country_code": "IN",
      "slug": "asha-sharma-delhi",
      "is_verified": false,
      "verified_at": null,
      "trial_days": 1,
      "rating_avg": 4.5,
      "rating_count": 10,
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00",
      "bio": "Maths lessons for school students",
      "rate_per_hour": 500,
      "linkedin_url": null,
      "primary_subject_id": 1,
      "price": 500,
      "distance_km": 0,
      "subjects": [
        {
          "id": 1,
          "name": "Mathematics",
          "is_bsl": false
        }
      ]
    },
    {
      "id": "10000000-0000-4000-8000-000000000018",
      "user_id": "10000000-0000-4000-8000-000000000012",
      "profile_type": "institute",
      "full_name": "Example Learning Centre",
      "avatar_url": null,
      "currency": "INR",
      "lesson_modes": [
        "in_person",
        "online"
      ],
      "latitude": 28.6239,
      "longitude": 77.209,
      "address_text": "Example address, Delhi",
      "city": "Delhi",
      "state": "Delhi",
      "country": "India",
      "country_code": "IN",
      "slug": "example-learning-centre-delhi",
      "is_verified": false,
      "verified_at": null,
      "trial_days": 1,
      "rating_avg": 4.5,
      "rating_count": 10,
      "created_at": "2026-10-21 10:00:00",
      "updated_at": "2026-10-21 10:00:00",
      "price": 500,
      "distance_km": 1.11195,
      "subjects": [
        {
          "id": 1,
          "name": "Mathematics",
          "is_bsl": false
        }
      ],
      "description": "Lessons for school students",
      "starting_rate": 500,
      "contact_person": "Asha Sharma",
      "instructors_text": null,
      "website_url": null,
      "institute_type": "independent",
      "is_bsl_official": false,
      "bsl_official_since": null
    }
  ]
}
```

Mock — 200 No providers in radius

```json
{
  "center": {
    "latitude": 28.6139,
    "longitude": 77.209
  },
  "radiusKm": 7,
  "teacherCount": 0,
  "instituteCount": 0,
  "totalCount": 0,
  "results": []
}
```

Mock — 422 Missing coordinates

```json
{
  "message": "The lat field is required. (and 1 more error)",
  "errors": {
    "lat": [
      "The lat field is required."
    ],
    "lng": [
      "The lng field is required."
    ]
  }
}
```

