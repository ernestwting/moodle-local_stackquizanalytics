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
 * Renders the dashboard's Model 1 / Model 2 sections from the report-builder
 * classes' plain data (model1_report.php, model2_report.php) — keeps
 * index.php thin, matching local_quizanalytics's index.php/sections_output_helper
 * split.
 *
 * Every indicator cell follows the same shape regardless of which of the
 * nine indicators it's showing: a plain-language band badge ('good' /
 * 'neutral' / 'watch', from the indicator's own compute_for_sample()) plus a
 * one-line sentence built from that indicator's real-world facts — never the
 * bare [-1, 1] value a machine-learning model would actually consume.
 *
 * @package local_stackquizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackquizanalytics\stack\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Static HTML-building helpers for the Model 1 / Model 2 dashboard sections.
 */
class dashboard_renderer {
    /** @var array<string, string> indicator key => lang string suffix, in table-column order, for Model 1. */
    const MODEL1_INDICATORS = [
        'gradetrajectory' => 'gradetrajectory',
        'responselatencyanomaly' => 'responselatencyanomaly',
        'disengagemententropy' => 'disengagemententropy',
        'helpseekinggap' => 'helpseekinggap',
        'feedbackrevisiondistance' => 'feedbackrevisiondistance',
    ];

    /** @var array<string, string> indicator key => lang string suffix, in table-column order, for Model 2. */
    const MODEL2_INDICATORS = [
        'questiondifficultyirt' => 'questiondifficultyirt',
        'syntaxerrorrate' => 'syntaxerrorrate',
        'unreachednoderatio' => 'unreachednoderatio',
        'feedbackineffectiveness' => 'feedbackineffectiveness',
    ];

    /** @var array<string, string> badge 'band' token => Bootstrap badge class suffix. */
    const BAND_CLASSES = [
        'good' => 'badge-success',
        'neutral' => 'badge-secondary',
        'watch' => 'badge-warning',
    ];

    /**
     * The collapsible "About this model" panel for Model 1 — the architecture
     * doc's §2.1-2.6 content (target, indicator catalog, time-splitting,
     * evaluation), in plain language, kept out of the main table so the page
     * stays scannable.
     *
     * @return string
     */
    public static function render_model1_about(): string {
        $items = '';
        foreach (self::MODEL1_INDICATORS as $indicatorkey => $stringsuffix) {
            $items .= \html_writer::tag('li', \html_writer::tag(
                'strong',
                get_string('indicator:' . $stringsuffix, 'local_stackquizanalytics') . ': '
            ) . get_string('model1desc_' . $stringsuffix, 'local_stackquizanalytics'));
        }

        $body = \html_writer::tag('p', get_string('model1aboutbody', 'local_stackquizanalytics'))
            . \html_writer::tag('ul', $items)
            . \html_writer::tag('p', get_string('model1aboutfooter', 'local_stackquizanalytics'), ['class' => 'text-muted small']);

        return self::about_panel(get_string('target:studentatrisk', 'local_stackquizanalytics'), $body);
    }

    /**
     * The Model 1 student table, or a "no students" notice if there are none.
     *
     * @param \stdClass $report model1_report::build()'s return value
     * @return string
     */
    public static function render_model1_table(\stdClass $report): string {
        if (empty($report->rows)) {
            return \html_writer::tag('p', get_string('model1nostudents', 'local_stackquizanalytics'), ['class' => 'text-muted']);
        }

        $table = new \html_table();
        $table->head = array_merge(
            [get_string('columnstudent', 'local_stackquizanalytics'), get_string('columncurrentstatus', 'local_stackquizanalytics')],
            array_map(
                fn($stringsuffix) => get_string('indicator:' . $stringsuffix, 'local_stackquizanalytics'),
                array_values(self::MODEL1_INDICATORS)
            )
        );

        foreach ($report->rows as $row) {
            $tablerow = [s($row->fullname), self::render_grade_status($row->gradestatus)];
            foreach (self::MODEL1_INDICATORS as $indicatorkey => $stringsuffix) {
                $tablerow[] = self::render_indicator_cell($row->indicators[$indicatorkey], 'model1sentence_' . $stringsuffix);
            }
            $table->data[] = $tablerow;
        }

        $html = \html_writer::table($table);
        if ($report->truncated) {
            $html .= self::truncated_notice(count($report->rows), $report->total);
        }
        return $html;
    }

