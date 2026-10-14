# SUSHI community upgrade

---

Implementation release: October 14, 2026. All changes are local and await the owner's go signal before a GitHub push or Railway deployment.

## Learner experience

Fresh Catch 10 and Memory Maki occupy the first two cards on the Counter. Other study tools, assignments, weekly rankings, and analytics remain accessible below them. The sticky navigation includes the 200-question tier, current catch streak, achievement count, and profile portrait.

Memory Maki has six distinct colors for unrated and ratings 1 through 5, with numbers and text so meaning does not depend on color. The stopping threshold is ceil(0.8 × the saved deck size). Reaching that number currently rated 5 opens a choice to finish or keep rolling. Keeping rolling dismisses the prompt for that session, including refresh. Finishing saves the deck, presents a summary, and allows resuming later. Resuming begins a new session; ratings remain. Familiarity is self-reported, independent of answer accuracy.

Profiles support a display name, short bio, a JPEG/PNG/WebP upload up to 4 MB, a movable square crop, and up to three earned badges. Photos are re-encoded to 512-pixel JPEG in private storage and served only to authenticated users. SVG is rejected. Removing a photo detaches it from the profile. Old uploaded files remain private.

The Character Closet has 32 choices: 11 base characters, Sakura and Golden Omakase frames for the 10 achievement characters, and Rice Rookie's Wasabi frame. Rice Rookie is the starter. Locked choices cannot be selected through the UI or a forged request. Rice Rookie and Nori Librarian have new generated artwork; existing mode and subject characters retain their illustrations.

## Daily catches and streaks

- A qualifying catch contains exactly ten answered questions and is submitted. Merely starting or partially answering it does not count. Correctness does not determine the daily stamp.
- Starting Fresh Catch resumes an existing unfinished catch before creating another.
- One stamp is recorded per learner per local calendar day. Asia/Manila is the default timezone. Changing the study timezone is limited to once every seven days; prior stamps retain their recorded dates.
- Each seven completed catch days overall earns one Wasabi Save, capped at two. A missed day consumes a save automatically to preserve continuity. It adds no completed day, points, or artificial study activity.
- If no save remains, a missed day resets the current streak. The longest streak and permanent achievements remain.
- Today is still open until midnight, so an unfinished catch today does not prematurely break yesterday's streak.
- Fresh Catch retains the existing new/latest-incorrect pool, subject selection, and duplicate-stem exclusion. Incorrect questions may appear in later catches.

## Passport achievements

The authoritative catalog is `App\Services\Passport::definitions()`: 45 badges with exact requirements, progress, earned dates, and portrait rewards. Awards are permanent and idempotent.

| Area | Named achievements |
|---|---|
| Fresh Catch | First Catch; Perfect Platter; Second Serving; Catch of the Week; Seasoned Fisher |
| Maki Mentor | First Roll; Mentor's Apprentice; Comeback Chef; Mentor's Table |
| Wasabi Rush | First Heat; Cool Under Wasabi; Triple Heat; Full-Service Rush |
| Memory Maki | First Wrap; Ready to Roll; Golden Maki; Sticky Rice Rescue; Four-Flavor Memory |
| Bento Builder | Lunchbox Launch; Four-Flavor Bento; Ready to Serve; Bento Chef; Meal Prepper |
| Roll Call | Nori Notes; Crew Sampler; Bookmark Comeback; Reference Regular |
| Daily habits | Three-Day Tray; Sushi Train; Fortnight Feast; Thirty-Day Omakase; Counter Regular; Welcome Back Roll |
| Each of IM, Pediatrics, Surgery, OB-GYN | Meet the Chef at 50 distinct correct questions; Sakura Specialist at 100; Golden Specialist at 200 |

Memory Maki's 80% and 100% achievement badges require a deck of at least 20 cards; its stopping prompt works for any deck size. Timed achievements require at least 20 fully answered questions. Full-Service Rush requires ten per subject. Meal Prepper requires five exported exams with different question sets, so changing titles or exporting the same set repeatedly does not qualify.

