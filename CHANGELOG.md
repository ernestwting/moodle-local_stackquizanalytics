# Changelog

All notable changes to `local_quizanalytics` are documented here.
Version numbers match `version.php`'s `$plugin->release`.

This plugin merges two previously-separate, independently-installed
plugins — the original, standalone `local_quizanalytics` and
`local_stackanalytics` — into one, and now replaces the former's own
Marketplace listing (see [2.3.0]). Each phase below corresponds to one
commit in this repository's history. Entries before [2.2.0] refer to the
plugin by its merge-time component name, `local_stackquizanalytics`, and
[2.2.0] itself by the (as it turned out, incorrect) `local_stackanalytics`
— each accurate to what the code was actually called at that point in
time; see [2.3.0] for why and when that settled on the current
`local_quizanalytics`.

## [2.4.0] — Fixed Quiz Analytics timing out on large courses (Cloudflare 524)

Live testing against a real 500-1000-finished-attempt course reproduced a
Cloudflare 524 ("A timeout occurred") on Quiz Analytics/Question Analytics —
the origin server not answering before the reverse proxy in front of it
(Cloudflare, ~100s default edge timeout) gave up. Three compounding causes,
all fixed without any UI change:

- **The actual bottleneck**: `data_fetcher.php`'s
  `get_response_records_for_quiz()` called
  `question_engine::load_questions_usage_by_activity()` once per finished
  attempt — each call its own set of DB queries — so a 500-1000-attempt
  course meant 500-1000x the round trips inside one HTTP request.
  `local_quizanalytics/computetimelimit` (PHP's own execution time limit)
  couldn't help here: it protects against PHP timing itself out, not
  against an independent reverse-proxy timeout sitting in front of it.
  Switched to `question_engine_data_mapper::load_questions_usages_by_activity()`
  (plural) — one batched query across every attempt's usage at once,
  the exact fix Moodle core's own mod_quiz "Responses" report uses for this
  identical problem at scale (`quiz_first_or_all_responses_table::
  load_extra_data()`, confirmed against a real Moodle 4.5 checkout).
  Verified byte-identical output against the old per-attempt code on real
  attempt data before and after the change.
- **The cache could never actually warm**: `index.php`/
  `questionanalytics.php` already cached results keyed by an attempt
  fingerprint (`cache_helper.php`), but a cold-cache request that gets
  524'd mid-compute has its PHP process killed by the disconnect (unless
  told otherwise) before ever reaching the `cache->set()` call that would
  have made the *next* visitor's request fast — so every visitor kept
  re-triggering the same expensive cold path indefinitely. Wrapped each
  cache-miss compute in `ignore_user_abort(true)`/restore, so the work
  still finishes and the cache still warms even after the browser/proxy
  has given up on that particular request.
- **No user should ever be the one paying for a cold cache in the first
  place**: added a scheduled task
  (`local_quizanalytics\task\warm_analytics_cache`, every 15 minutes) that
  proactively recomputes any stale/missing cache entry for every course
  with STACK activity, site-wide — the same compute, just run by cron
  instead of inside a visitor's own request. Scoped to the default view
  (colorblind/anonymize off) only; a visitor with a personal preference
  toggled on still computes cold on their own first visit, an accepted
  gap. Solution Process Visualization isn't warmed by this task — there's
  no single default question/part/student selection to precompute for it.

## [2.3.1] — Fixed named STACK inputs being misread as blank