    /**
     * The collapsible "About this model" panel for Model 2 — the architecture
     * doc's §3.1-3.5 content, in plain language. See render_model1_about().
     *
     * @return string
     */
    public static function render_model2_about(): string {
        $items = '';
        foreach (self::MODEL2_INDICATORS as $indicatorkey => $stringsuffix) {
            $items .= \html_writer::tag('li', \html_writer::tag(
                'strong',
                get_string('indicator:' . $stringsuffix, 'local_stackquizanalytics') . ': '
            ) . get_string('model2desc_' . $stringsuffix, 'local_stackquizanalytics'));
        }

        $body = \html_writer::tag('p', get_string('model2aboutbody', 'local_stackquizanalytics'))
            . \html_writer::tag('ul', $items)
            . \html_writer::tag('p', get_string('model1aboutfooter', 'local_stackquizanalytics'), ['class' => 'text-muted small']);

        return self::about_panel(get_string('target:questionneedsreview', 'local_stackquizanalytics'), $body);
    }

    /**
     * The Model 2 question table, or a "no questions" notice if there are none.
     *
     * @param \stdClass $report model2_report::build()'s return value
     * @return string
     */
    public static function render_model2_table(\stdClass $report): string {
        if (empty($report->rows)) {
            return \html_writer::tag('p', get_string('model2noquestions', 'local_stackquizanalytics'), ['class' => 'text-muted']);
        }

        $table = new \html_table();
        $table->head = array_merge(
            [get_string('columnquestion', 'local_stackquizanalytics'), get_string('columncurrentstatus', 'local_stackquizanalytics')],
            array_map(
                fn($stringsuffix) => get_string('indicator:' . $stringsuffix, 'local_stackquizanalytics'),
                array_values(self::MODEL2_INDICATORS)
            )
        );

        foreach ($report->rows as $row) {
            $questioncell = \html_writer::tag('strong', s($row->questionname)) . \html_writer::tag('div', get_string(
                'quizlabel',
                'local_stackquizanalytics',
                s($row->quizname)
            ), ['class' => 'small text-muted']);
            $tablerow = [$questioncell, self::render_needs_review($row->needsreview)];
            foreach (self::MODEL2_INDICATORS as $indicatorkey => $stringsuffix) {
                $tablerow[] = self::render_indicator_cell($row->indicators[$indicatorkey], 'model2sentence_' . $stringsuffix);
            }
            $table->data[] = $tablerow;
        }

        $html = \html_writer::table($table);
        if ($report->truncated) {
            $html .= self::truncated_notice(count($report->rows), $report->total);
        }
        return $html;
    }

    /**
     * The "Current status" table cell for Model 2: a needs-review badge, or a muted note if it can't be computed.
     *
     * @param \stdClass|null $needsreview question_needs_review::compute_for_sample()'s return value
     * @return string
     */
    private static function render_needs_review(?\stdClass $needsreview): string {
        if ($needsreview === null) {
            return \html_writer::tag(
                'span',
                get_string('notenoughdata', 'local_stackquizanalytics'),
                ['class' => 'text-muted small']
            );
        }

        $stringkey = $needsreview->needsreview ? 'needsreviewyes' : 'needsreviewno';
        $badgeclass = $needsreview->needsreview ? 'badge-warning' : 'badge-success';
        $label = get_string($stringkey, 'local_stackquizanalytics', (object) [
            'passpercent' => $needsreview->passpercent,
            'thresholdpercent' => $needsreview->thresholdpercent,
        ]);
        return \html_writer::tag('span', $label, ['class' => 'badge ' . $badgeclass]);
    }

    /**
     * The "Current status" table cell: a passing/at-risk badge, or a muted note if it can't be computed.
     *
     * @param \stdClass $gradestatus {gradepasspercent, gradepercent, atrisk} — see model1_report::get_grade_status()
     * @return string
     */
    private static function render_grade_status(\stdClass $gradestatus): string {
        if ($gradestatus->gradepasspercent === null) {
            return \html_writer::tag(
                'span',
                get_string('gradestatusnothreshold', 'local_stackquizanalytics'),
                ['class' => 'text-muted small']
            );
        }
        if ($gradestatus->gradepercent === null) {
            return \html_writer::tag(
                'span',
                get_string('gradestatusnogradeyet', 'local_stackquizanalytics'),
                ['class' => 'text-muted small']
            );
        }

        $stringkey = $gradestatus->atrisk ? 'gradestatusatrisk' : 'gradestatuspassing';
        $badgeclass = $gradestatus->atrisk ? 'badge-warning' : 'badge-success';
        $label = get_string($stringkey, 'local_stackquizanalytics', (object) [
            'grade' => $gradestatus->gradepercent,
            'gradepass' => $gradestatus->gradepasspercent,
        ]);
        return \html_writer::tag('span', $label, ['class' => 'badge ' . $badgeclass]);
    }

