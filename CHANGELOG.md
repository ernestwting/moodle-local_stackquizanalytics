# Changelog

All notable changes to `local_stackquizanalytics` are documented here.
Version numbers match `version.php`'s `$plugin->release`.

This plugin merges two previously-separate, independently-installed
plugins — `local_quizanalytics` and `local_stackanalytics` — into one. Each
phase below corresponds to one commit in this repository's history.

## [2.1.0] — Split into four sections

Quiz Analytics did double duty as both the course-wide comparison and,
via a quiz picker, the per-quiz Question Analytics/Solution Process
drill-down — picking a quiz silently swapped the whole page into a
different kind of report. Model & Diagnostics Analytics combined two
actual ML models with a non-ML Diagnostics Dashboard behind a View:
selector, which made Diagnostics read as a third model rather than what
it is. Both combined pages are split into four independently-reachable
sections, each with its own PDF export — no changes to any
report-builder, indicator, renderer, or PDF-content class, since this
was a page/routing-level split only.

- **UI polish first.** Course/View selectors laid out side by side
  instead of stacked; a single `pagemaintitle` ("STACK Analytics") used
  for every section's on-screen heading, replacing the previous
  per-section heading text; a full-width divider row in Model 2's table
  and a labeled block above each quiz's questions in the Diagnostics
  list, so which quiz a row belongs to is visually obvious instead of
  small muted text; the Diagnostics page's long intro text collapsed
  into a native `<details>` panel.
- **Quiz Analytics** (`index.php`) trimmed to the course-wide comparison
  only — no more quiz picker, no more `if ($quizid)` branch. Its PDF
  export moves to a new `quizanalyticspdf.php`.
