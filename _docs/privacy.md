# Privacy: data sent to the AI provider

This document describes what leaves the Moodle site when Assign AI asks the Datacurso AI
service to review a submission, why each field is needed, and how personal data is minimised
before the transfer. It complements the Moodle Privacy API metadata declared in
`classes/privacy/provider.php` and the payload examples in [ai_payloads.md](./ai_payloads.md).

## Purpose of the transfer

The plugin sends one request per submission review to the Datacurso AI service
(`POST /assign/answer`) so that the service can propose a grade and feedback for that
submission against the assignment's instructions, rubric or marking guide. No other
processing purpose exists: the plugin does not send data for training, analytics or
marketing.

## Outbound field allowlist

The outbound boundary is `\local_assign_ai\local\payload_anonymizer::anonymize()`. It is
applied to every payload before the HTTP client is called and enforces the allowlist below
(`payload_anonymizer::ALLOWED_FIELDS`, applied through the provider's
`outbound_privacy::apply_allowlist()`): **any key not listed here is dropped**, so the
outbound contract cannot grow silently when the internal payload builder changes.

| Field | Justification |
| --- | --- |
| `course_id` | Numeric course id; lets the service group reviews per course. Not personal data. |
| `course` | Course full name; gives the model subject context for the feedback. |
| `assignment_id` | Numeric assign instance id; identifies the activity being graded. Not personal data. |
| `cmi_id` | Numeric course module id; identifies the activity being graded. Not personal data. |
| `assignment_title` | Assignment name; context for the feedback. |
| `assignment_description` | Assignment description written by the teacher; defines what is being assessed. |
| `assignment_activity_instructions` | Activity instructions written by the teacher; defines what is being assessed. |
| `rubric` | The rubric definition (criteria and levels), when the assignment uses one; required to grade per criterion. |
| `assessment_guide` | The marking guide definition, when the assignment uses one; required to grade per criterion. |
| `userid` | **Pseudonymised** reviewer token (see below); used by the service for per-user consumption and rate limiting. |
| `student_name` | **Placeholder** `[STUDENT_NAME]` (see below); lets the feedback address the student by name once it is back on the site. |
| `submission_assign` | The student's online text submission; it is the object of grading. |
| `submission_files` | The student's uploaded files; they are the object of grading (see below). |
| `maximum_grade` | Maximum grade or number of scale points; bounds the proposed grade. |
| `prompt` | Additional grading instructions written by the teacher for this assignment. |
| `lang` | Language requested for the generated feedback. |

## Pseudonymised and anonymised fields

- `userid` is **not** the Moodle user id. It is the reviewer id (the teacher who triggered
  the review, otherwise the configured grader, otherwise the student as last resort)
  replaced by a stable, non-reversible, site-scoped token produced by the provider's shared
  helper `\aiprovider_datacurso\local\outbound_privacy::pseudonymise_userid()`
  (aiprovider_datacurso 1.6.0 or later): an HMAC-SHA256 of the user id keyed with the site
  identifier and the provider's frozen `outbound_privacy::PSEUDONYM_KEY_SUFFIX`, truncated to
  32 hex characters. The same user on the same site always yields the same token, so the
  provider can keep per-user accounting, but the raw id cannot be recovered from it and tokens
  cannot be correlated across sites. Assign AI does not implement any pseudonymisation of its
  own: `payload_anonymizer::anonymize()` delegates to that helper, and the provider's HTTP
  client applies the same helper again, so a raw id can never leave even if a caller bypasses
  the plugin boundary.
- `student_name` is replaced by the placeholder `[STUDENT_NAME]` before sending. The real
  name is kept on the site only, and is substituted back into the AI reply after it returns
  (`payload_anonymizer::deanonymize_text()`, which delegates to `outbound_privacy::restore_text()`).

Files are sent only when the student actually uploaded files for the submission, because
they are the object of grading; a text-only submission sends no files.

## Provider retention, location and data subject rights

Retention periods, storage locations and the handling of data subject requests on the
provider side are governed by the Datacurso AI service, not by this plugin. They are
described in the "Datacurso AI service: data processing, retention and data subject rights"
document, which is shared by every Datacurso plugin that uses the service and is delivered
to the institution together with the plugin security and privacy documentation.

On the Moodle side, the plugin keeps the AI reply, the proposed grade and the related
identifiers in its own tables; these are fully declared in the Privacy API metadata and are
exported and deleted through the standard Moodle privacy tools.
