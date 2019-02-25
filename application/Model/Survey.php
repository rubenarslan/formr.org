<?php
//fixme:
// attack vector against unlinked surveys
// - delete user data one by one, see which one disappears
// - calculate survey_run_sessions$session in the unlinked surveys
// - calculate private data from unlinked survey in linked survey
// - check user_overview to see which users have already made it to the unlinked survey
class Survey extends RunUnit {

	public $id = null;
	public $name = null;
	public $run_name = null;
	public $items = array();
	public $items_validated = array();
	public $session = null;
	public $results_table = null;
	public $run_session_id = null;
	public $settings = array();
	public $valid = false;
	public $public = false;
	public $errors = array();
	public $validation_errors = array();
	public $messages = array();
	public $warnings = array();
	public $position;
	public $rendered_items = array();
	private $SPR;
	public $openCPU = null;
	public $icon = "fa-pencil-square-o";
	public $type = "Survey";
	private $confirmed_deletion = false;
	private $created_new = false;
	public $item_factory = null;
	public $unanswered = array();
	public $to_render = array();
	public $study_name_pattern = "/[a-zA-Z][a-zA-Z0-9_]{2,64}/";
	private $result_count = null;

	/**
	 * Counts for progress computation
	 * @var int {collection}
	 */
	public $progress = 0;
	public $progress_counts = array(
		'already_answered' => 0,
		'not_answered' => 0,
		'hidden_but_rendered' => 0,
		'not_rendered' => 0,
		'visible_on_current_page' => 0,
		'hidden_but_rendered_on_current_page' => 0,
		'not_answered_on_current_page' => 0
	);

	/**
	 * An array of unit's exportable attributes
	 * @var array
	 */
	public $export_attribs = array('type', 'description', 'position', 'special');

	/**
	 * @var DB
	 */
	public $dbh;

	/**
	 * @var ParsedownExtra
	 */
	public $parsedown;


	public function __construct($fdb, $session, $unit, $run_session = null, $run = null) {
		$this->dbh = $fdb;
		if (isset($unit['name']) && !isset($unit['unit_id'])) { //when called via URL
			$this->load($unit['name']);
			// parent::__construct needs this
			$unit['unit_id'] = $this->id;
		} elseif (isset($unit['unit_id'])) {
			$this->id = (int) $unit['unit_id'];
		}

		parent::__construct($fdb, $session, $unit, $run_session, $run);

		// $this->valid means survey has been loaded from DB so no need to re-load it
		if ($this->id && !$this->valid):
			$this->load();
		endif;
	}

	/**
	 * Get survey by id
	 *
	 * @param int $id
	 * @return Survey
	 */
	public static function loadById($id) {
		$unit = array('unit_id' => (int) $id);
		return new Survey(DB::getInstance(), null, $unit);
	}

	/**
	 * Get survey by name
	 *
	 * @param string $name
	 * @return Survey
	 */
	public static function loadByName($name) {
		$db = Site::getDb();
		$id = $db->findValue('survey_studies', array('name' => $name), 'id');
		return self::loadById($id);
	}

	public static function loadByUserAndName(User $user, $name) {
		$db = Site::getDb();
		$id = $db->findValue('survey_studies', array('user_id' => $user->id, 'name' => $name), 'id');
		return self::loadById($id);
	}

	private function load($survey_name = null) {
		global $user;
		if ($survey_name !== null) {
			$vars = $this->dbh->findRow('survey_studies', array('name' => $survey_name, 'user_id' => $user->id));
		} else {
			$vars = $this->dbh->findRow('survey_studies', array('id' => $this->id));
		}
		if ($vars):
			$this->id = $vars['id'];
			$this->name = $vars['name'];
			$this->user_id = (int) $vars['user_id'];
			if (!isset($vars['results_table']) OR $vars['results_table'] == null) {
				$this->results_table = $this->name;
			} else {
				$this->results_table = $vars['results_table'];
			}

			$this->settings['maximum_number_displayed'] = (int) array_val($vars, 'maximum_number_displayed', null);
			$this->settings['displayed_percentage_maximum'] = (int) array_val($vars, 'displayed_percentage_maximum');
			$this->settings['add_percentage_points'] = (int) array_val($vars, 'add_percentage_points');
			$this->settings['enable_instant_validation'] = (int) array_val($vars, 'enable_instant_validation');
			$this->settings['expire_after'] = (int) array_val($vars, 'expire_after');
			$this->settings['google_file_id'] = array_val($vars, 'google_file_id');
			$this->settings['unlinked'] = array_val($vars, 'unlinked');
			$this->settings['expire_invitation_after'] = (int) array_val($vars, 'expire_invitation_after');
			$this->settings['expire_invitation_grace'] = (int) array_val($vars, 'expire_invitation_grace');
			$this->settings['hide_results'] = (int) array_val($vars, 'hide_results');
			$this->settings['use_paging'] = (int) array_val($vars, 'use_paging');

			$this->valid = true;
		endif;
	}

	public function create($options) {
		$old_name = $this->name;
		// If survey_data is present (an object with "name", "items", "settings" entries)
		// then create/update the survey and set $options[unit_id] as Survey's ID
		if (!empty($options['survey_data'])) {
			if ($created = $this->createFromData($options['survey_data'])) {
				$options = array_merge($options, $created);
				$this->id = $created['id'];
				$this->name = $created['name'];
				$this->results_table = $created['results_table'];
			}
		}

		// this unit type is a bit special
		// all other unit types are created only within runs
		// but surveys are semi-independent of runs
		// so it is possible to add a survey, without specifying which one at first
		// and to then choose one.
		// thus, we "mock" a survey at first
		if (count($options) === 1 || isset($options['mock'])) {
			$this->valid = true;
		} else { // and link it to the run only later
			if (!empty($options['unit_id'])) {
				$this->id = (int) $options['unit_id'];
				if ($this->linkToRun()) {
					$this->majorChange();
					$this->load();
					if(empty($options['description']) || $options['description'] === $old_name) {
						$options['description'] = $this->name;
					}
				}
			}
			$this->modify($options);
			$this->valid = true;
		}
	}

	/**
	 * Create survey from data the $data object has the following fields
	 * $data = {
	 * 		name: a string representing the name of the survey
	 * 		items: an array of objects representing items in the survey (fields as  in excel sheet)
	 * 		settings: an object with settings of the survey
	 * }
	 *
	 * @param object $data
	 * @param boolean $return_spr flag as to whether just an SPR object should be returned
	 * @return array|bool|SpreadsheetReader Returns an array with info on created survey, SpreadsheetReader if indicated by second parameter or FALSE on failure
	 * 
	 * @todo process items
	 */
	protected function createFromData($data, $return_spr = false) {
		if (empty($data->name) || empty($data->items)) {
			return false;
		}

		if (empty($data->settings)) {
			$data->settings = array();
		}

		$created = array();
		// check if survey exists by name even if it belongs to current user. If that is the case then use existing ID.
		$survey = Survey::loadByName($data->name);
		if ($survey->valid && Site::getCurrentUser()->created($survey)) {
			$created['id'] = $survey->id;
			$created['unit_id'] = $survey->id;
		} else {
			$unit = array(
				'user_id' => Site::getCurrentUser()->id,
				'name' => $data->name,
			);
			$survey = new Survey(DB::getInstance(), null, $unit);
			if ($survey->createIndependently((array)$data->settings)) {
				$created['unit_id'] = $survey->id;
				$created['id'] = $survey->id;
			}
		}

		$created['results_table'] = $survey->results_table;
		$created['name'] = $survey->name;

		// Mock SpreadSheetReader to use existing mechanism of creating survey items
		$SPR = new SpreadsheetReader();
		$i = 1;
		foreach ($data->items as $item) {
			if (!empty($item->choices) && !empty($item->choice_list)) {
				foreach ($item->choices as $name => $label) {
					$SPR->choices[] = array(
						'list_name' => $item->choice_list,
						'name' => $name,
						'label' => $label,
					);
				}
				unset($item->choices);
			}
			$SPR->addSurveyItem((array)$item);
		}

		if ($return_spr === true) {
			return $SPR;
		}

		if (!$survey->createSurvey($SPR)) {
			alert("Unable to import survey items in survey '{$survey->name}'. You may need to independently create this survey and attach it to run", 'alert-warning');
			$errors = array_merge($survey->errors, $SPR->errors, $SPR->warnings);
			if ($errors) {
				alert(nl2br(implode("\n", $errors)), 'alert-warning');
			}
			return false;
		}

		return $created;
	}

	public function render($form_action = null, $form_append = null) {
		$ret = '
		<div class="row study-' . $this->id . ' study-name-' . $this->name . '">
			<div class="col-md-12">
		';
		$ret .= $this->render_form_header($form_action) .
				$this->render_items() .
				$form_append .
				$this->render_form_footer();
		$ret .= '
			</div> <!-- end of col-md-12 div -->
		</div> <!-- end of row div -->
		';
		$this->dbh = null;
		return $ret;
	}

	protected function startEntry() {
		if (!$this->dbh->table_exists($this->results_table)) {
			alert('A results table for this survey could not be found', 'alert-danger');
			throw new Exception("Results table '{$this->results_table}' not found!");
		}

		$this->dbh->insert_update($this->results_table, array(
			'session_id' => $this->session_id,
			'study_id' => $this->id,
			'created' => mysql_now()),
		array(
			'modified' => mysql_now(),
		));

		// Check if session already has enough entries in the items_display table for this survey
		if($this->allItemsHaveAnOrder()) {
			return;
		} else {
			// get the definition of the order
			list($item_ids, $item_types) = $this->getOrderedItemsIds();

			// define paramers to bind parameters
			$display_order = null;
			$item_id = null;
			$page = 1;

			$survey_items_display = $this->dbh->prepare(
				"INSERT INTO `survey_items_display` (`item_id`, `session_id`, `display_order`, `page`)  VALUES (:item_id, :session_id, :display_order, :page)
				 ON DUPLICATE KEY UPDATE `display_order` = VALUES(`display_order`), `page` = VALUES(`page`)"
			);
			$survey_items_display->bindParam(":session_id", $this->session_id);
			$survey_items_display->bindParam(":item_id", $item_id);
			$survey_items_display->bindParam(":display_order", $display_order);
			$survey_items_display->bindParam(":page", $page);

			foreach ($item_ids as $display_order => $item_id) {
				$survey_items_display->execute();
				// set page number when submit button is hit or we reached max_items_per_page for survey
				if ($item_types[$item_id] === 'submit') {
					$page++;
				}
			}
		}
	}

