# Sushi Study Flow Implementation

---

Sushi 3.1.0 implements the six approved study-flow changes. The code is prepared in a separate checkout based on 3.0.2. This release does not import questions or change clinical content.

## Changes

| Item | Behavior | Main code |
| --- | --- | --- |
| 1 | Select metadata first, then load selected immutable versions. Cache grouped counts by catalog revision. Library and notebook load content by page. | Catalog, Draw, Selection, Study, StudyGuide, Notebook |
| 2 | Study offers Practice, Flashcards, and Exam tabs with Resume and common setup. | StudySetup, StudySetupController, QuickStudy, Home |
| 3 | Checked answers show rationale, alternative explanations, and reference inline. Timed and assigned exam release rules remain enforced. | StudyDetails, Attempt, FlashcardDeck |
| 4 | Three progress cards share subject, topic, date, difficulty, and study-tool filters. Review next and Review topic recompute eligible IDs on the server. | ProgressScope, ProgressOverview, StudyController |
| 5 | Bookmark, Make a note, and Report a problem are separate actions. Rating help explains the existing weights and recent-card exclusion. | StudyNote, ReportQuestion, RecallHelp |
| 6 | Remember valid setup after a successful start. Provide 5, 10, and 20 presets plus Customize. | User.study_preferences, StudySetup, QuickStudy |

## Data and API contract

Migration `2026_10_30_000001_add_study_preferences_and_catalog_revision.php` adds nullable JSON `users.study_preferences` and the singleton `catalog_revisions` table. A PostgreSQL statement trigger increments its revision on question insert, update, delete, or truncate. No learner responses, existing snapshots, question versions, or question content are rewritten.

Authenticated POST `/study/start` accepts mode (practice, flashcards, exam), subject names, registered topic keys, count (1 to 100), difficulty, pool (all, new, missed, low, bookmarks), and minutes (1 to 360). Topic membership and available question counts are checked against the current bank. Preferences save in the same transaction as a successful session.

Authenticated POST `/progress/review` accepts the displayed progress filters and optional subject/topic restrictions. The server derives the learner's matching latest incorrect answers and starts at most 20 distinct stems. It does not trust client-supplied question IDs or overwrite remembered setup.

## Metric definitions

The cards consider matching active question IDs. First-answer accuracy selects the first-ever known released response before applying dates or study tool. Unknown imported first-answer history is excluded. Coverage counts distinct questions answered within the selected filters; its denominator is the matching current active bank. Need review uses the latest overall released answer before applying the requested dates and tool, so a later correct answer clears an earlier mistake.

Unreleased assigned-exam responses are excluded. Legacy completion contributes only to all-time, all-tool coverage and review when no actual response supersedes it. Dates use Asia/Manila. Recall ratings remain separate from correctness. Existing lifetime summaries retain explicit labels.

## Architecture decisions

**Pin selection to immutable version IDs.** Metadata selection retains `current_version_id`, and hydration reads those exact versions in the selected order. A concurrent correction cannot silently replace the content chosen for a new session. Full snapshots are still retained for grading and historical review.

**Invalidate counts in the database transaction.** A statement trigger covers normal model writes, bulk imports, retirement, and taxonomy updates. The application bypasses shared cache inside transactions, preventing rolled-back revisions from populating cache. This requires PostgreSQL and permission for the application migration role to create the function and trigger.

**Store setup separately from history.** Preferences are a small nullable field on the learner, sanitized when the home page loads. They do not alter attempts, recall weights, grades, or study events. A failed start preserves the previous preferences.

## Verification and reproducibility

The retained performance entry point is `tests/performance/selection.php`. It refuses to run unless the database is `sushi_studyflow_benchmark` on `127.0.0.1`. Prepare once with `prepare`; run `full` and `select` in separate PHP processes with the same memory limit. The fixture contains 18,000 synthetic, nonclinical questions. Both paths use seed 42 and request ten questions. The `cache` mode verifies committed retirement and restoration invalidate cached counts.

`tests/Feature/StudyFlowTest.php` covers selection/version equivalence, the three modes, hidden exam feedback, remembered choices, catalog rollback, scoped review, literal library search, and assigned-answer release. Existing feature and frontend tests cover grading, authorization, replay protection, delivery recovery, and keyboard navigation. Browser evidence uses only a local synthetic account and synthetic questions.

Guide images for Study, practice, timed setup, flashcards, and Progress are refreshed from this local preview. Unchanged Fresh Catch, library, builder, passport, and administrator illustrations are carried forward from the prior guide.

## Release and rollback

Deploy code and run the normal release migration before serving new application requests. Existing 18,000-question production content requires no new import for these six changes. Verify the release number, sign-in, one saved answer, a rated flashcard, filtered review, and the catalog count after deployment.

The migration is additive. An application rollback to 3.0.2 can retain the new nullable preference column and revision table/trigger. Keeping them preserves preferences and avoids unnecessary schema changes. Do not roll back the new migration while 3.1.0 requests are running. Production deployment and live load testing are separate from this local implementation.

Large exam/PDF exports intentionally load their selected content, and historical analytics still grow with recorded response history. The selection benchmark measures the question-loading change on a local synthetic fixture, not production throughput or concurrency.
