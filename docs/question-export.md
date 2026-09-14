# Question export

`POST /api/admin/auth/question/export`

Requires admin authentication and **both** `questions.report.view` and
`questions.report.export` (super admins retain their existing bypass). Results
must satisfy the scopes assigned to both permissions. As elsewhere in the
question API, a permission with no scopes is unrestricted.

Register the permissions using `php artisan db:seed --class=LmsPermissionsSeeder`,
then assign both permissions and any required scopes to the reporting role.

Send the same filter payload as `/api/admin/auth/question/filter`. Supported
filters are `topic_id`, `status`, `question_type`, `cognitive_domain`,
`topic_content`, `difficulty`, `activate`, `is_mcq`, `has_diagram`,
`question_presentation_type_id`, `is_alp_question`, `class_id`, `subject_id`,
`curriculum_board_id`, `board_id`, `session_id`, `year`, `group_id`, `unit_id`,
`book_id`, and `search`. Except for `search`, filters accept single values or
arrays, matching the existing filter endpoint. Search uses the same fields and
LIKE matching as the existing endpoint.

Example request:

```json
{"book_id": [1], "unit_id": [2, 3], "activate": 1, "search": "motion"}
```

The response has `success: 1`, `total`, and a flat `questions` array containing
all matching questions in descending ID order. `page` and `per_page` do not limit
exports. No matches returns `total: 0` and `questions: []`.

Each question includes the existing question listing fields and board links,
plus `question_lang`, `question_um_lang`, `scenario_text`, `scenario_text_um`,
`scenario_image`, `answers`, and `options`. Answers contain the stored answer
records, including English/Urdu content. Options contain `id`, `question_id`,
`text`, `text_um`, and `is_correct`. Missing answers/options are empty arrays.
Each question includes IDs and names for curriculum board, class, subject, book,
unit, and topic (`curriculum_board_name`, `class_name`, `subject_name`,
`book_name`, `unit_name`, and `topic_name`). Missing related records have null names.

`board_links` contains every exam-board mapping from `exam_question_board_tbl`,
including exam board ID/name, year, session ID/name, group ID/name, and activation
status. These exam boards are separate from the curriculum board. Board filters
select matching questions but do not restrict their exported mappings. The
existing listing endpoint continues to filter its returned board links.

The frontend renders this JSON into the PDF, including Urdu fonts, image
references, and scenario grouping using `scenario_group_id` and
`scenario_question_order`. The backend does not generate a PDF file. Large
exports hold the full response in memory; use filters to keep reports practical.

Validation errors return HTTP 422. Missing authentication returns HTTP 401;
missing either report permission returns HTTP 403.
