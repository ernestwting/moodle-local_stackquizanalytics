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
 * Builds plain-text {columns, rows} table payloads for the PDF export, one
 * per dashboard section — re-derived directly from the same report-builder
 * classes the on-screen dashboard uses (model1_report/model2_report/
 * diagnostics_report), not scraped from rendered HTML. Cell text reuses the
 * exact same get_string() lookups dashboard_renderer.php's badges/sentences
 * do, just composed as plain "Label — sentence" text instead of HTML,
 * since a PDF table cell has no badge styling to render into.
 *
 * The Diagnostics section is deliberately summary-only here (question, quiz,
 * one line each for seed-bias/branch-coverage) rather than the full
 * per-branch breakdown table dashboard_renderer.php's collapsed detail view
 * shows — the same "condensed for readability" reasoning that drove that
 * on-screen redesign applies at least as much to a printed report.
 *
 * @package local_stackquizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackquizanalytics\stack\analytics;

use local_stackquizanalytics\stack\analytics\report\model1_report;
use local_stackquizanalytics\stack\analytics\report\model2_report;
use local_stackquizanalytics\stack\analytics\report\diagnostics_report;

defined('MOODLE_INTERNAL') || die();

/**
 * Re-derives each dashboard section's content, in the plain-text table shape pdf_builder.php draws.
 */
class pdf_content {
    /** @var array<string, string> indicator key => lang string suffix, matching dashboard_renderer::MODEL1_INDICATORS. */
    const MODEL1_INDICATORS = [
        'gradetrajectory' => 'gradetrajectory',
        'responselatencyanomaly' => 'responselatencyanomaly',
        'disengagemententropy' => 'disengagemententropy',
        'helpseekinggap' => 'helpseekinggap',
        'feedbackrevisiondistance' => 'feedbackrevisiondistance',
    ];

    /** @var array<string, string> indicator key => lang string suffix, matching dashboard_renderer::MODEL2_INDICATORS. */
    const MODEL2_INDICATORS = [
        'questiondifficultyirt' => 'questiondifficultyirt',
        'syntaxerrorrate' => 'syntaxerrorrate',
        'unreachednoderatio' => 'unreachednoderatio',
        'feedbackineffectiveness' => 'feedbackineffectiveness',
    ];

    /**
     * The Model 1 section payload for the PDF export.
     *
     * @param int $courseid
     * @return array{title: string, columns: string[], rows: array[], shown: int, total: int, truncated: bool}
     */
    public static function build_model1_section(int $courseid): array {
        $report = model1_report::build($courseid);

        $columns = array_merge(
            [
                get_string('columnstudent', 'local_stackquizanalytics'),
                get_string('columncurrentstatus', 'local_stackquizanalytics'),
            ],
            array_map(
                fn($stringsuffix) => get_string('indicator:' . $stringsuffix, 'local_stackquizanalytics'),
                array_values(self::MODEL1_INDICATORS)
            )
        );

        $rows = [];
        foreach ($report->rows as $row) {
            $cells = [$row->fullname, self::gradestatus_text($row->gradestatus)];
            foreach (self::MODEL1_INDICATORS as $indicatorkey => $stringsuffix) {
                $cells[] = self::indicator_text($row->indicators[$indicatorkey], 'model1sentence_' . $stringsuffix);
            }
            $rows[] = $cells;
        }

        return [
            'title' => get_string('model1heading', 'local_stackquizanalytics'),
            'columns' => $columns,
            'rows' => $rows,
            'shown' => count($rows),
            'total' => $report->total,
            'truncated' => $report->truncated,
        ];
    }

    /**
     * The Model 2 section payload for the PDF export.
     *
     * @param int $courseid
     * @param int|null $quizid
     * @return array{title: string, columns: string[], rows: array[], shown: int, total: int, truncated: bool}
     */
    public static function build_model2_section(int $courseid, ?int $quizid): array {
        $report = model2_report::build($courseid, $quizid);

        $columns = array_merge(
            [
                get_string('columnquestion', 'local_stackquizanalytics'),
                get_string('columncurrentstatus', 'local_stackquizanalytics'),
            ],
            array_map(
                fn($stringsuffix) => get_string('indicator:' . $stringsuffix, 'local_stackquizanalytics'),
                array_values(self::MODEL2_INDICATORS)
            )
        );

        $rows = [];
        foreach ($report->rows as $row) {
            $cells = [
                $row->questionname . ' (' . $row->quizname . ')',
                self::needsreview_text($row->needsreview),
            ];
            foreach (self::MODEL2_INDICATORS as $indicatorkey => $stringsuffix) {
                $cells[] = self::indicator_text($row->indicators[$indicatorkey], 'model2sentence_' . $stringsuffix);
            }
            $rows[] = $cells;
        }

        return [
            'title' => get_string('model2heading', 'local_stackquizanalytics'),
            'columns' => $columns,
            'rows' => $rows,
            'shown' => count($rows),
            'total' => $report->total,
            'truncated' => $report->truncated,
        ];
    }

