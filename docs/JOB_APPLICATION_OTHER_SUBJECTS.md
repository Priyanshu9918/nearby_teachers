# Job applications with Other courses

Import [the dedicated Postman collection](postman/job-application-other-subjects.postman_collection.json). The teacher/institute Other course collection also includes a job application folder. Set `baseUrl`, `jobEmail`, `password`, `jobContactPhone` and log in with an existing phone-verified account; alternatively supply its bearer in `jobToken`.

`POST /jobs/applications` accepts:

```json
{
  "name": "Asha Applicant",
  "phone": "+919876543210",
  "expectedSalary": 25000,
  "courseIds": [1],
  "otherSubjects": ["Abacus"]
}
```

Use real BSL course IDs or saved Other course IDs from `GET /subjects`. `otherSubjects` creates/reuses public subjects inside the same shared Other category used by teacher/institute registration. Course matching ignores case and extra whitespace. The course ID array may be omitted or empty when submitting custom names alone. After resolving duplicates, select 1–5 distinct courses in total. Each typed name must be nonblank and at most 200 characters; the optional typed-name array accepts 1–5 entries. Other non-BSL categories remain ineligible.

All existing name, international contact-phone, positive monthly INR salary, authentication, verified-phone, suspension and rate-limit rules remain. Learner, teacher and institute accounts can apply. Success is `201` with `application.courses` containing numeric IDs and names. Applying does not modify the account's profile subjects.

Applications save course names as immutable submission snapshots, so later catalogue renames do not change existing applications. Course creation, application rows, course snapshots and reporting events share one transaction. Errors leave no partial catalogue entries or applications. Version 1 `subject.other_created` covers newly created catalogue subjects; existing version 1 `teaching_job_application.submitted` records resolved course IDs and salary. Job submissions do not emit provider profile selection events. No historical records are backfilled, and direct SQL/admin catalogue edits are outside these event contracts.

Existing admin `job-applications` and `job-application-courses` reports and streamed CSV exports show the saved course snapshots with the same filters, admin authentication and access logging. The public subject catalogue shows new courses to subsequent users. No new migration is required.
