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
 * This file defines the quiz proformasubmexport report class.
 *
 * @package   quiz_proformasubmexport
 * @copyright 2017 IIT Bombay
 * @author      Kashmira Nagwekar, K.Borm (Ostfalia)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/reportlib.php');
require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/quiz/report/proformasubmexport/proformasubmexport_form.php');
require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport_options.php');
require_once($CFG->dirroot . '/mod/quiz/report/proformasubmexport/classes/quiz_proforma_responses_table.php');
require_once($CFG->dirroot . '/mod/quiz/report/proformasubmexport/classes/proforma_options.php');



/**
 * Quiz report subclass for the proformasubmexport report.
 *
 * This report allows you to download file attachments submitted
 * by students as a response to quiz proforma questions.
 *
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_proformasubmexport_report extends quiz_attempts_report {

    private $mem_info;
    private $max_mem = 0;
    private function set_mem($text) {
        if (memory_get_usage() > $this->max_mem)
            $this->max_mem = memory_get_usage();
        $this->mem_info .= ' _' . $text . ': ' . $this->max_mem;
    }

    public function display($quiz, $cm, $course) {
        global $OUTPUT, $DB;

        /*if (!ini_set('memory_limit','1024')) {
            throw new coding_exception('cannot set memory limit');
        }*/
        $this->mem_info = ' ';
        $this->max_mem = 0;

        // Initialisation.
        list($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins) = $this->init(
            'proformasubmexport', 'quiz_proformasubmexport_settings_form', $quiz, $cm, $course);

        $options = new quiz_proforma_options('proformasubmexport', $quiz, $cm, $course);

        if ($fromform = $this->form->get_data()) {
            $options->process_settings_from_form($fromform);
        } else {
            $options->process_settings_from_params();
        }
        $this->form->set_data($options->get_initial_form_data());

        // Load the required questions.
        $questions = $this->load_fullquestions($quiz); // quiz_report_get_significant_questions($quiz);
        // Remove non-ProFormA questions.
