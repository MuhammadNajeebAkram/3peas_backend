# Stage 1E.3.A — Milestone 3 Synthetic ID Safety Plan
## End-to-End Prevention of Synthetic Negative Program ID Leakage

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M3_SYNTHETIC_ID_SAFETY_PLAN.md`
- **Execution Date:** November 20, 2026 (Updated with Mandatory Corrections)
- **Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Planning & Architectural Specification

---

## 1. Problem Statement & Threat Model

In Stage 1E.3.A Milestone 1, `mergeFreemiumPrograms()` introduced deterministic synthetic negative IDs (e.g. `id: -classId` or `-9999`) to group uncovered free catalog resources into logical program containers for UI dropdown selectors.

### The Threat:
The Laravel backend strictly validates that any provided `offered_program_id` must exist in the database table `offered_programs`:
```php
'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id']
```
or in optional filters:
```php
'offered_program_id' => ['sometimes', 'nullable', 'integer', 'min:1']
```
If a synthetic negative ID (e.g. `-1`, `-11`, or `-9999`) reaches any backend endpoint:
1. Laravel validation fails with `422 Unprocessable Entity` (`"The selected offered program id is invalid."`).
2. If an unvalidated route queries `OfferedProgram::find(-11)`, it returns `null`, causing an unhandled `404 Not Found` or `500 Server Error`.
3. If `0` is sent instead of a missing program ID, Laravel validation fails with `422 Unprocessable Entity` because `0` violates `min:1` and does not exist in `offered_programs`.

---

## 2. Mandatory Architectural Rule

The following safety rule is absolute across all requests, navigation paths, and state handlers:

> [!CRITICAL]
> 1. **Never submit a synthetic negative program ID.**
> 2. **Never substitute zero (`0`) for a missing genuine program ID.**
> 3. **Omit `offered_program_id` (and URL parameter `offeredProgramId`) entirely when no valid backend program ID exists.**
> 4. **Preserve genuine academic identifiers (`book_id`, `subject_id`, `class_id`, `unit_id`) when required by the actual endpoint contract.**

---

## 3. Inventory of Vulnerable Call Sites & Verified Defenses

A comprehensive audit of `tests/page.jsx` and `TestsPageContent.jsx` identified seven call sites requiring strict defense rules:

| Call Site # | Location in Code | Target Endpoint / Path | Flawed Pattern | Verified Corrective Defense Rule |
| :---: | :--- | :--- | :--- | :--- |
| **CS-1** | `tests/page.jsx` (L101–L112) | `GET /web_api/auth/tests/get-recent-attempts` | Appended negative ID: `offered_program_id=-11`. | **Strict Omission:** If `!selectedProgram?.id || Number(selectedProgram.id) <= 0`, do NOT add `offered_program_id` to query params. Send only `subject_id`. |
| **CS-2** | `TestsPageContent.jsx` (L514–L522) | `GET /web_api/auth/tests/get-recent-attempts` | Appended negative ID on filter update. | **Strict Omission:** If `!selectedProgram?.id || Number(selectedProgram.id) <= 0`, do NOT add `offered_program_id` to query params. Send only `subject_id`. |
| **CS-3** | `TestsPageContent.jsx` (L362–L365) | `GET /web_api/auth/my-classes/get-units/{program_id}/{subject_id}` | Called route with negative ID: `/get-units/-11/5`. | **Route Diversion:** If `selectedProgram.id <= 0`, call verified endpoint `GET /web_api/get_units_of_book/${selectedSubject.book_id}`. |
| **CS-4** | `TestsPageContent.jsx` (L212–L227) | `POST /web_api/auth/statistics/get-student-weak-topics`<br>`POST /web_api/auth/statistics/get-student-weak-units` | Sent `{ offered_program_id: selectedProgram.id }` in body. | **Bypass POST:** If `selectedProgram.id <= 0`, do NOT make network call; set `weakAreas` to `[]`. |
| **CS-5** | `TestsPageContent.jsx` (L284–L307) | `POST /web_api/auth/statistics/get-student-*-performance-overview` | Sent `{ offered_program_id: selectedProgram.id }` in body. | **Bypass POST:** If `selectedProgram.id <= 0`, do NOT make network call; set `performance` to baseline fallback. |
| **CS-6** | `TestsPageContent.jsx` (L446–L466) | `POST /web_api/auth/statistics/get-student-*-mcq-stats` | Sent `{ offered_program_id: selectedProgram.id }` in body. | **Bypass POST:** If `selectedProgram.id <= 0`, do NOT make network call; set `stats` to baseline fallback. |
| **CS-7** | `TestsPageContent.jsx` (L669–L680) | Client navigation to `/lms/tests/launch?params` | Sent `offeredProgramId: "-11"` or `0` in searchParams. | **Strict Omission:** If `selectedProgram.id <= 0`, do NOT set `offeredProgramId` in `URLSearchParams`. Forward authentic `bookId` and `subjectId`. |

---

## 4. Code Implementation Specifications

### 4.1 Recent Attempts Defense (CS-1 & CS-2)
```javascript
// Correct Query Parameter Construction:
const queryParams = new URLSearchParams({
  subject_id: String(selectedSubject.id),
});

