# SUSHI Clerks Comprehensive Exam Reviewer

---

A warm pink study studio for undergraduate medical students and clerks. Release v1.3 includes 1,047 questions in Internal Medicine, Obstetrics and Gynecology, Pediatrics, and Surgery, with original SUSHI characters and a mobile-friendly layout.

## Open the app

Download this repository, extract it, and open [index.html](index.html) in Chrome, Edge, or another browser that runs local HTML apps. No installation or server is needed. The HTML embeds the questions, artwork, fonts, and PDF software for offline use.

The versioned [SUSHI v1.3 HTML](20261013_EDU_SushiClerksExamStudio_v1.3.html) is identical to index.html. The repository includes a root entry point for static hosting; hosting must be configured separately.

## Study menu

| Mode | Purpose |
|---|---|
| Fresh Catch 10 | Ten random unanswered questions from selected subjects. |
| Maki Mentor | Guided practice with immediate feedback after checking an answer. |
| Wasabi Rush | Timed practice with feedback after submission. |
| Bento Builder | Custom exams with downloadable PDF papers, answer sheets, and answer keys. |
| Roll Call | Searchable question library with explanations and source notes. |

Your current achievement tier and progress stay in the top sticky bar. Tap the badge to see all six tiers without leaving your active question. Subject characters appear in the relevant practice, library, and exam-builder screens.

Progress is saved locally in your browser. Before switching versions, devices, browsers, or file paths, use **Sources and progress → Download progress backup**, then restore the backup in the new copy.

See the [user guide](20261013_EDU_SushiStudioGuide_v1.3.md) for mode details, achievement thresholds, PDF exports, and progress transfer.

## Release contents and checks

- The released HTML, original artwork in [assets/](assets/), and application source in [src/](src/).
- Brand and character prompt records, plus desktop and mobile screenshots.
- [Release manifest](20261013_EDU_SushiReleaseManifest_v1.3.json) with file hashes and 50 passing checks for the sticky achievement bar.
- [Source manifest](20261013_EDU_SushiSourceManifest_v1.3.json) with source fingerprints and question counts.

All 1,047 supplied question records are preserved. The v1.3 checks cover desktop and mobile browser layouts, touch access, live tier updates, saved progress, session preservation, and offline use. Broader v1.2 regression results remain prior evidence, as documented in the release manifest.

The versioned ZIP named in the release manifest is the original local distribution archive. It is omitted from Git to avoid duplicating the repository contents. GitHub's **Code → Download ZIP** provides this repository as an archive. Temporary build output, test downloads, and personal progress backups are excluded.

The retained build and packaging scripts document the original Cowork OS release process. Rebuilding them requires its source CSV files, prior release layout, embedded PDF-library source, Calibri fonts, and Python dependencies described in the guide. None of these are required to run the released HTML.

## Credits

Created by **Jon Bryan S. Tiosin, MD**.

**(c) jstiosin 2026**

Creator credits appear throughout the app and on every generated PDF page. Embedded software attribution is available under **Sources and progress → Software attribution**.
