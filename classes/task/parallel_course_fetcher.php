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
 * Fans a course's STACK quizzes' response-record fetch out across forked
 * worker processes, CLI/cron context only.
 *
 * Why this exists: profiling a real 38-quiz/48,445-attempt course showed
 * the fetch stage (data_fetcher.php's get_response_records_for_quiz())
 * completely dominating runtime — ~10-20x the entire analysis pipeline
 * combined for the same quiz. The reason isn't a query or algorithm this
 * plugin controls: Moodle's own qtype_stack calls get_response_summary()
 * -> summarise_response() -> get_prt_result(), which re-runs the PRT's
 * actual CAS/Maxima grading logic live, every time, for every attempt —
 * confirmed directly against question/type/stack/question.php's
 * validate_cache()/get_prt_result(), which only memoizes repeat calls
 * *within one already-hydrated question object for the same response*, not
 * across the many separate question objects this plugin's batch fetch
 * creates (one per attempt). There's no "read the already-graded result
 * without recomputing" path through the standard question-engine API.
 *
 * What IS true, though: those CAS calls are I/O-bound (waiting on the
 * Maxima backend), and this site's goemaxima worker pool
 * (docker-compose.yml's GOEMAXIMA_QUEUE_LEN) sits almost entirely idle
 * during a fetch, since the whole plugin makes these calls strictly
 * sequentially, one attempt at a time, in one PHP process. Running several
 * quizzes' fetches concurrently in separate forked processes lets that
 * pool actually do concurrent work, without touching a single line of the
 * fetch logic itself (get_response_records_for_quiz() is called completely
 * unmodified in every child).
 *
 * pcntl_fork() only works in CLI/cron context (Apache/mod_php can't fork),
 * so this is deliberately only ever called from the warm_analytics_cache
 * scheduled task — the synchronous on-demand path (index.php,
 * questionanalytics.php) is untouched and keeps fetching serially on a
 * cold-cache request, same as before this existed.
 *
 * @package local_quizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizanalytics\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/data_fetcher.php');
require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/cache_helper.php');

/**
 * Forked-worker-pool fetch coordinator for one course's STACK quizzes.
 */
class parallel_course_fetcher {
    /**
     * Fetches response records for every given quiz in $course, running up
     * to $workers quizzes' worth of fetch work concurrently in separate
     * forked processes when possible.
     *
     * Falls back to a single-process sequential fetch (identical to what
     * this method's forked path would produce, just slower) whenever
     * forking isn't available or wouldn't help: no pcntl, $workers <= 1,
     * or only one quiz to fetch. That fallback path is exactly
     * data_fetcher.php's own get_course_response_records() — the same,
     * already-correct, already-tested code this whole class exists to
     * parallelize, never a reimplementation of it.
     *
     * @param \stdClass $course
     * @param \stdClass[] $stackquizzes quiz records to fetch, keyed by quiz id
     * @param int $workers maximum number of concurrent forked processes
     * @return array [quiz_name => records[]]
     * @throws \Exception if any worker failed — the caller should treat
     *         that as "could not warm this course this run", not cache a
     *         partial/incomplete result.
     */
    public static function fetch(\stdClass $course, array $stackquizzes, int $workers): array {
        if ($workers <= 1 || count($stackquizzes) <= 1 || !function_exists('pcntl_fork')) {
            return \local_quizanalytics_quiz_data_fetcher::get_course_response_records($course, $stackquizzes);
        }

        $chunks = array_values(array_filter(
            self::balance_chunks($stackquizzes, $workers),
            fn($chunk) => !empty($chunk)
        ));
        if (count($chunks) <= 1) {
            // Balancing collapsed everything into one bucket (e.g. only one
            // quiz has any attempts) — forking for a single chunk of work
            // only adds overhead, so run it inline instead.
            return \local_quizanalytics_quiz_data_fetcher::get_course_response_records($course, $stackquizzes);
        }

        $tmpdir = make_temp_directory('local_quizanalytics_parallel');
        $children = []; // pid => tmpfile path.
        $anyfailed = false;

        foreach ($chunks as $index => $chunk) {
            $tmpfile = $tmpdir . '/chunk_' . $index . '_' . uniqid('', true) . '.dat';
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed (e.g. process limit) — run this chunk inline
                // in the parent rather than losing its data.
                self::run_chunk_and_write($course, $chunk, $tmpfile);
                if (!self::read_and_delete($tmpfile, $partial)) {
                    $anyfailed = true;
                } else {
                    $byquiz = ($byquiz ?? []) + $partial;
                }
                continue;
            }

            if ($pid === 0) {
                // Child process: fork() gave this process its own private
                // copy of memory, so reassigning $DB here can never affect
                // the parent's — but the *inherited* $DB object still
                // points at the same underlying OS-level socket the parent
                // is using, and touching that from two processes at once
                // corrupts the connection for both. reconnect_db() never
                // touches the inherited connection at all — it builds an
                // entirely new one and rebinds the global to it — so the
                // parent's connection is left completely alone.
                $exitcode = 0;
                try {
                    self::reconnect_db();
                    self::run_chunk_and_write($course, $chunk, $tmpfile);
                } catch (\Throwable $e) {
                    debugging(
                        'local_quizanalytics: parallel fetch worker failed: '
                            . get_class($e) . ': ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                    $exitcode = 1;
                }
                exit($exitcode);
            }

            // Parent process.
            $children[$pid] = $tmpfile;
        }

        foreach (array_keys($children) as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                debugging(
                    'local_quizanalytics: parallel fetch worker pid ' . $pid . ' exited abnormally (status '
                        . $status . ')',
                    DEBUG_DEVELOPER
                );
                $anyfailed = true;
            }
        }

        $byquiz = $byquiz ?? [];
        foreach ($children as $tmpfile) {
            if (!self::read_and_delete($tmpfile, $partial)) {
                debugging('local_quizanalytics: parallel fetch worker result file missing or unreadable: ' . $tmpfile,
                    DEBUG_DEVELOPER);
                $anyfailed = true;
                continue;
            }
            $byquiz += $partial; // Quiz names are unique per course — safe to merge.
        }

        if ($anyfailed) {
            throw new \Exception(
                'local_quizanalytics: one or more parallel fetch workers failed for course '
                . $course->id . '; not caching a partial result this run.'
            );
        }

        return $byquiz;
    }

    /**
     * Runs the existing, unmodified per-quiz fetch for one chunk of quizzes
     * and serializes the result to a temp file for the parent to read back.
     *
     * @param \stdClass $course
     * @param \stdClass[] $chunk
     * @param string $tmpfile
     */
    private static function run_chunk_and_write(\stdClass $course, array $chunk, string $tmpfile): void {
        $result = \local_quizanalytics_quiz_data_fetcher::get_course_response_records($course, $chunk);
        file_put_contents($tmpfile, serialize($result));
    }

    /**
     * Reads and deletes a worker's result file.
     *
     * @param string $tmpfile
     * @param array|null $out set to the decoded [quiz_name => records[]] array on success
     * @return bool false if the file was missing/unreadable/corrupt
     */
    private static function read_and_delete(string $tmpfile, ?array &$out): bool {
        if (!is_readable($tmpfile)) {
            $out = null;
            return false;
        }
        $contents = file_get_contents($tmpfile);
        @unlink($tmpfile);
        $decoded = @unserialize($contents, ['allowed_classes' => true]);
        if (!is_array($decoded)) {
            $out = null;
            return false;
        }
        $out = $decoded;
        return true;
    }

    /**
     * Opens a brand new database connection and rebinds the global $DB to
     * it, without ever touching the connection $DB pointed at before the
     * fork. Same driver/credentials setup_DB() itself uses
     * (lib/dmllib.php), just building a second, independent instance
     * instead of reusing the process-inherited one.
     */
    private static function reconnect_db(): void {
        global $CFG, $DB;
        $freshdb = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary, false);
        $freshdb->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
        $DB = $freshdb;
    }

    /**
     * Greedily distributes quizzes across $workers buckets balanced by
     * finished-attempt count (a direct proxy for CAS/PRT-evaluation cost —
     * confirmed by profiling that fetch time scales with attempt count),
     * not plain quiz count — this course's own quizzes range from 41 to
     * 2,764 finished attempts each, so an even quiz-count split would leave
     * some workers with far more real work than others.
     *
     * @param \stdClass[] $quizzes
     * @param int $workers
     * @return array[] $workers buckets, each an array of quiz records
     */
    private static function balance_chunks(array $quizzes, int $workers): array {
        $withcounts = array_map(function ($quiz) {
            $stats = \local_quizanalytics_quiz_cache_helper::stats_for_quiz($quiz);
            return ['quiz' => $quiz, 'count' => $stats->count];
        }, array_values($quizzes));

        usort($withcounts, fn($a, $b) => $b['count'] <=> $a['count']);

        $buckets = array_fill(0, $workers, []);
        $bucketloads = array_fill(0, $workers, 0);
        foreach ($withcounts as $item) {
            $lightest = array_search(min($bucketloads), $bucketloads, true);
            $buckets[$lightest][] = $item['quiz'];
            $bucketloads[$lightest] += $item['count'];
        }
        return $buckets;
    }
}
