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
 * Computes and caches one specific Quiz Analytics or Question Analytics
 * view in the background, for a visitor whose own on-demand request hit a
 * cold cache on a quiz/course too large to safely compute inline.
 *
 * warm_analytics_cache (the scheduled task) already proactively warms every
 * STACK course's *default* view (colorblind off, anonymize off, average
 * grade) on a schedule — see that task's own docblock. This task exists for
 * the gap that leaves: index.php/questionanalytics.php's synchronous
 * on-demand path, which still fetches serially (pcntl_fork only works in
 * CLI/cron context — confirmed in this session's own notes), and which any
 * visitor can hit on a genuinely cold view (a course that hasn't been
 * warmed yet, or a colorblind/anonymize/grade-type combination the
 * scheduled task never covers, since it has no specific visitor's
 * preferences to warm for).
 *
 * Measured directly against this plugin's own real, largest test quiz
 * (2,764 finished attempts): a cold on-demand compute already takes
 * ~20s even in the best case this session measured, and profiling earlier
 * this session found the fetch stage scaling roughly linearly with attempt
 * count with no structural reason to expect that to change (the batching in
 * data_fetcher.php's own ATTEMPT_BATCH_SIZE already exists specifically to
 * keep memory, and therefore GC cost, from growing worse than linear as a
 * quiz gets larger — see that constant's own comment). A quiz several times
 * that size — nothing in Moodle stops one existing — can plausibly exceed a
 * reverse proxy's own timeout (Cloudflare's free/Pro default is ~100s)
 * before this plugin's own code ever gets a chance to finish or even to
 * reach the ignore_user_abort(true) guard that already protects a
 * request that does complete despite the browser giving up. Dispatching
 * to run here instead means the visitor's own request returns almost
 * immediately either way, and the expensive compute happens somewhere with
 * no proxy timeout waiting on it — the same reasoning as
 * warm_analytics_cache's own scheduled runs, just triggered by a real
 * visitor's specific cold view instead of a schedule.
 *
 * Deliberately dispatched via
 * \core\task\manager::queue_adhoc_task($task, true) — the second argument
 * deduplicates against an already-queued task with identical classname,
 * component, and custom data, so several visitors hitting the same cold
 * (quiz, colorblind, anonymize) or (course, gradetype, colorblind,
 * anonymize) combination in quick succession queue at most one task
 * between them, not one each. Also composes safely with the scheduled
 * task warming the *default* view on its own schedule: both this task and
 * a cron run computing the same view independently arrive at the same
 * deterministic, fingerprint-keyed result and both merely call
 * cache->set() with it — redundant if they overlap, never incorrect — and
 * this task's own cache->get() check before computing anything means
 * whichever of the two finishes first makes the other's remaining work a
 * no-op.
 *
 * @package local_quizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizanalytics\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/data_fetcher.php');
require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/api_client.php');
require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/cache_helper.php');

/**
 * Ad-hoc task: warm one specific quiz or course-wide view.
 */
class warm_single_view_adhoc_task extends \core\task\adhoc_task {
    /**
     * Queues (or, if an identical request is already queued, reuses) a
     * background compute of one quiz's Question Analytics/Solution Process
     * view for the given display options.
     *
     * @param int $quizid
     * @param bool $colorblind
     * @param bool $anonymize
     */
    public static function dispatch_for_quiz(int $quizid, bool $colorblind, bool $anonymize): void {
        $task = new self();
        $task->set_custom_data((object) [
            'type' => 'quiz',
            'id' => $quizid,
            'colorblind' => $colorblind,
            'anonymize' => $anonymize,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Queues (or reuses) a background compute of one quiz's Solution
     * Process Visualization *metadata* only (the question/part/student
     * selector's own options) — not any specific (question, part, student)
     * combination, which is deliberately never proactively warmed here or
     * by the scheduled task; see warm_analytics_cache's own docblock for
     * why (every combination is its own cache entry).
     *
     * @param int $quizid
     * @param bool $anonymize
     */
    public static function dispatch_for_quiz_meta(int $quizid, bool $anonymize): void {
        $task = new self();
        $task->set_custom_data((object) [
            'type' => 'quizmeta',
            'id' => $quizid,
            'anonymize' => $anonymize,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Queues (or reuses) a background compute of one course's Quiz
     * Analytics (course-wide) view for the given display options.
     *
     * @param int $courseid
     * @param string $gradetype
     * @param bool $colorblind
     * @param bool $anonymize
     */
    public static function dispatch_for_course(int $courseid, string $gradetype, bool $colorblind, bool $anonymize): void {
        $task = new self();
        $task->set_custom_data((object) [
            'type' => 'course',
            'id' => $courseid,
            'gradetype' => $gradetype,
            'colorblind' => $colorblind,
            'anonymize' => $anonymize,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task:warmsingleview', 'local_quizanalytics');
    }

    #[\Override]
    public function execute(): void {
        $data = $this->get_custom_data();
        $client = new \local_quizanalytics_quiz_api_client();

        switch ($data->type) {
            case 'quiz':
                $this->warm_quiz_view((int) $data->id, (bool) $data->colorblind, (bool) $data->anonymize, $client);
                break;
            case 'quizmeta':
                $this->warm_quiz_meta((int) $data->id, (bool) $data->anonymize, $client);
                break;
            default:
                $this->warm_course_view(
                    (int) $data->id,
                    (string) $data->gradetype,
                    (bool) $data->colorblind,
                    (bool) $data->anonymize,
                    $client
                );
        }
    }

    /**
     * @param int $quizid
     * @param bool $anonymize
     * @param \local_quizanalytics_quiz_api_client $client
     */
    private function warm_quiz_meta(int $quizid, bool $anonymize, $client): void {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            return;
        }
        $course = $DB->get_record('course', ['id' => $quiz->course]);
        if (!$course) {
            return;
        }

        $stats = \local_quizanalytics_quiz_cache_helper::stats_for_quiz($quiz);
        if ($stats->count === 0) {
            return;
        }

        $cache = \cache::make('local_quizanalytics', 'solutionprocessmeta');
        $key = \local_quizanalytics_quiz_cache_helper::build_key($quiz->id, $stats->fingerprint, $anonymize);
        if ($cache->get($key) !== false) {
            return;
        }

        $records = \local_quizanalytics_quiz_data_fetcher::get_response_records_for_quiz($quiz, $course);
        $meta = $client->solution_process_meta($quiz->name, $records, $anonymize);
        if ($meta !== null) {
            $cache->set($key, $meta);
        }
    }

    /**
     * @param int $quizid
     * @param bool $colorblind
     * @param bool $anonymize
     * @param \local_quizanalytics_quiz_api_client $client
     */
    private function warm_quiz_view(int $quizid, bool $colorblind, bool $anonymize, $client): void {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            return; // Deleted since this task was queued.
        }
        $course = $DB->get_record('course', ['id' => $quiz->course]);
        if (!$course) {
            return;
        }

        $stats = \local_quizanalytics_quiz_cache_helper::stats_for_quiz($quiz);
        if ($stats->count === 0) {
            return;
        }

        $cache = \cache::make('local_quizanalytics', 'questionanalysis');
        $key = \local_quizanalytics_quiz_cache_helper::build_key($quiz->id, $stats->fingerprint, $colorblind, $anonymize);
        if ($cache->get($key) !== false) {
            return; // Already warmed — cron or another dispatch beat this task to it.
        }

        $records = \local_quizanalytics_quiz_data_fetcher::get_response_records_for_quiz($quiz, $course);
        $result = $client->analyze($quiz->name, $records, $colorblind, $anonymize);
        if ($result !== null) {
            $cache->set($key, $result);
        }
    }

    /**
     * @param int $courseid
     * @param string $gradetype
     * @param bool $colorblind
     * @param bool $anonymize
     * @param \local_quizanalytics_quiz_api_client $client
     */
    private function warm_course_view(int $courseid, string $gradetype, bool $colorblind, bool $anonymize, $client): void {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return;
        }
        $stackquizzes = \local_quizanalytics_quiz_data_fetcher::get_course_stack_quizzes($courseid);
        if (empty($stackquizzes)) {
            return;
        }

        $coursestats = \local_quizanalytics_quiz_cache_helper::stats_for_quizzes($stackquizzes);
        if ($coursestats->count === 0) {
            return;
        }

        $cache = \cache::make('local_quizanalytics', 'quizanalysiscoursewide');
        $key = \local_quizanalytics_quiz_cache_helper::build_key($courseid, $coursestats->fingerprint, $gradetype, $colorblind, $anonymize);
        if ($cache->get($key) !== false) {
            return;
        }

        $byquiz = \local_quizanalytics_quiz_data_fetcher::get_course_response_records($course, $stackquizzes);
        $byquiz = array_filter($byquiz, fn($records) => !empty($records));
        $result = $client->analyze_course($course->fullname, $byquiz, $colorblind, $gradetype, $anonymize);
        if ($result !== null) {
            $cache->set($key, $result);
        }
    }
}