Live testing against a course whose STACK questions rename their inputs
away from the default `ans1`/`ans2`/... convention (e.g. a "separation of
variables" ODE question using `ans_mcq`, `ans_fx`, `ans_1fx`, etc.) showed
every one of those questions' responses misclassified as blank in the Quiz
Analytics error drill-down, despite a real score and a real submitted
response — the response text just didn't contain any Correct Answer, and
was contributing entirely fabricated "PRT" rows to the per-PRT pass-rate
breakdown.

Root cause: the `ansK: <expr> [tag]` field regex used in four places
(`classes/quiz/analytics/parser.php`, `latex_utils.php`,
`solution_distance.php`, and the PRT-field *exclusion* regex in
`prt_analysis.php`) all hardcoded a numeric-only suffix (`ans\d+`). A
question author can rename a STACK input to anything (`ans_mcq`,
`ans_1fx`, ...) — same as this plugin already accounted for on the PRT
side (PRT names are matched by shape, not a literal `prt` prefix, per
`prt_analysis.php`'s own docblock). A named input:

- Never matched the ans-field regex, so `parser.php` treated its response
  as having zero ans fields — the same test `parse_response_cell()` uses
  for a genuinely blank response — regardless of the real (non-empty,
  correctly-scored) PRT data sitting right next to it in the same cell.
- Also never matched `prt_analysis.php`'s `ANS_FIELD_RE` (used to exclude
  ans fields from its by-elimination PRT detection), so it fell through
  and got counted as a fake PRT named e.g. `ans_mcq`, always scored 0.0/
  incorrect — polluting the per-PRT pass-rate table and PRT branch-
  coverage diagnostics with entries that were never real PRTs.

Broadened all four regexes from `ans(\d+)` to `ans(\w+)`. `parser.php`'s
`ans_list` entries now carry `'index' => null` for a non-numeric input
name (rather than an incorrect `(int)` cast colliding everything on 0) —
the one consumer that keys off a numeric index
(`solution_distance.php`'s Solution Process Visualization tree-edit-
distance lookup) already treats a missing index as "no match," so a named
question degrades to no TED score there rather than a wrong one. Added
`tests/parser_named_ans_test.php` covering both the ans-field parse and
the PRT-exclusion fix against a real captured response.

## [2.3.0] — Renamed component to `local_quizanalytics`

[2.2.0]'s rename picked the wrong target. Confirming against the actual
Marketplace submission page (plugin id 3995,
marketplace.moodle.com/plugins/3995) showed it's registered as
`local_quizanalytics` — not `local_stackanalytics` — with a display title
("STACK Analytics") that happened to read like the latter. Renamed the
frankenstyle component again, this time to `local_quizanalytics`, through
the same set of files [2.2.0] lists (`version.php`, every `classes/`
namespace, the capability id, legacy class/function prefixes,
`lang/en/*.php`, cache/Analytics API identifiers, user-preference keys,
every hardcoded URL, the privacy provider, and docs).

The rename this time touches far more prose than [2.2.0] did, because
`local_quizanalytics` collides with the *other* source plugin's own name
(the original, standalone `local_quizanalytics` that this merge's
`classes/quiz/` subsystem was ported from) rather than just this plugin's
own prior name — several docblocks and comments explicitly named *both*
source plugins side by side (`version.php`, `db/access.php`, `lib.php`,
`settings.php`) and needed hand-fixing after the mechanical rename to stop
reading as the same name twice. `CHANGELOG.md`'s own historical narrative
(Phases 1–21, and the [2.0.0]/[2.1.0]/[2.2.0] entries) is deliberately
left describing the plugin by whatever name was actually true at each
point in time, same convention [2.2.0] established — see the note at the
top of this file.

## [2.2.0] — Renamed component to `local_stackanalytics`

This plugin's Marketplace listing replaces the original, standalone
`local_stackanalytics`'s own rather than sitting alongside it (see
[1.0.0]/"Pre-merge history" below) — but the Moodle Plugins directory
upload was rejected with "the frankenstyle component name in the uploaded
plugin does not match," since the component had stayed
`local_stackquizanalytics` (this merge's own working name) through v2.1.0.
Renamed the frankenstyle component to `local_stackanalytics` throughout so
it uploads as a new version of that existing listing:

- `version.php`'s `$plugin->component`.
- Every `classes/` namespace (`local_stackquizanalytics\...` →
  `local_stackanalytics\...`).
- The capability id (`db/access.php`,
  `local/stackquizanalytics:view` → `local/stackanalytics:view`) and every
  `require_capability()`/`has_capability()` call site.
- Every legacy global-namespace class prefix
  (`local_stackquizanalytics_quiz_*`, `local_stackquizanalytics_section_selector`
  → `local_stackanalytics_quiz_*`, `local_stackanalytics_section_selector`)
  and the two `lib.php` navigation-hook function names.
- `lang/en/local_stackquizanalytics.php` → `lang/en/local_stackanalytics.php`.
- Cache area and Analytics API component identifiers (`db/caches.php`,
  `db/analytics.php`), and the shared user-preference keys
  (`local_stackquizanalytics_anonymize`/`_colorblind` →
  `local_stackanalytics_anonymize`/`_colorblind`).
- Every hardcoded `/local/stackquizanalytics/...` asset/page URL across
  every entry point and `js/vendor-shared/sections-renderer.js`.
- `classes/privacy/provider.php`'s component references.
- Docs (`README.md`, `INSTALL.md`, `MARKETPLACE_LISTING.md`) updated to
  the new component/path throughout.

The plugin's display name (`pluginname`, "STACK Quiz & Model Analytics")
is unchanged — that's a marketing name independent of the frankenstyle
id, not part of what the Marketplace validator checks. Also unresolved:
the GitHub repository itself is still named
`moodle-local_stackquizanalytics`, not yet renamed to match
(`MARKETPLACE_LISTING.md`'s Repository/Issue tracker/Documentation URLs
still point at the current, real repo name pending that — see the
`<!-- TODO -->` comment there).

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

This plugin's Marketplace listing replaces the original, standalone
`local_quizanalytics`'s own (plugin id 3995) — the version history
continues directly from that plugin's v1.0.1 (see "Pre-merge history:
`local_quizanalytics`" at the bottom of this file for its own v1.0.0/
v1.0.1, which predate and form the basis of Phases 2–6 below).

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

