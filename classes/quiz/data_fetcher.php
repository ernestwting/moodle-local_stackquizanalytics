<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * All database access for the Quiz Analytics half of local_quizanalytics:
 * detecting which quizzes in a
 * course (or a specific quiz) contain qtype_stack questions, and reading
 * finished attempts + responses straight out of the database — reconstructing
 * the same row shape Moodle's own "Responses report" CSV export produces
 * (matching what analytics/parser.py::build_response_rows already expects),
 * so the analytics engine itself needs no changes, only its input source.
 *
 * @package local_quizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Fetches quiz/course attempt records from Moodle's own DB tables for the analytics package to consume.
 */
class local_quizanalytics_quiz_data_fetcher {
    /**
     * Cheap existence check: does this course contain at least one quiz with
     * at least one qtype_stack question in one of its slots?
     *
     * Only matches questions added directly to a slot (the normal way STACK
     * questions are added to a quiz). Quizzes that pull STACK questions in
     * only via "random question from category" slots are not detected here —
     * that's a deliberately narrow scope for a fast nav-gating check, not a
     * full quiz-structure walk.
     *
     * @param int $courseid
     * @return bool
     */
    public static function course_has_stack_quiz(int $courseid): bool {
        global $DB;

        $sql = "SELECT 1
                  FROM {quiz} quiz
                  JOIN {course_modules} cm ON cm.instance = quiz.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {context} ctx ON ctx.contextlevel = :contextmodule AND ctx.instanceid = cm.id
                  JOIN {quiz_slots} slot ON slot.quizid = quiz.id
                  JOIN {question_references} qr ON qr.usingcontextid = ctx.id
                                                AND qr.component = 'mod_quiz'
                                                AND qr.questionarea = 'slot'
                                                AND qr.itemid = slot.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid AND q.qtype = 'stack'
                 WHERE quiz.course = :courseid";

        return $DB->record_exists_sql($sql, [
            'contextmodule' => CONTEXT_MODULE,
            'courseid'      => $courseid,
        ]);
    }

    /**
     * Same matching rule as course_has_stack_quiz(), narrowed to one quiz —
     * used to gate the Analytics link this plugin adds to a quiz's own
     * settings/administration menu (see lib.php's
     * local_quizanalytics_extend_settings_navigation()), which runs on every
     * quiz page view so needs to stay just as cheap.
     *
     * @param int $quizid
     * @return bool
     */
    public static function quiz_has_stack_question(int $quizid): bool {
        global $DB;

        $sql = "SELECT 1
                  FROM {quiz} quiz
                  JOIN {course_modules} cm ON cm.instance = quiz.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {context} ctx ON ctx.contextlevel = :contextmodule AND ctx.instanceid = cm.id
                  JOIN {quiz_slots} slot ON slot.quizid = quiz.id
                  JOIN {question_references} qr ON qr.usingcontextid = ctx.id
                                                AND qr.component = 'mod_quiz'
                                                AND qr.questionarea = 'slot'
                                                AND qr.itemid = slot.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid AND q.qtype = 'stack'
                 WHERE quiz.id = :quizid";

        return $DB->record_exists_sql($sql, [
            'contextmodule' => CONTEXT_MODULE,
            'quizid'        => $quizid,
        ]);
    }

    /**
     * Lists every quiz in the course that contains at least one qtype_stack
     * question (same matching rule as course_has_stack_quiz()), for the quiz
     * selector on index.php.
     *
     * @param int $courseid
     * @return stdClass[] quiz records (id, name, course), keyed by quiz id
     */
    public static function get_course_stack_quizzes(int $courseid): array {
        global $DB;

        $sql = "SELECT DISTINCT quiz.id, quiz.name, quiz.course, quiz.sumgrades
                  FROM {quiz} quiz
                  JOIN {course_modules} cm ON cm.instance = quiz.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {context} ctx ON ctx.contextlevel = :contextmodule AND ctx.instanceid = cm.id
                  JOIN {quiz_slots} slot ON slot.quizid = quiz.id
                  JOIN {question_references} qr ON qr.usingcontextid = ctx.id
                                                AND qr.component = 'mod_quiz'
                                                AND qr.questionarea = 'slot'
                                                AND qr.itemid = slot.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid AND q.qtype = 'stack'
                 WHERE quiz.course = :courseid
              ORDER BY quiz.name";

        return $DB->get_records_sql($sql, [
            'contextmodule' => CONTEXT_MODULE,
            'courseid'      => $courseid,
        ]);
    }