/*        foreach ($questions as $question) {
            if ($question->type != 'proforma') {
                unset($questions[$question]);
            }
        }
*/
        // Prepare for downloading, if applicable.
        $courseshortname = format_string($course->shortname, true,
                array('context' => context_course::instance($course->id)));
        $table = new quiz_proforma_responses_table($quiz, $this->context, $this->qmsubselect,
                $options, $groupstudentsjoins, $studentsjoins, $questions, $options->get_url());
        $filename = quiz_report_download_filename('proformasubm', // get_string('responsesfilename', 'quiz_responses'),
                $courseshortname, $quiz->name);

        $table->is_downloading($options->download, $filename,
                $courseshortname . ' ' . format_string($quiz->name, true));
        if ($table->is_downloading()) {
            raise_memory_limit(MEMORY_EXTRA);
        }

        $this->hasgroupstudents = false;
        if (!empty($groupstudentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                      FROM {user} u
                    $groupstudentsjoins->joins
                     WHERE $groupstudentsjoins->wheres";
            $this->hasgroupstudents = $DB->record_exists_sql($sql, $groupstudentsjoins->params);
        }
        $hasstudents = false;
        if (!empty($studentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                    FROM {user} u
                    $studentsjoins->joins
                    WHERE $studentsjoins->wheres";
            $hasstudents = $DB->record_exists_sql($sql, $studentsjoins->params);
        }
        if ($options->attempts == self::ALL_WITH) {
            // This option is only available to users who can access all groups in
            // groups mode, so setting allowed to empty (which means all quiz attempts
            // are accessible, is not a security problem.
            $allowedjoins = new \core\dml\sql_join();
        }

        $this->process_actions($quiz, $cm, $currentgroup, $groupstudentsjoins, $allowedjoins, $options->get_url());

        $hasquestions = quiz_has_questions($quiz->id);

        // Start output.
        if (!$table->is_downloading()) {
            // Only print headers if not asked to download data.
                $this->print_standard_header_and_messages($cm, $course, $quiz,
                    $options, $currentgroup, $hasquestions, $hasstudents);

            // $this->print_header_and_tabs($cm, $course, $quiz, 'proformasubmexport');
            /*$this->print_messagees(false, $cm, $quiz, $OUTPUT, $user_attempts,
                    $hassubmissions, $currentgroup,
                    $hasproformaquestions, $hasstudents);*/

            // Print the display options.
            $this->form->display();
        }

        $hasstudents = $hasstudents && (!$currentgroup || $this->hasgroupstudents);
        if ($hasquestions && ($hasstudents || $options->attempts == self::ALL_WITH)) {

            $table->setup_sql_queries($allowedjoins);

            if (!$table->is_downloading()) {
                // Print information on the grading method.
                if ($strattempthighlight = quiz_report_highlighting_grading_method(
                        $quiz, $this->qmsubselect, $options->onlygraded)) {
                    echo '<div class="quizattemptcounts">' . $strattempthighlight . '</div>';
                }
            }

            // Define table columns.
            $columns = array();
            $headers = array();

            if (!$table->is_downloading() && $options->checkboxcolumn) {
                $columnname = 'checkbox';
                $columns[] = $columnname;
                $headers[] = $table->checkbox_col_header($columnname);
            }

            $this->add_user_columns($table, $columns, $headers);
            $this->add_state_column($columns, $headers);

            if ($table->is_downloading()) {
                $this->add_time_columns($columns, $headers);
            }

            $this->add_grade_columns($quiz, $options->usercanseegrades, $columns, $headers);

            foreach ($questions as $id => $question) {
                if ($options->showqtext) {
                    $columns[] = 'question' . $id;
                    $headers[] = get_string('questionx', 'question', $question->number);
                }
                // if ($options->showresponses) {
                    $columns[] = 'response' . $id;
                    $headers[] = get_string('responsex', 'quiz_responses', $question->number);
                // }
                /*
                if ($options->showright) {
                    $columns[] = 'right' . $id;
                    $headers[] = get_string('rightanswerx', 'quiz_responses', $question->number);
                }*/
            }

            $table->define_columns($columns);
            $table->define_headers($headers);
            $table->sortable(true, 'uniqueid');

            // Set up the table.
            $table->define_baseurl($options->get_url());

            $this->configure_user_columns($table);

            $table->no_sorting('feedbacktext');
            $table->column_class('sumgrades', 'bold');

            $table->set_attribute('id', 'responses');

            $table->collapsible(true);

            $table->out($options->pagesize, true);
        }


        return true;
    }

    /**
     * Load the questions in this quiz and add some properties to the objects needed in the reports.
     *
     * @param object $quiz the quiz.
     * @return array of questions for this quiz.
     */
    public function load_fullquestions($quiz) {
        // Load the questions.
        $questions = quiz_report_get_significant_questions($quiz);
        $questionids = array();
        foreach ($questions as $question) {
            $questionids[] = $question->id;
        }
        $fullquestions = question_load_questions($questionids);
        foreach ($questions as $qno => $question) {
            $q = $fullquestions[$question->id];
            $q->maxmark = $question->maxmark;
            $q->slot = $qno;
            $q->number = $question->number;
            $questions[$qno] = $q;
        }
        return $questions;
    }

    /**
     * @param bool $ds_button_clicked
     * @param stdClass $quiz
     * @param stdClass $OUTPUT
     * @param mod_quiz_attempts_report_options $user_attempts
     * @param int $hassubmissions
     * @param bool $hasproformaquestions
     * @param bool $hasstudents
     * @throws coding_exception
     */
    protected function print_messagees($ds_button_clicked, $cm, $quiz, $OUTPUT, $user_attempts, $hassubmissions,
            $currentgroup, bool $hasproformaquestions, bool $hasstudents): void {
        // Print information on the number of existing attempts.
        if ($strattemptnum = quiz_num_attempt_summary($quiz, $cm, true, $currentgroup)) {
            echo '<div class="quizattemptcounts">' . $strattemptnum . '</div>';
        }

        if ($ds_button_clicked) {
            if (!quiz_has_questions($quiz->id)) {
                echo $OUTPUT->notification(get_string('noquestions', 'quiz_proformasubmexport'));
            } else if (!$hasstudents) {
                echo $OUTPUT->notification(get_string('nostudentsyet'));
                // 	            } else if ($currentgroup && !$this->hasgroupstudents) {
                // 	                echo $OUTPUT->notification(get_string('nostudentsingroup'));
            } else if (!$hasproformaquestions) {
                echo $OUTPUT->notification(get_string('noproformaquestion', 'quiz_proformasubmexport'));
            } else if (!$user_attempts) {
                echo $OUTPUT->notification(get_string('noattempts', 'quiz_proformasubmexport'));
            } else if (!$hassubmissions) {
                echo $OUTPUT->notification(get_string('nosubmission', 'quiz_proformasubmexport'));
            }
        }
    }
}