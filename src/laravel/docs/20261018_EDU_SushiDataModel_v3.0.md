# Sushi version 3 data model

---

This is a local implementation of the 18 October 2026 proposal. It does not deploy a release or add questions. The original 6,000-item JSON bundles are unchanged. The migration creates the schema and taxonomy; a separate explicit command retags an existing bank.

## Changelog

- Added 12 controlled subjects, 175 topics and 790 subtopics, with foreign keys enforcing the hierarchy.
- Added authority registrations, version-specific question references and media, release membership, source files, exam families, source instances, duplicate groups, and question-to-source links.
- Added question classification, origin, review status, cognitive category and question type. Active concept keys are unique within a subtopic. Concept keys remain unset until curated.
- Added coverage and learner subtopic mastery views. Mastery counts submitted, graded answers, excluding skipped items; it is not a combined attempt and Memory Maki metric.
- Added a read-only retag preview and an atomic, idempotent application command. It creates new question versions and a mapping audit without sending answer-correction notices.
- Corrected misleading keyword overrides, including the arterial disease and rheumatoid arthritis items assigned to Psychiatry. Reviewed broader Physiology and Pathology labels against their existing objectives.
- Reassigned 15 questions to appropriate subjects with the owner's approval. Stable IDs retain their original prefix, even when their current subject changes.
- Updated study and Memory Maki selection to accept controlled codes and legacy topic selections. Current analytics classifications are overlaid in memory; saved response snapshots are unchanged.
- Updated the question editor to choose a controlled topic and edit structured citations for version 3 content.

The owner authorized deployment on 18 October 2026. The release identity is v3.0.0, Organized Study. Its in-app changelog retains v2.12.1 and earlier releases. Production deployment evidence is recorded separately from this implementation guide.

## Local application

Use a local PostgreSQL database populated with the existing bundled bank. Run from src/laravel:

~~~sh
php artisan migrate
php artisan sushi:retag-v3
php artisan sushi:retag-v3 --apply
~~~

The first retag command only validates and prints a summary. The second requires all 6,000 expected IDs and verifies each current clinical-content fingerprint and legacy topic against the retained manifest before writing. Any mismatch aborts the transaction. Already applied items are skipped, including items edited later by a curator. Missing bundled items can first be added by the existing database seeder. The authorized v3.0.0 release runs the explicit retag command after migrations and seeding; later deployments safely skip already applied items.

Apply preserves the question ID, position, stem hash, availability, stem, answer options, key, rationale and explanations. Earlier question versions, accounts, attempts, exams, bookmarks, progress, Memory Maki history and notifications are not rewritten. The current version receives taxonomy and structured metadata. Inactive questions become Retired and remain inactive. Held and Draft version 3 imports stay out of new draws.

After the version 3 release is applied, legacy imports are rejected, including new IDs, so an import cannot reintroduce uncontrolled topic labels. Before that release, the bundled legacy seeding path remains available.

## Mapping evidence and limits

The provided taxonomy and mapping CSV are read-only inputs. Their hashes and the unchanged bank hashes are retained in database/data/v3/source-manifest.json. tools/build_taxonomy_v3.py reproduces the packaged taxonomy, schema and mapping from that source folder. tools/taxonomy_v3_overrides.json records explicit reviewed corrections.

All 6,000 questions receive valid topic codes. There are 1,492 unassigned subtopics. The remaining 4,508 subtopic assignments are provisional matches carried from the supplied keyword map; this work does not claim a clinical review of all subtopic placements. Taxonomy subtopics retain draft status. Mapping methods and the original proposed codes are recorded per item. Topic classification does not change a clinical answer.

The taxonomy is the supplied working blueprint, not a claim of official PRC topic weights. Topic weights and targets remain null. items_per_exam is also null because the proposal explicitly leaves that figure to verification. Exam day order is retained as source metadata.

Question totals after reassignment:

| Subject code | Before | After |
|---|---:|---:|
| ANA | 500 | 502 |
| BCH | 500 | 500 |
| IM | 500 | 502 |
| LGM | 500 | 496 |
| MPA | 500 | 499 |
| OBG | 500 | 500 |
| PED | 500 | 501 |
| PHA | 500 | 501 |
| PHY | 500 | 502 |
| PRV | 500 | 500 |
| PTH | 500 | 497 |
| SUR | 500 | 500 |
| Total | 6,000 | 6,000 |

## Authority and provenance handling

Existing reference IDs and exact citation text are preserved. Where a single authority ID has a citation, its existing citation supplies the registry title. Multi-authority citations that cannot be split reliably retain the ID as the registry title. All imported registrations and references are marked legacy_unverified. A full legacy citation is retained as the locator; exact page parsing, editions, publication years, publishers and page offsets are not invented. Malformed legacy URLs remain in raw metadata and are null in the structured URL field.

The original 33 raw columns are retained, with six appended fields: Topic Code, Subtopic Code, Subtopic, Concept Key, Origin and Source Group IDs. In particular, the original raw Topic remains available for provenance. Legacy Recurrence Count still means files. Distinct exam-family counts are omitted because they are unknown; this migration does not silently relabel those counts.

No source wording or source corpus is imported. The provenance tables are ready for a separately scoped import, and school-source counts in the coverage view count only imported groups, currently zero. They must not be interpreted as the source universe being empty. No item-to-duplicate-group link is inferred from low-confidence text similarity. Commercial groups are rejected as conversion sources; authority-authored questions may link them only as topic maps.

The public question serializer exposes an explicit allowlist, including taxonomy codes and feedback citations. Raw rows, internal paths, source groups, lineage and review metadata are excluded. Source-model serialization also hides source wording and local paths.

## Proposal adaptations

- PostgreSQL column lengths match the existing string IDs. Citations and locators use text to preserve legacy values without truncation.
- References and release membership use a composite foreign key to an actual question version. Media also records its owning version and a stable asset ID.
- The content schema accepts the existing status and source_review_status compatibility fields, schema_version, and a reference verification status.
- The existing active flag and new status are kept consistent by the model, a database trigger for bulk updates, and a database constraint.
- Cross-subject reassignment does not rename IDs or rewrite attempt snapshots. The ID prefix records historical identity, not current classification.
- Internal source groups allow reference origin as well as school and commercial, matching the supplied corpus's handout and guideline class.

## Validation and recovery

Validation completed on 18 October 2026:

| Check | Result |
|---|---|
| Full Laravel regression suite | 113 tests passed, 73,099 assertions |
| Final v3 feature suite after the last changes | 10 tests passed, 54,054 assertions |
| Frontend unit tests | 11 passed |
| Production frontend build | Passed |
| Independent JSON Schema validation | All 6,000 records passed, including format checks |
| Synthetic local database preservation | 43 checks passed for original tables, old versions and stable question identities |
| Repeat retag application | Zero further changes |
| Original bank, proposal and mapping-tool integrity | All 12 hash comparisons passed |
| PHP formatting and Git whitespace checks | Passed |

The final focused run includes the database-reset compatibility correction, serialized import validation, rejection of new legacy imports after v3, and exclusion of skipped answers from mastery counts. The full regression run preceded those final changes. Both runs used isolated local PostgreSQL databases.

Feature tests cover schema rollback before retagging, complete taxonomy loading, dry-run behavior, all-item content preservation, idempotence, inactive state, concept collisions, invalid taxonomy and authority references, commercial conversion rejection, legacy downgrade rejection, historical answers and bookmarks, coverage views, and topic-code selection in study and Memory Maki.

tools/rehearse-v3.php captures and compares hashes only on the synthetic local sushi_ui_preview database at 127.0.0.1. It verifies prior database tables and old question versions after application. It refuses any other environment or database.

Before any later production migration, take and rehearse a database backup and review the preview against that database. A changed question requires an explicitly reviewed mapping update; do not weaken the fingerprint check. Schema rollback is allowed before retagging. Once retag versions exist, the migration refuses rollback because dropping classification columns alone would leave versioned content inconsistent. Recovery then requires the pre-migration database backup and the previous application revision.

The initial implementation was validated locally. Production deployment was separately authorized by the owner on 18 October 2026.
