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
 * Admin settings for local_stackquizanalytics, merging both source
 * plugins' settings onto one page.
 *
 * Unlike a quiz-report subplugin's settings.php (where core pre-creates an
 * admin_settingpage before including the file), core\plugininfo\
 * local::load_settings() just include()s this file directly with $ADMIN
 * available and nothing else — so a local_ plugin must create its own
 * admin_settingpage and add it to the tree itself. Verified against
 * public/lib/classes/plugininfo/local.php in the installed Moodle core.
 *
 * computetimelimit (from local_quizanalytics) governs how long PHP itself
 * is allowed to spend on the Quiz Analytics course-wide computation, the
 * one path whose cost scales with the whole course. The other three (from
 * local_stackanalytics) are hardcoded constants worth putting in an
 * administrator's hands rather than leaving as code-only defaults: the
 * Model 2 proxy-label threshold (a real methodological choice, not just a
 * tuning knob), the bloated-tree "low traffic" floor, and the help-seeking
 * lookback window. Each is read via get_config() with the original class
 * constant kept as fallback default, so an unconfigured site behaves
 * exactly as either source plugin did before this merge.
 *
 * @package local_stackquizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_stackquizanalytics',
        get_string('pluginname', 'local_stackquizanalytics')
    );
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_stackquizanalytics/computetimelimit',
        get_string('computetimelimit', 'local_stackquizanalytics'),
        get_string('computetimelimit_desc', 'local_stackquizanalytics'),
        120,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_stackquizanalytics/questionneedsreviewthreshold',
        get_string('questionneedsreviewthreshold', 'local_stackquizanalytics'),
        get_string('questionneedsreviewthreshold_desc', 'local_stackquizanalytics'),
        \local_stackquizanalytics\stack\analytics\target\question_needs_review::DEFAULT_PASSRATE_THRESHOLD,
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_stackquizanalytics/lowtrafficfloor',
        get_string('lowtrafficfloor', 'local_stackquizanalytics'),
        get_string('lowtrafficfloor_desc', 'local_stackquizanalytics'),
        \local_stackquizanalytics\stack\diagnostics\bloated_tree_report::LOW_TRAFFIC_FLOOR,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_stackquizanalytics/helpseekinglookback',
        get_string('helpseekinglookback', 'local_stackquizanalytics'),
        get_string('helpseekinglookback_desc', 'local_stackquizanalytics'),
        \local_stackquizanalytics\stack\analytics\indicator\help_seeking_gap::LOOKBACK_SECONDS,
        PARAM_INT
    ));
}
