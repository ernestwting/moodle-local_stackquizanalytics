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
 * The Diagnostics Analytics section: the non-ML Diagnostics Dashboard
 * (seed-bias ANOVA and bloated-PRT-tree coverage), computed directly from
 * attempt data rather than through the Analytics API's ML machinery — these
 * are not predictions the way Model 1/Model 2's indicator readings are
 * framed, just direct calculations, so this doesn't share Model Analytics's
 * "trained model" framing at all. Used to live on models.php behind a
 * View: selector; split into its own section so it reads as what it is —
 * statistical reports, not a model — rather than a third option alongside
 * two actual models.
 *
 * Reached from the "Section:" selector at the top of every page in this
 * plugin, or directly via
 * /local/stackquizanalytics/diagnosticsanalytics.php?id=<courseid>.
 *
 * @package local_stackquizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/stackquizanalytics/classes/section_selector.php');

use local_stackquizanalytics\stack\local\stack_course_helper;
use local_stackquizanalytics\stack\diagnostics\concept_dependency_report;
use local_stackquizanalytics\stack\analytics\report\diagnostics_report;
use local_stackquizanalytics\stack\output\dashboard_renderer;

$courseid = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($course->id);
require_capability('local/stackquizanalytics:view', $context);

$PAGE->set_url('/local/stackquizanalytics/diagnosticsanalytics.php', ['id' => $courseid]);
$PAGE->set_pagelayout('report');
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . get_string('dashboardtitle', 'local_stackquizanalytics'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pagemaintitle', 'local_stackquizanalytics'));
echo local_stackquizanalytics_section_selector::render($courseid, 'diagnostics');

// Collapsed by default (native <details>, no JS needed) — mirrors the same
// top-of-page intro box every other section shows.
echo html_writer::tag(
    'details',
    html_writer::tag('summary', get_string('diagnosticspageintrosummary', 'local_stackquizanalytics'))
        . html_writer::div(get_string('diagnosticspageintro', 'local_stackquizanalytics'), 'mt-2'),
    ['class' => 'alert alert-info']
);

$viewablecourses = stack_course_helper::get_viewable_courses();
if (count($viewablecourses) > 1) {
    $courseoptions = [];
    foreach ($viewablecourses as $viewablecourse) {
        $courseoptions[$viewablecourse->id] = format_string($viewablecourse->fullname);
    }
    $courseselector = new single_select(
        new moodle_url('/local/stackquizanalytics/diagnosticsanalytics.php'),
        'id',
        $courseoptions,
        $courseid,
        null
    );
    $courseselector->label = get_string('courseselectorlabel', 'local_stackquizanalytics');
    echo html_writer::div($OUTPUT->render($courseselector), 'd-inline-block mr-4 mb-3');
}

$slots = stack_course_helper::get_course_stack_slots($courseid);

if (empty($slots)) {
    echo $OUTPUT->notification(get_string('errornostackactivity', 'local_stackquizanalytics'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag(
    'details',
    html_writer::tag('summary', get_string('responsibleusesummary', 'local_stackquizanalytics'))
        . html_writer::div(get_string('responsibleusecallout', 'local_stackquizanalytics'), 'mt-2'),
    ['class' => 'alert alert-warning mt-2']
);

// Per-question, like Model 2 — narrows the report to one quiz at a time.
$quizid = optional_param('quizid', 0, PARAM_INT);

// Shares its user preference and lang string with every other section's own
// anonymize toggle — one teacher preference across the whole plugin, not a
// separate on/off switch per page.
$anonymizeparam = optional_param('anonymize', null, PARAM_INT);
if ($anonymizeparam !== null) {
    set_user_preference('local_stackquizanalytics_anonymize', (bool) $anonymizeparam);
    $anonymize = (bool) $anonymizeparam;
} else {
    $anonymize = (bool) get_user_preferences('local_stackquizanalytics_anonymize', false);
}

$quiznames = $DB->get_records_menu('quiz', ['course' => $courseid], '', 'id, name');
$slotsperquiz = [];
foreach ($slots as $slot) {
    $slotsperquiz[(int) $slot->quizid] = ($slotsperquiz[(int) $slot->quizid] ?? 0) + 1;
}
if (count($slotsperquiz) > 1) {
    // Each option names both the quiz and how many STACK questions it has,
    // so the list reads as "this quiz, with this much STACK content" rather
    // than a bare, unfamiliar-looking name.
    $quizoptions = [];
    foreach ($slotsperquiz as $slotquizid => $questioncount) {
        $quizname = format_string($quiznames[$slotquizid] ?? get_string('unknownquiz', 'local_stackquizanalytics'));
        $quizoptions[$slotquizid] = get_string('quizoptionlabel', 'local_stackquizanalytics', (object) [
            'name' => $quizname,
            'count' => $questioncount,
        ]);
    }
    asort($quizoptions);
    $quizselector = new single_select(
        new moodle_url('/local/stackquizanalytics/diagnosticsanalytics.php', ['id' => $courseid]),
        'quizid',
        $quizoptions,
        $quizid,
        [0 => get_string('allquizzes', 'local_stackquizanalytics')]
    );
    $quizselector->label = get_string('quizselectorlabel', 'local_stackquizanalytics');
    echo html_writer::div($OUTPUT->render($quizselector), 'd-inline-block mb-3');
} else {
    $quizid = 0; // Only one quiz in this course — nothing to filter.
}

echo $OUTPUT->heading(get_string('diagnosticsheading', 'local_stackquizanalytics'), 3);

$diagnosticsintrobody = html_writer::tag('p', get_string('diagnosticsintro', 'local_stackquizanalytics'));
if (!concept_dependency_report::is_available()) {
    $diagnosticsintrobody .= html_writer::tag(
        'p',
        get_string('conceptdependencynote', 'local_stackquizanalytics'),
        ['class' => 'text-muted small mb-0']
    );
}
echo html_writer::tag(
    'details',
    html_writer::tag('summary', get_string('diagnosticsintrosummary', 'local_stackquizanalytics'))
        . html_writer::div($diagnosticsintrobody, 'mt-2'),
    ['class' => 'mb-3']
);

echo dashboard_renderer::render_diagnostics_section(diagnostics_report::build($courseid, $quizid !== 0 ? $quizid : null));

echo dashboard_renderer::render_pdf_form(
    new moodle_url('/local/stackquizanalytics/diagnosticsanalyticspdf.php'),
    $courseid,
    $quizid !== 0 ? $quizid : null,
    $anonymize,
    [
        'diagnostics' => 'diagnosticsheading',
    ]
);

echo $OUTPUT->footer();
