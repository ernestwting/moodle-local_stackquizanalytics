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
 * Admin settings for local_quizanalytics, merging both source
 * plugins' settings onto one page.
 *
 * Unlike a quiz-report subplugin's settings.php (where core pre-creates an
 * admin_settingpage before including the file), core\plugininfo\
 * local::load_settings() just include()s this file directly with $ADMIN
 * available and nothing else — so a local_ plugin must create its own
 * admin_settingpage and add it to the tree itself. Verified against
 * public/lib/classes/plugininfo/local.php in the installed Moodle core.
 *
 * computetimelimit (from the original local_quizanalytics) governs how long
 * PHP itself is allowed to spend on the Quiz Analytics course-wide
 * computation, the one path whose cost scales with the whole course. The
 * other three (from the original local_stackanalytics) are hardcoded
 * constants worth putting in an administrator's hands rather than leaving
 * as code-only defaults: the
 * Model 2 proxy-label threshold (a real methodological choice, not just a
 * tuning knob), the bloated-tree "low traffic" floor, and the help-seeking
 * lookback window. Each is read via get_config() with the original class
 * constant kept as fallback default, so an unconfigured site behaves
 * exactly as either source plugin did before this merge.
 *
 * @package local_quizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_quizanalytics',
        get_string('pluginname', 'local_quizanalytics')
    );
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_quizanalytics/computetimelimit',
        get_string('computetimelimit', 'local_quizanalytics'),
        get_string('computetimelimit_desc', 'local_quizanalytics'),
        120,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_quizanalytics/questionneedsreviewthreshold',
        get_string('questionneedsreviewthreshold', 'local_quizanalytics'),
        get_string('questionneedsreviewthreshold_desc', 'local_quizanalytics'),
        \local_quizanalytics\stack\analytics\target\question_needs_review::DEFAULT_PASSRATE_THRESHOLD,
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_quizanalytics/lowtrafficfloor',
        get_string('lowtrafficfloor', 'local_quizanalytics'),
        get_string('lowtrafficfloor_desc', 'local_quizanalytics'),
        \local_quizanalytics\stack\diagnostics\bloated_tree_report::LOW_TRAFFIC_FLOOR,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_quizanalytics/helpseekinglookback',
        get_string('helpseekinglookback', 'local_quizanalytics'),
        get_string('helpseekinglookback_desc', 'local_quizanalytics'),
        \local_quizanalytics\stack\analytics\indicator\help_seeking_gap::LOOKBACK_SECONDS,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_quizanalytics/parallelworkers',
        get_string('parallelworkers', 'local_quizanalytics'),
        get_string('parallelworkers_desc', 'local_quizanalytics'),
        4,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_quizanalytics/parallelworkermemory',
        get_string('parallelworkermemory', 'local_quizanalytics'),
        get_string('parallelworkermemory_desc', 'local_quizanalytics'),
        2048,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_quizanalytics/backgroundthreshold',
        get_string('backgroundthreshold', 'local_quizanalytics'),
        get_string('backgroundthreshold_desc', 'local_quizanalytics'),
        \local_quizanalytics\quiz\output\sections_output_helper::DEFAULT_BACKGROUND_THRESHOLD,
        PARAM_INT
    ));
}