## Pre-merge history: `local_quizanalytics`

The entries below are carried over unchanged from the original, standalone
`local_quizanalytics`'s own `CHANGELOG.md` — that plugin's v1.0.0 and
v1.0.1, predating this merge and forming the basis of Phases 2–6 above.
Kept here in full since this listing (id 3995 on the Marketplace) replaces
that plugin's own rather than sitting alongside it.

## [1.0.1] — Plugins directory review fixes

- Fixed hard-coded language strings: PDF section checkboxes, PDF
  titles/captions, and PDF body text now go through `get_string()`
  instead of literal English, so they translate with the site
  language. The PDF section checkboxes previously posted their own
  label text as the form value; they now post a stable internal id,
  independent of the display language.
- Added the four `cachedef_*` language strings the plugin's MUC cache
  areas (`db/caches.php`) were missing, so they display properly on
  the site admin's cache configuration screen.

## [1.0.0] — Initial release

First public release. Everything runs as plain PHP inside the plugin
itself — no separate service, no external dependency beyond PHP.

**Features**
- Course-wide cross-quiz comparison (grade distribution, engagement over
  time, attempts-vs-grade scatter, per-quiz stats and trend lines) across
  every STACK quiz in a course.
- Per-quiz **Question Analytics**: difficulty and discrimination indices,
  response distribution, a per-question error drill-down, a student
  performance matrix, and consolidated question metrics.
- Per-quiz **Solution Process Visualization**: class-wide PRT answer
  transition graphs, per-node network centrality, PRT/tree-edit-distance
  3D distance charts, and a cross-attempt comparison with a clickable
  per-student drill-down.
- **Generate PDF Report** on every view, with section checkboxes, a
  colorblind-mode toggle, and an anonymize-student-data toggle (replaces
  real names/emails with stable per-student pseudonyms, consistent
  across every table, chart, and PDF) — charts are captured client-side
  from the already-rendered page and embedded into a PHP-generated PDF
  (TCPDF).
- Reachable from a course's secondary navigation, and from an **Analytics**
  link this plugin adds to each STACK quiz's own settings menu.
- Colorblind-safe chart palettes throughout, toggled next to the
  anonymize-student-data checkbox.
