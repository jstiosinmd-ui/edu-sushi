# SUSHI Laravel Application

---

SUSHI Clerks Comprehensive Exam Reviewer runs on Laravel 13, Vue 3/Inertia, and PostgreSQL. The locked dependencies require PHP 8.4 or newer and Node 22 or newer. The original self-contained HTML remains in the repository root as the offline v1.3 release.

## Start locally

Run these commands from this directory, src/laravel:

~~~sh
composer install
npm ci
cp .env.example .env
php artisan key:generate
~~~

Create PostgreSQL databases named sushi and sushi_test with a dedicated user. Set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD in .env. Enable PHP extensions pdo_pgsql, mbstring, dom, fileinfo, curl, zip, and gd.

~~~sh
php artisan migrate
php artisan db:seed
npm run build
php artisan serve
~~~

In separate terminals, run:

~~~sh
php artisan queue:work --tries=2 --timeout=300
php artisan schedule:work
~~~

The worker creates PDF downloads. The scheduler finalizes expired timed attempts even when a learner has closed the page.

## Initial accounts

| Username | Role |
|---|---|
| powquanico | Student |
| hannahquintero | Student |
| rachirpunla | Student |
| jstiosin | Administrator |

The initial password is password for all four accounts. Each must choose a new password on first login. Passwords are hashed; seeding again never resets an existing account or password. No public registration or email addresses are required. The admin can add students, reset passwords, group learners by cohort, or disable access.

## Study modes

- Fresh Catch 10 draws unanswered or latest-incorrect MCQs. A correct answer removes that item group until a later incorrect MCQ result.
- Maki Mentor checks answers immediately.
- Wasabi Rush uses a server-controlled deadline and releases feedback after submission.
- Memory Maki lets users select an exact filtered question range, answer with immediate feedback, and rate familiarity from 1 to 5. Low ratings have higher draw weights within the deck. The sticky bar shows unrated and ratings 1 to 5. There is no day-based waiting schedule.
- Bento Builder saves exam versions and generates exam, answer-sheet, and answer-key PDFs.
- Roll Call provides the searchable library, sources, explanations, and bookmarks.

The sticky achievement bar advances every 200 distinct completed question IDs. MCQ completions and rated flashcards share this count without duplicating it. Accuracy remains separate from familiarity. New question imports extend the possible journey without reducing anyone's rank.

## Head Chef workspace

The administrator can manage learners, review per-student analytics, export filtered response summaries, version or retire questions, preview and publish question-bank JSON, build exam assignments, set opening/closing times and attempt limits, and control explanation release. Audit entries record management actions without passwords.

Question imports use the shape of database/data/bank.json. CSV imports should first be converted to that shape while preserving the source fields. No external clinical AI service is called by this application.

## Analytics definitions

MCQ coverage counts unique answered and graded original question IDs in the current active bank. First-attempt accuracy uses known first graded responses; imported historical unknowns are excluded. Filtered response accuracy includes repetitions. Finalized set scores divide correct answers by all set items, including unanswered items.

Familiarity ratings and flashcard reviews are reported separately from MCQ accuracy. Reports show denominators and flag small response samples. Active study time is an estimate from visible-page heartbeats with a one-minute idle cutoff, not total time between sign-in and sign-out. Reports display Asia/Manila time.

## Tests

Create an empty, dedicated PostgreSQL database named sushi_test. Test migrations replace tables in that database, so never point it at learner data.

~~~sh
php artisan test
npm run build
~~~

Override DB_PORT, DB_USERNAME, and DB_PASSWORD as appropriate for the test database. PHPUnit defaults to PostgreSQL and sushi_test.

The browser smoke test expects a running app, queue worker, seeded accounts, and an isolated browser-test database. It changes passwords and creates study records there. Set SUSHI_URL if the server is not at http://127.0.0.1:8137. Set SUSHI_CHROME to use a locally installed Chrome.

~~~sh
npx playwright install chromium
node tests/browser/smoke.cjs
~~~

See [deployment](docs/DEPLOYMENT.md) for deployment, backup, and rollback. See [implementation decisions](docs/DECISIONS.md) for revised tier and flashcard behavior.

Jon Bryan S. Tiosin, MD
(c) jstiosin 2026
