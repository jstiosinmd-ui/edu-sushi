# SUSHI v2.0 Validation Record

---

## Result

The Laravel application passed local PostgreSQL, browser, PDF, and restore checks on October 13, 2026. The released clinical bank was carried over without rewriting its 1,047 supplied records.

## Checks performed

- PHP 8.5.10, Laravel 13.35.0, PostgreSQL 16.9, and the locked Vue/Inertia dependencies.
- PHPUnit: **25 tests passed, 1,234 assertions**. This includes two framework smoke tests and 23 application tests.
- All four seeded usernames authenticate with the requested initial password and must set their own password.
- Repeated seeding preserves credentials, administrator edits, and existing question positions.
- Each imported source record matches the bundled bank, including original source fields.
- Twelve deterministic PHP question draws match fixtures from the original JavaScript engine.
- Fresh Catch retries latest incorrect MCQs and excludes latest correct duplicate-stem groups.
- Attempts enforce ownership, stale-answer conflicts, checked-answer locking, deadlines, assignment windows, attempt limits, and controlled feedback release.
- Achievement credit is unique across MCQs and flashcards, persists for retired items, and advances every 200 questions with unlimited future tiers.
- Flashcards enforce the selected range, immediate answer feedback before rating, weighted draws, stale-token protection, repeat-submission idempotency, and deck ownership. Confidence ratings do not enter MCQ accuracy.
- Legacy import requires a matching preview hash and preserves existing server results without fabricating historical first-attempt data.
- Head Chef user management revokes disabled/reset sessions and avoids passwords in audit details.
- Production frontend build passed.
- Chrome browser checks passed for student/admin login, answers after reload, exact deck selection, feedback, familiarity counts after reload, sticky bars, admin denial, catalog access, and all three queued PDF downloads. No JavaScript page errors were observed.
- Screens checked at desktop and phone widths. Bento Builder overflow checks covered 320, 390, 768, and 1,440 pixels. Learner management uses cards on phones.
- A 12-question PDF set produced a three-page paper, one-page answer sheet, and four-page explained key. Every page contained the creator name, credit, exam code, and correct page number. The key matched every saved exam answer in order. Each paper question stayed with its four options. Rendered PDF pages were inspected.
- PostgreSQL custom-format backup restored successfully into a separate database. Both databases had 4 users, 1,047 questions, 1 attempt, 1 progress row, 1 deck, 1 flashcard review, and 1 exam at the time of the drill.
- The minute-based exam-expiry command ran successfully; the PDF queue had no failed jobs.
- Composer and npm production dependency audits reported no known vulnerability advisories.

## Release scope

The code and deployment instructions are ready to push to the edu-sushi repository. A production host has not been configured or deployed in this task. GitHub Pages serves only the retained offline HTML. Docker was unavailable locally, so the supplied container configuration still needs a smoke test on its intended host. Local runtime and PDF verification are direct PHP/PostgreSQL evidence.

The isolated browser-test database contains QA accounts and records; it is not the development database. No database dump, application key, real credentials, or private export is included in Git. The bootstrap passwords are intentionally defined by the owner's request.

Memo Maki was created with the built-in image-generation tool. The saved [prompt record](mascot-prompt.json) identifies its asset and full prompt.

Jon Bryan S. Tiosin, MD
(c) jstiosin 2026
