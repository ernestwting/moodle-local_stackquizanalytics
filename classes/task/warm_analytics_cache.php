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
 * Proactively (re)computes the Quiz Analytics/Question Analytics result
 * caches for every course with STACK quiz activity — the same
 * get_response_records_for_quiz()/analyze()/analyze_course() calls
 * index.php and questionanalytics.php already make on a cache miss, just
 * moved off a real visitor's own HTTP request and onto cron instead.
 *
 * Exists because that cold-cache compute, over a course with several
 * hundred finished attempts, can run long enough that a reverse proxy in
 * front of the site gives up on the browser before PHP finishes
 * (Cloudflare's default ~100s edge timeout, seen in practice as a 524) —
 * raising local_quizanalytics/computetimelimit doesn't help with that
 * specific failure, since it only raises PHP's own execution time limit,
 * not any proxy sitting in front of PHP. Running this on a schedule means
 * a real viewer only ever needs to hit a warm cache, and the expensive
 * first-time compute happens somewhere with no browser timeout waiting on
 * it. See ignore_user_abort() usage in index.php/questionanalytics.php for
 * the complementary fix for whatever narrow window still lands on a cold
 * cache between task runs.
 *
 * Scoped to the default view only — colorblind off, anonymize off, the
 * default course-wide grade type — the combination almost every visitor
 * actually lands on (sections_output_helper::resolve_colorblind_mode()/
 * resolve_anonymize_mode() both default false, and are per-user
 * preferences this task has no particular user's session to read). A
 * visitor who's personally toggled colorblind or anonymize mode still
 * computes cold on their own next visit — an accepted gap, not something a
 * site-wide background task can reasonably pre-empt for every user.
 *
 * Solution Process Visualization's own cache areas (solutionprocessmeta/
 * solutionprocess) are deliberately not warmed here: unlike the other two
 * areas, there's no single "default" question/part/student selection to
 * precompute — every combination is its own cache entry, and eagerly
 * computing all of them for every STACK question in every course would be
 * its own much larger background job. Those two still benefit from caching
 * after a teacher's first real look at one specific question/part, same as
 * before this task existed.
 *
 * @package local_quizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizanalytics\task;

use local_quizanalytics\quiz\analytics\course_analysis;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/data_fetcher.php');
require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/api_client.php');
require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/cache_helper.php');

/**
 * Scheduled task that keeps the Quiz Analytics/Question Analytics result caches warm.
 */
class warm_analytics_cache extends \core\task\scheduled_task {
    /**
     * Task name shown in Site administration > Server > Scheduled tasks.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:warmanalyticscache', 'local_quizanalytics');
    }

    /**
     * Recomputes and caches the default-view result for every STACK course
     * whose current cache entry is missing or stale, skipping anything
     * that's already warm for its current attempt fingerprint.
     */
    public function execute(): void {
        global $DB;

        $client = new \local_quizanalytics_quiz_api_client();

        foreach (\local_quizanalytics_quiz_data_fetcher::get_courses_with_stack_quizzes() as $courseid) {
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                continue; // Deleted since get_courses_with_stack_quizzes() ran.
            }

            $stackquizzes = \local_quizanalytics_quiz_data_fetcher::get_course_stack_quizzes($courseid);
            if (empty($stackquizzes)) {
                continue;
            }

            foreach ($stackquizzes as $quiz) {
                $this->warm_question_analysis($client, $quiz, $course);
            }

            $this->warm_course_wide_analysis($client, $stackquizzes, $course);
        }
    }

    /**
     * Warms one quiz's Question Analytics ('questionanalysis') cache entry, if it isn't already.
     *
     * @param \local_quizanalytics_quiz_api_client $client
     * @param \stdClass $quiz
     * @param \stdClass $course
     */
    private function warm_question_analysis(\local_quizanalytics_quiz_api_client $client, \stdClass $quiz, \stdClass $course): void {
        $stats = \local_quizanalytics_quiz_cache_helper::stats_for_quiz($quiz);
        if ($stats->count === 0) {
            return;
        }

        $cache = \cache::make('local_quizanalytics', 'questionanalysis');
        $key = \local_quizanalytics_quiz_cache_helper::build_key($quiz->id, $stats->fingerprint, false, false);
        if ($cache->get($key) !== false) {
            return; // Already warm for the current fingerprint.
        }

        $records = \local_quizanalytics_quiz_data_fetcher::get_response_records_for_quiz($quiz, $course);
        $result = $client->analyze($quiz->name, $records, false, false);
        if ($result !== null) {
            $cache->set($key, $result);
        }
    }

    /**
     * Warms a course's course-wide Quiz Analytics ('quizanalysiscoursewide') cache entry, if it isn't already.
     *
     * @param \local_quizanalytics_quiz_api_client $client
     * @param \stdClass[] $stackquizzes
     * @param \stdClass $course
     */
    private function warm_course_wide_analysis(\local_quizanalytics_quiz_api_client $client, array $stackquizzes, \stdClass $course): void {
        $stats = \local_quizanalytics_quiz_cache_helper::stats_for_quizzes($stackquizzes);
        if ($stats->count === 0) {
            return;
        }

        $cache = \cache::make('local_quizanalytics', 'quizanalysiscoursewide');
        $key = \local_quizanalytics_quiz_cache_helper::build_key(
            $course->id,
            $stats->fingerprint,
            course_analysis::DEFAULT_GRADE_TYPE,
            false,
            false
        );
        if ($cache->get($key) !== false) {
            return;
        }

        $byquiz = \local_quizanalytics_quiz_data_fetcher::get_course_response_records($course, $stackquizzes);
        $byquiz = array_filter($byquiz, fn($records) => !empty($records));

        $result = $client->analyze_course($course->fullname, $byquiz, false, course_analysis::DEFAULT_GRADE_TYPE, false);
        if ($result !== null) {
            $cache->set($key, $result);
        }
    }
}
