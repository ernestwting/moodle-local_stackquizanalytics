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
 * The Quiz Analytics section: course-wide cross-quiz comparison across
 * every STACK quiz in a course. For a single quiz's own Question Analytics
 * or Solution Process Visualization, see questionanalytics.php — that used
 * to live on this same page behind a ?quizid= param, split out into its
 * own section so picking a quiz here doesn't silently jump you into a
 * different kind of report.
 *
 * Reached from the course's secondary navigation "Analytics" entry (see
 * lib.php's local_quizanalytics_extend_navigation_course()), the
 * "Section:" selector at the top of every page in this plugin, or directly
 * via /local/quizanalytics/index.php?id=<courseid>. Kept as
 * index.php (rather than a more specifically-named file, like the other
 * three sections) since this is the plugin's own default landing page —
 * both the course-nav link above and a bare
 * /local/quizanalytics/ visit land here.
 *
 * @package local_quizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/data_fetcher.php');
require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/api_client.php');
require_once($CFG->dirroot . '/local/quizanalytics/classes/quiz/cache_helper.php');
require_once($CFG->dirroot . '/local/quizanalytics/classes/section_selector.php');

use local_quizanalytics\quiz\output\sections_output_helper;
use local_quizanalytics\stack\local\stack_course_helper;

$courseid = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($course->id);
require_capability('local/quizanalytics:view', $context);

$PAGE->set_url('/local/quizanalytics/index.php', ['id' => $courseid]);
$PAGE->set_pagelayout('report');
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . get_string('pagetitle', 'local_quizanalytics'));
$PAGE->set_heading($course->fullname);

