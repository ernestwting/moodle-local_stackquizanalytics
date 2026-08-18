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
