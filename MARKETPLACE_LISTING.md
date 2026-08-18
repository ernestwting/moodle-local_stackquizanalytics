# Moodle Marketplace / Plugins directory listing text

Copy-paste source for the plugin registration form. Not part of the
plugin itself — delete or ignore this file when packaging a release ZIP.

## Plugin name

STACK Quiz & Model Analytics

## Component / Frankenstyle name

`local_stackquizanalytics`

## Short description (1–2 sentences)

Question-level, course-wide, and Analytics-API-backed analytics for STACK
(Maxima CAS) quizzes — statistics, solution-process visualizations, and
risk/review prediction models, computed directly from Moodle's own
database with no separate service to install.

## Long description

STACK Quiz & Model Analytics brings two kinds of insight to STACK (Maxima
CAS) quizzes under one installable plugin, without any CSV export/upload
step or separate server to configure — everything reads straight out of
Moodle's own database and runs in-process, in plain PHP.

**Quiz Analytics** — course-wide view: compare every STACK quiz in a
course side by side (grade distributions, engagement over time, an
attempts-vs-grade scatter plot, trend lines). Per quiz, **Question
Analytics**: difficulty and discrimination indices, response outcome
distribution, a student performance matrix, consolidated question metrics,
and a per-question error drill-down showing exactly what each student
submitted next to the correct answer. Per quiz, **Solution Process
Visualization**: class-wide answer transition graphs showing how students
moved through a question's Potential Response Tree, per-node network
centrality, 3D charts plotting each student's distance from the correct
answer across attempts, and a cross-attempt comparison highlighting who
improved, stayed flat, or regressed. PDF export on every view, with
section checkboxes, a colorblind mode, and an anonymize-student-data
toggle.

**Model & Diagnostics Analytics** — built on Moodle's own Analytics API.
**Model 1 (Student risk)**: a target predicting whether a student is at
risk of not achieving course success, fed by five behavioural indicators
(grade trajectory, response-latency anomaly, disengagement entropy,
help-seeking gap, feedback-revision distance). **Model 2 (Question/PRT
review)**: a target predicting whether a STACK question's Potential
Response Tree needs instructor review, fed by four indicators (IRT-inspired
difficulty, syntax-error rate, unreached-node ratio, feedback-
ineffectiveness). **Diagnostics Dashboard**: seed-bias (one-way ANOVA) and
PRT branch-coverage reports, kept outside the ML pipeline since they have
no natural ground-truth label. Both models ship **disabled** by default —
what the dashboard shows is each model's live indicator reading, not a
trained prediction, until an administrator reviews the thresholds and
enables/trains a model under Site Administration → Analytics → Models.
PDF export re-derives whichever sections are ticked as a landscape report.

One "Analytics" nav entry (reachable from a course's own navigation, and
from an "Analytics" link this plugin adds directly to each STACK quiz's
own settings menu) with a "Section:" switcher between the two at the top
of every page — previously two separate plugins, now one install.

No external services, subscriptions, or API keys of any kind — every
computation runs in-process in plain PHP, and nothing ever leaves the
Moodle server.

Requires `qtype_stack` (the STACK question type) to have anything to show.

## Release notes (v1.0.0)

Copy-paste source for the "Plugin versions" tab (Edit plugin page →
Versions) when uploading this release.

Initial release, merging the previously-separate `local_quizanalytics` and
`local_stackanalytics` plugins into one. Everything runs as plain PHP
inside the plugin itself — no separate service to deploy, no external
dependency beyond PHP.

- **Quiz Analytics**: course-wide cross-quiz comparison, per-quiz Question
  Analytics (difficulty/discrimination, response distribution, error
  drill-down, student performance matrix, question metrics), and per-quiz
  Solution Process Visualization (PRT transition graphs, network
  centrality, 3D distance charts, cross-attempt comparison). PDF export
  with colorblind-mode and anonymize-student-data toggles.
- **Model & Diagnostics Analytics**: two Moodle Analytics API prediction
  models (Student risk, Question/PRT review) plus a non-ML Diagnostics
  Dashboard (seed-bias ANOVA, PRT branch coverage), ships with both models
  disabled pending administrator review. PDF export of whichever sections
  are selected.
- One course-level "Analytics" nav entry and per-quiz settings-menu link,
  with a "Section:" switcher between the two halves at the top of every
  page.
- Colorblind-safe chart palettes throughout Quiz Analytics.

## Suggested category

Analytics (or Reports, if "Analytics" isn't offered as a category on the
current Marketplace form)

## Maintainer

Ernest Ting — eting@caltech.edu

## Supported Moodle versions

`$plugin->requires` in `version.php` currently states Moodle 4.0.0 as the
floor; CI (`.github/workflows/moodle-ci.yml`) tests against MOODLE_405_STABLE
specifically across PHP 8.1/8.2/8.3 and both PostgreSQL and MariaDB — verify
that branch is still on Moodle's currently-maintained list at submission
time (see moodledev.io/general/releases) and adjust `requires`/this listing
if not.

## Dependencies

- `mod_quiz` (core) — this plugin reads finished quiz attempts through
  mod_quiz's own question engine.
- `qtype_stack` — required; this plugin exists specifically to analyze
  STACK/Maxima question responses and has nothing to show without it.
  Declared in `version.php`'s `$plugin->dependencies` (`ANY_VERSION`, no
  qtype_stack API added in a specific release is depended on).

## Repository

https://github.com/ernestwting/moodle_analytics

## Issue tracker

https://github.com/ernestwting/moodle_analytics/issues

(Required field — confirm GitHub Issues is enabled for the repo:
Settings → General → Features → Issues, on github.com.)

## Documentation

https://github.com/ernestwting/moodle_analytics#readme

## License

GNU GPL v3 or later (see `LICENSE`). TCPDF is vendored (for Quiz
Analytics's PDF export only) under LGPLv3 (GPL-compatible) — see
`classes/quiz/vendor/tcpdf/LICENSE.TXT`. Declared per-library in
`thirdpartylibs.xml`.

## Privacy

Implements `\core_privacy\local\metadata\null_provider` — this plugin
stores no personal data of its own; both sections only read data already
governed by mod_quiz/the question engine/gradelib/logstore_standard_log's
own privacy providers, and Quiz Analytics's own MUC caches are
derived/disposable, not independent storage. See
`classes/privacy/provider.php`.