    /**
     * One indicator table cell: a band badge plus a real-world-facts
     * sentence, or a muted "not enough data" note if $result is null.
     *
     * @param \stdClass|null $result an indicator's compute_for_sample() return value
     * @param string $sentencestringkey the lang string key for the facts sentence, taking $result->summary as $a
     * @return string
     */
    private static function render_indicator_cell(?\stdClass $result, string $sentencestringkey): string {
        if ($result === null) {
            return \html_writer::tag(
                'span',
                get_string('notenoughdata', 'local_stackquizanalytics'),
                ['class' => 'text-muted small']
            );
        }

        $badgeclass = self::BAND_CLASSES[$result->band] ?? self::BAND_CLASSES['neutral'];
        $badge = \html_writer::tag(
            'span',
            get_string('band_' . $result->band, 'local_stackquizanalytics'),
            ['class' => 'badge ' . $badgeclass]
        );
        $sentence = get_string($sentencestringkey, 'local_stackquizanalytics', (object) $result->summary);

        return $badge . \html_writer::tag('div', $sentence, ['class' => 'small text-muted mt-1']);
    }

    /**
     * The Diagnostics section: one collapsed-by-default <details> block per
     * question, its <summary> line showing both badges so every question is
     * scannable without expanding anything — the full seed-bias/branch-
     * coverage tables sit inside, expandable on demand.
     *
     * @param \stdClass $report diagnostics_report::build()'s return value
     * @return string
     */
    public static function render_diagnostics_section(\stdClass $report): string {
        if (empty($report->rows)) {
            return \html_writer::tag('p', get_string('diagnosticsnoquestions', 'local_stackquizanalytics'), ['class' => 'text-muted']);
        }

        $html = '';
        foreach ($report->rows as $row) {
            $summary = \html_writer::tag('summary', implode(' ', [
                \html_writer::tag('strong', s($row->questionname)),
                \html_writer::tag('span', get_string('quizlabel', 'local_stackquizanalytics', s($row->quizname)), [
                    'class' => 'text-muted small',
                ]),
                self::render_seed_bias_badge($row->seedbias),
                self::render_bloated_tree_badge($row->bloatedtree),
            ]));
            $body = \html_writer::div(
                self::render_seed_bias_detail($row->seedbias) . self::render_bloated_tree_detail($row->bloatedtree),
                'mt-2 ml-3'
            );
            $html .= \html_writer::tag('details', $summary . $body, ['class' => 'mb-2']);
        }

        if ($report->truncated) {
            $html .= self::truncated_notice(count($report->rows), $report->total);
        }
        return $html;
    }

    /**
     * The seed-bias effect-size badge shown in a question's collapsed summary line.
     *
     * @param \stdClass|null $seedbias diagnostics_report's seed-bias summary, or null
     * @return string
     */
    private static function render_seed_bias_badge(?\stdClass $seedbias): string {
        if ($seedbias === null) {
            return \html_writer::tag(
                'span',
                get_string('notenoughdata', 'local_stackquizanalytics'),
                ['class' => 'text-muted small']
            );
        }
        $badgeclass = self::BAND_CLASSES[$seedbias->band] ?? self::BAND_CLASSES['neutral'];
        return \html_writer::tag('span', get_string('diagnosticsseedbiassentence', 'local_stackquizanalytics', (object) [
            'etasquared' => format_float($seedbias->anova->etasquared, 3),
            'magnitude' => get_string('etamagnitude_' . $seedbias->magnitude, 'local_stackquizanalytics'),
        ]), ['class' => 'badge ' . $badgeclass]);
    }

    /**
     * The branch-coverage badge shown in a question's collapsed summary line.
     *
     * @param \stdClass|null $bloatedtree diagnostics_report's branch-coverage summary, or null
     * @return string
     */
    private static function render_bloated_tree_badge(?\stdClass $bloatedtree): string {
        if ($bloatedtree === null) {
            return \html_writer::tag(
                'span',
                get_string('notenoughdata', 'local_stackquizanalytics'),
                ['class' => 'text-muted small']
            );
        }
        $badgeclass = self::BAND_CLASSES[$bloatedtree->band] ?? self::BAND_CLASSES['neutral'];
        return \html_writer::tag('span', get_string('diagnosticsbloatedtreesentence', 'local_stackquizanalytics', (object) [
            'unreached' => $bloatedtree->unreachedcount,
            'total' => $bloatedtree->totalbranches,
        ]), ['class' => 'badge ' . $badgeclass]);
    }

    /**
     * The full seed-bias ANOVA table, or a muted "not enough data" note.
     *
     * @param \stdClass|null $seedbias
     * @return string
     */
    private static function render_seed_bias_detail(?\stdClass $seedbias): string {
        $heading = \html_writer::tag('h6', get_string('seedbiasheading', 'local_stackquizanalytics'));
        if ($seedbias === null) {
            return $heading . \html_writer::tag(
                'p',
                get_string('notenoughdata', 'local_stackquizanalytics'),
                ['class' => 'text-muted small']
            );
        }

        $anova = $seedbias->anova;
        $table = new \html_table();
        $table->data = [
            [get_string('seedgroups', 'local_stackquizanalytics'), $anova->ngroups],
            ['F', $anova->f !== null ? format_float($anova->f, 3) : get_string('notavailable', 'local_stackquizanalytics')],
            ['η²', format_float($anova->etasquared, 3) . ' (' . get_string(
                'etamagnitude_' . $seedbias->magnitude,
                'local_stackquizanalytics'
            ) . ')'],
        ];
        return $heading . \html_writer::table($table);
    }