$stackquizzes = local_quizanalytics_quiz_data_fetcher::get_course_stack_quizzes($course->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pagemaintitle', 'local_quizanalytics'));
echo local_quizanalytics_section_selector::render($courseid, 'quiz');

// The course selector: same single_select pattern and shared
// courseselectorlabel string every other section's own course selector
// uses, so a teacher with STACK activity in more than one course can jump
// between them from any section. Defaults to whichever course the
// "Analytics" link was opened from — $courseid comes straight from the
// required id param, and single_select's own $selected argument (passed
// as $courseid below) is what pre-selects that course in the dropdown,
// not any alphabetical/first-in-list default.
$viewablecourses = stack_course_helper::get_viewable_courses();
if (count($viewablecourses) > 1) {
    $courseoptions = [];
    foreach ($viewablecourses as $viewablecourse) {
        $courseoptions[$viewablecourse->id] = format_string($viewablecourse->fullname);
    }
    $courseselector = new single_select(
        new moodle_url('/local/quizanalytics/index.php'),
        'id',
        $courseoptions,
        $courseid,
        null
    );
    $courseselector->label = get_string('courseselectorlabel', 'local_quizanalytics');
    echo html_writer::div($OUTPUT->render($courseselector), 'd-inline-block mr-4 mb-3');
}

if (empty($stackquizzes)) {
    echo $OUTPUT->notification(get_string('nostackquizzes', 'local_quizanalytics'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

$colorblind = sections_output_helper::resolve_colorblind_mode();
$anonymize = sections_output_helper::resolve_anonymize_mode();
echo sections_output_helper::render_options_toggles($colorblind, $anonymize);

$client = new local_quizanalytics_quiz_api_client();

echo $OUTPUT->heading(get_string('coursewideheading', 'local_quizanalytics'), 3, 'main mb-3');

// Combines every STACK quiz's attempts into one computation — the one
// path in this plugin whose cost genuinely scales with the whole course
// rather than a single quiz, so it's the one raising PHP's own execution
// time limit (see settings.php).
core_php_time_limit::raise((int) get_config('local_quizanalytics', 'computetimelimit'));

// Cheap fingerprint across every STACK quiz in the course at once (the
// course-wide result depends on all of their attempts together) — the
// empty-course check below uses this instead of the expensive per-quiz
// fetch, and $byquiz itself is fetched lazily only on a cache miss.
$coursestats = local_quizanalytics_quiz_cache_helper::stats_for_quizzes($stackquizzes);
if ($coursestats->count === 0) {
    echo $OUTPUT->notification(get_string('nocourseattempts', 'local_quizanalytics'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

$fetchbyquiz = function () use ($course, $stackquizzes): array {
    $byquiz = local_quizanalytics_quiz_data_fetcher::get_course_response_records($course, $stackquizzes);
    return array_filter($byquiz, fn($records) => !empty($records));
};

// Grade-type selector for the Attempts-vs-Grades scatter plot — a plain
// GET-reload radio group, same convention as everywhere else in this
// plugin.
$gradetypeoptions = [
    'Highest Grade' => get_string('gradetypehighest', 'local_quizanalytics'),
    \local_quizanalytics\quiz\analytics\course_analysis::DEFAULT_GRADE_TYPE
        => get_string('gradetypeaverage', 'local_quizanalytics'),
    'Minimum Grade' => get_string('gradetypeminimum', 'local_quizanalytics'),
];
$gradetype = optional_param(
    'gradetype',
    \local_quizanalytics\quiz\analytics\course_analysis::DEFAULT_GRADE_TYPE,
    PARAM_RAW
);
if (!array_key_exists($gradetype, $gradetypeoptions)) {
    $gradetype = \local_quizanalytics\quiz\analytics\course_analysis::DEFAULT_GRADE_TYPE;
}
$PAGE->url->param('gradetype', $gradetype);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring(), 'class' => 'mb-4']);
foreach ($PAGE->url->params() as $name => $value) {
    if ($name === 'gradetype') {
        continue;
    }
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
}
echo html_writer::tag('span', get_string('gradetypelabel', 'local_quizanalytics') . ' ');
foreach ($gradetypeoptions as $value => $label) {
    $radioid = 'qw-gradetype-' . preg_replace('/[^a-z]/', '', strtolower($value));
    echo html_writer::empty_tag('input', array_merge([
        'type' => 'radio', 'name' => 'gradetype', 'value' => $value, 'id' => $radioid,
    ], $gradetype === $value ? ['checked' => 'checked'] : []));
    echo html_writer::label($label, $radioid, true, ['class' => 'mr-2 ml-1']);
}
echo ' ' . html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('gobutton', 'local_quizanalytics'), 'class' => 'btn btn-secondary btn-sm',
]);
echo html_writer::end_tag('form');

$qwcache = cache::make('local_quizanalytics', 'quizanalysiscoursewide');
$qwkey = local_quizanalytics_quiz_cache_helper::build_key(
    $courseid,
    $coursestats->fingerprint,
    $gradetype,
    $colorblind,
    $anonymize
);
$result = $qwcache->get($qwkey);
if ($result === false) {
    $result = $client->analyze_course($course->fullname, $fetchbyquiz(), $colorblind, $gradetype, $anonymize);
    if ($result !== null) {
        $qwcache->set($qwkey, $result);
    }
}

if ($result === null) {
    echo $OUTPUT->notification(get_string('servererror', 'local_quizanalytics'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

echo sections_output_helper::render_containers('qw');
echo sections_output_helper::render_vendor_and_payload('qw', $result);

echo $OUTPUT->heading(get_string('generatepdfheading', 'local_quizanalytics'), 3, 'main mt-4 mb-3');
echo sections_output_helper::render_pdf_form(
    new moodle_url('/local/quizanalytics/quizanalyticspdf.php'),
    ['id' => $courseid, 'colorblind' => $colorblind ? 1 : 0, 'anonymize' => $anonymize ? 1 : 0],
    $client->report_sections('quiz'),
    get_string('downloadpdfbutton', 'local_quizanalytics'),
    'qw-pdf',
    'qw'
);

echo $OUTPUT->footer();
