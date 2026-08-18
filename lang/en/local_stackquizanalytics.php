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
 * English language strings for local_stackquizanalytics.
 *
 * Skeleton phase — just enough to install. The full merged string set
 * (local_quizanalytics's + local_stackanalytics's, minus the handful of
 * overlapping keys reconciled into one value) lands in the phase that
 * unifies the language file, once both halves' content exists.
 *
 * @package local_stackquizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'STACK Quiz & Model Analytics';
$string['stackquizanalytics:view'] = 'View STACK quiz analytics, models, and diagnostics';
$string['privacy:metadata'] = 'The STACK Quiz & Model Analytics plugin does not store any personal data of its own. It reads finished quiz attempts, question responses, grades, and log events directly from Moodle\'s own database (mod_quiz, the question engine, grade_grades, and logstore_standard_log) at request/calculation time, all of which are already covered by their own privacy providers.';
