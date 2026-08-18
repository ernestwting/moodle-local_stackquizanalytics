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
 * The Question Analytics section: a single STACK quiz's Question Analytics
 * or Solution Process Visualization (?view=question|solutionprocess picks
 * which, defaulting to Question Analytics), picked via the required quiz
 * selector at the top of the page. Split out of what used to be
 * index.php's own ?quizid=... drill-down branch — Quiz Analytics
 * (index.php) is now the course-wide comparison only, and this is where
 * per-quiz analytics live instead.
 *
 * Reached from the "Analytics" link this plugin adds to each STACK quiz's
 * own settings menu (lib.php's
 * local_stackquizanalytics_extend_settings_navigation(), which links
 * straight here with &quizid= already set), from the "Section:" selector
 * at the top of every page in this plugin, or directly via
 * /local/stackquizanalytics/questionanalytics.php?id=<courseid>.
 *
 * @package local_stackquizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/stackquizanalytics/classes/quiz/data_fetcher.php');
require_once($CFG->dirroot . '/local/stackquizanalytics/classes/quiz/api_client.php');
require_once($CFG->dirroot . '/local/stackquizanalytics/classes/quiz/cache_helper.php');
require_once($CFG->dirroot . '/local/stackquizanalytics/classes/section_selector.php');

use local_stackquizanalytics\quiz\output\sections_output_helper;
use local_stackquizanalytics\stack\local\stack_course_helper;

$courseid = required_param('id', PARAM_INT);
$quizid   = optional_param('quizid', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($course->id);
require_capability('local/stackquizanalytics:view', $context);

$PAGE->set_url('/local/stackquizanalytics/questionanalytics.php', ['id' => $courseid, 'quizid' => $quizid]);
$PAGE->set_pagelayout('report');
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . get_string('pagetitle', 'local_stackquizanalytics'));
$PAGE->set_heading($course->fullname);

