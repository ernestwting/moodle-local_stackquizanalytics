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

namespace local_quizanalytics;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/type/stack/locallib.php');

/**
 * Regression test for a real correctness bug in
 * get_response_records_for_quiz()'s per-slot question-text memo.
 *
 * A randomised STACK question gets a different Moodle-assigned seed per
 * attempt — either from a fixed list of deployed variants, or from
 * completely free randomisation when none are deployed (see
 * qtype_stack_question::start_attempt()) — and different seeds genuinely
 * instantiate different CAS-substituted text for the same slot. The memo
 * this plugin adds on top of get_question($slot) (see that method's own
 * comment) was originally keyed by slot number alone, which is only correct
 * if every attempt at a slot shares one seed.
 *
 * This was verified as a real, live bug, not just a theoretical risk:
 * a census of question_attempts.variant across this project's own real
 * 48k-attempt test course data (courses 9/10/11/12) found 594 of 702
 * distinct question-usages actually use more than one distinct variant
 * across real student attempts. Directly checking a real 20-attempt sample
 * of one such quiz against each attempt's own independently-verified
 * instantiated text found the pre-fix code got **0 of 20** right (only
 * the first attempt processed for a given slot was ever correct; every
 * later attempt at that slot silently inherited the first one's text,
 * regardless of its own actual seed) — this was corrupting real Question
 * Analytics data for the majority of randomised STACK questions in the
 * course this whole session's performance work was benchmarked against.
 *
 * @package local_quizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class data_fetcher_variant_test extends \advanced_testcase {
    /**
     * Renders a STACK question's instantiated text the same way
     * data_fetcher.php's own (protected, so not called directly here)
     * render_stack_question_text() does — an independent implementation
     * of the rendering step, so this test's own ground truth doesn't rely
     * on the very code path it's meant to be checking.
     *
     * @param \question_definition $question
     * @return string
     */
    private function render_expected_text(\question_definition $question): string {
        if (!($question instanceof \qtype_stack_question) || empty($question->questiontextinstantiated)) {
            return $question->questiontext;
        }
        $processor = new \castext2_qa_processor(new \stack_outofcontext_process());
        return $question->questiontextinstantiated->get_rendered($processor);
    }

    /**
     * Creates a course with one quiz containing the official qtype_stack
     * 'test1' fixture — a real randomised question (questionvariables
     * includes rand()) with the random values substituted directly into
     * questiontext, so its instantiated text genuinely differs by seed —
     * then finishes two attempts by two different users, forced onto two
     * different variants for that question's slot via
     * quiz_start_new_attempt()'s $forcedvariantsbyslot parameter.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass[2], 3: string[2]}
     *         [$course, $quiz, [$user1, $user2], [expectedtext1, expectedtext2]]
     */
    private function create_quiz_with_two_variant_attempts(): array {
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $quizgenerator = $dg->get_plugin_generator('mod_quiz');
        $questiongenerator = $dg->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();

        $quiz = $quizgenerator->create_instance([
            'course' => $course->id,
            'name' => 'Variant memo test quiz',
            'grade' => 100.0,
            'sumgrades' => 1,
        ]);
        $question = $questiongenerator->create_question('stack', 'test1', ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        $users = [$dg->create_user(), $dg->create_user()];
        $variants = [2, 137]; // Arbitrary, distinct — free randomisation means seed = variant directly.
        $expectedtexts = [];

        foreach ($users as $i => $user) {
            $quizobj = \mod_quiz\quiz_settings::create($quiz->id, $user->id);
            $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
            $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
            $timenow = time();
            $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $user->id);
            quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow, [], [1 => $variants[$i]]);

            // Ground truth, captured in this attempt's own freshly-built
            // $quba right now — the most direct possible check that this
            // fixture actually exercises two different seeds, independent
            // of anything quiz_attempt::create() does on reload later.
            $expectedtexts[$i] = $this->render_expected_text($quba->get_question(1));

            quiz_attempt_save_started($quizobj, $quba, $attempt);
            $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
            $attemptobj->process_finish($timenow, false);
        }

        return [$course, $quiz, $users, $expectedtexts];
    }

    public function test_question_text_is_cached_per_variant_not_just_per_slot(): void {
        $this->resetAfterTest(true);
        [$course, $quiz, $users, $expectedtexts] = $this->create_quiz_with_two_variant_attempts();

        // Fixture sanity check: if this ever stops producing two genuinely
        // different instantiated texts (e.g. the 'test1' fixture changes
        // upstream), the rest of this test can't actually exercise the bug
        // it exists to catch.
        $this->assertNotSame($expectedtexts[0], $expectedtexts[1],
            'Test fixture problem: the two forced variants produced identical instantiated text, ' .
            'so this test cannot distinguish correct per-variant caching from the old per-slot-only bug.');

        $records = \local_quizanalytics_quiz_data_fetcher::get_response_records_for_quiz($quiz, $course);
        $this->assertCount(2, $records);

        $byemail = [];
        foreach ($records as $row) {
            $byemail[$row['email']] = $row['question_1_text'];
        }

        foreach ($users as $i => $user) {
            $this->assertSame(
                $expectedtexts[$i],
                $byemail[$user->email] ?? null,
                "Attempt for {$user->email} (variant-forced) did not get its own instantiated question text — " .
                "this is the per-slot-only memo bug: an attempt silently inherited a different attempt's " .
                "cached text for the same slot instead of its own seed's."
            );
        }

        // The two rows must differ from each other too — belt and braces
        // against a fix that happens to make both rows equal some other
        // (still wrong) shared value.
        $this->assertNotSame($byemail[$users[0]->email], $byemail[$users[1]->email]);
    }

    public function test_single_variant_still_shares_one_memo_entry(): void {
        // The whole point of keying by variant instead of dropping the memo
        // entirely: attempts that DO share a seed for a slot (the common
        // case on real course data — most students still land on the same
        // variant when a question has few deployed variants relative to
        // class size) should still only pay for one get_question() call per
        // distinct variant, not one per attempt. Not a performance
        // assertion here (that's already covered by this session's own
        // profiling work) — just confirming two same-variant attempts both
        // still get the one shared, correct text.
        $this->resetAfterTest(true);
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $quizgenerator = $dg->get_plugin_generator('mod_quiz');
        $questiongenerator = $dg->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();

        $quiz = $quizgenerator->create_instance([
            'course' => $course->id, 'name' => 'Same variant quiz', 'grade' => 100.0, 'sumgrades' => 1,
        ]);
        $question = $questiongenerator->create_question('stack', 'test1', ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        $users = [$dg->create_user(), $dg->create_user()];
        foreach ($users as $user) {
            $quizobj = \mod_quiz\quiz_settings::create($quiz->id, $user->id);
            $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
            $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
            $timenow = time();
            $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $user->id);
            quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow, [], [1 => 5]); // Same variant for both.
            quiz_attempt_save_started($quizobj, $quba, $attempt);
            $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
            $attemptobj->process_finish($timenow, false);
        }

        $records = \local_quizanalytics_quiz_data_fetcher::get_response_records_for_quiz($quiz, $course);
        $this->assertCount(2, $records);
        $texts = array_unique(array_column($records, 'question_1_text'));
        $this->assertCount(1, $texts, 'Two attempts sharing the same variant should show identical, shared text.');
    }
}
