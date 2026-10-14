# SUSHI Implementation Decisions

---

## Laravel application and PostgreSQL

The Laravel application lives in src/laravel alongside the preserved v1.3 offline release. PostgreSQL is the default development, test, and deployment database. Laravel's session authentication provides username sign-in, throttling, CSRF protection, role checks, and session revocation; the small two-role application uses dedicated controllers instead of a separate authentication package.

## Fixed achievement thresholds

The owner's revised requirement replaces the original six thresholds. Tiers advance every 200 distinct completed questions, including completed MCQs or rated flashcards. Duplicate stems and repeats across modes count once. Retiring questions and importing new questions do not reduce earned completion. The original first twelve names are preserved, with 26 unique names from Rice Rookie at zero through Omakase Master at 5,000 questions. Numbered Omakase Master tiers continue every 200 beyond 5,000.

Portrait frames are separate from tier names. Bronze is available initially, Silver at 200 completed questions, Gold at 400, and Sakura flowers at 600. Learners can choose any earned frame, and their saved choice persists across later tiers and character changes. A null preference keeps the previous highest-earned appearance until the learner chooses. This supersedes the v2.2 interpretation that used frame colors as tier names.

## Fresh Catch retry pool

Fresh Catch selects exactly ten distinct stems from never-completed MCQs and latest incorrect MCQ results. A correct graded MCQ excludes the duplicate-stem group; a later incorrect result puts it back. An unchecked answer selection alone does not remove a question. Confidence ratings belong to Memory Maki and do not alter MCQ accuracy or the Fresh Catch pool.

## Flashcard decks repeat within a session

The owner's latest requirement supersedes the initial day-based flashcard proposal. A learner filters subjects/topics/difficulty, previews an inclusive one-based range, selects individual questions, and saves a versioned deck. Each question is answered before feedback is revealed, then rated 1 to 5. A deck persists across sessions and devices.

Draw weights are unrated 6, rating 1: 6, rating 2: 4, rating 3: 3, rating 4: 2, and rating 5: 1. Sampling is weighted with replacement, so low-rated questions return more frequently and a question can repeat immediately. These are transparent application rules, not Brainscape's proprietary algorithm. There are no date-based due times. Counts are specific to the current deck and show unrated plus each of the five familiarity ratings.

## Frozen scoring evidence

Attempt items, exam items, and flashcard deck items preserve the question version actually presented. Catalog edits do not silently change past scores or existing decks. The original 33 CSV fields remain in the bank and question-version provenance.

New catalog IDs append after existing positions, keeping earlier ranges stable. Re-running the bootstrap seeder adds missing original IDs and preserves administrator edits to existing questions.

## Legacy progress

Offline backups provide latest answers and counts without a complete historical timeline. Imports preserve known completion and bookmarks, do not overwrite existing server results, and keep unknown historical first-attempt data out of that metric even after later practice.

## Community release, October 14, 2026

The approved community upgrade adds 45 persistent achievement stamps, portrait rewards, daily catch streaks, scheduled opt-in web push, and learner leaderboards. Current self-rated familiarity is separate from correctness and earns no leaderboard points. Assigned exams do not contribute shared points or subject achievements, preserving their feedback policy. Recorded history is reconciled idempotently; historical feedback views and bookmarked-at-answer status are not invented. See [Community rules](COMMUNITY.md) for the full operational specification. This release remains local until the owner authorizes pushing and Railway deployment.

## PDF rendering

Dompdf renders escaped Blade templates using local assets and embedded DejaVu fonts. All three files are queued and saved in private storage. Only the exam owner or an administrator can download them. Administrator assignments do not grant access to the creator's answer-key PDF. Classroom feedback release is enforced separately in attempt responses.

Jon Bryan S. Tiosin, MD
(c) jstiosin 2026