	protected function allItemsHaveAnOrder() {
		/*
			we have cascading deletes for items->item_display so we only need to worry whether the item_display is short of items
			12 items
			12 ordered items
		
			scenario A
			1 deleted
			11 items
			11 ordered items
			-> don't reorder

			scenario B
			1 added
			13 items
			12 ordered items

			-> reorder
			scenario C
			1 added, 1 deleted
			12 items
			11 ordered items
			-> reorder
		
		*/
		$nr_items = $this->dbh->count('survey_items', array('study_id' => $this->id), 'id');
		$nr_display_items = $this->dbh->count('survey_items_display', array('session_id' => $this->session_id), 'id');

		return $nr_display_items === $nr_items;
	}

	protected function getOrderedItemsIds() {
		$get_items = $this->dbh->select('
				`survey_items`.id,
				`survey_items`.`type`,
				`survey_items`.`item_order`,
				`survey_items`.`block_order`')
				->from('survey_items')
				->where("`survey_items`.`study_id` = :study_id")
				->order("`survey_items`.order")
				->bindParams(array('`study_id`' => $this->id))
				->statement();

		// sort blocks randomly (if they are consecutive), then by item number and if the latter are identical, randomly
		$block_segment = $block_order = $item_order = $random_order = $block_numbers = $item_ids = array();
		$types = array();

		$last_block = "";
		$block_nr = 0;
		$block_segment_i = 0;

		while ($item = $get_items->fetch(PDO::FETCH_ASSOC)) {
			if ($item['block_order'] == "") { // not blocked
				$item['block_order'] = ""; // ? why is this necessary
				$block_order[] = $block_nr;
			} else {
				if (!array_key_exists($item['block_order'], $block_numbers)) { // new block
					if ($last_block === "") { // new segment of blocks
						$block_segment_i = 0;
						$block_segment = range($block_nr, $block_nr + 10000); // by choosing this range, the next non-block segment is forced to follow
						shuffle($block_segment);
						$block_nr = $block_nr + 10001;
					}

					$rand_block_number = $block_segment[$block_segment_i];
					$block_numbers[$item['block_order']] = $rand_block_number;
					$block_segment_i++;
				}
				$block_order[] = $block_numbers[$item['block_order']]; // get stored block order
			} // sort the blocks with each other
			// but keep the order within blocks if desired
			$item_order[] = $item['item_order']; // after sorting by block, sort by item order 
			$item_ids[] = $item['id'];
			$last_block = $item['block_order'];

			$types[$item['id']] = $item['type'];
		}

		$random_order = range(1, count($item_ids)); // if item order is identical, sort randomly (within block)
		shuffle($random_order);
		array_multisort($block_order, $item_order, $random_order, $item_ids);
		// order is already sufficiently defined at least by random_order, but this is a simple way to sort $item_ids is sorted accordingly

		return array($item_ids, $types);
	}

	/**
	 * Save posted survey data to database
	 *
	 * @param array $posted
	 * @param bool $validate Should items be validated before posted?
	 * @return boolean Returns TRUE if all data was successfully validated and saved or FALSE otherwise
	 * @throws Exception
	 */
	public function post($posted, $validate = true) {
		// remove variables user is not allowed to overrite (they should not be sent to user in the first place if not used in request)
		unset($posted['id'], $posted['session'], $posted['session_id'], $posted['study_id'], $posted['created'], $posted['modified'], $posted['ended']);

		if (!$posted) {
			return false;
		}

		if (isset($posted["_item_views"]["shown"])):
			$posted["_item_views"]["shown"] = array_filter($posted["_item_views"]["shown"]);
			$posted["_item_views"]["shown_relative"] = array_filter($posted["_item_views"]["shown_relative"]);
			$posted["_item_views"]["answered"] = array_filter($posted["_item_views"]["answered"]);
			$posted["_item_views"]["answered_relative"] = array_filter($posted["_item_views"]["answered_relative"]);
		endif;

		/**
		 * The concept of 'save all possible data' is not so correct
		 * ALL data on current page must valid before any database operation or saves are made
		 * This should help avoid inconsistencies or having notes and headings spread across pages
		 */

		// Get items from database that are related to what is being posted
		$items = $this->getItemsWithChoices(null, array(
			'field' => 'name',
			'values' => array_keys($posted),
		));

		// Validate items and if any fails return user to same page with all unansered items and error messages
		// This loop also accumulates potential update data
		$update_data = array();
		foreach ($posted as $item_name => $item_value) {
			if (!isset($items[$item_name])) {
				continue;
			}

			/** @var $item Item */
			if ($item_value instanceof Item) {
				$item = $item_value;
				$item_value = $item->value_validated;
			} else {
				$item = $items[$item_name];
			}

			$validInput = ($validate && !$item->skip_validation) ? $item->validateInput($item_value) : $item_value;
			if ($item->save_in_results_table) {
				if ($item->error) {
					$this->validation_errors[$item_name] = $item->error;
				} else {
					$update_data[$item_name] = $item->getReply($validInput);
				}
				$item->value_validated = $item_value;
				$items[$item_name] = $item;
			}
		}

		if (!empty($this->validation_errors)) {
			$this->items_validated = $items;
			return false;
		}

		$survey_items_display = $this->dbh->prepare(
			"UPDATE `survey_items_display` SET 
				created = COALESCE(created,NOW()),
				answer = :answer, 
				saved = :saved,
				shown = :shown,
				shown_relative = :shown_relative,
				answered = :answered,
				answered_relative = :answered_relative,
				displaycount = COALESCE(displaycount,1),
				hidden = :hidden
			WHERE item_id = :item_id AND session_id = :session_id"); # fixme: displaycount starts at 2
		$survey_items_display->bindParam(":session_id", $this->session_id);

		try {
			$this->dbh->beginTransaction();

			// update item_display table for each posted item using prepared statement
			foreach ($posted AS $name => $value) {
				if (!isset($items[$name])) {
					continue;
				}

				/* @var $item Item */
				if ($value instanceof Item) {
					$item = $value;
					$value = $item->value_validated;
				} else {
					$item = $items[$name];
				}

				if (isset($posted["_item_views"]["shown"][$item->id], $posted["_item_views"]["shown_relative"][$item->id])) {
					$shown = $posted["_item_views"]["shown"][$item->id];
					$shown_relative = $posted["_item_views"]["shown_relative"][$item->id];
				} else {
					$shown = mysql_now();
					$shown_relative = null; // and where this is null, performance.now wasn't available
				}

				if (isset($posted["_item_views"]["answered"][$item->id], // separately to "shown" because of items like "note"
								$posted["_item_views"]["answered_relative"][$item->id])) {
					$answered = $posted["_item_views"]["answered"][$item->id];
					$answered_relative = $posted["_item_views"]["answered_relative"][$item->id];
				} else {
					$answered = $shown; // this way we can identify items where JS time failed because answered and show time are exactly identical
					$answered_relative = null;
				}

				$survey_items_display->bindValue(":item_id", $item->id);
				$survey_items_display->bindValue(":answer", $item->getReply($value));
				$survey_items_display->bindValue(":hidden", $item->skip_validation ? (int)$item->hidden : 0); // an item that was answered has to have been shown
				$survey_items_display->bindValue(":saved", mysql_now());
				$survey_items_display->bindParam(":shown", $shown);
				$survey_items_display->bindParam(":shown_relative", $shown_relative);
				$survey_items_display->bindParam(":answered", $answered);
				$survey_items_display->bindParam(":answered_relative", $answered_relative);
				$item_answered = $survey_items_display->execute();

				if (!$item_answered) {
					throw new Exception("Survey item '$name' could not be saved with value '$value' in table '{$this->results_table}' (FieldType: {$this->unanswered[$name]->getResultField()})");
				}
				unset($this->unanswered[$name]); //?? FIX ME
			} //endforeach

			// Update results table in one query
			if ($update_data) {
				$update_where = array(
					'study_id' => $this->id,
					'session_id' => $this->session_id,
				);
				$this->dbh->update($this->results_table, $update_data, $update_where);
			}
			$this->dbh->commit();
		} catch (Exception $e) {
			$this->dbh->rollBack();
			notify_user_error($e, 'An error occurred while trying to save your survey data. Please notify the author of this survey with this date and time');
			formr_log_exception($e, __CLASS__);
			//$redirect = false;
			return false;
		}

		// If all was well and we are re-directing then do so
		/*
		if ($redirect) {
			redirect_to($this->run_name);
		}
		*/
		return true;
	}

	protected function getProgress() {
		$answered = $this->dbh->select(array('COUNT(`survey_items_display`.saved)' => 'count', 'study_id', 'session_id'))
				->from('survey_items')
				->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
				->where('survey_items_display.session_id IS NOT NULL')
				->where('survey_items.study_id = :study_id')
				->where("survey_items.type NOT IN ('submit')")
				->where("`survey_items_display`.saved IS NOT NULL")
				->bindParams(array('session_id' => $this->session_id, 'study_id' => $this->id))
				->fetch();

		$this->progress_counts['already_answered'] = $answered['count'];

		/** @var Item $item */
		foreach ($this->unanswered as $item) {
			// count only rendered items, not skipped ones
			if ($item->isRendered($this)) {
				$this->progress_counts['not_answered'] ++;
			}
			// count those items that were hidden but rendered (ie. those relying on missing data for their showif)
			if ($item->isHiddenButRendered($this)) {
				$this->progress_counts['hidden_but_rendered'] ++;
			}
		}
		/** @var Item $item */
		foreach ($this->to_render as $item) {
			// On current page, count only rendered items, not skipped ones
			if ($item->isRendered()) {
				$this->progress_counts['visible_on_current_page'] ++;
			}
			// On current page, count those items that were hidden but rendered (ie. those relying on missing data for their showif)
			if ($item->isHiddenButRendered()) {
				$this->progress_counts['hidden_but_rendered_on_current_page'] ++;
			}
		}

		$this->progress_counts['not_answered_on_current_page'] = $this->progress_counts['not_answered'] - $this->progress_counts['visible_on_current_page'];

		$all_items = $this->progress_counts['already_answered'] + $this->progress_counts['not_answered'];

		if ($all_items !== 0) {
			$this->progress = $this->progress_counts['already_answered'] / $all_items;
		} else {
			$this->errors[] = _('Something went wrong, there are no items in this survey!');
			$this->progress = 0;
		}

		// if there only hidden items, that have no way of becoming visible (no other items)
		if ($this->progress_counts['not_answered'] === $this->progress_counts['hidden_but_rendered']) {
			$this->progress = 1;
		}
		return $this->progress;
	}

	/**
	 * Process show-ifs and dynamic values for a given set of items in survey
	 * @note: All dynamic values are processed (even for those we don't know if they will be shown)
	 *
	 * @param Item[] $items
	 * @return array
	 */
	protected function processDynamicValuesAndShowIfs(&$items) {
		// In this loop we gather all show-ifs and dynamic-values that need processing and all values.
		$code = array();

		/* @var $item Item */
		foreach ($items as $name => &$item) {
			// 1. Check item's show-if
			$showif = $item->getShowIf();
			if($showif) {
				$siname = "si.{$name}";
				$showif = str_replace("\n", "\n\t", $showif);
				$code[$siname] = "{$siname} = (function(){
	{$showif}
})()";
			}

			// 2. Check item's value
			if ($item->needsDynamicValue()) {
				$val = str_replace("\n","\n\t",$item->getValue($this));
				$code[$name] = "{$name} = (function(){
{$val}
})()";
				if($showif) {
					$code[$name] = "if({$siname}) {
	". $code[$name] ."
}";
				}
				// If item is to be shown (rendered), return evaluated dynamic value, else keep dynamic value as string
			}
			
		}

		if (!$code) {
			return $items;
		}

		$ocpu_session = opencpu_multiparse_showif($this, $code, true);
		if (!$ocpu_session || $ocpu_session->hasError()) {
			notify_user_error(opencpu_debug($ocpu_session), "There was a problem evaluating showifs using openCPU.");
			foreach($items as $name => &$item) {
				$item->alwaysInvalid();
			}
		} else {
			print_hidden_opencpu_debug_message($ocpu_session, "OpenCPU debugger for dynamic values and showifs.");
			$results = $ocpu_session->getJSONObject();
			$updateVisibility = $this->dbh->prepare("UPDATE `survey_items_display` SET hidden = :hidden WHERE item_id = :item_id AND session_id = :session_id");
			$updateVisibility->bindValue(":session_id", $this->session_id);

			$save = array();

			$definitelyShownItems = 0;
			foreach ($items as $item_name => &$item) {
				// set show-if visibility for items
				$siname = "si.{$item->name}";
				$isVisible = $item->setVisibility(array_val($results, $siname));
				// three possible states: 1 = hidden, 0 = shown, null = depends on JS on the page, render anyway
				if($isVisible === null) {
					// we only render it, if there are some items before it on which its display could depend
					// otherwise it's hidden for good
					$hidden = $definitelyShownItems > 0 ? null : 1;
				} else {
					$hidden = (int)!$isVisible;
				}
				$updateVisibility->bindValue(":item_id", $item->id);
				$updateVisibility->bindValue(":hidden", $hidden);
				$updateVisibility->execute();
				
				if($hidden === 1) { // gone for good
					unset($items[$item_name]); // we remove items that are definitely hidden from consideration
					continue; // don't increment counter
				} else {
					// set dynamic values for items
					$val = array_val($results, $item->name, null);
					$item->setDynamicValue($val);
					// save dynamic value
					// if a. we have a value b. this item does not require user input (e.g. calculate)
					if (array_key_exists($item->name, $results) && !$item->requiresUserInput()) {
						$save[$item->name] = $item->getComputedValue();
						unset($items[$item_name]); // we remove items that are immediately written from consideration
						continue; // don't increment counter
					}
				}
				$definitelyShownItems++; // track whether there are any items certain to be shown
			}
			$this->post($save, false);
		}

		return $items;
	}

	protected function processDynamicLabelsAndChoices(&$items) {
		// Gather choice lists
		$lists_to_fetch = $strings_to_parse = array();
		$session_labels = array();

		foreach ($items as $name => &$item) {
			if ($item->choice_list) {
				$lists_to_fetch[] = $item->choice_list;
			}
	
			if ($item->needsDynamicLabel($this) ) {
				$items[$name]->label_parsed = opencpu_string_key(count($strings_to_parse));
				$strings_to_parse[] = $item->label;
			}
		}

		// gather and format choice_lists and save all choice labels that need parsing
		$choices = $this->getChoices($lists_to_fetch, null);
		$choice_lists = array();
		foreach ($choices as $i => $choice) {
			if ($choice['label_parsed'] === null) {
				$choices[$i]['label_parsed'] = opencpu_string_key(count($strings_to_parse));
				$strings_to_parse[] = $choice['label'];
			}

			if (!isset($choice_lists[$choice['list_name']])) {
				$choice_lists[$choice['list_name']] = array();
			}
			$choice_lists[$choice['list_name']][$choice['name']] = $choices[$i]['label_parsed'];
		}

		// Now that we have the items and the choices, If there was anything left to parse, we do so here!
		if ($strings_to_parse) {
			$parsed_strings = opencpu_multistring_parse($this, $strings_to_parse);
			// Replace parsed strings in $choice_list array
			opencpu_substitute_parsed_strings($choice_lists, $parsed_strings);
			// Replace parsed strings in unanswered items array
			opencpu_substitute_parsed_strings($items, $parsed_strings);
		}

		// Merge parsed choice lists into items
		foreach ($items as $name => &$item) {
			$choice_list = $item->choice_list;
			if (isset($choice_lists[$choice_list])) {
				$list = $choice_lists[$choice_list];
				$list = array_filter($list, 'is_formr_truthy');
				$items[$name]->setChoices($list);
			}
			//$items[$name]->refresh($item, array('label_parsed'));
			$session_labels[$name] = $item->label_parsed;
		}

		Session::set('labels', $session_labels);
		return $items;
	}
	
	/**
	 * All items that don't require connecting to openCPU and don't require user input are posted immediately.
	 * Examples: get parameters, browser, ip.
	 *
	 * @param Item[] $items
	 * @return array Returns items that may have to be sent to openCPU or be rendered for user input
	 */
	protected function processAutomaticItems($items) {
		$hiddenItems = array();
		foreach ($items as $name => $item) {
			if (!$item->requiresUserInput() && !$item->needsDynamicValue()) {
				$hiddenItems[$name] = $item->getComputedValue();
				unset($items[$name]);
				continue;
			}
		}

		// save these values
		if ($hiddenItems) {
			$this->post($hiddenItems, false);
		}

		// return possibly shortened item array
		return $items;
	}

	/**
	 * Get the next items to be possibly displayed in the survey
	 *
	 * @return array Returns items that can be possibly shown on current page
	 */
	protected function getNextItems() {
		$this->unanswered = array();
		$select = $this->dbh->select('
				`survey_items`.id,
				`survey_items`.study_id,
				`survey_items`.type,
				`survey_items`.choice_list,
				`survey_items`.type_options,
				`survey_items`.name,
				`survey_items`.label,
				`survey_items`.label_parsed,
				`survey_items`.optional,
				`survey_items`.class,
				`survey_items`.showif,
				`survey_items`.value,

				`survey_items_display`.displaycount, 
				`survey_items_display`.session_id,
				`survey_items_display`.`display_order`,
				`survey_items_display`.`hidden`,
				`survey_items_display`.answered')
			->from('survey_items')
			->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
			->where('(survey_items.study_id = :study_id) AND 
				     (survey_items_display.saved IS null) AND 
				     (survey_items_display.hidden IS NULL OR survey_items_display.hidden = 0)')
			->order('`survey_items_display`.`display_order`', 'asc')
			->order('survey_items.`order`', 'asc') // only needed for transfer
			->order('survey_items.id', 'asc');

		$get_items = $select->bindParams(array('session_id' => $this->session_id, 'study_id' => $this->id))->statement();

		// We initialise item factory with no choice list because we don't know which choices will be used yet.
		// This assumes choices are not required for show-ifs and dynamic values (hope so)
		$itemFactory = new ItemFactory(array());
		$pageItems = array();
		$inPage = true;

		while ($item = $get_items->fetch(PDO::FETCH_ASSOC)) {
			/* @var $oItem Item */
			$oItem = $itemFactory->make($item);
			$this->unanswered[$oItem->name] = $oItem;

			// If no user input is required and item can be on current page, then save it to be shown
			if ($inPage) {
				$pageItems[$oItem->name] = $oItem;
			}

			if ($oItem->type === 'submit') {
				$inPage = false;
			}
		}

		return $pageItems;
	}

	protected function renderNextItems() {

		$this->dbh->beginTransaction();

		$view_query = "
			UPDATE `survey_items_display`
			SET displaycount = COALESCE(displaycount,0) + 1, created = COALESCE(created, NOW())
			WHERE item_id = :item_id AND session_id = :session_id";
		$view_update = $this->dbh->prepare($view_query);
		$view_update->bindValue(":session_id", $this->session_id);

		$itemsDisplayed = 0;

		$this->rendered_items = array();
		try {
			foreach ($this->to_render as &$item) {
				if ($this->settings['maximum_number_displayed'] && $this->settings['maximum_number_displayed'] === $itemsDisplayed) {
					break;
				} else if ($item->isRendered()) {
					// if it's rendered, we send it along here or update display count
					$view_update->bindParam(":item_id", $item->id);
					$view_update->execute();

					if (!$item->hidden) {
						$itemsDisplayed++;
					}

					$this->rendered_items[] = $item;
				}
			}

			$this->dbh->commit();
		} catch (Exception $e) {
			$this->dbh->rollBack();
			formr_log_exception($e, __CLASS__);
			return false;
		}
	}

	protected function render_form_header($action = null) {
		//$cookie = Request::getGlobals('COOKIE');
		$action = $action !== null ? $action : run_url($this->run_name);
		$enctype = 'multipart/form-data'; # maybe make this conditional application/x-www-form-urlencoded

		$tpl = '
			<form action="%{action}" method="post" class="%{class}" enctype="%{enctype}" accept-charset="utf-8">
				<input type="hidden" name="session_id" value="%{session_id}" />
				<input type="hidden" name="%{name_request_tokens}" value="%{request_tokens}" />
				<input type="hidden" name="%{name_user_code}" value="%{user_code}" />
				<input type="hidden" name="%{name_cookie}" value="%{cookie}" />
				
				<div class="row progress-container">
					<div class="progress">
						<div class="progress-bar" style="width: %{progress}%;" data-percentage-minimum="%{add_percentage_points}" data-percentage-maximum="%{displayed_percentage_maximum}" data-already-answered="%{already_answered}" data-items-left="%{not_answered_on_current_page}" data-items-on-page="%{items_on_page}" data-hidden-but-rendered="%{hidden_but_rendered}">
							%{progress} %
						</div>
					</div>
				</div>

				%{errors_tpl}
		';

		$errors_tpl = '
			<div class="alert alert-danger alert-dismissible form-message fmr-error-messages">
				<i class="fa fa-exclamation-triangle pull-left fa-2x"></i>
				<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				%{errors}
			</div>
		';

		if (!isset($this->settings["displayed_percentage_maximum"]) OR $this->settings["displayed_percentage_maximum"] == 0) {
			$this->settings["displayed_percentage_maximum"] = 100;
		}

		$prog = $this->progress * // the fraction of this survey that was completed
				($this->settings["displayed_percentage_maximum"] - // is multiplied with the stretch of percentage that it was accorded
				$this->settings["add_percentage_points"]);

		if (isset($this->settings["add_percentage_points"])) {
			$prog += $this->settings["add_percentage_points"];
		}

		if ($prog > $this->settings["displayed_percentage_maximum"]) {
			$prog = $this->settings["displayed_percentage_maximum"];
		}

		$prog = round($prog);

		$tpl_vars = array(
			'action' => $action,
			'class' => 'form-horizontal main_formr_survey' . ($this->settings['enable_instant_validation'] ? ' ws-validate' : ''),
			'enctype' => $enctype,
			'session_id' => $this->session_id,
			'name_request_tokens' => Session::REQUEST_TOKENS,
			'name_user_code' => Session::REQUEST_USER_CODE,
			'name_cookie' => Session::REQUEST_NAME,
			'request_tokens' => Session::getRequestToken(), //$cookie->getRequestToken(),
			'user_code' => h(Site::getCurrentUser()->user_code), //h($cookie->getData('code')),
			'cookie' => '', //$cookie->getFile(),
			'progress' => $prog,
			'add_percentage_points' => $this->settings["add_percentage_points"],
			'displayed_percentage_maximum' => $this->settings["displayed_percentage_maximum"],
			'already_answered' => $this->progress_counts['already_answered'],
			'not_answered_on_current_page' => $this->progress_counts['not_answered_on_current_page'],
			'items_on_page' => $this->progress_counts['not_answered'] - $this->progress_counts['not_answered_on_current_page'],
			'hidden_but_rendered' => $this->progress_counts['hidden_but_rendered_on_current_page'],

			'errors_tpl' => !empty($this->validation_errors) ? Template::replace($errors_tpl, array('errors' => $this->render_errors($this->validation_errors))) : null,
		);

		return Template::replace($tpl, $tpl_vars);
	}

	protected function render_items() {
		$ret = '';

		foreach ($this->rendered_items AS $item) {
			if (!empty($this->validation_errors[$item->name])) {
				$item->error = $this->validation_errors[$item->name];
			}
			if (!empty($this->items_validated[$item->name])) {
				$item->value_validated = $this->items_validated[$item->name]->value_validated;
			}
			$ret .= $item->render();
		}

		// if the last item was not a submit button, add a default one
		if (isset($item) && ($item->type !== "submit" || $item->hidden)) {
			$sub_sets = array(
				'label_parsed' => '<i class="fa fa-arrow-circle-right pull-left fa-2x"></i> Go on to the<br>next page!',
				'classes_input' => array('btn-info default_formr_button'),
			);
			$item = new Submit_Item($sub_sets);
			$ret .= $item->render();
		}

		return $ret;
	}

	protected function render_form_footer() {
		return '</form>';
	}

	/**
	 * 
	 * @param Item[] $items
	 * @return string
	 */
	protected function render_errors($items) {
		$labels = Session::get('labels', array());
		$tpl = 
		'<li>
			<i class=""></i>
			<b>Question/Code</b>: %{question} <br />
			<b>Error</b>: %{error}
		 </li>
		';
		$errors = '';

		foreach ($items as $name => $error) {
			if ($error) {
				$errors .= Template::replace($tpl, array(
					'question' => strip_tags(array_val($labels, $name, strtoupper($name))),
					'error' => $error,
				));
			}
		}
		Session::delete('labels');
		return '<ul>' . $errors . '</ul>';
	}


	public function end() {
		$query = "UPDATE `{$this->results_table}` SET `ended` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS null";
		$params = array('session_id' => $this->session_id, 'study_id' => $this->id);
		$ended = $this->dbh->exec($query, $params);

		return parent::end();
	}

	protected function getTimeWhenLastViewedItem() {
		// use created (item render time) if viewed time is lacking
		$arr = $this->dbh->select(array('COALESCE(`survey_items_display`.shown,`survey_items_display`.created)' => 'last_viewed'))
				->from('survey_items_display')
				->leftJoin('survey_items', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
				->where('survey_items_display.session_id IS NOT NULL')
				->where('survey_items.study_id = :study_id')
				->order('survey_items_display.shown', 'desc')
				->order('survey_items_display.created', 'desc')
				->limit(1)
				->bindParams(array('session_id' => $this->session_id, 'study_id' => $this->id))
				->fetch();

		return isset($arr['last_viewed']) ? $arr['last_viewed'] : null;
	}

	/**
	 * @see https://github.com/rubenarslan/formr.org/wiki/Expiry
	 * @return boolean
	 */
	private function hasExpired() {
		$expire_invitation = (int) $this->settings['expire_invitation_after'];
		$grace_period = (int) $this->settings['expire_invitation_grace'];
		$expire_inactivity = (int) $this->settings['expire_after'];
		if ($expire_inactivity === 0 && $expire_invitation === 0) {
			return false;
		} else {
			$now = time();

			$last_active = $this->getTimeWhenLastViewedItem(); // when was the user last active on the study
			$expire_invitation_time = $expire_inactivity_time = 0; // default to 0 (means: other values supervene. users only get here if at least one value is nonzero)
			if($expire_inactivity !== 0 && $last_active != null) {
				$expire_inactivity_time = strtotime($last_active) + $expire_inactivity * 60;
			}
			$invitation_sent = $this->run_session->unit_session->created;
			if($expire_invitation !== 0 && $invitation_sent) {
				$expire_invitation_time = strtotime($invitation_sent) + $expire_invitation * 60;
				if($grace_period !== 0 && $last_active) {
					$expire_invitation_time = $expire_invitation_time + $grace_period * 60;
				}
			}
			$expire = max($expire_inactivity_time, $expire_invitation_time);
			return ($expire > 0) && ($now > $expire); // when we switch to the new scheduler, we need to return the timestamp here
		}
	}

	public function exec() {
		// never show to the cronjob
		if ($this->called_by_cron) {
			if ($this->hasExpired()) {
				$this->expire();
				return false;
			}
			return true;
		}

		// execute survey unit in a try catch block
		// @todo Do same for other run units
		try {
			$request = new Request($_POST);
			$cookie = Request::getGlobals('COOKIE');
			//check if user session has a valid form token for POST requests
			//if (Request::isHTTPPostRequest() && $cookie && !$cookie->canValidateRequestToken($request)) {
			//	redirect_to(run_url($this->run_name));
			//}
			if (Request::isHTTPPostRequest() && !Session::canValidateRequestToken($request)) {
				redirect_to(run_url($this->run_name));
			}
			$this->startEntry();

			// Use SurveyHelper if study is configured to use pages
			if ($this->settings['use_paging']) {
				$surveyHelper = new SurveyHelper(new Request(array_merge($_POST, $_FILES)), $this, new Run($this->dbh, $this->run_name));
				$surveyHelper->savePageItems($this->session_id);
				if (($renderSurvey = $surveyHelper->renderSurvey($this->session_id)) !== false) {
					return array('body' => $renderSurvey);
				} else {
					// Survey ended
					return false;
				}
			}

			// POST items only if request is a post request
			if (Request::isHTTPPostRequest()) {
				$posted = $this->post(array_merge($request->getParams(), $_FILES));
				if ($posted) {
					redirect_to(run_url($this->run_name));
				}
			}

			$loops = 0;
			while(($items = $this->getNextItems())) {
				// exit loop if it has ran more than x times and log remaining items
				$loops++;
				if ($loops > Config::get('allowed_empty_pages', 80)) {
					alert('Too many empty pages in this survey. Please alert an administrator.', 'alert-danger');
					formr_log("Survey::exec() '{$this->run_name} > {$this->name}' terminated with an infinite loop for items: ");
					formr_log(array_keys($items));
					break;
				}
				// process automatic values (such as get, browser)
				$items = $this->processAutomaticItems($items);
				// process showifs, dynamic values for these items
				$items = $this->processDynamicValuesAndShowIfs($items);
				// If no items survived all the processing then move on
				if (!$items) {
					continue;
				}
				$lastItem = end($items);

				// If no items ended up to be on the page but for a submit button, make it hidden and continue
				// else render processed items
				if(count($items) == 1 && $lastItem->type === 'submit') {
					$sess_item = array(
						'session_id' => $this->session_id,
						'item_id' => $lastItem->id,
					);
					$this->dbh->update('survey_items_display', array('hidden' => 1), $sess_item);
					continue;
				} else {
					$this->to_render = $this->processDynamicLabelsAndChoices($items);
					break;
				}
			}

			if ($this->getProgress() === 1) {
				$this->end();
				return false;
			}

			$this->renderNextItems();

			return array('body' => $this->render());
		} catch (Exception $e) {
			formr_log_exception($e, __CLASS__);
			return array('body' => '');
		}
	}

	public function changeSettings($key_value_pairs) {
		$errors = false;
		array_walk($key_value_pairs, function (&$value, $key) {
		    if ($key !== 'google_file_id') {
		        $value = (int) $value;
		    }
		});
		if (isset($key_value_pairs['maximum_number_displayed']) && $key_value_pairs['maximum_number_displayed'] > 3000 || $key_value_pairs['maximum_number_displayed'] < 0) {
			alert("Maximum number displayed has to be between 1 and 3000", 'alert-warning');
			$errors = true;
		}

		if (isset($key_value_pairs['displayed_percentage_maximum']) && $key_value_pairs['displayed_percentage_maximum'] > 100 || $key_value_pairs['displayed_percentage_maximum'] < 1) {
			alert("Percentage maximum has to be between 1 and 100.", 'alert-warning');
			$errors = true;
		}

		if (isset($key_value_pairs['add_percentage_points']) && $key_value_pairs['add_percentage_points'] > 100 || $key_value_pairs['add_percentage_points'] < 0) {
			alert("Percentage points added has to be between 0 and 100.", 'alert-warning');
			$errors = true;
		}

		$key_value_pairs['enable_instant_validation'] = (int)(isset($key_value_pairs['enable_instant_validation']) && $key_value_pairs['enable_instant_validation'] == 1);
		$key_value_pairs['hide_results'] = (int)(isset($key_value_pairs['hide_results']) && $key_value_pairs['hide_results'] === 1);
		$key_value_pairs['use_paging'] = (int)(isset($key_value_pairs['use_paging']) && $key_value_pairs['use_paging'] === 1);
		$key_value_pairs['unlinked'] = (int)(isset($key_value_pairs['unlinked']) && $key_value_pairs['unlinked'] === 1);

		// user can't revert unlinking
		if($key_value_pairs['unlinked'] < $this->settings['unlinked']) {
			alert("Once a survey has been unlinked, it cannot be relinked.", 'alert-warning');
			$errors = true;
		}

		// user can't revert preventing results display
		if($key_value_pairs['hide_results'] < $this->settings['hide_results']) {
			alert("Once results display is disabled, it cannot be re-enabled.", 'alert-warning');
			$errors = true;
		}

		// user can't revert preventing results display
		if($key_value_pairs['use_paging'] < $this->settings['use_paging']) {
			alert("Once you have enabled the use of custom paging, you can't revert this setting.", 'alert-warning');
			$errors = true;
		}

		if (isset($key_value_pairs['expire_after']) && $key_value_pairs['expire_after'] > 3153600) {
			alert("Survey expiry time (in minutes) has to be below 3153600.", 'alert-warning');
			$errors = true;
		}

		if (array_diff(array_keys($key_value_pairs), array_keys($this->settings))) { // any settings that aren't in the settings array?
			alert("Invalid settings.", 'alert-danger');
			$errors = true;
		}

		if ($errors) {
			return false;
		}

		$this->dbh->update('survey_studies', $key_value_pairs, array('id' => $this->id));

		alert('Survey settings updated', 'alert-success', true);
	}

//  
	public function uploadItemTable($file, $confirmed_deletion, $updates = array(), $created_new = false) {
		umask(0002);
		ini_set('memory_limit', Config::get('memory_limit.survey_upload_items'));
		$target = $file['tmp_name'];
		$filename = $file['name'];

		$this->confirmed_deletion = $confirmed_deletion;
		$this->created_new = $created_new;
		
		$this->messages[] = "File <b>$filename</b> was uploaded to survey <b>{$this->name}</b>.";

		// @todo FIXME: This check is fakish because for some reason finfo_file doesn't deal with excel sheets exported from formr
		// survey uploaded via JSON?
		if (preg_match('/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.json$/', $filename)) {
			$data = @json_decode(file_get_contents($target));
			$SPR = $this->createFromData($data, true);
		} else {
			// survey uploaded via excel
			$SPR = new SpreadsheetReader();
			$SPR->readItemTableFile($target);
		}

		if (!$SPR || !is_object($SPR)) {
			alert('Spreadsheet object could not be created!', 'alert-danger');
			return false;
		}

		$this->errors = array_merge($this->errors, $SPR->errors);
		$this->warnings = array_merge($this->warnings, $SPR->warnings);
		$this->messages = array_merge($this->messages, $SPR->messages);
		$this->messages = array_unique($this->messages);
		$this->warnings = array_unique($this->warnings);

		// if items are ok, make actual survey
		if (empty($this->errors) && $this->createSurvey($SPR)):
			if (!empty($this->warnings)) {
				alert('<ul><li>' . implode("</li><li>", $this->warnings) . '</li></ul>', 'alert-warning');
			}

			if (!empty($this->messages)) {
				alert('<ul><li>' . implode("</li><li>", $this->messages) . '</li></ul>', 'alert-info');
			}

			// save original survey sheet
			$filename = 'formr-survey-' . Site::getCurrentUser()->id . '-' . $filename;
			$file = Config::get('survey_upload_dir') . '/' . $filename;
			if (file_exists($target) && (move_uploaded_file($target, $file) || rename($target, $file))) {
				$updates['original_file'] = $filename;	
			} else {
				alert('Unable to save original uploaded file', 'alert-warning');
			}

			// update db entry if necessary
			if ($updates) {
				$this->dbh->update('survey_studies', $updates, array('id' => $this->id));
			}

			return true;
		else:
			alert('<ul><li>' . implode("</li><li>", $this->errors) . '</li></ul>', 'alert-danger');
			return false;
		endif;
	}

	protected function resultsTableExists() {
		return $this->dbh->table_exists($this->results_table);
	}

	/* ADMIN functions */

	public function checkName($name) {
		if (!$name) {
			alert(_("<strong>Error:</strong> The study name (the name of the file you uploaded) can only contain the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. The name has to at least 2, at most 64 characters long. It needs to start with a letter. No dots, no spaces, no dashes, no umlauts please. The file can have version numbers after a dash, like this <code>survey_1-v2.xlsx</code>, but they will be ignored."), 'alert-danger');
			return false;
		} elseif (!preg_match($this->study_name_pattern, $name)) {
			alert('<strong>Error:</strong> The study name (the name of the file you uploaded) can only contain the characters from a to Z, 0 to 9 and the underscore. It needs to start with a letter. The file can have version numbers after a dash, like this <code>survey_1-v2.xlsx</code>.', 'alert-danger');
			return false;
		} else {
			$study_exists = $this->dbh->entry_exists('survey_studies', array('name' => $name, 'user_id' => $this->unit['user_id']));
			if ($study_exists) {
				alert(__("<strong>Error:</strong> The survey name %s is already taken.", h($name)), 'alert-danger');
				return false;
			}
		}

		return true;
	}

	public function createIndependently($settings = array(), $updates = array()) {
		$name = trim($this->unit['name']);
		$check_name = $this->checkName($name);
		if(!$check_name) {
			return false;	
		}
		$this->id = parent::create('Survey');
		$this->name = $name;

		$results_table = substr("s" . $this->id . '_' . $name, 0, 64);

		if($this->dbh->table_exists($results_table)) {
			alert("Results table name conflict. This shouldn't happen. Please alert the formr admins.", 'alert-danger');
			return false;
		}
		$this->results_table = $results_table;

		$study = array_merge(array(
			'id' => $this->id,
			'created' => mysql_now(),
			'modified' => mysql_now(),
			'user_id' => $this->unit['user_id'],
			'name' => $this->name,
			'results_table' => $this->results_table,
		), $updates);

		$this->dbh->insert('survey_studies', $study);
		$this->load();

		$this->changeSettings(array_merge(array(
			"maximum_number_displayed" => 0,
			"displayed_percentage_maximum" => 100,
			"add_percentage_points" => 0,
		), $settings));

		return true;
	}

	protected $user_defined_columns = array(
		'name', 'label', 'label_parsed', 'type', 'type_options', 'choice_list', 'optional', 'class', 'showif', 'value', 'block_order', 'item_order', 'order' // study_id is not among the user_defined columns
	);
	protected $choices_user_defined_columns = array(
		'list_name', 'name', 'label', 'label_parsed' // study_id is not among the user_defined columns
	);

	/**
	 * Get All choice lists in this survey with associated items
	 *
	 * @param array $specific An array if list_names which if defined, only lists specified in the array will be returned
	 * @param string $label
	 * @return $array Returns an array indexed by list name;
	 */
	public function getChoices($specific = null, $label = 'label') {
		$select = $this->dbh->select('list_name, name, label, label_parsed');
		$select->from('survey_item_choices');
		$select->where(array('study_id' => $this->id));

		if (!$specific && $specific !== null) {
			return array();
		} elseif ($specific !== null) {
			$select->whereIn('list_name', $specific);
		}
		$select->order('id', 'ASC');

		$lists = array();
		$stmt = $select->statement();

		// If we are not hunting for a particular field name return list as is
		if (!$label) {
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		}

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($lists[$row['list_name']])) {
				$lists[$row['list_name']] = array();
			}
			$lists[$row['list_name']][$row['name']] = $row[$label];
		}

		return $lists;
	}

	public function getChoicesForSheet() {
		return $this->dbh->select('list_name, name, label')
						->from('survey_item_choices')
						->where(array('study_id' => $this->id))
						->order('id', 'ASC')->fetchAll();
	}

	/**
	 * Create a new survey using items uploaded by the spreadsheet reader
	 * Cases:
	 * 0. new creation -> do it
	 * 1. is the upload identical to the existing -> do nothing
	 * 2. are there any results at all? -> nuke existing shit, do fresh start
	 * 3. does the upload entail text/minor changes to the item table -> simply do changes
	 * 4. does the upload entail changes to results (i.e. will there be deletion) -> confirm deletion
	 *    4a. deletion confirmed: backup results, modify results table, drop stuff
	 *    4b. deletion not confirmed: cancel the whole shit.
	 * 
	 * @param SpreadsheetReader $SPR
	 * @return boolean
	 */
	public function createSurvey($SPR) {
		$this->SPR = $SPR;
		$this->parsedown = new ParsedownExtra();
		$this->parsedown = $this->parsedown->setBreaksEnabled(true)->setUrlsLinked(true);

		// Get old choice lists for getting old items
		$choice_lists = $this->getChoices();
		$this->item_factory = new ItemFactory($choice_lists);
		
		// Get old items, mark them as false meaning all are vulnerable for delete.
		// When the loop over survey items ends you will know which should be deleted.
		$old_items = array();
		$old_items_in_results = array();

		foreach ($this->getItems() as $item) {
			if (($object = $this->item_factory->make($item)) !== false) {
				$old_items[$item['name']] = $object->getResultField();
				if ($object->isStoredInResultsTable()) {
					$old_items_in_results[] = $item['name'];
				}
			}
		}

		try {
			$this->dbh->beginTransaction();
			$data = $this->addItems();
			$new_items = $data['new_items'];
			$result_columns = $data['result_columns'];

			$added = array_diff_assoc($new_items, $old_items);
			$deleted = array_diff_assoc($old_items, $new_items);
			$unused = $this->item_factory->unusedChoiceLists();
			if ($unused) {
				$this->warnings[] = __("These choice lists were not used: '%s'", implode("', '", $unused));
			}

			// If there are items to delete, check if user confirmed deletion and if so check if back up succeeded
			if(count($deleted) > 0) {
				if($this->realDataExists() && !$this->confirmed_deletion) {
					$deleted_columns_string =  implode(array_keys($deleted), ", ");
					$this->errors[] = "<strong>No permission to delete data</strong>. Enter the survey name, if you are okay with data being deleted from the following items: " . $deleted_columns_string;
				}
				if($this->realDataExists() && $this->confirmed_deletion && !$this->backupResults($old_items_in_results)) {
					$this->errors[] = "<strong>Back up failed.</strong> Deletions would have been necessary, but backing up the item table failed, so no modification was carried out.</strong>";
				}
			}
			
			// If there are errors at this point then terminate to rollback all changes inluding adding choices and inserting items
			if (!empty($this->errors)) {
				throw new Exception('Process terminated prematurely due to errors');
			}

			$actually_deleted = array_diff(array_keys($deleted), array_keys($added));
			if ($deleted && $actually_deleted) {
				// some items were just re-typed, they only have to be deleted from the wide format table which has inflexible types
				$toDelete = implode(',', array_map(array($this->dbh, 'quote'), $actually_deleted));
				$studyId = (int) $this->id;
				$delQ = "DELETE FROM survey_items WHERE `name` IN ($toDelete) AND study_id = $studyId";
				$this->dbh->query($delQ);
			}
			
			// we start fresh if it's a new creation, no results table exist or it is completely empty
			if ($this->created_new || !$this->resultsTableExists() || !$this->dataExists()) {
				if($this->created_new && $this->resultsTableExists()) {
					throw new Exception("Results table name conflict. This shouldn't happen. Please alert the formr admins");
				}
				// step 2
				$this->messages[] = "The results table was newly created, because there were no results and test sessions.";
				// if there is no results table or no existing data at all, drop table, create anew
				// i.e. where possible prefer nuclear option
				$new_syntax = $this->getResultsTableSyntax($result_columns);
				if(!$this->createResultsTable($new_syntax)) {
					throw new Exception('Unable to create a data table for survey results');
				}
			} else {
				// this will never happen if deletion was not confirmed, because this would raise an error
				// 2 and 4a
				$merge = $this->alterResultsTable($added, $deleted);
				if(!$merge) {
					throw new Exception('Required modifications could not be made to the survey results table');
				}
			}

			$this->dbh->commit();
			return true;
		} catch (Exception $e) {
			$this->dbh->rollBack();
			$this->errors[] = 'Error: ' . $e->getMessage();
			formr_log_exception($e, __CLASS__, $this->errors);
			return false;
		}

	}

	/**
	 * Prepares the statement to insert new items and returns an associative containing
	 * the SQL definition of the new items and the new result columns
	 *
	 * @see createSurvey()
	 * @return array array(new_items, result_columns)
	 */
	protected function addItems() {
		// Save new choices and re-build the item factory
		$this->addChoices();
		$choice_lists = $this->getChoices();
		$this->item_factory = new ItemFactory($choice_lists);

		// Prepare SQL statement for adding items
		$UPDATES = implode(', ', get_duplicate_update_string($this->user_defined_columns));
		$addStmt = $this->dbh->prepare(
			"INSERT INTO `survey_items` (study_id, name, label, label_parsed, type, type_options, choice_list, optional, class, showif, value, `block_order`,`item_order`, `order`) 
			VALUES (:study_id, :name, :label, :label_parsed, :type, :type_options, :choice_list, :optional, :class, :showif, :value, :block_order, :item_order, :order) 
			ON DUPLICATE KEY UPDATE $UPDATES");
		$addStmt->bindParam(":study_id", $this->id);

		$ret = array(
			'new_items' => array(),
			'result_columns' => array(),
		);

		foreach ($this->SPR->survey as $row_number => $row) {
			$item = $this->item_factory->make($row);
			if (!$item) {
				$this->errors[] = __("Row %s: Type %s is invalid.", $row_number, array_val($this->SPR->survey[$row_number], 'type'));
				unset($this->SPR->survey[$row_number]);
				continue;
			}

			$val_results = $item->validate();
			if (!empty($val_results['val_errors'])) {
				$this->errors = $this->errors + $val_results['val_errors'];
				unset($this->SPR->survey[$row_number]);
				continue;
			}
			if(!empty($val_results['val_warnings'])) {
				$this->warnings = $this->warnings + $val_results['val_warnings'];
			}

			// if the parsed label is constant or exists
			if (!$this->knittingNeeded($item->label) && !$item->label_parsed) {
				$markdown = $this->parsedown->text($item->label);
				$item->label_parsed = $markdown;
				if (mb_substr_count($markdown, "</p>") === 1 AND preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)) {
					$item->label_parsed = $matches[1];
				}
			}

			foreach ($this->user_defined_columns as $param) {
				$addStmt->bindValue(":$param", $item->$param);
			}

			$result_field = $item->getResultField();
			$ret['new_items'][$item->name] = $result_field;
			$ret['result_columns'][] = $result_field;

			$addStmt->execute();
		}

		return $ret;
	}

	public function getItemsWithChoices($columns = null, $whereIn = null) {
		if($this->resultsTableExists()) {
			$choice_lists = $this->getChoices();
			$this->item_factory = new ItemFactory($choice_lists);

			$raw_items = $this->getItems($columns, $whereIn);

			$items = array();
			foreach ($raw_items as $row) {
				$item = $this->item_factory->make($row);
				$items[$item->name] = $item;
			}
			return $items;
		} else {
			return array();
		}
	}
	public function getItemsInResultsTable() {
		$items = $this->getItems();
		$names = array();
		$itemFactory = new ItemFactory(array());
		foreach($items AS $item) {
			$item = $itemFactory->make($item);
			if($item->isStoredInResultsTable()) {
				$names[] = $item->name;
			}
		}
		return $names;
	}

	private function addChoices() {
		// delete cascades to item display ?? FIXME so maybe not a good idea to delete then
		$deleted = $this->dbh->delete('survey_item_choices', array('study_id' => $this->id));
		$addChoiceStmt = $this->dbh->prepare(
			'INSERT INTO `survey_item_choices` (study_id, list_name, name, label, label_parsed) 
			 VALUES (:study_id, :list_name, :name, :label, :label_parsed )'
		);
		$addChoiceStmt->bindParam(":study_id", $this->id);

		foreach ($this->SPR->choices as $choice) {
			if (isset($choice['list_name']) && isset($choice['name']) && isset($choice['label'])) {
				if (!$this->knittingNeeded($choice['label']) && empty($choice['label_parsed'])) { // if the parsed label is constant
					$markdown = $this->parsedown->text($choice['label']); // transform upon insertion into db instead of at runtime
					$choice['label_parsed'] = $markdown;
					if (mb_substr_count($markdown, "</p>") === 1 AND preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)) {
						$choice['label_parsed'] = $matches[1];
					}
				}

				foreach ($this->choices_user_defined_columns as $param) {
					$addChoiceStmt->bindValue(":$param", $choice[$param]);
				}
				$addChoiceStmt->execute();
			}
		}

		return true;
	}

	private function getResultsTableSyntax($columns) {
		$columns = array_filter($columns); // remove null, false, '' values (note, fork, submit, ...)

		if (empty($columns)) {
			$columns_string = ''; # create a results tabel with only the access times
		} else {
			$columns_string = implode(",\n", $columns) . ",";
		}

		$create = "
		CREATE TABLE `{$this->results_table}` (
		  `session_id` INT UNSIGNED NOT NULL ,
		  `study_id` INT UNSIGNED NOT NULL ,
		  `created` DATETIME NULL DEFAULT NULL ,
		  `modified` DATETIME NULL DEFAULT NULL ,
		  `ended` DATETIME NULL DEFAULT NULL ,
	
		  $columns_string

		  PRIMARY KEY (`session_id`) ,
		  INDEX `idx_survey_results_survey_studies` (`study_id` ASC) ,
		  INDEX `idx_ending` (`session_id` DESC, `study_id` ASC, `ended` ASC) ,
		  CONSTRAINT
		    FOREIGN KEY (`session_id` )
		    REFERENCES `survey_unit_sessions` (`id` )
		    ON DELETE CASCADE
		    ON UPDATE NO ACTION,
		  CONSTRAINT
		    FOREIGN KEY (`study_id` )
		    REFERENCES `survey_studies` (`id` )
		    ON DELETE NO ACTION
		    ON UPDATE NO ACTION)
		ENGINE = InnoDB";
		return $create;
	}

	private function createResultsTable($syntax) {
		if ($this->deleteResults()) {
			$drop = $this->dbh->query("DROP TABLE IF EXISTS `{$this->results_table}` ;");
			$drop->execute();
		} else {
			return false;
		}

		$create_table = $this->dbh->query($syntax);
		if ($create_table) {
			return true;
		}
		return false;
	}

	public function getItems($columns = null, $whereIn = null) {
		if ($columns === null) {
			$columns = "id, study_id, type, choice_list, type_options, name, label, label_parsed, optional, class, showif, value, block_order,item_order";
		}

		$select =  $this->dbh->select($columns);
		$select->from('survey_items');
		$select->where(array('study_id' => $this->id));
		if ($whereIn) {
			$select->whereIn($whereIn['field'], $whereIn['values']);
		}
		$select->order("order");
		return $select->fetchAll();
	}

	public function getItemsForSheet() {
		$get_items = $this->dbh->select('type, type_options, choice_list, name, label, optional, class, showif, value, block_order, item_order')
				->from('survey_items')
				->where(array('study_id' => $this->id))
				->order("`survey_items`.order")
				->statement();

		$results = array();
		while ($row = $get_items->fetch(PDO::FETCH_ASSOC)):
			$row["type"] = $row["type"] . " " . $row["type_options"] . " " . $row["choice_list"];
			unset($row["choice_list"], $row["type_options"]); //FIXME: why unset here?
			$results[] = $row;
		endwhile;

		return $results;
	}

	public function getResults($items = null, $filter = null, array $paginate = null, $runId = null, $rstmt = false) {
		if ($this->resultsTableExists()) {
			ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

			$results_table = $this->results_table;
			if (!$items) {
				$items = $this->getItemsInResultsTable();
			}
			
			$count = $this->getResultCount();
			$get_all = true;
			if($this->settings['unlinked'] && $count['real_users'] <= 10) {
				if($count['real_users'] > 0) {
					alert("<strong>You cannot see the real results yet.</strong> It will only be possible after 10 real users have registered.", 'alert-warning');
				}
				$get_all = false;
			}
			if($this->settings['unlinked']) {
				$columns = array();
				// considered showing data for test sessions, but then researchers could set real users to "test" to identify them
/*				$columns = array(
					"IF(survey_run_sessions.testing, survey_run_sessions.session, '') AS session",
					"IF(survey_run_sessions.testing, `{$results_table}`.`created`, '') AS created",
					"IF(survey_run_sessions.testing, `{$results_table}`.`modified`, '') AS modified",
					"IF(survey_run_sessions.testing, `{$results_table}`.`ended`, '') AS ended",
				);
*/			} else {
				$columns = array('survey_run_sessions.session', "`{$results_table}`.`created`", "`{$results_table}`.`modified`", "`{$results_table}`.`ended`, `survey_unit_sessions`.`expired`");
			}
			foreach ($items as $item) {
				$columns[] = "{$results_table}.{$item}";
			}
			
			$select = $this->dbh->select($columns)
					->from($results_table)
					->leftJoin('survey_unit_sessions', "{$results_table}.session_id = survey_unit_sessions.id")
					->leftJoin('survey_run_sessions', 'survey_unit_sessions.run_session_id = survey_run_sessions.id');
			if (!$get_all) {
				$select->where('survey_run_sessions.testing = 1');
			}

			if ($runId !== null) {
				$select->where("survey_run_sessions.run_id = {$runId}");
			}

			if ($paginate && isset($paginate['offset'])) {
				$order = isset($paginate['order']) ? $paginate['order'] : 'asc';
				$order_by = isset($paginate['order_by']) ? $paginate['order_by'] : '{$results_table}.session_id';
				if($this->settings['unlinked']) {
					$order_by = "RAND()";
				}
				$select->order($order_by, $order);
				$select->limit($paginate['limit'], $paginate['offset']);
			}

			if (!empty($filter['session'])) {
				$session = $filter['session'];
				strlen($session) == 64 ? $select->where("survey_run_sessions.session = '$session'") : $select->like('survey_run_sessions.session', $session, 'right');
			}

			if (!empty($filter['results']) && ($res_filter = $this->getResultsFilter($filter['results']))) {
				$res_where = Template::replace($res_filter['query'], array('table' => $results_table));
				$select->where($res_where);
			}

			$stmt = $select->statement();
			if ($rstmt === true) {
				return $stmt;
			}

			$results = array();
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				unset($row['study_id']);
				$results[] = $row;
			}

			return $results;
		} else {
			return array();
		}
	}

	/**
	 * Get Results from the item display table
	 *
	 * @param array $items An array of item names that are required in the survey
	 * @param string $session If specified, only results of that particular session will be returned
	 * @param array $paginate Pagination parameters [offset, limit]
	 * @param boolean $rstmt If TRUE, PDOStament will be returned instead
	 * @return array|PDOStatement
	 */
	public function getItemDisplayResults($items = array(), $filter = null, array $paginate = null, $rstmt = false) {
		ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

		$count = $this->getResultCount();
		if($this->settings['unlinked']) {
			if($count['real_users'] > 0) {
				alert("<strong>You cannot see the long-form results yet.</strong> It will only be possible after 10 real users have registered.", 'alert-warning');
			}
			return array();
		}
		
		$select = $this->dbh->select("`survey_run_sessions`.session,
		`survey_items_display`.`session_id` as `unit_session_id`,
		`survey_items_display`.`item_id`,
		`survey_items`.`name` as `item_name`,
		`survey_items_display`.`answer`,
		`survey_items_display`.`created`,
		`survey_items_display`.`saved`,
		`survey_items_display`.`shown`,
		`survey_items_display`.`shown_relative`,
		`survey_items_display`.`answered`,
		`survey_items_display`.`answered_relative`,
		`survey_items_display`.`displaycount`,
		`survey_items_display`.`display_order`,
		`survey_items_display`.`hidden`");

		$select->from('survey_items_display')
				->leftJoin('survey_unit_sessions', 'survey_unit_sessions.id = survey_items_display.session_id')
				->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
				->leftJoin('survey_items', 'survey_items_display.item_id = survey_items.id')
				->where('survey_items.study_id = :study_id')
				->order('survey_run_sessions.session')
				->order('survey_run_sessions.created')
				->order('survey_unit_sessions.created')
				->order('survey_items_display.display_order')
				->bindParams(array('study_id' => $this->id));

		if ($items) {
			$select->whereIn('survey_items.name', $items);
		}

		$session = array_val($filter, 'session', null);
		if ($session) {
			if(strlen($session) == 64) {
				$select->where("survey_run_sessions.session = :session");
			} else {
				$select->where("survey_run_sessions.session LIKE :session");
				$session .= "%";
			}
			$select->bindParams(array("session" => $session));
		}

		if ($paginate && isset($paginate['offset'])) {
			$select->limit($paginate['limit'], $paginate['offset']);
		}

		if ($rstmt === true) {
			return $select->statement();
		}
		return $select->fetchAll();
	}

	public function getResultsByItemsPerSession($items = array(), $filter = null, array $paginate = null, $rstmt = false) {
		if($this->settings['unlinked']) {
			return array();
		}
		ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

		$filter_select = $this->dbh->select('session_id');
		$filter_select->from($this->results_table);
		$filter_select->leftJoin('survey_unit_sessions', "{$this->results_table}.session_id = survey_unit_sessions.id");
		$filter_select->leftJoin('survey_run_sessions', 'survey_unit_sessions.run_session_id = survey_run_sessions.id');

		if (!empty($filter['session'])) {
			$session = $filter['session'];
			strlen($session) == 64 ? $filter_select->where("survey_run_sessions.session = '$session'") : $filter_select->like('survey_run_sessions.session', $session, 'right');
		}

		if (!empty($filter['results']) && ($res_filter = $this->getResultsFilter($filter['results']))) {
			$res_where = Template::replace($res_filter['query'], array('table' => $this->results_table));
			$filter_select->where($res_where);
		}
		$filter_select->order('session_id');
		if ($paginate && isset($paginate['offset'])) {
			$filter_select->limit($paginate['limit'], $paginate['offset']);
		}
		$stmt = $filter_select->statement();
		$session_ids = '';
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$session_ids .= "{$row['session_id']},";
		}
		$session_ids = trim($session_ids, ',');

		$select = $this->dbh->select("
		`survey_run_sessions`.session,
		`survey_items_display`.`session_id` as `unit_session_id`,
		`survey_items`.`name` as `item_name`,
		`survey_items_display`.`item_id`,
		`survey_items_display`.`answer`,
		`survey_items_display`.`created`,
		`survey_items_display`.`saved`,
		`survey_items_display`.`shown`,
		`survey_items_display`.`shown_relative`,
		`survey_items_display`.`answered`,
		`survey_items_display`.`answered_relative`,
		`survey_items_display`.`displaycount`,
		`survey_items_display`.`display_order`,
		`survey_items_display`.`hidden`");

		$select->from('survey_items_display')
			->leftJoin('survey_unit_sessions', 'survey_unit_sessions.id = survey_items_display.session_id')
			->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
			->leftJoin('survey_items', 'survey_items.id = survey_items_display.item_id')
			->where('survey_items.study_id = :study_id')
			->where('survey_items_display.session_id IN ('.$session_ids.')')
			->order('survey_items_display.session_id')	
			->order('survey_items_display.display_order')
			->bindParams(array('study_id' => $this->id));

		if ($items) {
			$select->whereIn('survey_items.name', $items);
		}

		if ($rstmt === true) {
			return $select->statement();
		}
		return $select->fetchAll();
	}

	/**
	 * Get Results from the item display table
	 *
	 * @param array $items An array of item names that are required in the survey
	 * @param array $sessions If specified, only results of that particular session will be returned
	 * @return array
	 */
	public function getResultsByItemAndSession($items = array(), $sessions = null) {
		$select = $this->dbh->select('
		`survey_run_sessions`.session,
		`survey_items`.name,
		`survey_items_display`.answer');

		$select->from('survey_items_display')
				->leftJoin('survey_unit_sessions', 'survey_unit_sessions.id = survey_items_display.session_id')
				->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
				->leftJoin('survey_items', 'survey_items_display.item_id = survey_items.id')
				->where('survey_items.study_id = :study_id')
				->order('survey_run_sessions.session')
				->order('survey_run_sessions.created')
				->order('survey_unit_sessions.created')
				->order('survey_items_display.display_order')
				->bindParams(array('study_id' => $this->id));

		if (!empty($items)) {
			$select->whereIn('survey_items.name', $items);
		}

		if (!empty($sessions)) {
			$select->whereIn('survey_items.name', $sessions);
		}

		return $select->fetchAll();
	}
	
	protected function dataExists($min = 0) {
		$this->result_count = $this->getResultCount();
		if(($this->result_count["real_users"] + $this->result_count['testers']) > 0) {
			return true;
		} else {
			return false;
		}
	}

	protected function realDataExists($min = 0) {
		$this->result_count = $this->getResultCount();
		if($this->result_count["real_users"] > 1) {
			return true;
		} else {
			return false;
		}
	}

	public function deleteResults($run_id = null) {
		$this->result_count = $this->getResultCount($run_id);
		
		if (array_sum($this->result_count) === 0) {
			return true;
		} elseif ($run_id !== null) {
			//@todo implement deleting results only for a particular run
			$this->error[] =  'Deleting run specific results for a survey is not yet implemented';
			return false;
		} elseif ($this->backupResults()) {
			$delete = $this->dbh->query("TRUNCATE TABLE `{$this->results_table}`");
			$delete_item_disp = $this->dbh->delete('survey_unit_sessions', array('unit_id' => $this->id));
			return $delete && $delete_item_disp;
		} else {
			$this->errors[] = __("Backup of %s result rows failed. Deletion cancelled.", array_sum($this->result_count));
			return false;
		}
	}

	public function backupResults($itemNames = null) {
		$this->result_count = $this->getResultCount();
		if ($this->realDataExists()) {
			$this->messages[] = __("<strong>Backed up.</strong> The old results were backed up in a file (%s results)", array_sum($this->result_count));
			
			$filename = $this->results_table . date('YmdHis') . ".tab";
			if (isset($this->user_id)) {
				$filename = "user" . $this->user_id . $filename;
			}
			$filename = APPLICATION_ROOT . "tmp/backups/results/" . $filename;

			$SPR = new SpreadsheetReader();
			return $SPR->backupTSV($this->getResults($itemNames), $filename);
		} else { // if we have no real data, no need for backup
			return true;
		}
	}

	public function getResultCount($run_id = null, $filter = array()) {
		// If there is no filter and results have been saved in a previous operation then that
		if ($this->result_count !== null && !$filter) {
			return $this->result_count;
		}
		
		$count = array('finished' => 0, 'begun' => 0, 'testers' => 0, 'real_users' => 0);
		if ($this->resultsTableExists()) {
			$results_table = $this->results_table;
			$select = $this->dbh->select(array(
				"SUM(`survey_run_sessions`.`testing` IS NOT NULL AND `survey_run_sessions`.`testing` = 0 AND `{$results_table}`.ended IS null)" => 'begun',
				"SUM(`survey_run_sessions`.`testing` IS NOT NULL AND `survey_run_sessions`.`testing` = 0 AND `{$results_table}`.ended IS NOT NULL)" => 'finished',
				"SUM(`survey_run_sessions`.`testing` IS NULL OR `survey_run_sessions`.`testing` = 1)" => 'testers',
				"SUM(`survey_run_sessions`.`testing` IS NOT NULL AND `survey_run_sessions`.`testing` = 0)" => 'real_users'
			))->from($results_table)
			  ->leftJoin('survey_unit_sessions', "survey_unit_sessions.id = {$results_table}.session_id")
			  ->leftJoin('survey_run_sessions', "survey_unit_sessions.run_session_id = survey_run_sessions.id");

			if ($run_id) {
				$select->where("survey_run_sessions.run_id = {$run_id}");
			}
			if (!empty($filter['session'])) {
				$session = $filter['session'];
				strlen($session) == 64 ? $select->where("survey_run_sessions.session = '$session'") : $select->like('survey_run_sessions.session', $session, 'right');
			}

			if (!empty($filter['results']) && ($res_filter = $this->getResultsFilter($filter['results']))) {
				$res_where = Template::replace($res_filter['query'], array('table' => $results_table));
				$select->where($res_where);
			}

			$count = $select->fetch();
		}

		return $count;
	}

	public function getAverageTimeItTakes() {
		if($this->resultsTableExists()) {
			$get = "SELECT AVG(middle_values) AS 'median' FROM (
			  SELECT took AS 'middle_values' FROM
				(
				  SELECT @row:=@row+1 as `row`, (x.ended - x.created) AS took
			      FROM `{$this->results_table}` AS x, (SELECT @row:=0) AS r
				  WHERE 1
				  -- put some where clause here
				  ORDER BY took
				) AS t1,
				(
				  SELECT COUNT(*) as 'count'
			      FROM `{$this->results_table}` x
				  WHERE 1
				  -- put same where clause here
				) AS t2
				-- the following condition will return 1 record for odd number sets, or 2 records for even number sets.
				WHERE t1.row >= t2.count/2 and t1.row <= ((t2.count/2) +1)) AS t3;";

			$get = $this->dbh->query($get, true);
			$time = $get->fetch(PDO::FETCH_NUM);
			$time = round($time[0] / 60, 3); # seconds to minutes

			return $time;
		}
		return '';
	}

	public function rename($new_name) {
		if($this->checkName($new_name)) {
			$mod = $this->dbh->update('survey_studies', array('name' => $new_name), array(
				'id' => $this->id,
				));
			if($mod) {
				$this->name = $new_name;
			}
			return $mod;
		}
	}

	public function delete($special = null) {
		if ($this->deleteResults()): // always back up
			$this->dbh->query("DROP TABLE IF EXISTS `{$this->results_table}`");
			if (($filename = $this->getOriginalFileName())) {
				@unlink(Config::get('survey_upload_dir') . '/' . $filename);
			}
			return parent::delete($special);
		endif;
		return false;
	}

	public function displayForRun($prepend = '') {

		global $user;
		$studies = $this->dbh->select('id, name')->from('survey_studies')->where(array('user_id' => $user->id))->fetchAll();

		if ($studies):
			$dialog = '<div class="form-group">';
			$dialog .= '<select class="select2" name="unit_id" style="width:300px">';

			if ($this->id === null)
				$dialog .= '<option value=""></option>';
			foreach ($studies as $study):
				$selected = "";
				if ($this->id === $study['id']) {
					$selected = 'selected = "selected"';
				}
				$dialog .= "<option value=\"{$study['id']}\" $selected>{$study['name']}</option>";
			endforeach;
			$dialog .= "</select>";
			$dialog .= '</div>';
		else:
			$dialog = "<h5>No studies. <a href='" . admin_study_url() . "'>Add some first</a></h5>";
		endif;

		if ($this->id) {
			$resultCount = $this->howManyReachedItNumbers();

			$time = $this->getAverageTimeItTakes();

			$dialog .= "
			<p>" . (int) $resultCount['finished'] . " complete <a href='" . admin_study_url($this->name, 'show_results') . "'>results</a>, " . (int) $resultCount['begun'] . " begun <abbr class='hastooltip' title='Median duration participants needed to complete the survey'>(in ~{$time}m)</abbr>
			</p>
			<p class='btn-group'>
					<a class='btn btn-default' href='" . admin_study_url($this->name, 'show_item_table?to=show') . "'>View items</a>
					<a class='btn btn-default' href='" . admin_study_url($this->name, 'upload_items') . "'>Upload items</a>
			</p>";
			$dialog .= '<br><p class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Survey">Save</a>
				<a title="Test this survey with this button for a quick look. Unless you need a quick look, you should prefer to use the \"Test run\" function to test the survey in the context of the run." class="btn btn-default" target="_blank" href="' . admin_study_url($this->name, 'access') . '">Test</a>
			</p>';
//		elseif($studies):
		} else {
			$dialog .= '<p>
				<div class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Survey">Save</a>
				</div>
			</p>';
		}

		$dialog = $prepend . $dialog;
		return parent::runDialog($dialog, 'fa-pencil-square');
	}

	/**
	 * Merge survey items. Each parameter is an associative array indexed by the names of the items in the survey, with the 
	 * mysql field definition as the value.
	 * new items are added, old items are deleted, items that changed type are deleted from the results table but not the item_display_table
	 * All non null entries represent the MySQL data type definition of the fields as they should be in the survey results table
	 * NOTE: All the DB queries here should be in a transaction of calling function
	 *
	 * @param array $newItems
	 * @param array $deleteItems
	 * @return bool;
	 */
	private function alterResultsTable(array $newItems, array $deleteItems) {
		$actions = $toAdd = $toDelete = array();
		$deleteQuery = $addQuery = array();
		$addQ = $delQ = null;
		
		// just for safety checking that there is something to be deleted (in case of aborted earlier tries)
		$existingColumns = $this->dbh->getTableDefinition($this->results_table, 'Field');

		// Create query to drop items in existing table
		foreach ($deleteItems as $name => $result_field) {
			if ($result_field !== null && isset($existingColumns[$name])) {
				$deleteQuery[] = " DROP `{$name}`";
			}
			$toDelete[] = $name;
		}
		// Create query for adding items to existing table
		foreach ($newItems as $name => $result_field) {
			if ($result_field !== null) {
				$addQuery[] = " ADD $result_field";
			}
			$toAdd[] = $name;
		}

		// prepare these strings for feedback
		$added_columns_string =  implode($toAdd, ", ");
		$deleted_columns_string =  implode($toDelete, ", ");
		
		
		// if something should be deleted
		if ($deleteQuery) {
			$q = "ALTER TABLE `{$this->results_table}`" . implode(',', $deleteQuery);
			$this->dbh->query($q);
			$actions[] = "Deleted columns: $deleted_columns_string.";
		}
		
		// we only get here if the deletion stuff was harmless, allowed or did not happen
		if ($addQuery) {
			$q = "ALTER TABLE `{$this->results_table}`" . implode(',', $addQuery);
			$this->dbh->query($q);
			$actions[] = "Added columns: $added_columns_string.";
		}

		if(!empty($actions)) {
			$this->messages[] = "<strong>The results table was modified.</strong>";
			$this->messages = array_merge($this->messages, $actions);
		} else {
			$this->messages[] = "The results table did not need to be modified.";
		}

		return true;
	}

	public function getOriginalFileName() {
		return $this->dbh->findValue('survey_studies', array('id' => $this->id), 'original_file');
	}

	public function getGoogleFileId() {
		return $this->dbh->findValue('survey_studies', array('id' => $this->id), 'google_file_id');
	}

	public function getResultsFilter($f = null) {
		$filter = array(
			'all' => array(
				'title' => 'Show All',
				'query' => null,
			),
			'incomplete' => array(
				'title' => 'Incomplete',
				'query' => '(%{table}.created <> %{table}.modified or %{table}.modified is null) and %{table}.ended is null',
			),
			'complete' => array(
				'title' => 'Complete',
				'query' => '%{table}.ended is not null',
			),
		);

		return $f !== null ? array_val($filter, $f, null) : $filter;
	}
}