    /**
     * Every course id containing at least one STACK quiz (same matching
     * rule as course_has_stack_quiz()), site-wide — used by the cache-
     * warming scheduled task to find which courses to precompute for. Unlike
     * stack_course_helper::get_viewable_courses(), this isn't scoped to any
     * particular user's own enrolments/capabilities, since a scheduled task
     * has no such user to check against.
     *
     * @return int[] course ids
     */
    public static function get_courses_with_stack_quizzes(): array {
        global $DB;

        $sql = "SELECT DISTINCT quiz.course
                  FROM {quiz} quiz
                  JOIN {course_modules} cm ON cm.instance = quiz.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {context} ctx ON ctx.contextlevel = :contextmodule AND ctx.instanceid = cm.id
                  JOIN {quiz_slots} slot ON slot.quizid = quiz.id
                  JOIN {question_references} qr ON qr.usingcontextid = ctx.id
                                                AND qr.component = 'mod_quiz'
                                                AND qr.questionarea = 'slot'
                                                AND qr.itemid = slot.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid AND q.qtype = 'stack'";

        $courseids = $DB->get_records_sql($sql, ['contextmodule' => CONTEXT_MODULE]);
        return array_map('intval', array_keys($courseids));
    }

    /**
     * Response records for a single quiz — one row per finished attempt,
     * with one set of question_N_text, response_N, right_answer_N (etc.)
     * columns per slot in that attempt.
     *
     * @param stdClass $quiz   row from mdl_quiz
     * @param stdClass $course course record
     * @return array
     */
    public static function get_response_records_for_quiz(stdClass $quiz, stdClass $course): array {
        global $DB;

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $records = [];

        // Only finished attempts — matches what a teacher would get from the
        // "Responses" report with the default "Finished" state filter. Adjust
        // this if you also want in-progress/overdue attempts included.
        $attempts = $DB->get_records('quiz_attempts', [
            'quiz'  => $quiz->id,
            'state' => 'finished',
        ], 'userid, attempt');

        if (!$attempts) {
            return [];
        }

        // Batch-load user records instead of querying per attempt.
        $userids = array_unique(array_map(fn($a) => $a->userid, $attempts));
        $users = $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, lastname, email');

        // Batch-load every attempt's question usage in one pass instead of
        // once per attempt. question_engine::load_questions_usage_by_activity()
        // (singular) issues its own set of queries every time it's called —
        // at 500-1000 finished attempts that's 500-1000x the round trips,
        // easily slow enough on its own to blow past a reverse proxy's
        // request timeout (Cloudflare 524) before this method ever returns
        // and lets its caller populate the result cache other requests rely
        // on. question_engine_data_mapper::load_questions_usages_by_activity()
        // (plural) is the exact same underlying question_usage_by_activity
        // API, just backed by one batched recordset query across every
        // requested usage id — the same fix mod_quiz's own "Responses"
        // report uses for this identical problem at scale (see
        // quiz_first_or_all_responses_table::load_extra_data() in Moodle
        // core, confirmed against a real Moodle 4.5 checkout).
        $uniqueids = array_map(fn($a) => (int) $a->uniqueid, $attempts);
        $datamapper = new question_engine_data_mapper();
        $qubasbyid = $datamapper->load_questions_usages_by_activity(new qubaid_list($uniqueids));

        // render_stack_question_text() runs a full CASText2 render (real CAS
        // work, not a cheap DB read) — expensive enough that at a few
        // thousand attempts it dominates this method's entire runtime. Every
        // consumer of question_N_text (question_details.php's
        // build_question_detail(), question_analysis.php) only ever wants
        // one representative value per *question*, taking the first
        // non-empty one across every attempt's row — never a specific
        // attempt's own value. So of the up to one call per attempt per
        // slot this loop would otherwise make, only the first non-empty
        // result per slot position is ever kept; every later render for
        // that same slot is thrown away downstream. Memoizing by slot
        // position (stable across attempts of the same quiz) skips that
        // wasted work entirely without changing which value ends up
        // selected — right_answer_N is deliberately not memoized here since
        // it's seed-dependent and the error drill-down needs each attempt's
        // own value, and it's a cheap already-stored read anyway, not a
        // fresh CAS render.
        $questiontextbyslot = [];

        foreach ($attempts as $attempt) {
            $user = $users[$attempt->userid] ?? null;
            if (!$user) {
                continue; // Deleted/suspended user — skip rather than fail the whole report.
            }

            $quba = $qubasbyid[$attempt->uniqueid] ?? null;
            if (!$quba) {
                continue; // No question usage recorded for this attempt — shouldn't happen for a finished attempt.
            }

            $row = [
                'last_name'      => $user->lastname,
                'first_name'     => $user->firstname,
                'email'          => $user->email,
                'state'          => $attempt->state,
                // Passing fixday=false: userdate()'s default (true) strips the
                // leading zero from the day ("2026-6-3" instead of
                // "2026-06-03"), which breaks sections-renderer.js's
                // ISO_DATETIME_RE match for single-digit days — that row
                // then fell back to showing this raw string unformatted
                // while every other row displayed nicely, which is exactly
                // the inconsistent Completed On/Started On formatting
                // reported against this page.
                'started_on'     => userdate($attempt->timestart, '%Y-%m-%d %H:%M:%S', 99, false),
                'completed'      => $attempt->timefinish
                    ? userdate($attempt->timefinish, '%Y-%m-%d %H:%M:%S', 99, false) : '',
                'time_taken_secs' => $attempt->timefinish
                    ? ($attempt->timefinish - $attempt->timestart) : null,
                'grade'          => $attempt->sumgrades,
                'max_grade'      => $quiz->sumgrades,
                'attempt_number' => $attempt->attempt,
            ];

            $qnum = 1;
            foreach ($quba->get_slots() as $slot) {
                // $quba->get_question($slot) is the single most expensive
                // call in this whole method — 4.2ms/call measured directly
                // (vs. under 0.03ms combined for the three calls below),
                // because it instantiates the STACK question fresh for this
                // attempt's specific seed (CAS work), not just a DB read.
                // $question's only use anywhere in this method is as the
                // argument to render_stack_question_text() two lines below,
                // which is itself memoized per slot — so once that memo is
                // warm, instantiating a fresh question object here would be
                // pure waste. Only call get_question() when the memo is
                // actually still empty and its result will really be used.
                if (empty($questiontextbyslot[$qnum])) {
                    $questiontextbyslot[$qnum] = self::render_stack_question_text($quba->get_question($slot));
                }
                $row["question_{$qnum}_text"] = $questiontextbyslot[$qnum];

                // Get_response_summary() is the same method the core "Responses"
                // report calls to build its "Response N" column — for STACK
                // questions this includes the ansK/prtK trace the Python parser
                // already knows how to read (parse_response_cell).
                $row["response_{$qnum}"]         = $quba->get_response_summary($slot) ?? '';
                $row["right_answer_{$qnum}"]     = $quba->get_right_answer_summary($slot) ?? '';
                $row["question_{$qnum}_mark"]    = $quba->get_question_mark($slot);
                $row["question_{$qnum}_maxmark"] = $quba->get_question_max_mark($slot);

                $qnum++;
            }

            $records[] = $row;
        }

        return $records;
    }

