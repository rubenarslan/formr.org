<?php

class Survey extends RunUnit {

	public $id = null;
	public $name = null;
	public $run_name = null;
	public $items = array();
	public $session = null;
	public $results_table = null;
	public $run_session_id = null;
	public $settings = array();
	public $valid = false;
	public $public = false;
	public $errors = array();
	public $messages = array();
	public $warnings = array();
	public $position;
	private $SPR;
	public $openCPU = null;
	public $icon = "fa-pencil-square-o";
	public $type = "Survey";
	public $result_count = 0;
	private $confirmed_deletion = false;
	public $item_factory = null;
	public $unanswered = array();
	public $to_render = array();

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

			$this->valid = true;
		endif;
	}

	public function create($options) {
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
		// check if survey exists by name even if it belongs to different user
		$survey = Survey::loadByName($data->name);
		if ($survey->valid && !Site::getCurrentUser()->created($survey)) {
			alert("You are not the creator of survey {$data->name} so can't modify it", 'alert-warning');
			return false;
		} elseif ($survey->valid && Site::getCurrentUser()->created($survey)) {
			$created['id'] = $survey->id;
			$created['unit_id'] = $survey->id;
		} else {
			$survey->unit = array(
				'user_id' => Site::getCurrentUser()->id,
				'name' => $data->name,
			);
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
			alert("Unable to import survey items in survey '{$survey->name}'", 'alert-warning');
			return false;
		}

		return $created;
	}

	public function render() {
		global $js;
		$js = (isset($js) ? $js : '') . '<script src="' . asset_url('assets/' . (DEBUG ? 'js' : 'minified') . '/survey.js') . '"></script>';

		$ret = '
		<div class="row study-' . $this->id . ' study-name-' . $this->name . '">
			<div class="col-md-12">
		';
		$ret .= $this->render_form_header() .
				$this->render_items() .
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
		$no_items = $this->dbh->count('survey_items', array('study_id' => $this->id), 'id');
		$no_display_items = $this->dbh->count('survey_items_display', array('session_id' => $this->session_id), 'id');
		if ($no_display_items >= $no_items) {
			return;
		}

		// get the definition of the order
		$item_ids = $this->getOrderedItemsIds();

		$survey_items_display = $this->dbh->prepare(
			"INSERT INTO `survey_items_display` (`item_id`, `session_id`, `display_order`) 
			 VALUES (:item_id, :session_id, :display_order)
			 ON DUPLICATE KEY UPDATE item_id = VALUES(item_id)");

		$survey_items_display->bindParam(":session_id", $this->session_id);

		foreach ($item_ids AS $display_order => $item_id) {
			$survey_items_display->bindParam(":item_id", $item_id);
			$survey_items_display->bindParam(":display_order", $display_order);
			$survey_items_display->execute();
		}
	}

	protected function getOrderedItemsIds() {
		$get_items = $this->dbh->select('
				`survey_items`.id,
				`survey_items`.`item_order`,
				`survey_items`.`block_order`')
				->from('survey_items')
				->where("`survey_items`.`study_id` = :study_id")
				->order("`survey_items`.order")
				->bindParams(array('`study_id`' => $this->id))
				->statement();

		// sort blocks randomly (if they are consecutive), then by item number and if the latter are identical, randomly
		$block_segment = $block_order = $item_order = $random_order = $block_numbers = $item_ids = array();
		$last_block = "";
		$block_nr = 0;
		$block_segment_i = 0;

		while ($item = $get_items->fetch(PDO::FETCH_ASSOC)) {
			if ($item['block_order'] == "") { // not blocked
				$item['block_order'] = "";
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
		}

		$random_order = range(1, count($item_ids)); // if item order is identical, sort randomly (within block)
		shuffle($random_order);
		array_multisort($block_order, $item_order, $random_order, $item_ids);
		// order is already sufficiently defined at least by random_order, but this is a simple way to sort $item_ids is sorted accordingly

		return $item_ids;
	}

	public function post($posted, $redirect = true) {
		// remove variables user is not allowed to overrite (they should not be sent to user in the first place if not used in request)
		unset($posted['id'], $posted['session'], $posted['session_id'], $posted['study_id'], $posted['created'], $posted['modified'], $posted['ended']);

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
		// Validate items and if any fails return user to same page with all unansered items and error messages
		// This loop also accumulates potential update data
		$update_data = array();
		foreach ($posted as $item_name => $item_value) {
			if (isset($this->unanswered[$item_name]) && $this->unanswered[$item_name]->save_in_results_table) {
				$value = $this->unanswered[$item_name]->validateInput($item_value);
				if ($this->unanswered[$item_name]->error) {
					$this->errors[$item_name] = $this->unanswered[$item_name]->error;
				} else {
					// save validated input
					$this->unanswered[$item_name]->value_validated = $value;
					$update_data[$item_name] = $this->unanswered[$item_name]->getReply($value);
				}
			}
		}

		if (!empty($this->errors)) {
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
				WHERE item_id = :item_id AND
				session_id = :session_id"); # fixme: displaycount starts at 2
		$survey_items_display->bindParam(":session_id", $this->session_id);

		try {
			$this->dbh->beginTransaction();

			// Update results table in one query
			if ($update_data) {
				$update_where = array(
					'study_id' => $this->id,
					'session_id' => $this->session_id,
				);
				$this->dbh->update($this->results_table, $update_data, $update_where);
			}

			// update item_display table for each posted item using prepared statement
			foreach ($posted AS $name => $value) {
				if (!isset($this->unanswered[$name])) {
					continue;
				}

				$survey_items_display->bindValue(":item_id", $this->unanswered[$name]->id);
				$survey_items_display->bindValue(":answer", $this->unanswered[$name]->getReply($value));

				if (isset($posted["_item_views"]["shown"][$this->unanswered[$name]->id], $posted["_item_views"]["shown_relative"][$this->unanswered[$name]->id])):
					$shown = $posted["_item_views"]["shown"][$this->unanswered[$name]->id];
					$shown_relative = $posted["_item_views"]["shown_relative"][$this->unanswered[$name]->id];
				else:
					$shown = mysql_now();
					$shown_relative = null; // and where this is null, performance.now wasn't available
				endif;

				if (isset($posted["_item_views"]["answered"][$this->unanswered[$name]->id], // separately to "shown" because of items like "note"
								$posted["_item_views"]["answered_relative"][$this->unanswered[$name]->id])):
					$answered = $posted["_item_views"]["answered"][$this->unanswered[$name]->id];
					$answered_relative = $posted["_item_views"]["answered_relative"][$this->unanswered[$name]->id];
				else:
					$answered = $shown; // this way we can identify items where JS time failed because answered and show time are exactly identical
					$answered_relative = null;
				endif;

				$survey_items_display->bindValue(":hidden", 0); // an item that was answered has to have been shown
				$survey_items_display->bindValue(":saved", mysql_now());
				$survey_items_display->bindParam(":shown", $shown);
				$survey_items_display->bindParam(":shown_relative", $shown_relative);
				$survey_items_display->bindParam(":answered", $answered);
				$survey_items_display->bindParam(":answered_relative", $answered_relative);
				$item_answered = $survey_items_display->execute();

				if (!$item_answered) {
					throw new Exception("Survey item '$name' could not be saved with value '$value' in table '{$this->results_table}' (FieldType: {$this->unanswered[$name]->getResultField()})");
				}
				unset($this->unanswered[$name]);
			} //endforeach
			$this->dbh->commit();
		} catch (Exception $e) {
			$this->dbh->rollBack();
			notify_user_error($e, 'An error occurred while trying to save your survey data. Please notify the author of this survey with this date and time');
			formr_log_exception($e, __CLASS__);
			$redirect = false;
		}

		// If all was well and we are re-directing then do so
		if ($redirect) {
			redirect_to($this->run_name);
		}
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
			if ($item->isRendered($this)) {
				$this->progress_counts['visible_on_current_page'] ++;
			}
			// On current page, count those items that were hidden but rendered (ie. those relying on missing data for their showif)
			if ($item->isHiddenButRendered($this)) {
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
	 *
	 * @param Item[] $items
	 * @param array $show_ifs
	 * @param array $dynamic_values
	 * @return array
	 */
	protected function parseShowIfsAndDynamicValues(&$items) {
		// In this loop we gather all show-ifs and dynamic-values that need processing and all values.
		$show_ifs = $dynamic_values = array();
		$showifs_cache = array();
		/* @var Item $item */
		foreach ($items as $name => $item) {
			if (($showif = $item->getShowIf())) {
				$name = "si.{$name}";
				$cache_key = md5($showif);
				if (isset($showifs_cache[$cache_key])) {
					$showif = "{$name} = {$showifs_cache[$cache_key]}";
				} else {
					$showifs_cache[$cache_key] = $name;
					$showif = "{$name} = {$showif}";
				}
				$show_ifs[] = $showif;
			}
		}

		$hidden_update = $this->dbh->prepare("UPDATE `survey_items_display`
			SET hidden = :hidden
			WHERE item_id = :item_id AND session_id = :session_id");
		$hidden_update->bindValue(":session_id", $this->session_id);

		$showif_results = array();

		if ($show_ifs) {
			$ocpu_session = opencpu_multiparse_showif($this, $show_ifs, true);
			if (!$ocpu_session OR $ocpu_session->hasError()) {
				notify_user_error(opencpu_debug($ocpu_session), "There was a problem evaluating showifs using openCPU.");
			}
			else {
				$showif_results = $ocpu_session->getJSONObject();
			}
		}
		// Fit show-ifs
		foreach ($items as &$item) {
			$hidden = $item->setVisibility(array_val($showif_results, "si.{$item->name}"));

			$hidden_update->bindValue(":item_id", $item->id);
			if ($hidden !== null) {
				$hidden = (int) !$hidden;
			}
			$hidden_update->bindValue(":hidden", null);
			$hidden_update->execute();
		}
		
		$dyn_value_results = array();

		// Compute dynamic values only if items are certainly visible
		foreach ($items as $name => &$item) {
			if ($item->needsDynamicValue() && $item->isRendered()) {
				// for items of type 'opencpu_session', compute thier values immediately and not send in bulk request
				if ($item->type === 'opencpu_session') {
					$item->evaluateDynamicValue($this);
					continue;
				}
				$dynamic_values[] = "{$name} = (function(){{$item->getValue()}})()";
			}
		}
		if ($dynamic_values) {
			$ocpu_session = opencpu_multiparse_values($this, $dynamic_values, true);
			if (!$ocpu_session OR $ocpu_session->hasError()) {
				notify_user_error(opencpu_debug($ocpu_session), "There was a problem getting dynamic values using openCPU.");
			} else {
				$dyn_value_results = $ocpu_session->getJSONObject();
			}
			
			// Fit dynamic values in properly 
			foreach ($items as &$item) {
				$val = array_val($dyn_value_results, $item->name, null);
				$item->setDynamicValue($val);
			}
		}
	}

	/**
	 * Get the next items to be displayed in the survey
	 * This function first restricts the number of items to walk through by only requesting those from the DB which have not yet been answered.
	 * - Choices for fetched items are filtered out and fetched from DB.
	 * - Item and choice labels that require parsing are bundled together and sent to opencpu. Some 'placeholder' logic is used here
	 *
	 * @param boolean $process Should dynamic values and showifs be processed?
	 * @return Item[] Returns an array of items that should be shown next
	 * @todo delegate the work load in this method
	 */
	protected function getNextItems($process = true) {
		$this->unanswered = array();
		$get_items = $this->dbh->select('
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
				`survey_items_display`.answered')
				->from('survey_items')
				->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
				->where("survey_items.study_id = :study_id AND (survey_items_display.saved IS null) AND (survey_items_display.hidden IS NULL OR survey_items_display.hidden = 0)")
				->order('survey_items_display.`display_order`', 'asc')
				->order('survey_items.`order`', 'asc') // only needed for transfer
				->order('survey_items.id', 'asc')
				->bindParams(array('session_id' => $this->session_id, 'study_id' => $this->id))
				->statement();

		// We initialise item factory with no choice list because we don't know which choices will be used yet.
		// This assumes choices are not required for show-ifs and dynamic values (hope so)
		$itemFactory = new ItemFactory(array());
		while ($item = $get_items->fetch(PDO::FETCH_ASSOC)) {
			$this->unanswered[$item['name']] = $itemFactory->make($item);
		}

		if (!$this->unanswered) {
			return array();
		}

		// Process show-ifs to determine which items need to be shown
		// FIXME: Maybe there is a way to process only page-necessary show-ifs. At the moment all are processed
		if ($process) {
			$this->parseShowIfsAndDynamicValues($this->unanswered);
		}

		// At this point all show-ifs have been processed and all possible dynamic values have been set
		// Save values for all items that do not require user input, have values set and will be rendered
		// If there are any of such values, the we post and redirect
		$post = array();
		foreach ($this->unanswered as $name => $item) {
			if ($item->no_user_input_required && isset($item->input_attributes['value']) && $item->isRendered()) {
				$post[$item->name] = $item->input_attributes['value'];
			}
		}
		if ($post) {
			// save survey data and redirect.
			$this->post($post, true);
		}

		// Gather labels and choice_lists to be parsed only for items that will potentially be visibile
		$strings_to_parse = array();
		$lists_to_fetch = array();
		$this->to_render = array();

		/** @var Item $item */
		$visibleItems = 0;
		foreach ($this->unanswered as $name => $item) {
			if (!$item->isRendered()) {
				continue;
			}
			if (!$item->hidden) {
				$visibleItems++;
			} else if ($visibleItems === 0) {
				// if this item was not preceded by any visible items
				$this->unanswered[$name]->setVisibility(array(false));
				continue;
			}

			if ($item->choice_list) {
				$lists_to_fetch[] = $item->choice_list;
			}

			if ($item->needsDynamicLabel() ) {
				$this->unanswered[$name]->label_parsed = opencpu_string_key(count($strings_to_parse));
				$strings_to_parse[] = $item->label;
			}

			$this->to_render[$name] = (array) $this->unanswered[$name];
			// Since as we are skipping all non-rendered items, we can safely truncate here on a submit button
			// This will help process fewer item labels and choice labels (maybe it is more optimal)
			if ($item->type === 'submit' && $visibleItems > 0 && $this->to_render) {
				break;
			}
		}

		// Use only visible items
		// FIXME: Truncating the unanswered items array here will break progress so use $this->to_render array to process labels and merge back
		// $this->unanswered = $this->to_render;
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
			opencpu_substitute_parsed_strings($this->to_render, $parsed_strings);
		}

		// Merge processed visible items into the unanswered array
		// and set choices for various items with processed choice_lists
		$itemFactory->setChoiceLists($choice_lists);
		foreach ($this->to_render as $name => $item) {
			$choice_list = $item['choice_list'];
			if (isset($choice_lists[$choice_list])) {
				$this->unanswered[$name]->setChoices($choice_lists[$choice_list]);
			}
			$this->unanswered[$name]->refresh($item, array('label_parsed'));
			$this->to_render[$name] = $this->unanswered[$name];
		}
		return $this->unanswered;
	}

	protected function renderNextItems() {

		$this->dbh->beginTransaction();

		$view_query = "UPDATE `survey_items_display`
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

	protected function render_form_header() {
		$action = run_url($this->run_name);
		$enctype = 'multipart/form-data'; # maybe make this conditional application/x-www-form-urlencoded

		$ret = '<form action="' . $action . '" method="post" class="form-horizontal main_formr_survey' .
				($this->settings['enable_instant_validation'] ? ' ws-validate' : '')
				. '" accept-charset="utf-8" enctype="' . $enctype . '">';

		/* pass on hidden values */
		$ret .= '<input type="hidden" name="session_id" value="' . $this->session_id . '" />';

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

		$ret .= '
			<div class="container progress-container">
			<div class="progress">
				  <div data-percentage-minimum="' . $this->settings["add_percentage_points"] . '" data-percentage-maximum="' . $this->settings["displayed_percentage_maximum"] . '" data-already-answered="' . $this->progress_counts['already_answered'] . '" data-items-left="' . $this->progress_counts['not_answered_on_current_page'] . '" data-items-on-page="' . ($this->progress_counts['not_answered'] - $this->progress_counts['not_answered_on_current_page']) . '" data-hidden-but-rendered="' . $this->progress_counts['hidden_but_rendered_on_current_page'] . '" class="progress-bar" style="width: ' . $prog . '%;">' . $prog . '%</div>
			</div>
			</div>';

		if (!empty($this->errors)) {
			$ret .= '
			<div class="form-group has-error form-message">
				<div class="control-label"><i class="fa fa-exclamation-triangle pull-left fa-2x"></i>' . implode("<br>", array_unique($this->errors)) . '</div>' .
					'</div>';
		}

		return $ret;
	}

	protected function render_items() {
		$ret = '';

		foreach ($this->rendered_items AS $item) {
			$ret .= $item->render();
		}

		// if the last item was not a submit button, add a default one
		if (isset($item) AND ( $item->type !== "submit" OR $item->hidden)) {
			$sub_sets = array('label_parsed' => '<i class="fa fa-arrow-circle-right pull-left fa-2x"></i> Go on to the<br>next page!', 'class_input' => 'btn-info .default_formr_button');
			$item = new Item_submit($sub_sets);
			$ret .= $item->render();
		}

		return $ret;
	}

	protected function render_form_footer() {
		return "</form>"; /* close form */
	}

	public function expire() {
		return parent::end();
	}

	public function end() {
		$ended = $this->dbh->exec(
				"UPDATE `{$this->results_table}` SET `ended` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS null", array('session_id' => $this->session_id, 'study_id' => $this->id)
		);
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

		return $arr['last_viewed'];
	}

	private function hasExpired() {
		$expire = (int) $this->settings['expire_after'];
		if ($expire === 0) {
			return false;
		} else {
			if (!($last = $this->getTimeWhenLastViewedItem())) {
				$last = $this->run_session->unit_session->created;
			}
			if (!$last) {
				return false;
			}
			$expired = $this->dbh
					->select(array(":last <= DATE_SUB(NOW(), INTERVAL :expire_after MINUTE)" => "no_longer_active"))
					->from('survey_items_display')
					->bindParams(array("last" => $last, "expire_after" => $expire))
					->fetch();

			return (bool) $expired['no_longer_active'];
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
			$this->startEntry();

			// POST items only if request is a post request
			if (Request::isHTTPPostRequest()) {
				$request = new Request($_POST);
				$items = $this->getNextItems(false);
				$this->post(array_merge($request->getParams(), $_FILES));
			}

			// we only arrive here if neither of the posts above led to a redirect (either because it's a fake post request or because there was an error)
			$items = $this->getNextItems();

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
		if (isset($key_value_pairs['maximum_number_displayed'])
				AND $key_value_pairs['maximum_number_displayed'] = (int) $key_value_pairs['maximum_number_displayed']
				AND $key_value_pairs['maximum_number_displayed'] > 3000 || $key_value_pairs['maximum_number_displayed'] < 1
		) {
			alert("Maximum number displayed has to be between 1 and 65535", 'alert-warning');
			$errors = true;
		}

		if (isset($key_value_pairs['displayed_percentage_maximum'])
				AND $key_value_pairs['displayed_percentage_maximum'] = (int) $key_value_pairs['displayed_percentage_maximum']
				AND $key_value_pairs['displayed_percentage_maximum'] > 100 || $key_value_pairs['displayed_percentage_maximum'] < 1
		) {
			alert("Percentage maximum has to be between 1 and 100.", 'alert-warning');
			$errors = true;
		}

		if (isset($key_value_pairs['add_percentage_points'])
				AND $key_value_pairs['add_percentage_points'] = (int) $key_value_pairs['add_percentage_points']
				AND $key_value_pairs['add_percentage_points'] > 100 || $key_value_pairs['add_percentage_points'] < 0
		) {
			alert("Percentage points added has to be between 0 and 100.", 'alert-warning');
			$errors = true;
		}

		if (isset($key_value_pairs['enable_instant_validation'])
				AND $key_value_pairs['enable_instant_validation'] = (int) $key_value_pairs['enable_instant_validation']
				AND ! ($key_value_pairs['enable_instant_validation'] === 0 || $key_value_pairs['enable_instant_validation'] === 1)
		) {
			alert("Instant validation has to be set to either 0 (off) or 1 (on).", 'alert-warning');
			$errors = true;
		}

		if (isset($key_value_pairs['expire_after'])
				AND $key_value_pairs['expire_after'] = (int) $key_value_pairs['expire_after']
				AND $key_value_pairs['expire_after'] > 3153600
		) {
			alert("Survey expiry time (in minutes) has to be below 3153600.", 'alert-warning');
			$errors = true;
		}

		if (count(array_intersect(array_keys($key_value_pairs), array_keys($this->settings))) !== count($this->settings)) { // any settings that aren't in the settings array?
			alert("Invalid settings.", 'alert-danger');
			$errors = true;
		}

		if ($errors) {
			return false;
		}

		$this->dbh->update('survey_studies', $key_value_pairs, array(
			'id' => $this->id,
		));

		alert('Survey settings updated', 'alert-success', true);
	}

//  
	public function uploadItemTable($file, $confirmed_deletion, $updates = array()) {
		if (trim($confirmed_deletion) == ''):
			$this->confirmed_deletion = false;
		elseif ($confirmed_deletion === $this->name):
			$this->confirmed_deletion = true;
		else:
			alert("<strong>Error:</strong> You confirmed the deletion of the study's results but your input did not match the study's name. Update aborted.", 'alert-danger');
			$this->confirmed_deletion = false;
			return false;
		endif;

		$allowed_size = Config::get('admin_maximum_size_of_uploaded_files');
		if ($allowed_size && $file['size'] > $allowed_size * 1024 * 1024):
			alert("File exceeds allowed size of {$allowed_size} MB", 'alert-danger');
			return false;
		endif;

		umask(0002);
		ini_set('memory_limit', '256M');
		$target = $file['tmp_name'];
		$filename = $file['name'];

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
			if (move_uploaded_file($target, $file) || rename($target, $file)) {
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

	protected function existsByName($name, $results_table) {
		if (!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,64}/", $name) || !preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,64}/", $results_table)) {
			return;
		}

		$study_exists = $this->dbh->entry_exists('survey_studies', array('name' => $this->name, 'user_id' => $this->unit['user_id']));
		if ($study_exists) {
			return true;
		}

		$table_exists = $this->dbh->table_exists($results_table);
		if ($table_exists) {
			return true;
		}

		return false;
	}

	protected function hasResultsTable() {
		return $this->dbh->table_exists($this->results_table);
	}

	/* ADMIN functions */
	public function createIndependently($settings = array(), $updates = array()) {
		$name = trim($this->unit['name']);
		$results_table = "formr_" . $this->unit['user_id'] . '_' . $name;
		if ($name == ""):
			alert(_("<strong>Error:</strong> The study name (the name of the file you uploaded) can only contain the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. The name has to at least 2, at most 64 characters long. It needs to start with a letter. No dots, no spaces, no dashes, no umlauts please. The file can have version numbers after a dash, like this <code>survey_1-v2.xlsx</code>, but they will be ignored."), 'alert-danger');
			return false;
		elseif (!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,64}/", $name)):
			alert('<strong>Error:</strong> The study name (the name of the file you uploaded) can only contain the characters from a to Z, 0 to 9 and the underscore. It needs to start with a letter. The file can have version numbers after a dash, like this <code>survey_1-v2.xlsx</code>.', 'alert-danger');
			return false;
		elseif ($this->existsByName($name, $results_table)):
			alert(__("<strong>Error:</strong> The survey name %s is already taken.", h($name)), 'alert-danger');
			return false;
		endif;

		$this->id = parent::create('Survey');
		$this->name = $name;
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

	public function createSurvey($SPR) {
		$this->SPR = $SPR;

		$this->dbh->beginTransaction();

		$this->parsedown = new ParsedownExtra();
		$this->parsedown = $this->parsedown->setBreaksEnabled(true)->setUrlsLinked(true);

		$this->addChoices();
		$choice_lists = $this->getChoices();

		$this->item_factory = new ItemFactory($choice_lists);

		// Get old items, mark them as false meaning all are vulnerable for delete.
		// When the loop over survey items ends you will know which should be deleted.
		$items = $this->getItems('name, type, choice_list');
		$oldItems = $keptItems = $newItems = array();
		foreach ($items as $item) {
			$item['skip_more_options'] = true;
			if (($object = $this->item_factory->make($item)) !== false) {
				$oldItems[$item['name']] = $object;
			}
		}

		$UPDATES = implode(', ', get_duplicate_update_string($this->user_defined_columns));
		$add_items = $this->dbh->prepare(
				"INSERT INTO `survey_items` (study_id, name, label, label_parsed, type, type_options, choice_list, optional, class, showif, value, `block_order`,`item_order`, `order`) 
			VALUES (:study_id, :name, :label, :label_parsed, :type, :type_options, :choice_list, :optional, :class, :showif, :value, :block_order, :item_order, :order
		) ON DUPLICATE KEY UPDATE $UPDATES");

		$result_columns = array();
		$add_items->bindParam(":study_id", $this->id);

		foreach ($this->SPR->survey as $row_number => $row) {
			$item = $this->item_factory->make($row);
			if (!$item) {
				$this->errors[] = __("Row %s: Type %s is invalid.", $row_number, $this->SPR->survey[$row_number]['type']);
				unset($this->SPR->survey[$row_number]);
				continue;
			}

			$val_results = $item->validate();
			if (!empty($val_results['val_errors'])) {
				$this->errors = $this->errors + $val_results['val_errors'];
				unset($this->SPR->survey[$row_number], $oldItems[$item->name]);
				continue;
			}
			if(!empty($val_results['val_warnings'])) {
				$this->warnings = $this->warnings + $val_results['val_warnings'];
			}

			// if the parsed label is constant or exists
			if (!$this->knittingNeeded($item->label) && !$item->label_parsed):
				$markdown = $this->parsedown->text($item->label);
				$item->label_parsed = $markdown;
				if (mb_substr_count($markdown, "</p>") === 1 AND preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)) {
					$item->label_parsed = $matches[1];
				}
			endif;

			foreach ($this->user_defined_columns as $param) {
				$add_items->bindValue(":$param", $item->$param);
			}

			$result_field = $item->getResultField();

			// Mark item as not to be deleted from survey_items table by saving in $keptItems array.
			// All not in $keptItems but in $oldItems will be deleted and also removed from results_table.
			if (isset($oldItems[$item->name])) {
				$keptItems[$item->name] = $item;
				unset($oldItems[$item->name]);
			} else {
				// these will be used to alter the results table adding new items
				$newItems[$item->name] = $item;
			}

			$result_columns[] = $result_field;
			$add_items->execute();
		}
		$unused = $this->item_factory->unusedChoiceLists();
		if (!empty($unused)):
			$this->warnings[] = __("These choice lists were not used: '%s'", implode("', '", $unused));
		endif;

		// Try to merge survey items if survey table already exists and deletion was not confirmed or create a new table
		if (empty($this->errors)) {
			try {

				if ($this->hasResultsTable() && !$this->confirmed_deletion) {
					// queries of the merge are included in opened transaction
					if ($this->backupResults() AND $this->mergeItems($keptItems, $newItems, $oldItems)) {
						$this->messages[] = "<strong>The old results table was backed up and modified.</strong>";
					} else {
						$this->errors[] = "<strong>The back up or updating the item table failed.</strong>";
					}
				} else {
					if ($this->confirmed_deletion) {
						$this->deleteResults();
					}
					$new_syntax = $this->getResultsTableSyntax($result_columns);
					$this->createResultsTable($new_syntax);
					$this->warnings[] = "A new results table was created.";
				}
				return $this->dbh->commit();
			} catch (Exception $e) {
				$this->dbh->rollBack();
				$this->errors[] = "An Error occured and all changes were rolled back";
				formr_log_exception($e, __CLASS__, $this->errors);
				return false;
			}
		} else {
			$this->dbh->rollBack();
			$this->errors[] = "An error occured, so all changes were rolled back";
			return false;
		}
	}

	public function getItemsWithChoices() {
		if($this->hasResultsTable()) {
			$choice_lists = $this->getChoices();
			$this->item_factory = new ItemFactory($choice_lists);

			$raw_items = $this->getItems();

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

	private function addChoices() {
		// delete cascades to item display ?? FIXME so maybe not a good idea to delete then
		$deleted = $this->dbh->delete('survey_item_choices', array('study_id' => $this->id));
		$add_choices = $this->dbh->prepare('INSERT INTO `survey_item_choices` (
			study_id,
	        list_name,
			name,
	        label,
			label_parsed
		) VALUES (
			:study_id,
			:list_name,
			:name,
			:label,
			:label_parsed
		)');
		$add_choices->bindParam(":study_id", $this->id);

		foreach ($this->SPR->choices AS $choice) {
			if (isset($choice['list_name']) AND isset($choice['name']) AND isset($choice['label'])):
				if (!$this->knittingNeeded($choice['label']) && empty($choice['label_parsed'])): // if the parsed label is constant
					$markdown = $this->parsedown->text($choice['label']); // transform upon insertion into db instead of at runtime

					if (mb_substr_count($markdown, "</p>") === 1 AND preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)):
						$choice['label_parsed'] = $matches[1];
					else:
						$choice['label_parsed'] = $markdown;
					endif;
				endif;

				foreach ($this->choices_user_defined_columns as $param) {
					$add_choices->bindParam(":$param", $choice[$param]);
				}
				$add_choices->execute();
			endif;
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
		  
		  INDEX `fk_survey_results_survey_unit_sessions1_idx` (`session_id` ASC) ,
		  INDEX `fk_survey_results_survey_studies1_idx` (`study_id` ASC) ,
		  PRIMARY KEY (`session_id`) ,
		  INDEX `ending` (`session_id` DESC, `study_id` ASC, `ended` ASC) ,
		  CONSTRAINT `fk_{$this->results_table}_survey_unit_sessions1`
		    FOREIGN KEY (`session_id` )
		    REFERENCES `survey_unit_sessions` (`id` )
		    ON DELETE CASCADE
		    ON UPDATE NO ACTION,
		  CONSTRAINT `fk_{$this->results_table}_survey_studies1`
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

	public function getItems($columns = null) {
		if ($columns === null) {
			$columns = "id, study_id, type, choice_list, type_options, name, label, label_parsed, optional, class, showif, value, block_order,item_order";
		}

		return $this->dbh->select($columns)
						->from('survey_items')
						->where(array('study_id' => $this->id))
						->order("`survey_items`.order")
						->fetchAll();
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

	public function countResults() {
		$query = "SELECT COUNT(*) AS count FROM `{$this->results_table}`";
		$results = $this->dbh->query($query, true);
		if ($row = $results->fetch(PDO::FETCH_ASSOC)) {
			$this->result_count = $row['count'];
		}
		return $this->result_count;
	}

	public function getResults($items = null, $session = null, $returnStmt = false) { // fixme: shouldnt be using wildcard operator here.
		if ($this->hasResultsTable()) {
			ini_set('memory_limit', '256M');

			$results_table = $this->results_table;
			if ($items === null) {
				$items = array('*');
			}

			$colums = array('survey_run_sessions.session');
			foreach ($items as $item) {
				$colums[] = "{$results_table}.{$item}";
			}
			$select = $this->dbh->select($colums)
					->from($results_table)
					->leftJoin('survey_unit_sessions', "{$results_table}.session_id = survey_unit_sessions.id")
					->leftJoin('survey_run_sessions', 'survey_unit_sessions.run_session_id = survey_run_sessions.id');

			if ($session !== null) {
				$select->where("survey_run_sessions.session = '$session'");
			}
			$stmt = $select->statement();

			if ($returnStmt) {
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
	 * @return array
	 */
	public function getItemDisplayResults($items = array(), $session = null) {
		$select = $this->dbh->select('
		`survey_run_sessions`.session,
		`survey_items`.name,
		`survey_items_display`.session_id as unit_session_id,
		`survey_items_display`.item_id,
		`survey_items_display`.answer,
		`survey_items_display`.created,
		`survey_items_display`.saved,
		`survey_items_display`.shown,
		`survey_items_display`.shown_relative,
		`survey_items_display`.answered,
		`survey_items_display`.answered_relative,
		`survey_items_display`.displaycount,
		`survey_items_display`.display_order,
		`survey_items_display`.hidden');

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

		if ($session) {
			if(strlen($session) == 64) {
				$select->where("survey_run_sessions.session = :session");
			} else {
				$select->where("survey_run_sessions.session LIKE :session");
				$session .= "%";
			}
			$select->bindParams(array("session" => $session));
		}

		return $select->fetchAll();
	}
	/**
	 * Get Results from the item display table
	 *
	 * @param array $items An array of item names that are required in the survey
	 * @param string $session If specified, only results of that particular session will be returned
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

		if (!empty( $items)) {
			$select->whereIn('survey_items.name', $items);
		}

		if (!empty( $sessions)) {
			$select->whereIn('survey_items.name', $sessions);
		}

		return $select->fetchAll();
	}

	public function deleteResults($dry_run = false) {
		$resC = $this->getResultCount();
		if ($resC['finished'] > 10):
			if ($this->backupResults()):
				$this->warnings[] = __("%s results rows were backed up.", array_sum($resC));
			else:
				$this->errors[] = __("Backup of %s result rows failed. Deletion cancelled.", array_sum($resC));
				return false;
			endif;
		elseif ($resC == array('finished' => 0, 'begun' => 0)):
			return true;
		else:
			$this->warnings[] = __("%s results rows were deleted.", array_sum($resC));
		endif;

		if (!$dry_run) {
			$delete = $this->dbh->query("TRUNCATE TABLE `{$this->results_table}`");
			$this->dbh->delete('survey_unit_sessions', array('unit_id' => $this->id));
		} else {
			$delete = true;
		}
		return $delete;
	}

	public function backupResults() {
		$filename = $this->results_table . date('YmdHis') . ".tab";
		if (isset($this->user_id)) {
			$filename = "user" . $this->user_id . $filename;
		}
		$filename = INCLUDE_ROOT . "tmp/backups/results/" . $filename;

		$SPR = new SpreadsheetReader();
		return $SPR->backupTSV($this->getResults(), $filename);
	}

	public function getResultCount() {
		$results_table = $this->results_table;
		if ($this->hasResultsTable()):
			$count = $this->dbh->select(array(
						"SUM(`{$results_table}`.ended IS null)" => 'begun',
						"SUM(`{$results_table}`.ended IS NOT NULL)" => 'finished'
					))->from($results_table)
					->leftJoin('survey_unit_sessions', "survey_unit_sessions.id = {$results_table}.session_id")
					->fetch();
			return $count;
		else:
			return array('finished' => 0, 'begun' => 0);
		endif;
	}

	public function getAverageTimeItTakes() {
		if($this->hasResultsTable()) {
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

	public function delete() {
		if ($this->deleteResults()): // always back up
			$this->dbh->query("DROP TABLE IF EXISTS `{$this->results_table}`");
			if (($filename = $this->getOriginalFileName())) {
				@unlink(Config::get('survey_upload_dir') . '/' . $filename);
			}
			return parent::delete();
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
					<a class='btn' href='" . admin_study_url($this->name, 'show_item_table') . "'>View items</a>
					<a class='btn' href='" . admin_study_url($this->name, 'upload_items') . "'>Upload items</a>
			</p>";
			$dialog .= '<br><p class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Survey">Save</a>
				<a class="btn btn-default" href="' . admin_study_url($this->name, 'access') . '">Test</a>
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
	 * Merge survey items. Each parameter is an associative array indexed by the names of the items in the survey
	 * If $oldItems is empty, then we are creating a new survey table. If $oldItems is not empty, then we delete all items which are not 'keepable'
	 * All non null entries represent the MySQL data type definition of the fields as they should be in the survey results table
	 * NOTE: All the DB queries here should be in a transaction of calling function
	 *
	 * @param array $keptItems
	 * @param array $newItems
	 * @param array $deleteItems
	 * @return bool;
	 */
	private function mergeItems(array $keptItems, array $newItems, array $deleteItems) {
		if (!$keptItems && !$newItems) {
			// we are creating a new table
			return false;
		}

		$toDelete = $alterQuery = array();
		$altQ = $delQ = null;
		$existingColumns = $this->dbh->getTableDefinition($this->results_table, 'Field');

		/* @var $item Item */
		// Create query to modify items in an existing results table
		foreach ($keptItems as $item) {
			if (($field_definition = $item->getResultField()) !== null && isset($existingColumns[$item->name])) {
				$alterQuery[] = " MODIFY $field_definition";
			}
		}
		// Create query to drop items in existing table
		foreach ($deleteItems as $item) {
			if (($field_definition = $item->getResultField()) !== null && isset($existingColumns[$item->name])) {
				$alterQuery[] = " DROP `{$item->name}`";
			}
			$toDelete[] = $item->name;
		}
		// Create query for adding items to an existing table
		foreach ($newItems as $item) {
			if (($field_definition = $item->getResultField()) !== null) {
				$alterQuery[] = " ADD $field_definition";
			}
		}

		if ($alterQuery) {
			// prepend the alter table clause
			$alterQuery[0] = "ALTER TABLE `{$this->results_table}` {$alterQuery[0]}";
			$altQ = implode(',', $alterQuery);
			//formr_log("\nMerge Survey {$this->name} \n ALTER: $altQ");
			$this->dbh->query($altQ);
		}

		// Create query for deleting items from survey_items table
		if ($toDelete) {
			$toDelete = implode(',', array_map(array($this->dbh, 'quote'), $toDelete));
			$studyId = (int) $this->id;
			$delQ = "DELETE FROM survey_items WHERE `name` IN ($toDelete) AND study_id = $studyId";
			//formr_log("\nMerge Survey {$this->name} \n DELETE: $delQ");
			$this->dbh->query($delQ);
		}

		return true;
	}

	public function getOriginalFileName() {
		return $this->dbh->findValue('survey_studies', array('id' => $this->id), 'original_file');
	}

	public function getGoogleFileId() {
		return $this->dbh->findValue('survey_studies', array('id' => $this->id), 'google_file_id');
	}
}
