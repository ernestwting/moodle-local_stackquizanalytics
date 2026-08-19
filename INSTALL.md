# Installing STACK q-type Analytics

This is a single Moodle plugin — installing `local_quizanalytics` is
the whole install. All four sections (Quiz Analytics, Question Analytics,
Model Analytics, Diagnostics Analytics) run entirely in-process; there's no
separate service to deploy and nothing here ever talks to the public
internet.

## Prerequisites

- Admin access to the Moodle site, plus shell/SFTP access to the Moodle
  codebase (not just the web UI).
- Moodle 4.0+ (`version.php`'s `requires`; raise or lower it if your target
  Moodle needs a different floor).
- `mod_quiz` (core, always present) and `qtype_stack` installed — this
  plugin is a no-op without STACK questions to analyze.

## 1. Place the files

This repository's own root **is** the plugin (`version.php`, `classes/`,
`lang/`, etc. sit directly here) — a plain GitHub "Download ZIP" of this
repo can go straight into Moodle's plugin uploader.

**Option A — Moodle's own plugin installer (easiest, no shell access
needed):** Site administration → Plugins → Install plugins → upload a zip
of this repository's contents. Moodle places it at the right path
(`local/quizanalytics/`) itself.

**Option B — shell/SFTP, for admins who prefer it:**

```bash
# From a clone/extract of this repo:
cp -r . <moodleroot>/local/quizanalytics
chown -R www-data:www-data <moodleroot>/local/quizanalytics
# (use whatever user your web server actually runs as)
```

Either way, the folder Moodle sees it at must be exactly
`<moodleroot>/local/quizanalytics` — Moodle derives the component name
`local_quizanalytics` from that path. Plotly.js, KaTeX, and TCPDF are
already vendored inside `js/vendor/` and `classes/quiz/vendor/`; no separate
download step is needed.

## 2. Run the Moodle upgrade

Log in as an admin and visit **Site administration** (or
`<yoursite>/admin/index.php` directly if the upgrade screen doesn't appear
on its own). This single step:

- Registers the `local/quizanalytics:view` capability (`db/access.php`).
- Registers the Quiz Analytics/Question Analytics cache areas
  (`db/caches.php`).
- Registers both Model Analytics prediction models —
  **Student at risk in a STACK-based course** (Model 1) and **STACK
  question/PRT needs review** (Model 2) — from `db/analytics.php`, via
  `\core_analytics\manager::update_default_models_for_component()`. Both
  are created **disabled**; see step 4.
