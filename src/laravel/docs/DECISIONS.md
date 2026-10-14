# SUSHI Implementation Decisions

---

## Laravel application and PostgreSQL

The Laravel application lives in src/laravel alongside the preserved v1.3 offline release. PostgreSQL is the default development, test, and deployment database. Laravel's session authentication provides username sign-in, throttling, CSRF protection, role checks, and session revocation; the small two-role application uses dedicated controllers instead of a separate authentication package.

## Fixed achievement thresholds

The owner's revised requirement replaces the original six thresholds. Tiers advance every 200 distinct completed questions, including completed MCQs or rated flashcards. Duplicate stems and repeats across modes count once. Retiring questions and importing new questions do not reduce earned completion. The ladder has 26 unique names from Rice Rookie at zero through Omakase Legend at 5,000 questions. Mochi Maestro fills the 1,000-question slot. Numbered Omakase Legend tiers continue every 200 beyond 5,000.

Portrait frames are separate from tier names. Bronze is available initially, Silver at 200 completed questions, Gold at 400, and Sakura flowers at 600. Learners can choose any earned frame, and their saved choice persists across later tiers and character changes. A null preference keeps the previous highest-earned appearance until the learner chooses. This supersedes the v2.2 interpretation that used frame colors as tier names.

## Fresh Catch retry pool

Fresh Catch selects exactly ten distinct stems from never-completed MCQs and latest incorrect MCQ results. A correct graded MCQ excludes the duplicate-stem group; a later incorrect result puts it back. An unchecked answer selection alone does not remove a question. Confidence ratings belong to Memory Maki and do not alter MCQ accuracy or the Fresh Catch pool.

## Flashcard decks repeat within a session

The owner's latest requirement supersedes the initial day-based flashcard proposal. A learner filters subjects/topics/difficulty, previews an inclusive one-based range, selects individual questions, and saves a versioned deck. Each question is answered before feedback is revealed, then rated 1 to 5. A deck persists across sessions and devices.

Draw weights are unrated 6, rating 1: 6, rating 2: 4, rating 3: 3, rating 4: 2, and rating 5: 1. The learning upgrade keeps these weights and excludes the last three rated cards when deck size permits. Smaller decks shorten the gap; a one-card deck can repeat immediately. Weights apply to the currently eligible cards. These are transparent application rules, not Brainscape's proprietary algorithm. There are no date-based due times. Counts are specific to the current deck and show unrated plus each of the five familiarity ratings.

## Frozen scoring evidence

Attempt items, exam items, and flashcard deck items preserve the question version actually presented. Catalog edits do not silently change past scores or existing decks. The original 33 CSV fields remain in the bank and question-version provenance.

New catalog IDs append after existing positions, keeping earlier ranges stable. Re-running the bootstrap seeder adds missing original IDs and preserves administrator edits to existing questions.

## Legacy progress

Offline backups provide latest answers and counts without a complete historical timeline. Imports preserve known completion and bookmarks, do not overwrite existing server results, and keep unknown historical first-attempt data out of that metric even after later practice.

## Community release, October 14, 2026

The approved community upgrade adds 45 persistent achievement stamps, portrait rewards, daily catch streaks, scheduled opt-in web push, and learner leaderboards. Current self-rated familiarity is separate from correctness and earns no leaderboard points. Assigned exams do not contribute shared points or subject achievements, preserving their feedback policy. Recorded history is reconciled idempotently; historical feedback views and bookmarked-at-answer status are not invented. See [Community rules](COMMUNITY.md) for the full operational specification. This release remains local until the owner authorizes pushing and Railway deployment.

## PDF rendering

Dompdf renders escaped Blade templates using local assets and embedded DejaVu fonts. All three files are queued and saved in private storage. Only the exam owner or an administrator can download them. Administrator assignments do not grant access to the creator's answer-key PDF. Classroom feedback release is enforced separately in attempt responses.

## Learning guidance and response evidence

Today's Bento uses 3, 10, or 20 questions for approximate 5-, 15-, or 30-minute untimed tutor rounds. It prioritizes latest missed practice responses, low familiarity, unseen questions, then other questions. Subject scope is always learner controlled. It never grants a daily catch stamp. Recommendations are deterministic within the learner's local day and recomputed at start.

The subject map and cohort overview use distinct current question stems and practice events, including Memory Maki answers. Assigned exams are excluded from practice guidance. Imported completion can contribute coverage, while unknown first-attempt history stays out of first-attempt accuracy. The existing detailed MCQ history retains its separately labeled scope. Familiarity is self-reported after feedback, not an objective mastery score.

Second Serving automatically includes ever-missed practice questions and manually saved private notes. Recovered questions remain available under the latest-answer-correct or all view. Notes are scoped to their owner and use revision checks to avoid overwriting another tab's edits.

Question reports retain the reported question version. Administrative reviews are append-only history records. Corrections through the catalog create in-app notices for affected learners without changing frozen exams or decks. No email or push subscription is required for Study Updates.

Question observations use the first graded response per learner and question version, including rated Memory Maki answers. Repeats, administrators, and assigned exams are excluded. The 20-learner percentage threshold is a product display rule, not a psychometric validation standard. Raw option counts and denominators remain visible. Cohort support prompts use inactivity for seven days or accuracy below 60% across at least five first practice responses; they do not predict exam outcomes.

## Recoverable answer delivery

An unsent answer or rating is retained in session storage for the current browser tab, keyed by learner and round. It contains only the selection and concurrency tokens, expires after 24 hours, and can be retried explicitly or discarded. It does not cache questions, enable offline exams, or extend deadlines. MCQ save receipts make repeated delivery of the same request idempotent. Changed payloads cannot reuse a receipt, and deck review tokens continue to prevent stale card submissions.

Jon Bryan S. Tiosin, MD
(c) jstiosin 2026