// Strictly attach offered_program_id ONLY if positive integer.
// Never send negative numbers; never send 0.
if (selectedProgram?.id && Number(selectedProgram.id) > 0) {
  queryParams.set("offered_program_id", String(selectedProgram.id));
}

const response = await apiFetch(`/auth/tests/get-recent-attempts?${queryParams.toString()}`);
```

### 4.2 Unit / Chapter Discovery Defense (CS-3)
```javascript
// Correct Unit Discovery Dispatch:
if (selectedProgram?.id && Number(selectedProgram.id) > 0) {
  // Genuine paid program: call program-scoped units endpoint
  const response = await apiFetch(
    `/auth/my-classes/get-units/${selectedProgram.id}/${selectedSubject.id}`
  );
  setChapterOptions(mapUnits(response?.units));
} else {
  // Free catalog resource: call verified book-level units endpoint
  const bookId = selectedSubject?.book_id;
  if (bookId && Number(bookId) > 0) {
    const response = await apiFetch(`/get_units_of_book/${bookId}`);
    setChapterOptions((response?.units || []).map((u) => ({
      id: Number(u.id),
      bookId: Number(bookId),
      bookName: selectedSubject?.name || "",
      label: u.unit_name || "Chapter",
    })));
  } else {
    setChapterOptions([]);
  }
}
```

### 4.3 Student Statistics Endpoints Defense (CS-4, CS-5, CS-6)
```javascript
// Correct Statistics Bypass:
const isRealProgram = Number(selectedProgram?.id) > 0;

if (!isRealProgram) {
  // Free resource container: display clean baseline statistics without firing network calls
  setWeakAreas([]);
  setPerformance(createPerformanceFromResponse(null, data.performance));
  setStats(createStatsFromResponse(null, data.summary));
  return;
}

// Only genuine programs proceed to make statistics POST calls
```

### 4.4 Launch Navigation Defense (CS-7)
```javascript
// Correct Launch URL Construction:
const params = new URLSearchParams({
  mode: filters.mode,
  scope: filters.scope,
  subjectId: String(selectedSubject.id),
  classProgram: filters.classProgram,
  subject: filters.subject,
  questionType: filters.questionType,
  numberOfQuestions: String(generator.numberOfQuestions),
  timeLimit: String(generator.timeLimit),
});

// Strictly attach offeredProgramId ONLY if genuine positive integer.
// Never send negative numbers; never send 0. Omit entirely otherwise.
if (selectedProgram?.id && Number(selectedProgram.id) > 0) {
  params.set("offeredProgramId", String(selectedProgram.id));
}

// Forward genuine bookId for free resources
const fallbackBookId = selectedSubject?.book_id || chapterOptions[0]?.bookId || 0;
if (Number(fallbackBookId) > 0) {
  params.set("bookId", String(fallbackBookId));
}

router.push(`/lms/tests/launch?${params.toString()}`);
```

---

## 5. Academic Scope & Identifiers Preservation

While synthetic IDs are omitted, authentic academic context is preserved with 100% fidelity:
- `class_id`: Authentic integer from free catalog item (e.g. `1` for Grade 11).
- `subject_id`: Authentic integer from `subject_tbl` (e.g. `5` for Biology).
- `book_id`: Authentic integer from `book_tbl` (e.g. `42`).
- `unit_id`: Authentic integer from `book_unit_tbl` (e.g. `101`).

Backend test generation in Milestone 4 consumes `book_id` and `subject_id` to evaluate access via `UnifiedEntitlementResolver` and construct assessment questions accurately without requiring an `offered_program_id`.