- Registers the **Warm STACK q-type Analytics result caches** scheduled task
  (`db/tasks.php`, every 15 minutes) — proactively recomputes the Quiz
  Analytics/Question Analytics result cache for any course whose entry is
  missing or stale, so a real visitor essentially never has to wait through
  a cold compute themselves. This depends on your site's Moodle cron
  actually running on schedule (`php admin/cli/cron.php`, or whatever your
  hosting's cron job/systemd timer runs) — a site with cron disabled or
  badly delayed loses this benefit entirely, same as any other Moodle
  scheduled task.

## 3. (Optional) Adjust the Quiz Analytics computation time limit

**Site administration → Plugins → Local plugins → STACK q-type Analytics:**

- **Computation time limit** → 120 seconds by default. Only relevant for a
  course with many STACK quizzes and/or students — the Quiz Analytics
  course-wide view and PDF export are the paths whose cost scales with the
  whole course rather than a single quiz. Raise this if you see a timeout
  on a large course specifically; 0 removes PHP's execution-time limit
  entirely for this plugin's own requests. Note this only raises *PHP's*
  own execution limit — it does nothing for a timeout enforced by a reverse
  proxy/CDN in front of your site (e.g. Cloudflare's default ~100s edge
  timeout, surfaced to a visitor as a 524); the scheduled task above is
  what actually keeps a real visitor off that path on a large course.

## 4. Review and enable the Model Analytics models

**Site administration → Analytics → Models.** Both models this plugin
registers appear here, disabled by default (deliberately — see
`db/analytics.php`'s own comment for why). Before enabling either:

- Review the thresholds on the same settings page as step 3:
  **Question-needs-review pass-rate threshold** (default 0.5, Model 2's
  proxy label), **Bloated-tree "low traffic" floor** (default 2, on the
  Diagnostics Dashboard), and **Help-seeking lookback window** (default
  3600 seconds).
- Note the proxy-label circularity caveat on "STACK question/PRT needs
  review" (architecture doc §3.3) before relying on its predictions.
- A model needs training data before it predicts anything — use the
  **Evaluate**/**Get predictions** actions on the model's own page once
  enabled.
- If those actions are missing from a model's **Actions** menu, Moodle's
  **Restrict processing to CLI only** analytics setting (`onlycli`) is on —
  a banner on the Models page says so directly. With it on, predictions
  still happen automatically via the `\core\task\analytics_process_models`
  scheduled task (cron), just not on-demand from the web UI. Either wait for
  cron, force that task from the CLI
  (`php admin/cli/scheduled_task.php
  --execute='\core\task\analytics_process_models'`), or disable `onlycli`
  (Site administration → Analytics → Analytics settings) to get the
  **Evaluate**/**Get predictions**/**Log** buttons back for iterative
  testing — worth switching back on once you're done.

## 5. Test the course-level page

1. Go to a course with **at least one quiz containing a STACK question**
   (added directly to a slot, not pulled in only via "random question from
   category"), with at least one **finished** attempt.
2. Look at the course's secondary navigation bar
   (`Course | Settings | Participants | Grades | Reports | ...`) for an
   **Analytics** entry — check inside **More** too if the bar is full.
3. Click it. You should land on **Quiz Analytics**'s course-wide cross-quiz
   comparison, with a "Section:" switcher at the top linking to the other
   three sections.
4. Click **Question Analytics** in the "Section:" switcher. It defaults to
   the course's first STACK quiz, with a "View:" selector — **Question
   Analytics** is the default view; picking **Solution Process
   Visualization** reloads the page showing that instead. Use the quiz
   dropdown to switch quizzes.
5. Click **Model Analytics** in the "Section:" switcher. You should land on
   Model 1's table; the "View:" selector there switches between Model 1
   and Model 2.
6. Click **Diagnostics Analytics** in the "Section:" switcher. You should
   land on the Diagnostics Dashboard.
7. Try the PDF export button on whichever view is showing, in any section.
8. Confirm the page is correctly **hidden** on a course with no STACK
   quizzes, and that a student account gets Moodle's standard
   permission-denied error if they navigate to
   `local/quizanalytics/index.php?id=<courseid>` (or
   `questionanalytics.php`, `modelanalytics.php`,
   `diagnosticsanalytics.php`) directly.

## 6. Test the per-quiz shortcut

1. Open a STACK quiz directly (not through the course-level page) and find
   its settings/administration menu (the gear icon, or wherever your theme
   surfaces activity settings).
2. You should see an **Analytics** entry there too — it jumps straight to
   Question Analytics's drill-down for this same quiz (step 5.4 above),
   just reached in one click from the quiz itself.

If either entry point doesn't appear at all, check that the quiz actually
has finished attempts and a STACK question added directly to a slot — first
load computes everything fresh (a few seconds); reloading the same page
should be near-instant afterward (cache hit, Quiz Analytics/Question
Analytics only) until a new attempt is submitted.

## 7. Test the prediction models

Once a model is enabled (step 4) and has run at least once (Moodle's own
scheduled task, `\core\task\analytics_process_models`, or the model's own
**Get predictions now** action), check **Site administration → Analytics →
Insights** for generated predictions. If nothing appears, confirm the target
course has enough data — `is_valid_analysable()`/`is_valid_sample()` on both
targets reject courses/samples without enough to work with rather than
producing a misleading prediction.

---

## Troubleshooting quick-reference

| Symptom | Likely cause |
|---|---|
| "Analytics" doesn't appear anywhere, course nav or quiz settings menu | Plugin not installed, or no STACK quiz/finished attempts (both entry points are gated on this) |
| "No attempts yet" / "...has no finished attempts" (Quiz Analytics) | No attempts in `state = finished` for the quiz(zes) in question |
| "Analytics could not be computed for this quiz" | An unexpected error — check Moodle's debugging messages/logs (Site administration → Reports → Logs, or your server's PHP error log) for the underlying exception |
| A large course's course-wide view or PDF export times out | Raise **Computation time limit** in the plugin's settings (see step 3 above) |
| Quiz/Question Analytics 524s (or otherwise times out) on a large course (500+ attempts) | A reverse proxy/CDN in front of the site (e.g. Cloudflare), not PHP, is giving up first — **Computation time limit** won't fix this. Confirm Moodle cron is actually running so the **Warm STACK q-type Analytics result caches** scheduled task can keep the cache warm ahead of real visitors (Site administration → Server → Scheduled tasks); you can also run it once by hand (`php admin/cli/scheduled_task.php --execute='\local_quizanalytics\task\warm_analytics_cache'`) to warm a course immediately rather than waiting up to 15 minutes |
| Charts blank / JS console errors (Quiz Analytics) | Check the browser console for a 404 on `js/vendor/plotly.min.js` or `js/vendor/katex/*` — those ship inside this repo already, so a 404 usually means the plugin folder wasn't copied completely |
| Math renders as literal `\(...\)` text instead of typeset symbols | KaTeX's CSS/font files (`js/vendor/katex/fonts/`) didn't come along with the rest of `js/vendor/katex/` — re-copy the whole folder |
| Question text shows `@variable@` placeholders or both languages' `[[lang]]` blocks at once | `castext2_qa_processor`/`stack_outofcontext_process` couldn't be loaded — check `qtype_stack` is installed and up to date |
| "STACK q-type Analytics" / Model Analytics or Diagnostics Analytics section shows nothing | The course has no STACK question in any quiz slot (`stack_course_helper::course_has_stack_activity()` gates this) |
| A model shows 0 samples after training/prediction | `is_valid_analysable()` or `is_valid_sample()` rejected the course/sample — check the model's own log in Site administration → Analytics → Models → (model) → Log for the specific reason string |
| A model's **Actions** menu has no **Evaluate**/**Get predictions**/**Log** | `onlycli` analytics setting is on, restricting those to CLI/cron only — see step 4 |
| Seed-bias table says "Not enough attempt data yet" | Fewer than 2 distinct STACK seeds have recorded attempts for that quiz slot yet |
| PRT branch-coverage table is empty for a question | That question's PRT nodes have no non-blank `trueanswernote`/`falseanswernote` set — coverage can't be observed without one |
| "STACK question/PRT needs review" predictions look circular/self-fulfilling | Expected, documented limitation — see the architecture doc's §3.3 and this target's own class docblock |
| CI fails on Code Checker / PHPDoc Checker but PHPUnit passes | Those two steps are `continue-on-error` for now — see `CHANGELOG.md`'s Phase 17/20 entries |