$stackquizzes = local_stackquizanalytics_quiz_data_fetcher::get_course_stack_quizzes($course->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pagemaintitle', 'local_stackquizanalytics'));
echo local_stackquizanalytics_section_selector::render($courseid, 'question');

// The course selector: same single_select pattern and shared
// courseselectorlabel string every other section's own course selector
// uses, so a teacher with STACK activity in more than one course can jump
// between them from any section.
$viewablecourses = stack_course_helper::get_viewable_courses();
if (count($viewablecourses) > 1) {
    $courseoptions = [];
    foreach ($viewablecourses as $viewablecourse) {
        $courseoptions[$viewablecourse->id] = format_string($viewablecourse->fullname);
    }
    $courseselector = new single_select(
        new moodle_url('/local/stackquizanalytics/questionanalytics.php'),
        'id',
        $courseoptions,
        $courseid,
        null
    );
    $courseselector->label = get_string('courseselectorlabel', 'local_stackquizanalytics');
    echo html_writer::div($OUTPUT->render($courseselector), 'd-inline-block mr-4 mb-3');
}

if (empty($stackquizzes)) {
    echo $OUTPUT->notification(get_string('nostackquizzes', 'local_stackquizanalytics'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

// This section's whole purpose is per-quiz drill-down, so — unlike the old
// combined index.php, where 0 meant "show the course-wide view instead" —
// landing here with no quiz picked defaults to the first STACK quiz in the
// course rather than showing an empty "pick one" state.
if ($quizid === 0 || !array_key_exists($quizid, $stackquizzes)) {
    $quizid = (int) array_key_first($stackquizzes);
}

$selectoptions = [];
foreach ($stackquizzes as $quiz) {
    $selectoptions[$quiz->id] = $quiz->name;
}

$quizselector = new single_select(
    new moodle_url('/local/stackquizanalytics/questionanalytics.php', ['id' => $courseid]),
    'quizid',
    $selectoptions,
    $quizid,
    null
);
$quizselector->label = get_string('quizselectlabel', 'local_stackquizanalytics');
echo html_writer::div($OUTPUT->render($quizselector), 'd-inline-block mb-4');

$colorblind = sections_output_helper::resolve_colorblind_mode();
$anonymize = sections_output_helper::resolve_anonymize_mode();
echo sections_output_helper::render_options_toggles($colorblind, $anonymize);

$client = new local_stackquizanalytics_quiz_api_client();

$selectedquiz = $stackquizzes[$quizid];

echo $OUTPUT->heading($selectedquiz->name, 3, 'main mt-4 mb-3');

// Cheap fingerprint first — a cache hit below skips the expensive
// per-attempt DB fetch entirely. $records is fetched lazily (at most once)
// since either view below might independently need it on its own cache
// miss.
$stats = local_stackquizanalytics_quiz_cache_helper::stats_for_quiz($selectedquiz);
if ($stats->count === 0) {
    echo $OUTPUT->notification(get_string('noattempts', 'local_stackquizanalytics'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

$records = null;
$fetchrecords = function () use (&$records, $selectedquiz, $course): array {
    return $records ??= local_stackquizanalytics_quiz_data_fetcher::get_response_records_for_quiz($selectedquiz, $course);
};

// The "View:" sub-selector — only the selected view's data is ever
// fetched/computed (a plain GET reload, not a client-side tab swap that
// would need both already computed).
$view = optional_param('view', 'question', PARAM_ALPHA);
if (!in_array($view, ['question', 'solutionprocess'], true)) {
    $view = 'question';
}
$PAGE->url->param('view', $view);
echo sections_output_helper::render_view_selector_form($view);

if ($view === 'question') {
    $qacache = cache::make('local_stackquizanalytics', 'questionanalysis');
    $qakey = local_stackquizanalytics_quiz_cache_helper::build_key(
        $selectedquiz->id,
        $stats->fingerprint,
        $colorblind,
        $anonymize
    );
    $result = $qacache->get($qakey);
    if ($result === false) {
        $result = $client->analyze($selectedquiz->name, $fetchrecords(), $colorblind, $anonymize);
        if ($result !== null) {
            $qacache->set($qakey, $result);
        }
    }

    if ($result === null) {
        echo $OUTPUT->notification(get_string('servererror', 'local_stackquizanalytics'), 'notifyproblem');
        echo $OUTPUT->footer();
        exit;
    }

    echo sections_output_helper::render_containers('qa');
    echo sections_output_helper::render_vendor_and_payload('qa', $result);

    echo $OUTPUT->heading(get_string('generatepdfheading', 'local_stackquizanalytics'), 3, 'main mt-4 mb-3');
    echo sections_output_helper::render_pdf_form(
        new moodle_url('/local/stackquizanalytics/questionanalyticspdf.php'),
        [
            'id' => $courseid,
            'kind' => 'question',
            'quizid' => $quizid,
            'colorblind' => $colorblind ? 1 : 0,
            'anonymize' => $anonymize ? 1 : 0,
        ],
        $client->report_sections('question'),
        get_string('downloadpdfbutton', 'local_stackquizanalytics'),
        'qa-pdf',
        'qa'
    );
} else {
    // Solution Process Visualization.
    $metacache = cache::make('local_stackquizanalytics', 'solutionprocessmeta');
    $metakey = local_stackquizanalytics_quiz_cache_helper::build_key($selectedquiz->id, $stats->fingerprint, $anonymize);
    $meta = $metacache->get($metakey);
    if ($meta === false) {
        $meta = $client->solution_process_meta($selectedquiz->name, $fetchrecords(), $anonymize);
        if ($meta !== null) {
            $metacache->set($metakey, $meta);
        }
    }

    if ($meta === null) {
        echo $OUTPUT->notification(get_string('servererror', 'local_stackquizanalytics'), 'notifyproblem');
        echo $OUTPUT->footer();
        exit;
    }
    if (empty($meta['questions'])) {
        echo $OUTPUT->notification(get_string('nostackquestions', 'local_stackquizanalytics'), 'notifymessage');
        echo $OUTPUT->footer();
        exit;
    }

    // Resolve the current question/part/student selection, validating
    // every GET param against what meta() actually returned rather than
    // trusting the request.
    $questionnames = array_column($meta['questions'], 'name');
    $spvquestion = optional_param('spvquestion', $questionnames[0], PARAM_RAW);
    if (!in_array($spvquestion, $questionnames, true)) {
        $spvquestion = $questionnames[0];
    }

    $spvpartsforquestion = 1;
    foreach ($meta['questions'] as $q) {
        if ($q['name'] === $spvquestion) {
            $spvpartsforquestion = max(1, (int) $q['parts']);
            break;
        }
    }
    $spvpart = optional_param('spvpart', 1, PARAM_INT);
    if ($spvpart < 1 || $spvpart > $spvpartsforquestion) {
        $spvpart = 1;
    }

    $spvstudentid = optional_param('spvstudent', '', PARAM_RAW);
    if ($spvstudentid !== '') {
        $spvvalidstudent = false;
        foreach ($meta['students'] as $s) {
            if ($s['id'] === $spvstudentid) {
                $spvvalidstudent = true;
                break;
            }
        }
        if (!$spvvalidstudent) {
            $spvstudentid = '';
        }
    }

    $PAGE->url->params([
        'spvquestion' => $spvquestion,
        'spvpart'     => $spvpart,
        'spvstudent'  => $spvstudentid,
    ]);

    echo sections_output_helper::render_solutionprocess_selector_form(
        $PAGE->url,
        $meta,
        $spvquestion,
        $spvpartsforquestion,
        $spvpart,
        $spvstudentid
    );

    $resultcache = cache::make('local_stackquizanalytics', 'solutionprocess');
    $resultkey = local_stackquizanalytics_quiz_cache_helper::build_key(
        $selectedquiz->id,
        $stats->fingerprint,
        $spvquestion,
        $spvpart,
        $spvstudentid,
        $colorblind,
        $anonymize
    );
    $result = $resultcache->get($resultkey);
    if ($result === false) {
        $result = $client->solution_process_analyze(
            $selectedquiz->name,
            $fetchrecords(),
            $spvquestion,
            $spvpart,
            $spvstudentid !== '' ? $spvstudentid : null,
            $colorblind,
            $anonymize
        );
        if ($result !== null) {
            $resultcache->set($resultkey, $result);
        }
    }

    if ($result === null) {
        echo $OUTPUT->notification(get_string('servererror', 'local_stackquizanalytics'), 'notifyproblem');
        echo $OUTPUT->footer();
        exit;
    }

    echo sections_output_helper::render_containers('spv');
    echo sections_output_helper::render_vendor_and_payload('spv', $result);

    echo $OUTPUT->heading(get_string('generatepdfheading', 'local_stackquizanalytics'), 3, 'main mt-4 mb-3');
    echo sections_output_helper::render_pdf_form(
        new moodle_url('/local/stackquizanalytics/questionanalyticspdf.php'),
        [
            'id'          => $courseid,
            'kind'        => 'solutionprocess',
            'quizid'      => $quizid,
            'spvquestion' => $spvquestion,
            'spvpart'     => $spvpart,
            'colorblind'  => $colorblind ? 1 : 0,
            'anonymize'   => $anonymize ? 1 : 0,
        ],
        $client->report_sections('solutionprocess'),
        get_string('downloadpdfbutton', 'local_stackquizanalytics'),
        'spv-pdf',
        'spv'
    );
}

echo $OUTPUT->footer();
