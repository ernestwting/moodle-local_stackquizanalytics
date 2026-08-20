# Session notes — 2026-08-19 → 2026-08-20

A working log of everything done and discovered across this multi-day
session, across plugin naming, a parsing bug, the Cloudflare 524 fix,
local dev environment setup, the large-course performance investigation,
and two further follow-up rounds (cron reliability/host-adaptive sizing/
50-quiz stress testing, then a scoping audit that surfaced and fixed a
severe Model Analytics performance bug plus two real CI failures). Written
after the fact as a reference — see the actual commits (`git log`) for the
authoritative, tested detail on each change; this is the narrative/context
around them, including things that were tried, discovered, or ruled out
that don't show up in a diff.

## Contents

1. [Display name renamed twice](#1-display-name-renamed-twice)
2. [Testing the Analytics API models (Model 1/2)](#2-testing-the-analytics-api-models-model-12)
3. [Named STACK input parsing bug (`ans_mcq`, `ans_1fx`, ...)](#3-named-stack-input-parsing-bug-ans_mcq-ans_1fx-)
4. [Cloudflare 524 fix for large courses (first pass)](#4-cloudflare-524-fix-for-large-courses-first-pass)
5. [Git history rewrite for sole authorship](#5-git-history-rewrite-for-sole-authorship)
6. [Local dev environment: live-mounting, php.ini, Docker rebuild](#6-local-dev-environment-live-mounting-phpini-docker-rebuild)
7. [Restoring real course data: the async-restore one-shot trap](#7-restoring-real-course-data-the-async-restore-one-shot-trap)
8. [The large-course performance investigation](#8-the-large-course-performance-investigation)
9. [Final state (first round)](#9-final-state-first-round)
10. [Follow-up round 1: cron reliability, host-adaptive sizing, 50-quiz stress test](#10-follow-up-round-1-cron-reliability-host-adaptive-sizing-50-quiz-stress-test)
11. [Follow-up round 2: scoping audit, Model Analytics performance bug, CI fixes](#11-follow-up-round-2-scoping-audit-model-analytics-performance-bug-ci-fixes)

---

## 1. Display name renamed twice

- `pluginname` (drives the Site administration nav entry, page headings,
  PDF titles) was originally **"STACK Quiz & Model Analytics"**.
- First renamed to **"STACK Analytics"** (matching the plugin's actual
  Moodle Marketplace listing title, confirmed against
  `marketplace.moodle.com/plugins/3995` — a mismatch discovered mid-session
  that also explained why an earlier component rename to
  `local_stackanalytics` had been rejected by the Marketplace uploader).
- Later renamed again to **"STACK q-type Analytics"** per explicit
  instruction. Both passes updated every live echo of the name — lang
  strings, PDF report headings/authors, the privacy metadata string, the
  scheduled task's own name, and the current copy in `README.md`/
  `INSTALL.md`/`MARKETPLACE_LISTING.md` — while deliberately leaving
  historical CHANGELOG/marketplace-notes narrative describing what the
  name *was* at an earlier point in time untouched, matching this
  project's own established convention for prior renames.
- Along the way, found and fixed a couple of spots still stuck on the
  *original* pre-any-rename name that had never been updated in either
  earlier pass (`README.md`'s title, `version.php`'s docblock, the privacy
  string).

## 2. Testing the Analytics API models (Model 1/2)

Explained (no code changes) how to actually exercise this plugin's two
Analytics API models:

- Both ship **disabled by default** (`db/analytics.php`) — a newly
  installed site shouldn't start generating predictions/notifications
  before an admin has reviewed them.
- Model 1 (`student_at_risk`) samples **course enrolments**, extends core's
  `course_gradetopass`, needs a *finished* course with a grade-to-pass set
  and STACK activity.
- Model 2 (`question_needs_review`) samples **quiz_slots** (one STACK
  question-in-a-quiz), doesn't need the course to be finished.
- **`Evaluate`** runs cross-validation against the site's own real
  historical data — no upload needed. The `onlycli` analytics setting
  (`Site administration → Analytics → Analytics settings`) restricts
  `Evaluate`/`Get predictions`/`Log` to CLI/cron only; when it's on, those
  actions are simply missing from a model's Actions menu, with a banner
  explaining why.
- **Correction made during this session**: originally suggested
  `php admin/tool/task/cli/schedule_task.php` for forcing a task run from
  CLI — that path doesn't exist in real Moodle core. The correct path,
  confirmed by grepping a real Moodle 4.5 checkout, is
  `php admin/cli/scheduled_task.php --execute=...` (and
  `admin/cli/adhoc_task.php` for adhoc tasks). `INSTALL.md` was corrected
  to match.
- Real errors seen while testing on live data, explained:
  - Model 1 "There is not enough data to evaluate this model using the
    provided analysis interval" — traced to `mlbackend_php`'s own
    `processor.php`: fires when a target class (0 or 1) has fewer than 2
    samples, i.e. a class-balance problem, not literally "not enough data."
  - Model 2 "Analysable ... is not valid for this target: The course is
    too long" — traced to core's `course_enrolments` target base class
    (`course/classes/analytics/target/course_enrolments.php`), which
    rejects a course whose end date is more than a year + 4 weeks after
    its start — this check only applies to Model 1's ancestry
    (`course_gradetopass` extends `course_enrolments`), not Model 2's
    target class at all, so the two error reports were about two different
    models despite how the user described them.

## 3. Named STACK input parsing bug (`ans_mcq`, `ans_1fx`, ...)

**Symptom** (from a live screenshot): a question's error drill-down showed
every response as **blank** despite a real, correctly-computed score
(0.6), and the question's raw response text clearly wasn't empty.

**Root cause**: `classes/quiz/analytics/parser.php`,
`prt_analysis.php`, `latex_utils.php`, and `solution_distance.php` all
matched STACK's `ansK: <expr> [tag]` response fields with a regex hardcoded
to a **numeric-only** suffix (`ans\d+`). STACK questions can rename their
inputs to anything (`ans_mcq`, `ans_1fx`, ...) — this plugin already
accounted for the equivalent case on the PRT-name side, just not for
inputs. A named input:

- Never matched the `parser.php` regex, so the response was treated as
  having zero ans fields — indistinguishable from genuinely blank.
- Also never matched `prt_analysis.php`'s `ANS_FIELD_RE` (used to exclude
  ans fields from its by-elimination PRT detection), so it fell through
  and got counted as a **fake PRT**, always scored 0.0/incorrect —
  polluting the per-PRT pass-rate table too.

**Fix**: broadened `ans\d+` → `ans\w+` in all four locations.
`parser.php`'s `ans_list` entries now carry `'index' => null` for a
non-numeric input name rather than an incorrect `(int)` cast colliding
everything on 0 (the one consumer keying off a numeric index —
`solution_distance.php`'s TED lookup — already treats a missing index as
"no match"). Verified against the exact response text from the reporting
screenshot: correctly parses all 7 named ans fields, identifies the 5 real
PRTs, and reproduces the observed 0.6 score with status `incorrect`
instead of `blank`. Added `tests/parser_named_ans_test.php`.

## 4. Cloudflare 524 fix for large courses (first pass)

**Symptom**: Quiz/Question Analytics timing out (Cloudflare 524) on a
course with several hundred finished attempts.

**Root cause found by profiling, not assumed**: `data_fetcher.php`'s
`get_response_records_for_quiz()` called
`question_engine::load_questions_usage_by_activity()` (singular) once per
finished attempt — each call its own set of DB queries. At 500-1000
attempts that's 500-1000x the round trips. `local_quizanalytics/
computetimelimit` (PHP's own execution-time limit) couldn't have fixed
this even at any value, since it only raises PHP's own limit, not an
independent reverse-proxy timeout sitting in front of it.

**Fix**: switched to
`question_engine_data_mapper::load_questions_usages_by_activity()`
(plural) — one batched query across every attempt's usage at once, the
same fix Moodle core's own mod_quiz "Responses" report uses for this exact
problem (`quiz_first_or_all_responses_table::load_extra_data()`, confirmed
against a real Moodle 4.5 checkout). Verified byte-identical output
against the old per-attempt code on real data before shipping it.

Two complementary fixes shipped alongside:

- **`ignore_user_abort(true)`** around each cache-miss compute in
  `index.php`/`questionanalytics.php`: without it, a request that gets
  524'd mid-compute has its PHP process killed by the client disconnect
  before ever reaching the line that populates the cache — meaning every
  subsequent visitor re-triggers the same expensive path forever, even
  after the fix above. With it, the work finishes server-side regardless
  of whether the original visitor is still there to see it.
- **`classes/task/warm_analytics_cache.php`** (first version): a new
  scheduled task that proactively warms the course-wide and per-quiz
  caches for every STACK course, so a real visitor essentially never has
  to eat a cold compute at all. Scoped to the default view only
  (colorblind/anonymize off) — a visitor with a personal preference
  toggled still computes cold on their own next visit, an accepted gap.

This was version [2.3.2] (originally numbered 2.4.0, renumbered down since
it's a patch on the 2.3.x line, not new user-facing functionality).

## 5. Git history rewrite for sole authorship

Three already-pushed commits from this session had carried a
`Co-Authored-By: Claude` trailer. Per explicit instruction ("ALWAYS make
me the sole author"), rewrote them:

- Used `git commit-tree` (not `filter-branch`/`rebase -i`) to reconstruct
  each commit with its exact original tree/author/dates, just with the
  trailer stripped from the message — verified via `git diff <old> <new>`
  showing **zero** file-content difference for each pair before updating
  the branch pointer.
- Force-pushed only after explicit confirmation (per the git safety
  protocol: never force-push without asking first).
- Saved a standing memory (`feedback_sole_git_author.md`) so this applies
  automatically to every future commit in this repo without being asked
  again.

## 6. Local dev environment: live-mounting, php.ini, Docker rebuild

The local Docker Moodle stack (`~/Desktop/moodle`, separate from this
plugin's own git repo) needed several changes to support real iterative
development and, later, the parallel-fetch work:

- **Live-mounted the plugin repo directly** over `local/quizanalytics` in
  `docker-compose.yml`, replacing what had been a disconnected, manually
  `docker cp`'d copy. Edits to the real repo now show up in the running
  site on refresh with zero manual sync step.
- **`php.ini` limits raised** (`Dockerfile.moodle`'s baked-in
  `moodle.ini`): `upload_max_filesize=200M`, `post_max_size=220M`,
  `max_execution_time=300`, `max_input_time=300`, `memory_limit=512M` —
  needed for uploading real, large (~100MB) course backup files through
  the browser.
- **Added `pcntl`** to the PHP build (`docker-php-ext-install ... pcntl`)
  — required for the forked-worker-pool parallel fetch (§8); not present
  in the base `php:8.2-apache` image at all.
- **Fixed JIT/opcache being effectively inert**: `opcache.jit_buffer_size`
  was `0` despite `opcache.jit=tracing` looking "on" — JIT needs a
  non-zero buffer to actually compile anything. Also `opcache.enable_cli`
  was `Off`, meaning CLI-run scripts (including the scheduled task this
  whole session ended up optimizing) got zero opcode caching, paying full
  recompilation cost on every invocation. Both fixed.
- Reset the local admin password via `admin/cli/reset_password.php`
  (didn't know it) to get a real authenticated browser session for
  testing, rather than fighting curl-based login/session cookies.

## 7. Restoring real course data: the async-restore one-shot trap

Getting real, large (~50k-attempt) course data onto the local instance
from `.mbz` backups surfaced a genuinely confusing failure mode worth
recording:

- The local container had **no cron running at all** (no crontab, no cron
  daemon) — queued `asynchronous_restore_task` adhoc tasks just sat there
  indefinitely with no error, looking "stuck" when they were actually just
  never being processed. Fixed by manually running
  `admin/cli/adhoc_task.php --execute`.
- **Killed several restores prematurely** early on, not yet knowing each
  one genuinely needs ~20 minutes uninterrupted for a course this size —
  learned this the hard way, several times, before adopting a strict
  "always wait in the background with no artificial timeout, never
  interrupt" discipline for anything long-running for the rest of the
  session.
- **The costly discovery**: `\core\task\asynchronous_restore_task::
  retry_until_success()` returns `false` — confirmed directly in Moodle
  core source
  (`lib/classes/task/asynchronous_restore_task.php`). This means the task
  gets **exactly one attempt, ever** (`attemptsavailable` forced to `1` in
  `manager.php`); any interruption — including everything above —
  permanently and silently kills that specific queued restore. There is no
  way to resume it; only a fresh restore (new upload, new backupid) works.
  One restore that *was* left to run uninterrupted (task 15, 1235
  seconds/~20.5 min) succeeded cleanly, confirming the mechanism itself
  was never broken — only repeated interruption was.
- Net result: of an original 5 queued restore attempts (across 3 uploaded
  backup files), most were permanently lost to this before the pattern was
  understood; the affected courses were successfully re-restored from
  scratch afterward. Final real courses used for the rest of the session:
  course 9 (MT171 2023/2024, the one profiled most heavily — **38 STACK
  quizzes, 48,445 finished attempts combined**), plus 10/11/12 (smaller).

## 8. The large-course performance investigation

By far the largest single piece of work this session. Full detail and
exact code is in the commits (`c0ca160` through `5ed80ca`); this is the
narrative of how the investigation actually unfolded, including dead ends.

### 8.1 First symptom, and the wrong initial theory

Reported symptom: Quiz/Question Analytics on the real 38-quiz course took
close to a minute to "load," then rendered **nothing** — no charts, no
error message, just the page shell. Model Analytics silently bounced back
to Quiz Analytics; Diagnostics Analytics landed on Course reuse.

Initial theory (wrong, but reasonable given the code at the time):
`get_response_summary()`/`get_right_answer_summary()` — assumed these
re-ran live CAS/PRT grading on every call, based on reading
`qtype_stack_question::summarise_response()` → `get_prt_result()`, which
genuinely does call into CAS. This was **disproven** by tracing one level
deeper: `question_attempt::get_response_summary()` is just
`return $this->responsesummary;` — a plain property read, populated once
from a stored DB column (`question_attempts.responsesummary`) when the
attempt is hydrated, not recomputed on access. The real cost was
elsewhere (§8.3).

### 8.2 Profiling method

Rather than keep guessing, added direct `microtime()`/
`memory_get_usage()` instrumentation around each stage, run against the
real course via CLI (the local Docker container has a live DB with the
real 48,445-attempt data — see §7). Key numbers, all measured directly,
not estimated:

- **Fetch stage** (`get_response_records_for_quiz()`) for one 2,764-attempt
  quiz: **46.05s**.
- **Entire analysis pipeline** (`question_analysis::build_analysis()` —
  parsing, PRT frame, difficulty, response outcomes, ranking, everything)
  for the *same* quiz: **~2-4s total**.

This one comparison redirected the whole investigation: the fetch stage
was the story, by 10-20x, and `tree_edit_distance.php`/PRT-comparison
code (which the user had specifically flagged as suspect) turned out to
be unreachable from this code path at all — confirmed by grep — only ever
called from Solution Process Visualization.

### 8.3 Finding the real cost inside the fetch stage

Instrumented each of the four per-slot calls in
`get_response_records_for_quiz()` separately:

| Call | Time (300 attempts) | Per-call |
|---|---|---|
| `$quba->get_question($slot)` | 1.269s | **4.23ms** |
| `get_response_summary()` | 0.002s | 0.0067ms |
| `get_right_answer_summary()` | 0.002s | 0.0058ms |
| marks (x2) | 0.004s | 0.0127ms |

`get_question($slot)` — instantiating the STACK question object for that
attempt's specific random seed, real CAS work — was **~99.5%** of the
whole per-attempt-slot cost. Re-reading the plugin's own code at this
point found the actual fix: `$question` (the return value of
`get_question()`) is used for exactly one thing in the whole method —
`render_stack_question_text($question)` — which was *already* memoized
per slot from an earlier pass this same session. The expensive call was
being made unconditionally, every attempt, even when its only consumer
was about to be thrown away. Moving the `get_question()` call inside the
`if (empty($questiontextbyslot[$qnum]))` guard removed the redundant
instantiation entirely. Verified byte-identical output before/after;
measured a 1,455-attempt quiz go from 8.04s → 4.23s from this alone.

### 8.4 The garbage-collector cliff

Separately, running the *course-wide* fetch (looping over all 38 quizzes
in one PHP process) showed something stranger: quiz timings that had
nothing to do with quiz size. One 705-attempt quiz took **461.85 seconds**
— dramatically slower than a 924-attempt quiz processed earlier in the
same run (22.27s). Isolating that same 705-attempt quiz in its own,
fresh process: **0.02s** (essentially instant, confirming it wasn't the
quiz's own data at fault).

Root cause: PHP's automatic cyclic garbage collector — needed because
`question_usage_by_activity`/`question_attempt`/`question_attempt_step`
objects hold internal reference cycles refcounting alone can't free — was
falling further behind the more quizzes got processed sequentially in one
run, and each of its automatic sweeps got more expensive to walk as more
cyclic garbage piled up uncollected. Empirically confirmed by testing two
variants on the same quiz sequence:

- `gc_disable()`: eliminated the slowdown, but memory then grew
  unboundedly (1850MB after only 7 of 11 quizzes) until the script
  crashed — trading the CPU problem for a worse memory problem.
- Explicit `gc_collect_cycles()` after each quiz: the previously-461.85s
  quiz dropped to **16.66s** (~28x), a previously-229.3s quiz dropped to
  **9.82s** (~23x), and memory stayed bounded (~844MB peak across the
  whole 11-quiz run) rather than climbing.

Shipped the explicit `gc_collect_cycles()` version.

### 8.5 Deciding on parallelization (option B)

At this point the fetch stage was much faster per-quiz, but a
*course-wide* view combining all 38 quizzes was still bound by every
attempt's CAS grading happening strictly sequentially in one process —
despite the local Docker stack's Maxima backend (`goemaxima`,
`GOEMAXIMA_QUEUE_LEN: 32`) sitting almost entirely idle the whole time,
since nothing had ever asked it to do more than one thing at once. Given
explicit direction to focus on parallelizing the CAS-bound work (rather
than, say, a fully async/background-task UI rewrite, or trying to
memoize the CAS grading itself, which turned out to already be a
non-issue per §8.1's correction), built `classes/task/
parallel_course_fetcher.php`: forks up to N worker processes (new
`parallelworkers` setting), each running the existing, completely
unmodified `get_response_records_for_quiz()` for its assigned quizzes.

Deliberately **not** applied to the synchronous on-demand pages
(`index.php`/`questionanalytics.php`) — `pcntl_fork()` only works in
CLI/cron context, so this only ever runs inside the `warm_analytics_cache`
scheduled task. The on-demand path still fetches serially on a cold
cache, same as before, an accepted/documented gap.

### 8.6 Getting the fork mechanism actually correct at scale

The first working version passed correctness testing at small scale
(4 quizzes, byte-identical serial-vs-parallel output) but failed
repeatedly once run against the real, full 38-quiz course — three
separate, genuinely different failure modes, each found only by running
the real task end to end, not by any smaller benchmark:

1. **"MySQL server has gone away"** on the *parent's* connection, well
   inside MariaDB's 8-hour `wait_timeout` (checked directly — not a
   timeout at all). Diagnosed by tracing `moodle_database::__destruct()`
   → `dispose()`: a forked child's *inherited* connection object — even
   after the child creates and switches to its own fresh one — still gets
   destructed at some point in the child's own lifetime, and that
   destructor closes the connection on the socket the parent duplicated
   via `fork()` and is still actively using. Fixed by having the **parent**
   dispose its own connection *before* forking (so nobody has it open at
   the instant `fork()` actually runs), then reconnecting fresh in every
   child and the parent afterward.
2. **Memory exhaustion**: a worker drawing several of the course's largest
   quizzes (some 2,000-2,700+ attempts each) held all of their combined,
   *finished* records in memory simultaneously before writing anything
   out. Fixed by rewriting the write path to stream each quiz's records to
   its temp file (a simple length-prefixed binary format — plain
   newline-delimited text wasn't safe, since response text can contain
   arbitrary bytes including newlines) and free them immediately after,
   bounding peak memory to roughly one quiz's worth rather than a whole
   worker's chunk.
3. **Still-unbounded memory growth**, even after (2) — traced via direct
   `memory_get_usage()` instrumentation per quiz within a worker to
   Moodle's own `core_questiondata` MUC cache: it accumulates every
   distinct question a process ever loads for the rest of that process's
   life. A reasonable assumption for a short web request; not for a CLI
   worker that can touch hundreds of distinct STACK questions across many
   quizzes. Fixed by calling `cache_helper::purge_by_definition('core',
   'questiondata')` after each quiz — confirmed directly that memory then
   plateaus instead of climbing for the rest of the run.
4. Along the way, an initial "just make it unlimited" fix
   (`ini_set('memory_limit', -1)`) was tried and then explicitly reverted:
   confirmed directly that with no PHP-level ceiling, multiple concurrent
   workers exceeded the *host's* real available memory before PHP would
   ever object, at which point the Linux kernel's OOM killer started
   choosing what to sacrifice — observed taking out this task's own worker
   processes with no catchable error (`SIGKILL`, no PHP fatal message at
   all), and on a busier/shared host it could just as easily have picked
   MariaDB or Maxima instead. Replaced with a bounded, admin-configurable
   `parallelworkermemory` setting (default 2048M) — a real PHP "Allowed
   memory size exhausted" fatal is self-contained and reported; an
   OOM-killed process is neither.

### 8.7 Final verified result

Running the actual `warm_analytics_cache` scheduled task, unmodified, via
CLI, against the real course:

- Fully cold cache warm of all 38 quizzes plus the course-wide view:
  **526.8 seconds** (workers=2, worker memory=3072M — the configuration
  that worked reliably on this specific, memory-constrained local Docker
  host; see the note in §9 about sizing for a real production host).
- Compare: the *unfixed* serial fetch, given 28 minutes, had processed 13
  of 38 quizzes — extrapolated, well over an hour for the full course.
- Confirmed via direct cache inspection afterward: all 38 per-quiz caches
  and the course-wide cache genuinely warm (not just "the task didn't
  throw").
- Byte-identical serial-vs-parallel output reverified after every change
  to the fork mechanism in this section, each time, on a small real
  sample.

### 8.8 What wasn't resolved

- A second `warm_analytics_cache` run immediately after a confirmed-fully-warm
  cache took ~180s rather than being near-instant, despite direct
  inspection showing every relevant cache entry already warm. Likely the
  per-quiz/per-course cache-fingerprint check itself (a `COUNT`/`MAX`/`SUM`
  query per quiz, run for every course on the site) rather than a genuine
  recompute, but not root-caused.
- No isolated JIT-only before/after benchmark was produced, despite
  fixing the JIT config (§6) — the investigation kept surfacing real
  correctness bugs that needed fixing before any number was trustworthy,
  and time ran out before circling back.
- `tree_edit_distance.php`'s string-key-to-integer-key optimization
  (confirmed not on this course's critical path, but fixed anyway since
  explicitly requested) has no dedicated automated test — the change
  itself is a mechanical, behavior-preserving storage-layout refactor,
  reviewed carefully but not covered by a new test.
- Sub-30-second cold-start for a course this size was the original
  target and is **not achievable** — CAS grading is fundamentally
  per-attempt work; parallelism buys roughly linear speedup with worker
  count against a host's real available resources, not a constant-time
  answer regardless of course size.

## 9. Final state (first round)

- 7 commits this session (`c0ca160` → `5ed80ca`), all sole-authored, all
  pushed to `origin/main`.
- Plugin version bumped to **2.4.0** (`version.php`, `CHANGELOG.md`).
- New admin settings: `local_quizanalytics/parallelworkers` (default 4),
  `local_quizanalytics/parallelworkermemory` (default 2048, in MB) — both
  need sizing against whatever host actually runs the scheduled task; the
  defaults are a reasonable starting point for typical dedicated hosting,
  not tuned to this session's specific 7.75GB-shared local Docker VM
  (which needed workers=2/memory=3072M to succeed reliably at the full
  48k-attempt scale — left configured that way on the local test site
  intentionally, since it's what's actually proven to work there).
- New file: `classes/task/parallel_course_fetcher.php`.
- New test: `tests/parallel_course_fetcher_test.php`.
- Local Docker Moodle environment: `pcntl` extension added, JIT/opcache
  fixed, plugin repo live-mounted, admin password reset to a known value
  for testing, debug logging left off (production-like default) after
  being toggled on/off repeatedly for diagnostics during the session.

## 10. Follow-up round 1: cron reliability, host-adaptive sizing, 50-quiz stress test

A second, much larger round of work, working through a prioritized list of
eight items (0-7). Full detail is in the commits (`45a0c4c` through
`edecefb`); summarized here.

### 10.0 Verify and push

Discovered the previous round's 4 commits had never actually reached
`origin/main` — local only. Pushed immediately; confirmed clean afterward.

### 10.1 The stuck "generating in the background" page

Confirmed the user's own theory directly: this local Docker environment had
*no cron daemon at all* — a real `task_adhoc` row sat queued forever. But
running it by hand surfaced two further, independent real bugs that cron
alone would never have fixed:

- `warm_single_view_adhoc_task`'s course-wide path called the plain serial
  fetch with no memory_limit raise and no parallelization — a real 38-quiz
  course died silently at PHP's default 512M CLI limit (exit 255, no
  visible error at that specific margin).
- Running under *real* Moodle cron (not just standalone CLI scripts)
  surfaced a shutdown-function inheritance bug across `pcntl_fork()`: a
  forked child's plain `exit()` re-runs PHP's whole shutdown sequence,
  including a cron lock's auto-release handler the child inherited but
  never itself acquired, using a `$DB` reference the child had already
  reconnected out from under — cascading into the scheduled task itself
  being marked failed. Fixed by having a child SIGKILL itself instead,
  skipping the inherited shutdown sequence entirely.

Added a `moodle-cron` service to the local Docker `docker-compose.yml`
(this environment had none), a cron-health banner on the plugin's own
settings page (reusing core's `tool_task\check\cronrunning`), and honest
staleness detection for the "generating" notice (15-minute flat cap, past
which it says the task looks stuck rather than repeating the calm message
forever). Dedup logic audited directly (dispatch the same view 5x, hit two
other views, inspect `task_adhoc`) and confirmed already correct.

### 10.2 Confirmed already-correct scoping

Question Analytics and Model 2 were already fetching/computing only the
selected quiz, not the whole course — no code change needed. (Revisited,
and found a real problem in a *different* place, in round 2 below.)

### 10.3-10.4 Fixed-number thresholds replaced with host-measured ones

The on-demand background-compute threshold was a fixed attempt count.
Replaced with `data_fetcher.php`'s `estimate_seconds_per_attempt()` — times
a real ~100-attempt sample on the actual quiz/host and extrapolates,
verified directly to correctly reverse the old threshold's blind spot (a
slow complex quiz with fewer attempts now defers; a fast simple quiz with
more attempts doesn't). `resource_detector.php` detects CPU/RAM (cgroup
first, `/proc` fallback) and recommends `parallelworkers`, wired into
`db/install.php`/`db/upgrade.php` (without overwriting an admin's own
already-tuned value) with a live "Detected resources" readout + "Re-detect"
button on the settings page.

### 10.5 Stress test: 50 quizzes × 1,000 students (50,000 attempts)

Generated by reusing a small, fixed pool of 10 STACK variants per attempt
(measured ~10-13x faster than fresh variants each time — appropriate here
since this test is about fetch/cache/task-layer scaling, not grading
diversity, already verified separately) — 19.4 minutes for the full
50,000-attempt dataset.

Surfaced the single most important bug of this round: an earlier attempt
(within *this same* round, in 10.1's cron-lock fix) to remove
`parallel_course_fetcher::fetch()`'s pre-fork `$DB->dispose()` step broke
down specifically at this larger scale — "MySQL server has gone away" on
the *parent's* own connection, invisible at real 38-quiz scale but
reliably reproducible at 50. Reproduced down to a fast 6-quiz/3-worker
case; confirmed specifically fork-related via an A/B check against
`workers=1` (no forking — stays healthy). Tried a Reflection-based fix
first (mark a child's inherited connection as already-disposed so its
eventual destructor is a no-op) — verified the Reflection call itself
worked, but the corruption persisted anyway, meaning that theory of the
mechanism was wrong (likely an OS/TCP-level file-descriptor-sharing effect
from forking with a live connection at all, not specifically a PHP
destructor/close() call — not fully pinned down). Reverted to disposing
the parent's connection before forking, proven reliable across this
project's entire testing history including this stress test after the
revert; documented the real remaining tradeoff (the cron lock's own
reference can still go stale, showing this task as "failed" in Moodle's
task log despite the work having actually succeeded) rather than pretend
it doesn't exist.

Final numbers at this scale: cold cron warm 293.7s (peak 2.3GB), on-demand
single quiz 0.6s, on-demand course-wide 20.3s — all comfortably under a
100s reverse-proxy timeout. One calibration gap found and documented, not
hidden: the sampled course-wide time estimate (13.8s) underestimated the
real course-wide time (20.3s) — the per-attempt-rate model doesn't account
for fixed per-quiz overhead, which becomes material at 50 quizzes. Both
numbers stay well clear of the actual 100s timeout, so this is a minor
internal-threshold note, not a safety gap.

### 10.6 PDF export redundant re-fetch

Investigated before building either of the two originally-proposed UX
options (grey out the button, or a background-task+ready-link flow) —
traced that the "Download PDF" button is only ever reachable *after* the
on-screen view has already computed successfully (its own chart images are
captured from the already-rendered page), so gating the button on the
threshold would rarely trigger. The real, still-live problem was different:
`questionanalyticspdf.php` unconditionally re-fetched every raw record from
scratch on every click, even moments after the same records had just been
fetched for the page the button appears on. Fixed with a new, short-lived
(300s) raw-record cache shared between the two routes — verified
byte-identical output, cache hit dropped from several seconds to 0.00s.

### 10.7 Documentation

README.md/INSTALL.md updated with deployment/sizing guidance, a "how do I
know it's working" checklist, and the tested-scale ceiling stated
explicitly. Released as **2.4.1**.

## 11. Follow-up round 2: scoping audit, Model Analytics performance bug, CI fixes

Prompted by two things: a screenshot of Moodle's own "Reactive instances"
developer-debug footer appearing on a real page (traced to a debug flag
this session had left on from 10.1's cron-check diagnostics and forgotten
to reset — turned off, and this alone likely explains why Question
Analytics had seemed to stop loading, since debug-level SQL trace output on
a page issuing many queries can make a browser choke), and a request to
verify every one of the four sections is actually scoped to only the
course/quiz selected, not silently computing for everything.

### 11.1 Scoping verification, live

- **Quiz Analytics** (`index.php`) — already course-scoped only. ✓.
- **Question Analytics** — already quiz-scoped only (re-confirmed in code,
  then live: loaded in ~1s for a real course once the stray debug flag was
  off). ✓.
- **Model 2** — already quiz-scoped when a quiz is selected, with its own
  row cap. ✓.
- **Model 1** — correctly course-scoped *by design* (its indicators are
  per-student; there's no quiz to scope it to) — but found something much
  more serious while checking it (11.2 below).
- **Diagnostics Analytics** — course/quiz-scoped correctly; 11.6s for a
  real 38-quiz course is the natural cost of ~100 genuine per-slot
  computations (each row's own queries are scoped to a *different*
  quiz/question, unlike Model 1's pattern below), not a bug — no fix
  needed.

### 11.2 Model 1 taking 5+ minutes on a real course

`modelanalytics.php?id=9` timed out an entire 3-minute test batch on its
own. Direct CLI timing: `model1_report::build(9)` took **299.4 seconds** on
the real 38-quiz/1,147-student course — effectively unusable, and a
genuinely different problem from scoping (the code already caps rows at
100 students).

Root cause: `model1_report::build()` calls 5 indicators once per student
row (up to 100). Two of `stack_attempt_reader`'s own query methods several
of those indicators go through are *course-wide* —
`get_course_step_deltas()` (no userid filter at all), and
`get_resource_access_timestamps()`/`get_stack_failure_events()` when
`help_seeking_gap` calls them with `$userid` left null (the course-wide
baseline population) — meaning the exact same expensive multi-table join,
returning an identical result every time within one report, was being
re-run in full once per student, up to 100 times over. A second, smaller
redundancy: `disengagement_entropy` and `feedback_revision_distance` both
independently call `get_attempt_step_sequences()` for the same student.

Fixed with a per-process memoization layer inside `stack_attempt_reader`
itself, keyed on each method's own call parameters — deliberately not
touching the 5 indicator classes' `compute_for_sample()` signatures, since
that's the Analytics API's own contract (real predictions call these too,
not just this plugin's dashboard). Verified: **299.4s → 16.8s (~18x)** on
the same real course, live-confirmed via an actual authenticated page load
(17.5s, status 200, correct content), and byte-identical output against the
pre-fix version across 19 checks including two consecutive calls to the
same course-wide method to directly confirm the memo returns a consistent
result on a cache hit, not just matching old behaviour once.

### 11.3 Two real GitHub Actions CI failures fixed

Confirmed directly from the actual failed-run output the user provided (a
screenshot of the GitHub Actions log), not guessed:

- `db/upgrade.php`'s version-guarded block never called
  `upgrade_plugin_savepoint()` — `moodle-plugin-ci`'s own "Check upgrade
  savepoints" step flags this by design. Added the missing call.
- `tests/data_fetcher_variant_test.php` used `$CFG->dirroot` at the top of
  the file without `global $CFG;` first — works when a file runs as the
  true top-level script, but PHPUnit's own test loader `require_once()`s
  test files from inside one of its own methods, so the included file's
  top-level code executes in *that* method's local scope, not the true
  global scope. `$CFG` was undefined, crashing every PHPUnit job in the CI
  matrix (3 PHP versions × 2 databases = the "six failing tests") before a
  single test could run. Fixed with an explicit `global $CFG;`, verified by
  simulating PHPUnit's own inclusion pattern locally.
- Also removed a stray `#[\Override]` attribute (PHP 8.3+ only) found via a
  defensive scan of this round's changed files — the CI matrix tests PHP
  8.1 too, and this repo doesn't use that attribute anywhere else.

Not independently re-verified against a live GitHub Actions run as of this
writing (no `gh` authentication available in this environment) — the fixes
are verified as correct against the exact reported error text and via local
simulation, but the next real CI run is what confirms all 6 matrix jobs
actually go green.