    /**
     * $question->questiontext is the raw, author-written source — for STACK
     * questions that still contains unresolved CAS placeholders (@variable@)
     * and every language's [[lang code='xx']]...[[/lang]] block side by side,
     * none of which get processed until the question is actually rendered
     * through STACK's own CAS-text engine. This renders it the same way
     * STACK's own question renderer would (CAS variables substituted,
     * [[lang]] blocks resolved to only the current language), using the
     * "out of context" processor STACK itself uses for report/summary
     * generation (stack_question::get_question_summary() is the other
     * caller of this exact pattern) since there's no live question_attempt
     * here to render against.
     *
     * Falls back to the raw questiontext for non-STACK questions or if
     * anything above goes wrong — a report showing unresolved placeholders
     * is still more useful than one that fails outright for one bad question.
     *
     * @param question_definition $question
     * @return string
     */
    protected static function render_stack_question_text(question_definition $question): string {
        global $CFG;

        if (!($question instanceof \qtype_stack_question) || empty($question->questiontextinstantiated)) {
            return $question->questiontext;
        }

        try {
            require_once($CFG->dirroot . '/question/type/stack/locallib.php');
            $processor = new \castext2_qa_processor(new \stack_outofcontext_process());
            $rendered = $question->questiontextinstantiated->get_rendered($processor);
            if ($rendered !== null && $rendered !== '') {
                return $rendered;
            }
        } catch (\Throwable $e) {
            debugging('local_quizanalytics (quiz): could not render CAS question text for a STACK question: ' .
                $e->getMessage(), DEBUG_DEVELOPER);
        }

        return $question->questiontext;
    }

    /**
     * Response records for every STACK quiz in the course, grouped by quiz
     * name — the shape local_quizanalytics_quiz_api_client::analyze_course()
     * expects.
     *
     * @param stdClass $course
     * @param stdClass[] $stackquizzes as returned by get_course_stack_quizzes()
     * @return array [quiz_name => records[]]
     */
    public static function get_course_response_records(stdClass $course, array $stackquizzes): array {
        $bycourse = [];
        foreach ($stackquizzes as $quiz) {
            $bycourse[$quiz->name] = self::get_response_records_for_quiz($quiz, $course);

            // get_response_records_for_quiz() batch-loads a question_usage_by_activity
            // per attempt (load_questions_usages_by_activity()) — these hold internal
            // reference cycles (question_attempt <-> question_attempt_step linkage),
            // which only PHP's *cycle* collector (not plain refcounting) can reclaim.
            // Across many quizzes in one course-wide request, that collector's own
            // automatic, threshold-based triggering falls further and further behind
            // as more cyclic garbage piles up, and each of its automatic runs gets
            // more expensive to walk — measured directly on a real 38-quiz/48,445-
            // attempt course, this showed up as a single quiz's own fetch going from
            // ~10s (first few quizzes) to 461s (ninth) with no code-level difference
            // between them. Forcing a clean, predictable collection right after each
            // quiz — while its now-unreferenced QUBA objects are the only large batch
            // of cyclic garbage outstanding — keeps every run cheap instead of one
            // increasingly expensive automatic sweep; same course dropped to 16-17s
            // for the same previously-461s quiz with this in place, and memory usage
            // stayed bounded rather than growing for the rest of the request.
            gc_collect_cycles();
        }
        return $bycourse;
    }
}