- **Question Analytics** (`questionanalytics.php`, new) is the per-quiz
  drill-down moved out of `index.php`, with its own required quiz
  selector (defaulting to the course's first STACK quiz when none is
  specified) and its own PDF export, `questionanalyticspdf.php` (renamed
  from `pdf.php`, dropping the old `kind=quiz` branch — that's
  `quizanalyticspdf.php`'s job now).
- **Model Analytics** (`modelanalytics.php`, renamed from `models.php`)
  trimmed to Model 1 + Model 2 only. Its PDF export is
  `modelanalyticspdf.php` (renamed from `modelspdf.php`).
- **Diagnostics Analytics** (`diagnosticsanalytics.php`, new) is the
  Diagnostics Dashboard moved out of `models.php`'s View: selector, with
  its own PDF export, `diagnosticsanalyticspdf.php`.
  `dashboard_renderer::render_pdf_form()` gained a `$sectionheadings`
  parameter so Model Analytics and Diagnostics Analytics each control
  their own "Include in the PDF" checkbox subset, instead of the method
  hardcoding all three of the old page's sections.
- **`classes/section_selector.php`** grows from a 2-way to a 4-way
  switch; `lib.php`'s per-quiz settings-menu link now points at
  `questionanalytics.php` instead of `index.php`, since that's where
  per-quiz analytics live now. The course-level "Analytics" nav entry
  still lands on `index.php`.

## [2.0.0] — Post-merge polish

The first round of feedback after the 21-phase merge landed, ahead of
submitting this as the new major version of `local_quizanalytics` on the
Moodle Plugins directory rather than a separate listing.

- **Wording fixes.** "Question analytics"/"Solution process visualization"
  in Quiz Analytics's "View:" selector and "All quizzes" in Model &
  Diagnostics's quiz selector now match the title-case convention used
  everywhere else in the plugin.
- **Simpler intro text.** Model & Diagnostics Analytics's blue and yellow
  info boxes at the top of the page (`pageintro`, `responsibleusecallout`)
  rewritten as plain sentences — the two blue boxes combined into one, and
  every em dash/hyphen-as-punctuation/semicolon in them replaced with
  ordinary sentence breaks.
- **American spelling throughout.** "Behaviour"/"behavioural" →
  "behavior"/"behavioral", "labelled" → "labeled", "optimised" →
  "optimized", "Initialise" → "Initialize", across every lang string,
  docblock, and doc file. Left untouched: "analyser"/"analysable"/"analyse"
  and `summarise_response()`, which are Moodle core's and qtype_stack's own
  API vocabulary, not this plugin's wording.
- **Model & Diagnostics PDF now shows the same colors as the dashboard.**
  `pdf_content.php`'s cell builders return `{text, band}` instead of plain
  strings; `pdf_builder.php` colors each cell's background/text to match
  the on-screen badge-success (green) / badge-warning (yellow) palette for
  'good'/'watch' bands. Previously every PDF cell was plain black-on-white
  regardless of band.
- **Descriptive PDF filenames everywhere.** All four PDF export entry
  points (`pdf.php`'s three kinds, `modelspdf.php`) now name the download
  after the course, the specific quiz/section scope, and the download
  date (e.g. `mycourse-question-analytics-2026-08-18.pdf`,
  `mycourse-model-diagnostics-model1-model2-2026-08-18.pdf`) instead of a
  bare `{shortname}-quiz-analysis.pdf`/`{shortname}-stack-analytics.pdf`,
  so a teacher who downloads several reports over time can tell them apart
  in their downloads folder without opening each one.
- **Anonymize toggle for Model 1.** A new "Anonymize student data" checkbox
  on the Model 1 view replaces every student's name with a stable
  "Student N" pseudonym (`dashboard_renderer::pseudonym()`, numbered by
  `model1_report::build()`'s deterministic row order), on-screen and in
  the PDF export alike. Shares its user preference
  (`local_stackquizanalytics_anonymize`) and lang string
  (`anonymizemode`) with Quiz Analytics's existing anonymize toggle, so
  it's one teacher preference across both sections rather than two
  separate switches.
- **Repository renamed** from `moodle_analytics` (this merge's working name)
  to `moodle-local_stackquizanalytics`, matching the
  `moodle-{plugintype}_{pluginname}` convention both source plugins'
  repositories already followed — the naming-convention gap flagged in
  Phase 20 is now resolved.

## [1.0.0] — Merge phases 1–21

This plugin's Marketplace listing replaces `local_stackanalytics`'s own —
the version history continues directly from that plugin's v0.10.0 (see
"Pre-merge history: `local_stackanalytics`" at the bottom of this file for
its own Phases 0–10, which predate and form the basis of Phases 7–11
below).

**Phase 1 — Skeleton.** Bare installable no-op plugin: `version.php`
(component `local_stackquizanalytics`, dependencies `mod_quiz` + declared
`qtype_stack`), `LICENSE` (GPL v3), `.gitignore`, `db/access.php` (the one
merged capability, `local/stackquizanalytics:view`, matching both source
plugins' identical archetypes for the same content class), a minimal
`lib.php` stub, and a skeleton `lang/en/local_stackquizanalytics.php`.

**Phase 2 — Quiz Analytics data layer.** Ported `classes/data_fetcher.php`,
`api_client.php`, `cache_helper.php` from `local_quizanalytics` into
`classes/quiz/`, renamed to the `local_stackquizanalytics_quiz_*` prefix
(these are legacy global-namespace classes, manually `require_once`'d —
confirmed never autoloaded, so free to relocate). `db/caches.php` carried
over unchanged.

**Phase 3 — Quiz Analytics computation layer.** All ~29 files from
`classes/analytics/` ported to `classes/quiz/analytics/`, namespace
`local_stackquizanalytics\quiz\analytics` — chart/table/stats helpers,
parser/expression-tree math, course/question/solution-process analysis,
PDF builder classes, the vendored TCPDF subclass. `classes/output/
sections_output_helper.php` → `classes/quiz/output/`, with hardcoded
`/local/quizanalytics/...` asset URLs and user-preference keys updated to
`/local/stackquizanalytics/...`.

**Phase 4 — Vendored libraries.** TCPDF (`classes/quiz/vendor/tcpdf/`),
KaTeX and Plotly.js (`js/vendor/`), and the shared sections-renderer
(`js/vendor-shared/sections-renderer.js`) copied verbatim.
`thirdpartylibs.xml` updated with the new TCPDF path and a note that it's
Quiz-Analytics-specific.

**Phase 5 — Quiz Analytics entry point.** `index.php` adapted from
`local_quizanalytics`'s own, all paths/namespaces/capability/cache/config
keys updated, plus the new `classes/section_selector.php` — the one
genuinely new piece of UI this merge adds, a "Section:" switcher between
Quiz Analytics and Model & Diagnostics Analytics. Live-verified against a
real course: course-wide, Question Analytics, and Solution Process
Visualization views all render correctly.

**Phase 6 — Quiz Analytics PDF export.** `pdf.php` adapted from
`local_quizanalytics`'s own. Live-verified by generating a real PDF against
real course data and visually inspecting rendered pages.

**Phase 7 — Model & Diagnostics data/indicator/target/analyser layer.**
Ported from `local_stackanalytics`'s `classes/local/`,
`classes/analytics/{indicator,target,analyser}/` into
`classes/stack/{local,analytics/indicator,analytics/target,
analytics/analyser}/`, namespace `local_stackquizanalytics\stack\...`.
Caught and fixed one real bug during the port: `stack_course_helper.php`'s
`get_viewable_courses()` had a hardcoded capability string
(`'local/stackanalytics:view'`) the mechanical sed pass for quoted
component-name literals didn't catch (it's a capability string, slash not
underscore before the colon) — would have silently returned zero courses
for everyone.

**Phase 8 — Model & Diagnostics diagnostics/report/renderer layer.**
Ported `classes/diagnostics/*.php`, `classes/analytics/report/*.php`,
`classes/output/dashboard_renderer.php` into their `\stack\` equivalents.
Brought forward the full Model & Diagnostics lang string set (~85 strings)
plus three new strings for the shared section selector. Verification
initially checked only non-empty PDF/HTML byte length, which wouldn't
catch missing lang strings (`get_string()` degrades silently to
`[[stringid]]` rather than throwing) — caught this gap and added a proper
`preg_match_all` check against real rendered output.

**Phase 9 — Model & Diagnostics PDF system.** `pdf_builder.php`,
`pdf_content.php`, and `stack_pdf.php` (renamed from
`stackanalytics_pdf.php`) ported, built on Moodle core's own bundled TCPDF
rather than vendoring a second copy (Quiz Analytics's own PDF export
already vendors one; no need to duplicate that ~5MB library twice in one
plugin). Live-verified by generating a real PDF against real course data
(35 Model 1 rows, 21 Model 2 rows, 21 Diagnostics rows) and visually
inspecting it.

**Phase 10 — Model & Diagnostics entry point + PDF.** `models.php`
(adapted from `local_stackanalytics`'s index.php, with the shared "Section:"
selector) and `modelspdf.php` (adapted from its pdf.php, renamed to avoid
colliding with Quiz Analytics's own `pdf.php`). Found and fixed one missing
lang string (`courseselectorlabel`, dropped during Phase 8's port) via live
click-through testing. Live-verified all three views (Model 1, Model 2,
Diagnostics) and the full PDF export request path.

**Phase 11 — Analytics API registration.** `db/analytics.php` registers
both prediction models, target/indicator class strings updated to the new
namespace. Live-verified via `\core_analytics\manager`: both models
register with the correct target, indicator set, and time-splitting
method, both disabled by default.

**Phase 12 — Navigation hooks.** `lib.php`'s
`local_stackquizanalytics_extend_navigation_course()` adds the single
merged course-nav "Analytics" entry (landing on Quiz Analytics),
`local_stackquizanalytics_extend_settings_navigation()` carries over
`local_quizanalytics`'s per-quiz settings-menu link (`local_stackanalytics`
never had one). Both gated on the same capability plus a single "does this
course have any STACK quiz" check — both source plugins' own gating
queries resolve to the same underlying join. Live-verified via a CLI script
calling both hook functions directly against a real course/quiz.

**Phase 13 — Unified settings.** `settings.php` merges
`local_quizanalytics`'s `computetimelimit` with `local_stackanalytics`'s
three Model & Diagnostics settings (`questionneedsreviewthreshold`,
`lowtrafficfloor`, `helpseekinglookback`) onto one admin settings page.
Live-verified by loading the real admin settings page.

**Phase 14 — Lang file reconciliation.** Full click-through across every
page and view in both sections (course-wide, Question Analytics, Solution
Process Visualization, Model 1, Model 2, Diagnostics) confirmed zero
missing-string placeholders and zero duplicate keys; the four originally-
overlapping keys between both source plugins' lang files
(`pluginname`, `privacy:metadata`, `downloadpdfbutton`, `pdfnosections`)
each resolved to one reconciled value.

**Phase 15 — Unified privacy provider.** `classes/privacy/provider.php`
merges both source plugins' identical `null_provider`s into one, covering
both sections' data sources. Live-verified via
`\core_privacy\manager::component_is_compliant()`.

**Phase 16 — Tests.** All 16 PHPUnit test files ported from
`local_stackanalytics`'s `tests/`, namespace-adapted. 11 are DB-free
pure-math tests, live-verified by running their real test methods
directly against the ported production classes (73 test methods, 99
assertions, all passing). The remaining 5 use PHPUnit generator fixtures
and need a full `composer install` + `admin/tool/phpunit/cli/init.php`
environment this dev container doesn't have to actually execute — the
production code paths they exercise were already live-verified end-to-end
with real course data in Phases 7-11.

**Phase 17 — CI.** `.github/workflows/moodle-ci.yml` combines
`local_quizanalytics`'s wider test matrix (PHP 8.1-8.3 × pgsql/mariadb)
with `local_stackanalytics`'s `qtype_stack` handling (Maxima installation,
the `QTYPE_STACK_TEST_CONFIG_PLATFORM` override that skips qtype_stack's
own optimized-Maxima-image build during PHPUnit init). Code Checker and
PHPDoc Checker stay `continue-on-error` pending Phase 20's cleanup.

**Phase 18 — Docs.** `README.md`, `INSTALL.md`, `MARKETPLACE_LISTING.md`,
and this changelog rewritten to describe the merged plugin; the Model &
Diagnostics Analytics architecture doc carried over to `docs/`.

**Phase 19 — Full functional pass, two real bugs found and fixed.** Fresh
full redeploy (not an incremental sync) and a complete click-through of
every page/view in both sections with `$CFG->debug` set to
`DEVELOPER`/`debugdisplay` on, to catch what `debug=0` silently swallows.
This surfaced two real, pre-existing correctness bugs in Model &
Diagnostics Analytics's question-versioning handling, both predating this
merge:

- `stack_course_helper.php` and `stack_attempt_reader.php` each joined
  `{question_versions}` on `questionbankentryid` alone, with no filter to
  a single version. Any STACK question with edit history (this plugin's
  own test course has one with 6 accumulated 'ready' versions) fanned
  every slot out to one row per version — in `stack_course_helper.php`
  this could return the wrong `questionid` for a slot depending on row
  order; in `stack_attempt_reader.php` it duplicated every attempt step
  once per version, corrupting five indicators with inflated observations.
  Fixed by pinning `{question_versions}` to the referenced version or the
  latest non-draft one, the same semantics mod_quiz's own
  `qbank_helper::get_question_structure()` uses.
- `get_slot_finished_fractions()` selected only `qas.fraction` (no unique
  column) via `get_records_sql()`, which keys its return array by the
  first selected column regardless — silently collapsing every attempt
  sharing a fraction value down to one, badly corrupting
  `question_difficulty_irt`'s distribution math. Fixed by switching to
  `get_fieldset_sql()`, the correct API for a flat list of one column's
  values.

Both found via genuine `debugging()` warning floods against real test
data, not guessed. Re-ran the Phase 16 pure-math suite (still 73/73) and
regenerated a real PDF after the fix (same row counts) to confirm nothing
else broke. The only remaining warning after both fixes is confirmed to
originate entirely inside `qtype_stack`'s own `connector.class.php`
(third-party code), not this plugin's.

**Phase 20 — Marketplace compliance pass.** Ran `phpcbf` across the whole
plugin (34 opening-brace violations auto-fixed), then manually resolved
the remaining ~40 "missing one-line docblock description" errors and 6
over-length lines — full-plugin `phpcs` total went from 85 errors/52
warnings (baseline debt tracked since Phase 7-9) to 0 errors/41 warnings
(all MOODLE_INTERNAL stylistic notices, left as-is, matching both source
plugins' universal convention). Re-verified zero regressions via the pure-
math suite and a full page click-through after redeploying. Security
review: grepped for eval/exec/shell_exec, unescaped superglobal access,
raw SQL interpolation, unserialize(), extract(), debug leftovers, and
hardcoded credentials — zero hits. Confirmed every entry point requires
login+capability before doing anything, `settings.php` gates on
`$hassiteconfig`, and the plugin's one POST form (Quiz Analytics's PDF
export, POST only because it carries client-captured chart images too
large for a GET query string) is a read-only, capability-gated action that
doesn't need sesskey protection by Moodle's own convention. Flagged rather
than silently fixed: this repository's name (`moodle_analytics`) doesn't
follow the Moodle Plugins directory's `moodle-{plugintype}_{pluginname}`
convention for single-plugin repos — the user's own call to make at
submission time.

**Phase 21 — Wrap-up.** This changelog entry.

---

## Pre-merge history: `local_stackanalytics`

The entries below are carried over unchanged from `local_stackanalytics`'s
own `CHANGELOG.md` — that plugin's Phases 0–10 (v0.1.0–v0.10.0), predating
this merge and forming the basis of Phases 7–11 above. Kept here in full,
including its own internal quirks (the non-monotonic ordering around
"Test fixture fix"/"CI fixes", and `0.8.0` covering two separate phases),
since this listing replaces that plugin's own rather than sitting
alongside it.

## [0.10.0] — Phase 10

Follow-up to Phase 9's dashboard revamp, from real usage feedback on a
live course: the page was still too long, the Diagnostics section was
still hard to read, and there was no way to get the data off the page.

- **View switcher.** Replaced the anchor-link "Jump to section" nav with
  a real "View:" selector — exactly one of Model 1/Model 2/Diagnostics
  renders (and queries) per page load now, not all three every time,
  which is what was actually making the page long.
- **Diagnostics readability.** New `classes/analytics/report/diagnostics_report.php`
  wraps `seed_bias_report`/`bloated_tree_report`'s existing results with
  a 'good'/'neutral'/'watch' band, same convention as the Model 1/2
  indicators. `dashboard_renderer::render_diagnostics_section()` renders
  each question as a collapsed-by-default `<details>` block — scannable
  from its summary line alone, full raw ANOVA/branch tables still
  available inside on expand.
- **PDF export.** `classes/analytics/stackanalytics_pdf.php` extends
  Moodle core's own bundled TCPDF (`lib/pdflib.php`) rather than
  vendoring a separate copy — confirmed core has shipped TCPDF for
  its own PDF features for years, so there's nothing to duplicate here.
  `pdf_content.php` re-derives each section as plain-text tables from
  the same report-builder classes the dashboard uses; `pdf_builder.php`
  renders them via `writeHTML()`, landscape A4. A checkbox-driven
  "Download PDF" form sits at the bottom of every view. Built and
  visually verified an actual PDF against the live Moodle instance
  while developing this (not just reviewed as code) — caught two real
  bugs that way: core's bundled TCPDF has no `dejavusans` font (needed
  `freesans` instead, for its full Unicode coverage), and the initial
  column-width formula left the Diagnostics table's third column
  absorbing almost all the width.

## [0.9.0] — Phase 9

Turned index.php from a diagnostics-only page into the full dashboard the
architecture doc always intended: one page, three clearly-labelled
sections (Model 1, Model 2, Diagnostics), each explained in plain
language for a reader with no ML background, tolerant of missing data
throughout.

- Every indicator (all nine, across both models) and `question_needs_review`
  gained a public `compute_for_sample()`, extracted from the existing
  `calculate_sample()` — same DB-fetch and math, now returning the
  real-world facts behind the value plus a plain-language 'good' /
  'neutral' / 'watch' band, not just the bare [-1, 1] figure the Analytics
  API consumes. `calculate_sample()` itself is unchanged behaviour, just a
  one-line delegate.
- `classes/analytics/report/model1_report.php` / `model2_report.php`:
  build one row per enrolled student / per STACK quiz slot respectively,
  pairing each model's direct, un-trained-model read (grade-vs-pass for
  Model 1, pass-rate-vs-threshold for Model 2 — both models ship disabled
  by default, alpha stage) with their indicators. Both cap at 100 rows
  with a "showing the first N" notice.
- `classes/output/dashboard_renderer.php`: turns those into badge+sentence
  tables, plus a collapsible "About this model" panel per model carrying
  the architecture doc's target/indicator-catalog content without
  cluttering the main view.
- `index.php`: a course selector (`stack_course_helper::get_viewable_courses()`,
  new) and a quiz selector for courses with more than one, a "Jump to
  section:" nav, per-question "Jump to:" links, a plain-language intro
  explaining what each section is and that both models are un-trained, and
  a responsible-use callout (architecture doc §7, condensed) shown before
  the first badge on the page. The existing seed-bias/bloated-tree content
  moved under its own "Diagnostics Dashboard" heading; a one-line note
  covers concept-dependency mapping, which `concept_dependency_report.php`
  already documented as intentionally unimplemented but which never
  actually appeared anywhere on the page.
- `tests/model1_report_test.php`, `tests/model2_report_test.php`: cover
  each report builder's own orchestration (student-role filtering,
  grade-vs-pass correctness, quiz filtering, empty states) using the same
  fixture depth as the rest of this plugin's tests. Indicator values
  themselves aren't asserted on here, for the same reason
  `calculate_sample()` coverage is deferred elsewhere in this file: real
  values need a full attempt-walkthrough fixture this plugin doesn't have
  yet.

## Test fixture fix (post-Phase 8)

- CI run #4 got all the way to PHPUnit and ran all 84 tests, failing only the
  3 that create a real STACK question via
  `$questiongenerator->create_question('stack', 'test0', ...)`: "Method
  get_stack_question_form_data_test0 does not exist on the stack question
  type test helper class." Confirmed against the real
  `question/type/stack/tests/helper.php`: `qtype_stack_test_helper` only
  implements the `get_stack_question_form_data_*()` method
  `core_question_generator::create_question()` needs for *some* named
  fixtures (e.g. `'test1'`, `'test3'`) — others, including the simpler
  `'test0'` this plugin's tests originally used, only have a
  `make_stack_question_*()` object-constructor for STACK's own internal
  tests, which `create_question()` can't call. Switched all three affected
  tests (`student_at_risk_test`, `stack_question_analyser_test`,
  `question_needs_review_test`) to `'test1'`.

## CI fixes (post-Phase 8)

- Run #2 failed at plugin install with "maxima_opt_auto creation failed" —
  traced to `question/type/stack/db/install.php`: qtype_stack unconditionally
  tries to build an optimised Maxima image whenever `PHPUNIT_TEST` is true
  (moodle-plugin-ci's own phpunit-init sub-step), and no `maxima` binary was
  installed anywhere in the workflow. Fixed by installing Ubuntu's packaged
  `maxima` before the plugin-install step.
- Run #3, with `maxima` now installed, hit the *same* failure — Ubuntu's
  packaged Maxima isn't a drop-in match for whatever
  `connectorhelper.class.php`'s `create_auto_maxima_image()` expects to
  compile against. Rather than keep guessing at Maxima package
  compatibility, used `install.php`'s own documented escape hatch instead:
  it skips the optimised-image build entirely if
  `QTYPE_STACK_TEST_CONFIG_PLATFORM` is defined as `'none'` before its
  `PHPUNIT_TEST` branch runs. `moodle-plugin-ci add-config` can't set this in
  time — its own source (`AddConfigCommand.php`) confirms it edits
  `config.php` *after* `install` completes, by which point install.php has
  already run. Used PHP's `auto_prepend_file` ini setting instead (wired via
  the `Setup PHP` step's `ini-values`), which guarantees the constant exists
  for every PHP process in the job from the start, including
  moodle-plugin-ci's internal phpunit-init sub-step.

## [0.1.0] — Phase 0

- Initial plugin skeleton: `version.php`, `lib.php`, `settings.php`, `db/access.php`,
  `lang/en/local_stackanalytics.php`, `LICENSE`.
- No Analytics API classes yet — this phase only establishes an installable no-op
  plugin (`local/stackanalytics:view` capability, dependency on `mod_quiz` +
  `qtype_stack`) before any target/indicator/analyser code lands.
- Design document (`docs/moodle-stack-analytics-architecture.md`) checked in as
  the source of truth for every subsequent phase.

## [0.2.0] — Phase 1

- `classes/local/stack_course_helper.php` and `classes/local/stack_attempt_reader.php`:
  shared STACK-question detection and question-engine data access reused by all
  Model 1 indicators (and, later, both models' targets).
- Five Model 1 indicators (`classes/analytics/indicator/`): `grade_trajectory`,
  `response_latency_anomaly`, `disengagement_entropy`, `help_seeking_gap`,
  `feedback_revision_distance` — each extends `\core_analytics\local\indicator\linear`
  and implements the exact [-1, 1] normalization formulas from the architecture
  doc's §2.2. Base-class contracts (`calculate_sample($sampleid, $sampleorigin,
  $starttime, $endtime)`, `get_name(): \lang_string`, `required_sample_data()`)
  were verified against a real Moodle 4.5 core checkout rather than assumed.
- Each indicator's normalization math is factored into small public static
  methods, unit-tested directly with synthetic values in `tests/`. DB-fixture-backed
  integration tests for `calculate_sample()` itself are deferred to Phase 7.

## [0.3.0] — Phase 2

- `classes/analytics/target/student_at_risk.php`: Model 1's binary target,
  extending core's own `\core_course\analytics\target\course_gradetopass`
  (grade-to-pass-threshold risk, with all of `course_enrolments`'s enrolment-
  window/course-validity checks) and adding the architecture doc's "STACK
  courses only" restriction via `stack_course_helper::course_has_stack_activity()`.
- `db/analytics.php`: registers Model 1 (target + five Phase 1 indicators +
  `\core\analytics\time_splitting\quarters_accum`, disabled by default pending
  admin review of thresholds) — the schema and its automatic-registration
  mechanism (`\core_analytics\manager::update_default_models_for_component()`,
  called from every plugin install/upgrade) were confirmed against the real
  Moodle 4.5 core checkout, not assumed from documentation.
- `tests/student_at_risk_test.php`: the STACK-activity gate, the inherited
  grade-to-pass validity check taking precedence over it, and a
  `calculate_sample()`/analyser integration test modelled directly on core's
  own `course_gradetopass` test in `course/tests/targets_test.php`.

## [0.4.0] — Phase 3

- `classes/analytics/analyser/stack_question_analyser.php`: Model 2's analyser.
  After verifying against a real Moodle 4.5 core checkout that core's only two
  analyser base classes (`by_course`, `sitewide`) both hardcode their
  analysable, this extends `by_course` and reuses the existing
  `\core_analytics\course` analysable unchanged — mirroring exactly how core's
  own `student_enrolments` analyser works for Model 1 — rather than building a
  from-scratch custom analysable with no precedent to verify against. STACK
  questions become *samples* (one `quiz_slots` row each) within a course,
  which still delivers the course-scoped, memory-safe processing the
  architecture doc calls for.
- `classes/local/stack_course_helper.php`: adds `get_course_stack_slots()` and
  `get_stack_slots()`, reusing the same STACK-question join as Phase 0/1's
  `course_has_stack_activity()` (now factored into a shared private helper).
- `tests/stack_question_analyser_test.php`: confirms non-STACK quiz slots are
  correctly excluded from Model 2's samples. A positive-path test (a real
  qtype_stack question included as a sample) needs qtype_stack's own question
  generator and is deferred to Phase 7, same as `student_at_risk_test.php`'s
  equivalent gap.

## [0.5.0] — Phase 4

- `classes/local/stack_prt_graph.php`: PRT branch enumeration and coverage,
  built on a real finding from the qtype_stack source (question/type/stack in
  the same Moodle 4.5 checkout): `qtype_stack_prt_nodes`' teacher-authored
  `trueanswernote`/`falseanswernote` strings are exactly what
  `qtype_stack\question::summarise_response()` writes into the standard
  `question_attempts.responsesummary` field, so a branch's reach is
  observable by substring-matching its answernote against attempts'
  response summaries — no STACK-internal parsing needed.
- Four Model 2 indicators: `question_difficulty_irt` (logit-scale difficulty
  from empirical pass rate — documented as a deliberate simplification of the
  architecture doc's full 2PL IRT model, since joint a/b/c/θ estimation needs
  a batch calibration step the per-sample `calculate_sample()` API has no
  hook for), `syntax_error_rate` (reuses the standard question-engine
  `'invalid'` state rather than parsing STACK's AnswerTest output),
  `unreached_node_ratio` (on `stack_prt_graph`), and `feedback_ineffectiveness`
  (a documented simplification of the doc's per-branch paired McNemar's test:
  an aggregate log-odds effect size of post-failure improvement vs. first-try
  baseline, since per-branch attribution isn't observable from
  `responsesummary`'s current-value-only history).
- `classes/analytics/target/question_needs_review.php`: Model 2's binary
  target, using the architecture doc's proxy-label option 2 (pass rate below
  a threshold) — the doc's own circularity caveat against
  `question_difficulty_irt` is called out directly in the class docblock.
- `db/analytics.php`: registers Model 2 (target + four new indicators,
  `\core\analytics\time_splitting\single_range` per the doc's §3.5, disabled
  by default like Model 1).
- Pure-math tests for all four new indicators plus `stack_prt_graph`, and an
  `is_valid_analysable`/`can_use_timesplitting` test for the new target.
  `calculate_sample()` integration coverage remains deferred to Phase 7
  pending a qtype_stack question fixture.

## [0.6.0] — Phase 5

- `classes/diagnostics/seed_bias_report.php`: one-way ANOVA of question score
  by STACK random seed (architecture doc §3.4e). The per-attempt seed is read
  from `question_attempt_step_data`'s `'_seed'` name — traced directly to
  `qtype_stack\question::start_attempt()` in the real qtype_stack source,
  since STACK's seed/variant mechanism has no core-Moodle equivalent to infer
  it from. Reports the F-statistic and η² (with Cohen's standard magnitude
  labels) but deliberately not an exact p-value, which would need the
  F-distribution's CDF — a numerical routine not worth risking getting subtly
  wrong without a reference implementation to verify it against, for a
  dashboard that's exploratory by design.
- `classes/diagnostics/bloated_tree_report.php`: per-branch PRT traversal
  coverage on the same `stack_prt_graph` Phase 4 built, reported as a
  maintenance metric (never-reached vs. low-traffic vs. adequate) rather than
  folded into an ML feature, per the doc's own non-ML triage.
- `classes/diagnostics/concept_dependency_report.php`: an explicit stub —
  the doc itself frames concept-dependency Markov-chain mapping as offline/
  future work outside the live pipeline, so this is a placeholder rather than
  a half-built approximation.
- `index.php` + `lib.php`'s `local_stackanalytics_extend_navigation_course()`:
  the Diagnostics Dashboard page and its course navigation link, mirroring
  local_quizanalytics's nav-hook pattern exactly. Deliberately free of
  student-identifying data (§7).
- Pure-math tests for the ANOVA and branch-classification logic.

## [0.7.0] — Phase 6

- `settings.php`: three admin settings replacing hardcoded constants from
  Phases 4-5 — `questionneedsreviewthreshold` (Model 2's proxy-label pass-rate
  cutoff, a real methodological choice flagged as such in its own
  description string, not just a tuning knob), `lowtrafficfloor` (the
  bloated-tree dashboard's never-reached-vs-low-traffic boundary), and
  `helpseekinglookback` (the help-seeking-gap indicator's post-failure
  window). Each class keeps its original constant as the fallback default
  via a new `get_*()` accessor reading `get_config()`, so an unconfigured
  site behaves exactly as it did before this phase.
- `classes/privacy/provider.php`: a `null_provider`, modelled directly on the
  sibling local_quizanalytics plugin's own privacy provider (found already
  installed in the Moodle checkout used throughout this build) — this plugin
  creates no tables of its own and reads everything live from core tables
  already covered by their own privacy providers; the Analytics API's own
  prediction storage is handled generically by core_analytics's privacy
  provider regardless of which plugin registered the model.

## [0.8.0] — Phase 7

- Closed several of the "deferred to Phase 7" test gaps for real, having
  confirmed the exact mechanism: `core_question_generator::create_question('stack',
  'test0', ...)` (question/tests/generator/lib.php) saves a genuine DB-backed
  qtype_stack question from one of `qtype_stack_test_helper::get_test_questions()`'s
  named fixtures (question/type/stack/tests/helper.php) — not an in-memory-only
  object. Added positive-path tests to `student_at_risk_test.php`,
  `stack_question_analyser_test.php`, and `question_needs_review_test.php`
  using this.
- What's still genuinely deferred: `calculate_sample()`-level tests that need
  real *attempt* data (responses, seeds, PRT traversal), not just a question
  existing. These need a full quiz-attempt walkthrough fixture — qtype_stack's
  own `tests/walkthrough_interactive_test.php` is the real, verified mechanism
  to build that from — left for a future pass rather than shipping an
  unverified attempt-simulation test with no live DB available in this
  session to actually run it against.
- `.github/workflows/ci.yml`: a GitHub Actions workflow running
  moodle-plugin-ci (phplint, phpmd, phpcs, phpdoc, validate, savepoints,
  phpunit) against a real Moodle 4.5 + qtype_stack + PostgreSQL environment.
  qtype_stack is checked out separately and wired in via `EXTRA_PLUGINS_DIR`
  (confirmed against moodle-plugin-ci's own `InstallCommand.php` source,
  not guessed) — its real repository (`github.com/maths/moodle-qtype_stack`)
  was confirmed from a CDN URL inside qtype_stack's own `mkdocs.yml`, not
  assumed. Matrix deliberately kept to one PHP/Moodle/DB combination for now;
  `phpcs`/`phpdoc` are `continue-on-error` since this codebase has never been
  run through Code Checker/PHPDoc Checker and may have real, fixable style
  findings on the first run. No Behat step yet — no `.feature` files exist.

## [0.8.0] — Phase 8

- `README.md`: corrected the Model 2 description (no custom analysable, per
  Phase 3's design decision), added a CI badge, and a "Known gaps" section
  naming the two documented indicator simplifications and the deferred
  attempt-data test coverage explicitly, rather than letting them only live
  in scattered docblocks.
- `INSTALL.md`: full placement/upgrade/enable-the-models/configure-thresholds
  walkthrough plus a troubleshooting table, mirroring local_quizanalytics's
  own `INSTALL.md` depth.
- `version.php`: release bumped to `0.8.0` to match this phase's CHANGELOG
  entry.

This closes the phased build. Custom-regression-backend and offline
concept-dependency-mapping work (architecture doc §8's Phase 6/stretch items)
remain explicit future work, not attempted here.