Actual tutor feedback opens and library explanation opens are recorded; simply listing questions does not award review credit. Subject achievements use graded correctness across self-study modes, excluding assigned exams. Confidence alone earns no subject correctness.

Every 200 distinct completed questions still advances the global tier. Repeated stems and study modes count once. Growing the bank extends the journey without a percentage denominator changing the tier. Subject achievements show available question counts when the active bank cannot yet reach a threshold.

## Shared leaderboard

Only authenticated users can view the board and learner profiles. Active student accounts participate; administrators and disabled accounts are excluded from ranking.

- Weekly points: one per distinct graded question, plus one if its first graded answer that week was correct.
- Completing the daily catch adds five, at most once per Asia/Manila calendar day.
- Duplicate stems and repeats across self-study modes count once per week. The same question may count again in another week.
- Weeks run Monday through Sunday in Asia/Manila. Ties share rank.
- All-Time Coverage counts distinct graded questions; Streaks shows current and longest completed catch streaks.
- Confidence ratings, badges, bookmarking, and PDF generation add no points. Assigned exams are excluded to respect delayed feedback.
- Public profiles show display name, portrait, bio, tier, habits, and earned badges. They do not expose credentials, private answers, or detailed accuracy analytics.

## Reminders

The Counter's in-app reminder is available immediately. Learners can dismiss it for today or snooze for one hour. Push requires explicit browser permission and server VAPID setup. It defaults off.

The scheduler checks each minute. After the learner's chosen local time, it queues a reminder only for an enabled active learner with an incomplete catch, enough eligible questions or a resumable catch, a registered device, and no recent activity. Sending rechecks eligibility, completion, date, settings, and recent activity. Active study delays delivery. The most recently enabled device receives the reminder.

Delivery claims are committed before contacting the external push service. A worker retry cannot repeat a claimed notification. An interrupted or ambiguous delivery is not automatically retried; an explicit learner snooze may request another reminder. Expired device subscriptions are removed. A notification already handed to the browser's push service cannot be recalled if the learner completes the catch immediately afterward. The payload expires after one hour.

The service worker handles push and opens/resumes Fresh Catch. It does not cache authenticated question or profile pages. Endpoints are limited to supported browser push providers and stored encrypted. Admin counts report reminder-days sent and clicked; these are not confirmed reads.

## Migration and deployment preparation

The additive migration creates event, review-view, badge, daily catch, subscription, and reminder tables, plus user profile/preferences and deck session fields. `sushi:backfill-community` reconciles known graded responses and qualifying completed catches with stable event keys. Running it again does not duplicate history. Historical feedback views and bookmarked-at-answer status are not invented.

After deployment approval:

1. Back up PostgreSQL, private storage, and the stable APP_KEY.
2. Push the reviewed source and run the existing Railway release process. `deploy/release.sh` applies migrations, seeds idempotently, and reconciles recorded community history.
3. Generate a VAPID key pair with the installed Minishlink library. Store the public/private keys in `SUSHI_VAPID_PUBLIC_KEY` and `SUSHI_VAPID_PRIVATE_KEY` through Railway Variables. Set `SUSHI_VAPID_SUBJECT` to the production HTTPS origin or an owner-provided contact. Never commit keys.
4. Keep the supervised queue worker and minute scheduler running. The container now builds GD with JPEG and WebP support for profile uploads.
5. Verify HTTPS login, photos after restart, an opted-in real-device push, completion cancellation, session resume, and PDF downloads.

Local verification uses isolated `sushi_test` and `sushi_community_preview` databases. The guarded preview fixture is `tests/browser/prepare-community.php`. It creates sample history solely for UI review and refuses another database.

Jon Bryan S. Tiosin, MD
(c) jstiosin 2026
