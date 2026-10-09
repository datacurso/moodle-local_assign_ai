# Assign AI payloads and responses

This document describes the JSON payload sent to the AI service and the expected response
for each grading type. It also includes a quick guide to the plugin structure.

## Plugin structure overview

- `amd/` JavaScript AMD modules (UI behavior, injection, review flows)
- `backup/` backup/restore handlers
- `classes/api/` AI provider client
- `classes/config/` assignment configuration helpers
- `classes/external/` web service endpoints
- `classes/grading/` grading helpers and feedback applier
- `classes/hook/` output hooks
- `classes/observer/` event observers
- `classes/output/` renderables
- `classes/pending/` pending record helpers
- `classes/privacy/` privacy API implementations
- `classes/rubric/` rubric helpers
- `classes/task/` scheduled/adhoc tasks
- `db/` install/upgrade and services definitions
- `lang/` language packs
- `styles/` plugin CSS
- `templates/` Mustache templates
- `_docs/` documentation assets

## Payload sent to AI

The payload is built in `local/assign_ai/classes/assign_submission.php` and always contains
the same base fields. The only difference between grading types is which advanced grading
object is present (`rubric` or `assessment_guide`).

Before the request leaves the site, `\local_assign_ai\local\payload_anonymizer::anonymize()`
restricts the payload to the documented allowlist (`payload_anonymizer::ALLOWED_FIELDS`), drops
any other key, replaces `student_name` with the `[STUDENT_NAME]` placeholder and replaces
`userid` with a pseudonymous site-scoped token derived from the reviewer id. The allowlist
filtering and the token come from the provider's shared helper
`\aiprovider_datacurso\local\outbound_privacy` (aiprovider_datacurso 1.6.0 or later). The examples below
show the payload as it is actually sent. See [privacy.md](./privacy.md) for the justification of
each field.

### Simple grading payload

```json
{
  "course_id": 12,
  "course": "Course name",
  "assignment_id": 34,
  "cmi_id": 56,
  "assignment_title": "Essay 1",
  "assignment_description": "Write about...",
  "assignment_activity_instructions": "Include references in APA format...",
  "rubric": null,
  "assessment_guide": null,
  "userid": "3f1c9b2e7a4d5c6b8e9f0a1b2c3d4e5f",
  "student_name": "[STUDENT_NAME]",
  "submission_assign": "Student submission text",
  "submission_files": [],
  "maximum_grade": 100,
  "prompt": "Additional grading instructions written by the teacher",
  "lang": "en"
}
```

### Rubric grading payload

When the assignment uses a rubric, `rubric` is populated and `assessment_guide` is null.

```json
{
  "course_id": 12,
  "course": "Course name",
  "assignment_id": 34,
  "cmi_id": 56,
  "assignment_title": "Essay 1",
  "assignment_description": "Write about...",
  "assignment_activity_instructions": "Include references in APA format...",
  "rubric": {
    "title": "Rubric title",
    "description": "Rubric description",
    "criteria": [
      {
        "id": 1,
        "criterion": "Thesis",
        "levels": [
          {"id": 10, "points": 5, "description": "Excellent"},
          {"id": 11, "points": 3, "description": "Good"}
        ]
      },
      {
        "id": 2,
        "criterion": "Evidence",
        "levels": [
          {"id": 20, "points": 5, "description": "Strong"},
          {"id": 21, "points": 3, "description": "Adequate"}
        ]
      }
    ]
  },
  "assessment_guide": null,
  "userid": "3f1c9b2e7a4d5c6b8e9f0a1b2c3d4e5f",
  "student_name": "[STUDENT_NAME]",
  "submission_assign": "Student submission text",
  "submission_files": [],
  "maximum_grade": 100,
  "prompt": "Additional grading instructions written by the teacher",
  "lang": "en"
}
```

### Guide grading payload

When the assignment uses a marking guide, `assessment_guide` is populated and `rubric` is null.

```json
{
  "course_id": 12,
  "course": "Course name",
  "assignment_id": 34,
  "cmi_id": 56,
  "assignment_title": "Essay 1",
  "assignment_description": "Write about...",
  "assignment_activity_instructions": "Include references in APA format...",
  "rubric": null,
  "assessment_guide": {
    "title": "Guide title",
    "description": "Guide description",
    "criteria": [
      {
        "id": 1,
        "criterion": "Thesis",
        "description_students": "What the student sees",
        "description_evaluators": "What the grader sees",
        "maximum_score": 10
      },
      {
        "id": 2,
        "criterion": "Evidence",
        "description_students": "Student guidance",
        "description_evaluators": "Marker guidance",
        "maximum_score": 10
      }
    ],
    "predefined_comments": [
      "Well structured",
      "Needs more evidence"
    ]
  },
  "userid": "3f1c9b2e7a4d5c6b8e9f0a1b2c3d4e5f",
  "student_name": "[STUDENT_NAME]",
  "submission_assign": "Student submission text",
  "submission_files": [],
  "maximum_grade": 100,
  "prompt": "Additional grading instructions written by the teacher",
  "lang": "en"
}
```

## Expected AI responses

The AI response is stored in `local_assign_ai_pending` and used by the injector. These are
examples of the structures the plugin expects.

### Simple grading response

```json
{
  "reply": "Good work. Consider expanding your conclusion.",
  "grade": 85
}
```

### Rubric response

The plugin accepts either an array of criteria or an object with `criteria`. Each criterion
carries the authoritative Moodle criterion id (`id`) and the chosen level carries the Moodle
level id (`id`). The matching mode is detected per record: when any criterion carries an id
the whole record is matched strictly by criterion and level ids (a criterion with a missing
or unknown id fails, with no fallback to its name or points); only responses without ids
anywhere (older records/service versions) use the legacy criterion-name and level-points
matching.

```json
{
  "reply": "Solid overall performance.",
  "grade": 86,
  "rubric": {
    "criteria": [
      {
        "id": 1,
        "criterion": "Thesis",
        "levels": [
          {"id": 10, "points": 5, "comment": "Clear and focused thesis."}
        ]
      },
      {
        "id": 2,
        "criterion": "Evidence",
        "levels": [
          {"id": 21, "points": 3, "comment": "Adequate evidence, could be stronger."}
        ]
      }
    ]
  }
}
```

### Guide response

Keys are criterion shortnames and values include `grade` and `reply`.

```json
{
  "reply": "Good effort overall.",
  "grade": 82,
  "assessment_guide": {
    "Thesis": {
      "grade": 8,
      "reply": ["Clear thesis", "Could be more specific"]
    },
    "Evidence": {
      "grade": 7,
      "reply": "Evidence is relevant but limited."
    }
  }
}
```
