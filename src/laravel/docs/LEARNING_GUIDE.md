# SUSHI learning upgrade

---

The approved improvements were implemented in order: 1, 2, 3, 4, 5, 6, and 8. Exam blueprint expansion was not part of this pass. Publication and Railway deployment remain on hold.

## Where to find each improvement

| Feature | Location | Behavior |
|---|---|---|
| Today's Bento | Counter | Choose 5, 15, or 30 minutes and subjects; see why questions were selected; start an untimed practice round or resume a matching unfinished deck. |
| Accuracy and familiarity | Memory Maki deck | Separate latest-answer correctness from familiarity ratings, show session accuracy, and insert up to three intervening cards before a repeat. The 80% rated-5 stopping prompt remains optional. |
| Second Serving | My Progress | Review missed questions, add private mistake reasons and notes, and create tutor rounds or Memory Maki decks from selected questions. |
| Subject map | My Progress | Ten character cards show coverage, first-attempt accuracy with denominators, familiarity, weak topics, and the next available subject achievement. |
| Chef's Review | Beside explanations and Head Chef | Learners report unclear wording, disputed answers, references, or typos. Administrators review the reported version and record a response. Study Updates contains replies and correction notices. |
| Cohort overview | Head Chef | Filter active learners by cohort, inspect subject results, assign focused practice, and inspect version-specific answer-choice counts. |
| Mobile study | Study pages | Quick deck presets, touch-sized controls, keyboard shortcuts, reduced motion, and recoverable unsent selections. |

## Data definitions

Practice guidance excludes assigned exams. Coverage counts distinct active stems and retains known imported completion. Unknown imported first-attempt history is excluded from accuracy. The old detailed MCQ analytics and the new combined-practice subject map have different, explicitly labeled scopes.

Familiarity is entered after seeing feedback. Ratings 4 and 5 form the confident group in the accuracy comparison. The 80% stopping target still specifically requires rating 5. Displayed session accuracy includes rated responses.

Question statistics count a learner once per question version. Twenty learners are required before displaying an item percentage; observed counts remain available below that threshold. This display rule does not establish clinical validity, official difficulty, discrimination, or examination readiness.

## Local operation and future deployment

The upgrade adds four additive migrations: deck spacing history, study notes, question feedback and notices, and answer-delivery receipts. Run migrations before serving the updated frontend. Preserve the normal database backup and restore procedure.

The local preview uses the separate sushi_community_preview PostgreSQL database. The automated feature suite uses sushi_test. The preview database was backed up before migration. The primary application database, GitHub remote, and Railway service were not changed in this pass.

Unsent selections remain only in the current tab for up to 24 hours and contain no question text or authentication credentials. Retrying requires a valid signed-in session. Timed exams keep their server deadlines. This is connection-recovery support, not a fully offline application.

Creator: Jon Bryan S. Tiosin, MD

(c) jstiosin 2026
