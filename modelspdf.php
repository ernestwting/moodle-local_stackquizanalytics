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
 * Streams a PDF export of the Model & Diagnostics Analytics dashboard —
 * whichever of the Model 1 / Model 2 / Diagnostics sections were ticked on
 * the "Download PDF" form (dashboard_renderer::render_pdf_form()),
 * re-derived server-side from the same report-builder classes models.php
 * uses, respecting the same quiz filter. A separate entry point from
 * models.php, gated by the same local/stackquizanalytics:view capability.
 * Named modelspdf.php (rather than pdf.php) so it doesn't collide with the
 * Quiz Analytics section's own pdf.php in the same plugin.
 *
 * @package local_stackquizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_stackquizanalytics\stack\analytics\pdf_content;
use local_stackquizanalytics\stack\analytics\pdf_builder;

$courseid = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($course->id);
require_capability('local/stackquizanalytics:view', $context);

// Building all three sections' worth of indicators for a large course can
// run long — each indicator is its own set of DB queries per sample, with
// no batching (see model1_report.php/model2_report.php's own docblocks).
core_php_time_limit::raise(120);

$quizid = optional_param('quizid', 0, PARAM_INT);
$sections = optional_param_array('sections', ['model1', 'model2', 'diagnostics'], PARAM_ALPHANUM);

$payload = [];
if (in_array('model1', $sections, true)) {
    $payload[] = pdf_content::build_model1_section($courseid);
}
if (in_array('model2', $sections, true)) {
    $payload[] = pdf_content::build_model2_section($courseid, $quizid !== 0 ? $quizid : null);
}
if (in_array('diagnostics', $sections, true)) {
    $payload[] = pdf_content::build_diagnostics_section($courseid, $quizid !== 0 ? $quizid : null);
}

if (empty($payload)) {
    throw new \moodle_exception('pdfnosections', 'local_stackquizanalytics');
}

$pdfbytes = pdf_builder::build($course->fullname, $payload);
$filename = clean_filename($course->shortname . '-stack-analytics.pdf');

send_file($pdfbytes, $filename, 0, 0, true, true, 'application/pdf');