    /**
     * The full PRT branch-coverage table, or a muted "not enough data" note.
     *
     * @param \stdClass|null $bloatedtree
     * @return string
     */
    private static function render_bloated_tree_detail(?\stdClass $bloatedtree): string {
        $heading = \html_writer::tag('h6', get_string('bloatedtreeheading', 'local_stackquizanalytics'));
        if ($bloatedtree === null) {
            return $heading . \html_writer::tag(
                'p',
                get_string('notenoughdata', 'local_stackquizanalytics'),
                ['class' => 'text-muted small']
            );
        }

        $table = new \html_table();
        $table->head = [
            get_string('node', 'local_stackquizanalytics'),
            get_string('branch', 'local_stackquizanalytics'),
            get_string('traversals', 'local_stackquizanalytics'),
            get_string('coverage', 'local_stackquizanalytics'),
        ];
        foreach ($bloatedtree->branches as $branch) {
            $table->data[] = [
                s($branch->nodename),
                s($branch->branch),
                $branch->count,
                get_string('coverage_' . $branch->classification, 'local_stackquizanalytics'),
            ];
        }
        return $heading . \html_writer::table($table);
    }

    /**
     * The "Download PDF" form at the bottom of the page — section checkboxes
     * (all checked by default, matching every other "export everything by
     * default" form in this plugin family), a hidden course/quiz-filter
     * context, and a plain GET submit to pdf.php. Deliberately GET, not POST
     * with a client-side chart-capture step like local_quizanalytics's own
     * PDF form: this dashboard has no charts, only tables, so pdf.php can
     * re-derive everything server-side with nothing the client needs to hand
     * it beyond which sections were ticked.
     *
     * @param \moodle_url $action pdf.php's URL
     * @param int $courseid
     * @param int|null $quizid the currently-selected quiz filter, or null for "all quizzes"
     * @return string
     */
    public static function render_pdf_form(\moodle_url $action, int $courseid, ?int $quizid): string {
        $html = \html_writer::start_tag('form', ['method' => 'get', 'action' => $action->out(false), 'class' => 'mt-4']);
        $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
        if ($quizid !== null) {
            $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid', 'value' => $quizid]);
        }

        $html .= \html_writer::tag('p', get_string('pdfsectionslabel', 'local_stackquizanalytics'), ['class' => 'mb-1']);
        $sectionheadings = [
            'model1' => 'model1heading',
            'model2' => 'model2heading',
            'diagnostics' => 'diagnosticsheading',
        ];
        foreach ($sectionheadings as $sectionid => $headingstringkey) {
            $checkboxid = 'stackanalytics-pdf-' . $sectionid;
            $html .= \html_writer::empty_tag('input', [
                'type' => 'checkbox', 'name' => 'sections[]', 'value' => $sectionid,
                'id' => $checkboxid, 'checked' => 'checked',
            ]);
            $html .= ' ' . \html_writer::label(
                get_string($headingstringkey, 'local_stackquizanalytics'),
                $checkboxid,
                true,
                ['class' => 'mr-3']
            );
        }

        $html .= \html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('downloadpdfbutton', 'local_stackquizanalytics'),
            'class' => 'btn btn-primary d-block mt-2',
        ]);
        $html .= \html_writer::end_tag('form');
        return $html;
    }

    /**
     * A native, JS-free collapsible panel (no styling dependency beyond the
     * theme's own <details> rendering).
     *
     * @param string $title
     * @param string $bodyhtml already-escaped/tag-built HTML
     * @return string
     */
    private static function about_panel(string $title, string $bodyhtml): string {
        return \html_writer::tag(
            'details',
            \html_writer::tag('summary', get_string('aboutthismodel', 'local_stackquizanalytics') . ': ' . $title)
                . \html_writer::div($bodyhtml, 'mt-2'),
            ['class' => 'mb-3']
        );
    }

    /**
     * The "showing the first N of M" note shown under a truncated table.
     *
     * @param int $shown
     * @param int $total
     * @return string
     */
    private static function truncated_notice(int $shown, int $total): string {
        return \html_writer::tag('p', get_string('truncatednotice', 'local_stackquizanalytics', (object) [
            'shown' => $shown,
            'total' => $total,
        ]), ['class' => 'text-muted small']);
    }
}
