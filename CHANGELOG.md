# Changelog

All notable changes to `local_stackquizanalytics` are documented here.
Version numbers match `version.php`'s `$plugin->release`.

This plugin merges two previously-separate, independently-installed
plugins — `local_quizanalytics` and `local_stackanalytics` — into one. Each
phase below corresponds to one commit in this repository's history.

## [1.0.0] — Merge phases 1–18

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
own optimised-Maxima-image build during PHPUnit init). Code Checker and
PHPDoc Checker stay `continue-on-error` pending Phase 20's cleanup.

**Phase 18 — Docs.** `README.md`, `INSTALL.md`, `MARKETPLACE_LISTING.md`,
and this changelog rewritten to describe the merged plugin; the Model &
Diagnostics Analytics architecture doc carried over to `docs/`.
