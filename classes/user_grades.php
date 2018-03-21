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
 * The mod_hvp user grades
 *
 * @package    mod_hvp
 * @copyright  2016 Joubel AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_hvp;

defined('MOODLE_INTERNAL') || die();

require(__DIR__ . '/../lib.php');

/**
 * Handles grade storage for users
 * @package mod_hvp
 */
class user_grades {

    public static function handle_ajax() {
        global $DB, $USER;

        if (!\H5PCore::validToken('result', required_param('token', PARAM_RAW))) {
            \H5PCore::ajaxError(get_string('invalidtoken', 'hvp'));
            return;
        }

        $cm = get_coursemodule_from_id('hvp', required_param('contextId', PARAM_INT));
        if (!$cm) {
            \H5PCore::ajaxError('No such content');
            http_response_code(404);
            return;
        }

        // Content parameters.
        $score = required_param('score', PARAM_INT);
        $maxscore = required_param('maxScore', PARAM_INT);

        // Check permission.
        $context = \context_module::instance($cm->id);
        if (!has_capability('mod/hvp:saveresults', $context)) {
            \H5PCore::ajaxError(get_string('nopermissiontosaveresult', 'hvp'));
            http_response_code(403);
            return;
        }

        // Get hvp data from content.
        $hvp = $DB->get_record('hvp', array('id' => $cm->instance));
        if (!$hvp) {
            \H5PCore::ajaxError('No such content');
            http_response_code(404);
            return;
        }

        // Create grade object and set grades.
        $grade = (object) array(
            'userid' => $USER->id
        );

        // Set grade using Gradebook API.
        $hvp->cmidnumber = $cm->idnumber;
        $hvp->name = $cm->name;
        $hvp->rawgrade = $score;
        $hvp->rawgrademax = $maxscore;
        hvp_grade_item_update($hvp, $grade);

        // Get content info for log.
        $content = $DB->get_record_sql(
                "SELECT c.name AS title, l.machine_name AS name, l.major_version, l.minor_version
                   FROM {hvp} c
                   JOIN {hvp_libraries} l ON l.id = c.main_library_id
                  WHERE c.id = ?",
                array($hvp->id)
        );

        // Log results set event.
        new \mod_hvp\event(
                'results', 'set',
                $hvp->id, $content->title,
                $content->name, $content->major_version . '.' . $content->minor_version
        );

        \H5PCore::ajaxSuccess();
    }

    /**
     *  Since the subcontent types do not have their own row in the table,
     *  we use the hvp_results_table as a 'staging area' to set and get
     *  dynamically graded scores.
     */
    public static function handle_dynamic_grading() {
      global $DB, $USER;

      if (!\H5PCore::validToken('result', required_param('token', PARAM_RAW))) {
          \H5PCore::ajaxError(get_string('invalidtoken', 'hvp'));
          return;
      }

      $cm = get_coursemodule_from_id('hvp', required_param('contextId', PARAM_INT));
      if (!$cm) {
          \H5PCore::ajaxError('No such content');
          http_response_code(404);
          return;
      }

      // Content parameters.
      $subcontentID = required_param('subcontent_id', PARAM_INT);
      $score = required_param('score', PARAM_INT);
      $maxscore = required_param('maxScore', PARAM_INT);

      // Update the mdl_hvp_xapi_results table
      $data = (object) [
        'id' => $subcontentID,
        'raw_score' => $score
      ];
      $DB->update_record('hvp_xapi_results', $data, $bulk=false);

      // Get the all content types associated with the containing content type
      $result = $DB->get_records('hvp_xapi_results', array(
        'content_id' => $cm->instance,
      ));

      // Keep only the dynamically gradable content types
      $gradables = array_filter($result, function ($var) {
        return $var->additionals === '{"extensions":{"https:\/\/h5p.org\/x-api\/h5p-machine-name":"H5P.IVOpenEndedQuestion"}}';
      });

      // Get the scores from the dynamically graded content types
      $gradableScores = array_map(function ($var) {
        return intval($var->raw_score);
      }, $gradables);

      $totalGradablesScore = array_sum($gradableScores);

      // Get the original raw score from the main content type
      $result = $DB->get_records('hvp_xapi_results', array(
        'content_id' => $cm->instance,
      ));

      $mainContentType = array_filter($result, function ($var) {
        return $var->interaction_type === 'compound'; // More robust selection
      });

      $mainContentTypeRawScore = array_map(function ($var) {
        return intval($var->raw_score);
      }, $mainContentType);

      $mainContentTypeRawScore = array_sum($mainContentTypeRawScore);

      // Set the real gradebook score
      // Get hvp data from content.
      $hvp = $DB->get_record('hvp', array('id' => $cm->instance));
      if (!$hvp) {
          \H5PCore::ajaxError('No such content');
          http_response_code(404);
          return;
      }

      // Create grade object and set grades.
      $grade = (object) array(
          'userid' => $USER->id
      );

      $newScore = $mainContentTypeRawScore + $totalGradablesScore;

      // Set grade using Gradebook API.
      $hvp->cmidnumber = $cm->idnumber;
      $hvp->name = $cm->name;
      $hvp->rawgrade = $newScore;
      $hvp->rawgrademax = $maxscore;
      hvp_grade_item_update($hvp, $grade);
      // \H5PCore::ajaxSuccess(0);
      // Get the content id from the subcontent id
      $result = $DB->get_records('hvp_xapi_results', array(
        'id' => $subcontentID,
      ));

      // Get the all content types associated with the main content type
      $result = $DB->get_records('hvp_xapi_results', array(
        'content_id' => $result[$subcontentID]->content_id,
      ));

      $ungraded = array_filter($result, function ($var) {
        return $var->raw_score == NULL && $var->additionals == '{"extensions":{"https:\/\/h5p.org\/x-api\/h5p-machine-name":"H5P.IVOpenEndedQuestion"}}';
      });

      $response = [
        'score' => $result[$subcontentID]->raw_score,
        'maxScore' => intval($result[$subcontentID]->max_score),
        'totalUngraded' => sizeof($ungraded),
      ];
      \H5PCore::ajaxSuccess($response);
    }

    public static function return_subcontent_grade() {
      global $DB, $USER;
      // Content parameters.
      $subcontentID = required_param('subcontent_id', PARAM_INT);

      // Get the content id from the subcontent id
      $result = $DB->get_records('hvp_xapi_results', array(
        'id' => $subcontentID,
      ));

      // Get the all content types associated with the main content type
      $result = $DB->get_records('hvp_xapi_results', array(
        'content_id' => $result[$subcontentID]->content_id,
      ));

      $ungraded = array_filter($result, function ($var) {
        return $var->raw_score == NULL && $var->additionals == '{"extensions":{"https:\/\/h5p.org\/x-api\/h5p-machine-name":"H5P.FreeTextQuestion"}}';
      });

      $response = [
        'score' => $result[$subcontentID]->raw_score,
        'maxScore' => intval($result[$subcontentID]->max_score),
        'totalUngraded' => sizeof($ungraded),
      ];
      \H5PCore::ajaxSuccess($response);
    }
}
