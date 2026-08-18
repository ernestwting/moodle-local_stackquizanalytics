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
 * The top-level "Section:" selector shared by index.php (Quiz Analytics —
 * ported from local_quizanalytics) and models.php (Model & Diagnostics
 * Analytics — ported from local_stackanalytics), the one genuinely new
 * piece of UI this merge adds: a single course/quiz-response-level dashboard
 * with one nav entry, switching between the two previously-separate
 * plugins' whole page trees via a plain GET-reload — same convention every
 * other selector in both halves already uses, no JS required.
 *
 * Deliberately global-namespace and outside classes/quiz/ or classes/stack/
 * — this belongs to neither product specifically, only to the merged
 * plugin's own top-level page structure.
 *
 * @package local_stackquizanalytics
 * @copyright  2026 Ernest Ting <eting@caltech.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Renders the "Section:" selector between Quiz Analytics (index.php) and Model & Diagnostics Analytics (models.php).
 */
class local_stackquizanalytics_section_selector {
    /**
     * Renders the two-link "Section:" switcher, bolding whichever section is currently active.
     *
     * @param int $courseid
     * @param string $current 'quiz' or 'models'
     * @return string
     */
    public static function render(int $courseid, string $current): string {
        $options = [
            'quiz' => get_string('sectionquiz', 'local_stackquizanalytics'),
            'models' => get_string('sectionmodels', 'local_stackquizanalytics'),
        ];

        // Rendered as two plain links rather than a form+select — there's no
        // per-section state to carry across the switch (unlike the quiz/view
        // selectors nested inside each section, which do need a GET form to
        // submit their own params), so a link is the simpler, more honestly-GET
        // choice here.
        $links = [];
        foreach ($options as $section => $label) {
            $url = new \moodle_url(
                $section === 'quiz' ? '/local/stackquizanalytics/index.php' : '/local/stackquizanalytics/models.php',
                ['id' => $courseid]
            );
            if ($section === $current) {
                $links[] = \html_writer::tag('strong', $label);
            } else {
                $links[] = \html_writer::link($url, $label);
            }
        }

        return \html_writer::tag(
            'p',
            \html_writer::tag('span', get_string('sectionselectorlabel', 'local_stackquizanalytics'), ['class' => 'mr-2'])
                . implode(' · ', $links),
            ['class' => 'mb-3']
        );
    }
}