    /**
     * The Diagnostics section payload for the PDF export.
     *
     * @param int $courseid
     * @param int|null $quizid
     * @return array{title: string, columns: string[], rows: array[], shown: int, total: int, truncated: bool}
     */
    public static function build_diagnostics_section(int $courseid, ?int $quizid): array {
        $report = diagnostics_report::build($courseid, $quizid);

        $columns = [
            get_string('columnquestion', 'local_stackquizanalytics'),
            get_string('seedbiasheading', 'local_stackquizanalytics'),
            get_string('bloatedtreeheading', 'local_stackquizanalytics'),
        ];

        $rows = [];
        foreach ($report->rows as $row) {
            $rows[] = [
                $row->questionname . ' (' . $row->quizname . ')',
                self::seedbias_text($row->seedbias),
                self::bloatedtree_text($row->bloatedtree),
            ];
        }

        return [
            'title' => get_string('diagnosticsheading', 'local_stackquizanalytics'),
            'columns' => $columns,
            'rows' => $rows,
            'shown' => count($rows),
            'total' => $report->total,
            'truncated' => $report->truncated,
        ];
    }

    /**
     * Plain-text "Label — sentence" for one indicator cell.
     *
     * @param \stdClass|null $result an indicator's compute_for_sample() return value
     * @param string $sentencestringkey the lang string key for the facts sentence, taking $result->summary as $a
     * @return string
     */
    private static function indicator_text(?\stdClass $result, string $sentencestringkey): string {
        if ($result === null) {
            return get_string('notenoughdata', 'local_stackquizanalytics');
        }
        $label = get_string('band_' . $result->band, 'local_stackquizanalytics');
        $sentence = get_string($sentencestringkey, 'local_stackquizanalytics', (object) $result->summary);
        return $label . ' — ' . $sentence;
    }

    /**
     * Plain-text "Current status" cell for one Model 1 row.
     *
     * @param \stdClass $gradestatus {gradepasspercent, gradepercent, atrisk} — see model1_report::get_grade_status()
     * @return string
     */
    private static function gradestatus_text(\stdClass $gradestatus): string {
        if ($gradestatus->gradepasspercent === null) {
            return get_string('gradestatusnothreshold', 'local_stackquizanalytics');
        }
        if ($gradestatus->gradepercent === null) {
            return get_string('gradestatusnogradeyet', 'local_stackquizanalytics');
        }
        $stringkey = $gradestatus->atrisk ? 'gradestatusatrisk' : 'gradestatuspassing';
        return get_string($stringkey, 'local_stackquizanalytics', (object) [
            'grade' => $gradestatus->gradepercent,
            'gradepass' => $gradestatus->gradepasspercent,
        ]);
    }

    /**
     * Plain-text "Current status" cell for one Model 2 row.
     *
     * @param \stdClass|null $needsreview question_needs_review::compute_for_sample()'s return value
     * @return string
     */
    private static function needsreview_text(?\stdClass $needsreview): string {
        if ($needsreview === null) {
            return get_string('notenoughdata', 'local_stackquizanalytics');
        }
        $stringkey = $needsreview->needsreview ? 'needsreviewyes' : 'needsreviewno';
        return get_string($stringkey, 'local_stackquizanalytics', (object) [
            'passpercent' => $needsreview->passpercent,
            'thresholdpercent' => $needsreview->thresholdpercent,
        ]);
    }

    /**
     * Plain-text "Label — sentence" for one Diagnostics row's seed-bias cell.
     *
     * @param \stdClass|null $seedbias diagnostics_report's seed-bias summary, or null
     * @return string
     */
    private static function seedbias_text(?\stdClass $seedbias): string {
        if ($seedbias === null) {
            return get_string('notenoughdata', 'local_stackquizanalytics');
        }
        $label = get_string('band_' . $seedbias->band, 'local_stackquizanalytics');
        $sentence = get_string('diagnosticsseedbiassentence', 'local_stackquizanalytics', (object) [
            'etasquared' => format_float($seedbias->anova->etasquared, 3),
            'magnitude' => get_string('etamagnitude_' . $seedbias->magnitude, 'local_stackquizanalytics'),
        ]);
        return $label . ' — ' . $sentence;
    }

    /**
     * Plain-text "Label — sentence" for one Diagnostics row's branch-coverage cell.
     *
     * @param \stdClass|null $bloatedtree diagnostics_report's branch-coverage summary, or null
     * @return string
     */
    private static function bloatedtree_text(?\stdClass $bloatedtree): string {
        if ($bloatedtree === null) {
            return get_string('notenoughdata', 'local_stackquizanalytics');
        }
        $label = get_string('band_' . $bloatedtree->band, 'local_stackquizanalytics');
        $sentence = get_string('diagnosticsbloatedtreesentence', 'local_stackquizanalytics', (object) [
            'unreached' => $bloatedtree->unreachedcount,
            'total' => $bloatedtree->totalbranches,
        ]);
        return $label . ' — ' . $sentence;
    }
}
