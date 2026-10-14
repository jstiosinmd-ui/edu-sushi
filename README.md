# SUSHI Clerks Comprehensive Exam Reviewer

---

SUSHI v2.5 is a Laravel and Vue study studio backed by PostgreSQL. It includes 2,172 released questions across ten subjects, student accounts, persistent learner analytics, an administrator workspace, and downloadable exam PDFs.

**Start with the [Laravel application guide](src/laravel/README.md) and [deployment guide](src/laravel/docs/DEPLOYMENT.md).** The web server must serve `src/laravel/public`. GitHub Pages cannot run this backend.

## Study menu

| Mode | Purpose |
|---|---|
| Fresh Catch 10 | Ten random new or previously missed questions from selected subjects. |
| Maki Mentor | Guided MCQ practice with immediate feedback. |
| Wasabi Rush | Timed practice with server-enforced deadlines. |
| Memory Maki | Select a question range and exact items, answer for immediate feedback, then rate familiarity from 1 to 5. Lower ratings repeat more often during the session. |
| Bento Builder | Save exams and download matching exam papers, answer sheets, and answer keys. |
| Roll Call | Browse subjects, explanations, source notes, and bookmarks. |
| Head Chef | Manage learners, question versions, assignments, and individual analytics. |

Memory Maki has no day-based waiting schedule. Its sticky bar shows the number of unrated questions and counts at ratings 1, 2, 3, 4, and 5 within the selected deck. Memo Maki is its new study companion.

Achievement tiers advance every **200 unique completed question IDs**. New questions expand the bank without reducing earned rank. Repeated practice adds history without awarding duplicate tier points.

## Accounts and operations

The initial students are `powquanico`, `hannahquintero`, and `rachirpunla`; the administrator is `jstiosin`. Seeding sets their initial password to `password` and requires a password change at first login. Re-running the seeder preserves existing credentials and progress.

Use PHP 8.4 or newer and PostgreSQL 16 or newer. A queue worker creates private PDF exports, and the scheduler closes expired exams. See the deployment guide for HTTPS, configuration, backups, and restore instructions.

The [technical decisions](src/laravel/docs/DECISIONS.md) explain progress, scoring, question snapshots, weighted flashcards, and legacy imports. The application includes PostgreSQL feature tests, deterministic draw tests, and a retained browser smoke test.

## Earlier offline release

The root [index.html](index.html) and [versioned v1.3 HTML](20261013_EDU_SushiClerksExamStudio_v1.3.html) remain the original offline release. They store progress only in the browser and do not include the Laravel features. Use the new application's progress-import screen to transfer an offline backup.

The [original v1.3 README](src/laravel/docs/legacy-v1.3-readme.md), [user guide](20261013_EDU_SushiStudioGuide_v1.3.md), artwork, and release manifests are retained as provenance.

## Credits

Created by **Jon Bryan S. Tiosin, MD**.

**(c) jstiosin 2026**

Creator credits appear throughout the application and on every generated PDF page.
