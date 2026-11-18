# Job applications with Other courses

`POST /jobs/applications` still accepts approved BSL/Other course IDs and typed
custom names:

```json
{
  "name": "Asha Applicant",
  "phone": "+919876543210",
  "expectedSalary": 25000,
  "courseIds": [1],
  "otherSubjects": ["Abacus"]
}
```

New typed names now enter the admin Course approvals queue. They do not create
public catalogue entries immediately. Existing Other names reuse their approved
IDs. One to five distinct approved and requested courses are allowed; typed-only
applications remain valid. Name, phone, salary, authentication and throttling
rules remain unchanged.

Successful submission is HTTP 201. An approved course response is `{ "id": 1,
"name": "Approved course" }`. A new custom entry has no invented catalogue ID:

```json
{
  "id": null,
  "name": "Abacus",
  "requestId": 12,
  "approvalStatus": "pending"
}
```

Application course names remain immutable private submission snapshots. Pending
snapshots have nullable `course_id` and an explicit `custom_request_id`. Approval
creates/reuses a shared subject and records its ID on the request; it does not
rewrite historical job snapshots or attach courses to a provider profile.
Rejected custom names remain only in private job/request history.

Migration `2026_11_18_000003_create_custom_subject_requests.php` creates the queue
and nullable snapshot references. Source mutations, snapshots and events share
one transaction. Version 1 `teaching_job_application.submitted` retains approved
`course_ids`, adds `custom_request_ids`, and retains salary metadata. The new
request events record actual status transitions. `subject.other_created` happens
only at actual catalogue approval; no provider selection event is emitted for jobs.

The existing `job-applications` and `job-application-courses` admin reports/CSV
include snapshots, with `custom_request_id` explicitly registered in the latter.
The new approval request report uses shared access control, logging and filters.
No old data or approval history is invented. See [approval lifecycle](OTHER_SUBJECTS.md).

[Postman collection](postman/job-application-other-subjects.postman_collection.json):
after submitting a new course, approve it in admin and refresh `GET /subjects`
before sending requests that require a numeric catalogue ID.
