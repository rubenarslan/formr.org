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
	public $already_answered = 0;
	public $not_answered = 0;
	public $hidden_but_rendered = 0;
	public $hidden_but_rendered_on_current_page = 0;
	public $not_answered_on_current_page = 0;

	/**
	 * @var DB
	 */
	public $dbh;

	/**
	 * @var ParsedownExtra
	 */
	public $parsedown;

	public function __construct($fdb, $session, $unit, $run_session = NULL, $run = NULL) {
		$this->dbh = $fdb;
		if (isset($unit['name']) AND ! isset($unit['unit_id'])): // when called via URL
			global $user;
			$id = $this->dbh->findValue('survey_studies', array('name' => $unit['name'], 'user_id' => $user->id), array('id'));
			$this->id = $id;
			$unit['unit_id'] = $this->id; // parent::__construct needs this
		endif;

		parent::__construct($fdb, $session, $unit, $run_session, $run);

		if ($this->id):
			$this->load();
		endif;
	}

	private function load() {
		$vars = $this->dbh->findRow('survey_studies', array('id' => $this->id));
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
		// this unit type is a bit special
		// all other unit types are created only within runs
		// but surveys are semi-independent of runs
		// so it is possible to add a survey, without specifying which one at first
		// and to then choose one.
		// thus, we "mock" a survey at first
		if (count($options) === 1 || isset($options['mock'])) {
			$this->valid = true;
		} else { // and link it to the run only later
			if (isset($options['unit_id']) AND $options['unit_id'] != '') {
				$this->id = $options['unit_id'];
				if ($this->linkToRun()) {
					$this->load();
				}
			}
			$this->modify($options);
			$this->valid = true;
		}
	}

	public function render() {
		global $js;
		$js = (isset($js) ? $js : '') . '<script src="' . WEBROOT . 'assets/' . (DEBUG ? 'js' : 'minified') . '/survey.js"></script>';

		$ret = '
		<div class="row study-' . $this->id . ' study-name-'. $this->name .'">
			<div class="col-md-12">
		';
		$ret .= $this->render_form_header() .
				$this->render_items() .
				$this->render_form_footer();
		$ret .= '
			</div> <!-- end of col-md-12 div -->
		</div> <!-- end of row div -->
		';
		$this->dbh = NULL;
		return $ret;
	}

        protected function startEntry() {
		$this->dbh->insert_update($this->results_table, array(
			'session_id' => $this->session_id,
			'study_id' => $this->id,
			'created' => mysql_now()
				), array(
			'modified' => mysql_now(),
		));
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
			"INSERT INTO `survey_items_display` 
				(item_id, session_id, displaycount, created, answer, saved, shown, shown_relative, answered, answered_relative)
			VALUES (:item_id, :session_id, NULL, NOW(), :answer, :saved, :shown, :shown_relative, :answered, :answered_relative)
			ON DUPLICATE KEY UPDATE 
				answer = VALUES(answer), 
				saved = VALUES(saved),
				shown = VALUES(shown),
				shown_relative = VALUES(shown_relative),
				answered = VALUES(answered),
				answered_relative = VALUES(answered_relative),
				displaycount = displaycount + 1");
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
			notify_user_error($e, 'An error saving your survey data. Please notify the author of this survey with this date and time');
			formr_log($e->getMessage());
			formr_log($e->getTraceAsString());
			$redirect = false;
		}

		// If all was well and we are re-directing then do so
		if ($redirect) {
			redirect_to($this->run_name);
		}
/*
		FIXME: Remove this till further notice
		// If we did not redirect (meaning an error occured or $redirect == FALSE) and post was not internal, then you need to refresh items
		if (empty($posted['__INTERNAL__'])) {
			$this->getNextItems();
		}
 */
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

		$counts = array(
			'already_answered' => $answered['count'],
			'not_answered' => 0,
			'hidden_but_rendered' => 0,
			'visible_on_current_page' => 0,
			'hidden_but_rendered_on_current_page' => 0,
		);

		/** @var Item $item */
		foreach ($this->unanswered as $item) {
			// count only rendered items, not skipped ones
			if ($item->isVisible($this)) {
				$counts['not_answered']++;
			}
			// count those items that were hidden but rendered (ie. those relying on missing data for their showif)
			if ($item->isHiddenButRendered($this)) {
				$counts['hidden_but_rendered']++;
			}
		}
		/** @var Item $item */
		foreach ($this->to_render as $item) {
			// On current page, count only rendered items, not skipped ones
			if ($item->isVisible($this)) {
				$counts['visible_on_current_page']++;
			}
			// On current page, count those items that were hidden but rendered (ie. those relying on missing data for their showif)
			if ($item->isHiddenButRendered($this)) {
				$counts['hidden_but_rendered_on_current_page']++;
			}
		}

		$this->already_answered = $counts['already_answered'];
		$this->not_answered = $counts['not_answered'];
		$this->hidden_but_rendered = $counts['hidden_but_rendered'];
		$this->hidden_but_rendered_on_current_page = $counts['hidden_but_rendered_on_current_page'];
		$this->not_answered_on_current_page = $this->not_answered - $counts['visible_on_current_page'];

		$all_items = $this->already_answered + $this->not_answered;

		if ($all_items !== 0) {
			$this->progress = $this->already_answered / $all_items;
		} else {
			$this->errors[] = _('Something went wrong, there are no items in this survey!');
			$this->progress = 0;
		}
		// if there only hidden items, that have no way of becoming visible (no other items)
		if($this->not_answered === $this->hidden_but_rendered) {
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
		/* @var Item $item */
		foreach ($items as $name => $item) {
			if ($item->getShowIf()) {
				$show_ifs[] = "{$name} = (function() { with(tail({$this->name}, 1), {\n {$item->getShowIf()} \n} ) })()";
			}
		}

		if ($show_ifs) {
			$code = "list(\n" . implode(",\n", $show_ifs) . "\n)";
			$variables = $this->getUserDataInRun($this->dataNeeded($this->dbh, $code, $this->name));
			$ocpu_session = opencpu_evaluate($code, $variables, 'json', null, true);
			if(!$ocpu_session OR $ocpu_session->hasError()) {
				notify_user_error(opencpu_debug($ocpu_session), "There was a problem evaluating showifs using openCPU.");
			}
			$results = $ocpu_session->getJSONObject();
			// Fit show-ifs
			foreach ($items as &$item) {
				$item->setVisibility(array_val($results, $item->name));
			}
		}

		// Compute dynamic values only if items are certainly visisble
		foreach ($items as $name => &$item) {
			if ($item->needsDynamicValue() && $item->isVisible()) {
				$dynamic_values[] = "{$name} = (function() { with(tail({$this->name}, 1), {\n {$item->getValue()} \n} ) })()";
			}
		}
		if ($dynamic_values) {
			$code = "list(\n" . implode(",\n", $dynamic_values) . "\n)";
			$variables = $this->getUserDataInRun($this->dataNeeded($this->dbh, $code, $this->name));
			$ocpu_session = opencpu_evaluate($code, $variables, 'json', null, true);
			$results = $ocpu_session->getJSONObject();
			if(!$ocpu_session OR $ocpu_session->hasError()) {
				notify_user_error(opencpu_debug($ocpu_session), "There was a problem getting dynamic values using openCPU.");
			}
			// Fit dynamic values in properly reder
			$post = array();
			foreach ($items as &$item) {
				$item->setDynamicValue(array_val($results, $item->name), $ocpu_session);
				if ($item->no_user_input_required && isset($item->input_attributes['value']) && $item->isVisible()) {
					$post[$item->name] = $item->input_attributes['value'];
				}
			}
		}

		// save any data that does not require user imput
		if (!empty($post)) {
			// flag not to reprocess items if posting failed
			// FIXME: $post['__INTERNAL__'] = true;
			$this->post($post, false);
			return false;
		}
		return true;
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
				`survey_items`.`order`,

		`survey_items_display`.displaycount, 
		`survey_items_display`.session_id,
		`survey_items_display`.answered')
				->from('survey_items')
				->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
				->where("survey_items.study_id = :study_id AND (survey_items_display.saved IS NULL)")
				->order('CAST(survey_items.`order` AS UNSIGNED)', 'asc')->order('survey_items.id', 'ASC')
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
			if(!$this->parseShowIfsAndDynamicValues($this->unanswered)) {
				return $this->getNextItems(true);
			}
		}

		// Gather labels and choice_lists to be parsed only for items that will potentially be visibile
		$strings_to_parse = array();
		$lists_to_fetch = array();
		$this->to_render = array();

		/** @var Item $item */
		foreach ($this->unanswered as $name => $item) {
			if (!$item->isVisible()) {
				continue;
			}

			if ($item->choice_list) {
				$lists_to_fetch[] = $item->choice_list;
			}

			if (!$item->label_parsed) {
				$this->unanswered[$name]->label_parsed = opencpu_string_key(count($strings_to_parse));
				$strings_to_parse[] = $item->label;
			}

			$this->to_render[$name] = (array) $this->unanswered[$name];
			// Since as we are skipping all non-vsisible items, we can safely truncate here on a submit button
			// This will help process fewer item labels and choice labels (maybe it is more optimal)
			if ($item->type === 'submit' && count($this->to_render) > 0) {
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
			if (!$choice['label_parsed']) {
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

		$view_query = "INSERT INTO `survey_items_display` (item_id,  session_id, displaycount, created)
			VALUES(:item_id, :session_id, 0, NOW() ) 
			ON DUPLICATE KEY UPDATE displaycount = displaycount + 1";
		$view_update = $this->dbh->prepare($view_query);
		$view_update->bindValue(":session_id", $this->session_id);

		$itemsDisplayed = 0;

		$this->rendered_items = array();
		try {
			foreach ($this->to_render as &$item) {
				if ($this->settings['maximum_number_displayed'] && $this->settings['maximum_number_displayed'] === $itemsDisplayed) {
					break;
				} else if ($item->isVisible()) {
					// if it's rendered, we send it along here or update display count
					$view_update->bindParam(":item_id", $item->id);
					$view_update->execute();

					if (!$item->hidden) {
						$itemsDisplayed++;
					}

					$this->rendered_items[] = $item;
					if ($item->type === 'submit') {
						break;
					}
				}
			}

			$this->dbh->commit();

		} catch (Exception $e) {
			$this->dbh->rollBack();
			log_exception($e, __CLASS__);
			return false;
		}
	}

	protected function render_form_header() {
		$action = run_url($this->run_name);
		$enctype = 'multipart/form-data'; # maybe make this conditional application/x-www-form-urlencoded

		$ret = '<form action="' . $action . '" method="post" class="form-horizontal' .
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
				  <div data-percentage-minimum="' . $this->settings["add_percentage_points"] . '" data-percentage-maximum="' . $this->settings["displayed_percentage_maximum"] . '" data-already-answered="' . $this->already_answered . '" data-items-left="' . $this->not_answered_on_current_page . '" data-items-on-page="' . ($this->not_answered - $this->not_answered_on_current_page) . '" data-hidden-but-rendered="' . $this->hidden_but_rendered_on_current_page . '" class="progress-bar" style="width: ' . $prog . '%;">' . $prog . '%</div>
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
		if (isset($item) AND $item->type !== "submit") {
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
				"UPDATE `{$this->results_table}` SET `ended` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS NULL", array('session_id' => $this->session_id, 'study_id' => $this->id)
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
			} else {
				$request = new Request($_GET);
				$added_via_get = array_diff(array_keys($request->getParams()), array("route","code","run_name") );

				// if information was transmitted via GET
				if(count( $added_via_get ) > 0) {
					$write = array();
					foreach($added_via_get AS $name) {
						$write[$name] = $request->getParam($name);
					}
					$items = $this->getNextItems(false);
					$this->post($write);
				} else {
					$items = $this->getNextItems();
				}
			}

			if ($this->getProgress() === 1) {
				$this->end();
				return false;
			}

			$this->renderNextItems();

			return array('body' => $this->render());
		} catch (Exception $e) {
			log_exception($e, __CLASS__);
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
	public function uploadItemTable($file, $confirmed_deletion) {
		if (trim($confirmed_deletion) == ''):
			$this->confirmed_deletion = false;
		elseif ($confirmed_deletion === $this->name):
			$this->confirmed_deletion = true;
		else:
			alert("<strong>Error:</strong> You confirmed the deletion of the study's results but your input did not match the study's name. Update aborted.", 'alert-danger');
			$this->confirmed_deletion = false;
			return false;
		endif;

		umask(0002);
		ini_set('memory_limit', '256M');
		$target = $_FILES['uploaded']['tmp_name'];
		$filename = $_FILES['uploaded']['name'];

		$this->messages[] = "File <b>$filename</b> was uploaded.";
		$this->messages[] = "Survey name was determined to be <b>{$this->name}</b>.";

		$SPR = new SpreadsheetReader();
		$SPR->readItemTableFile($target);
		$this->errors = array_merge($this->errors, $SPR->errors);
		$this->warnings = array_merge($this->warnings, $SPR->warnings);
		$this->messages = array_merge($this->messages, $SPR->messages);
		$this->messages = array_unique($this->messages);
		$this->warnings = array_unique($this->warnings);

		// if items are ok, make actual survey
		if (empty($this->errors) AND $this->createSurvey($SPR)):

			if (!empty($this->warnings)) {
				alert('<ul><li>' . implode("</li><li>", $this->warnings) . '</li></ul>', 'alert-warning');
			}

			if (!empty($this->messages)) {
				alert('<ul><li>' . implode("</li><li>", $this->messages) . '</li></ul>', 'alert-info');
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

	public function createIndependently() {
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

		$this->dbh->insert('survey_studies', array(
			'id' => $this->id,
			'created' => mysql_now(),
			'modified' => mysql_now(),
			'user_id' => $this->unit['user_id'],
			'name' => $this->name,
			'results_table' => $this->results_table,
		));

		$this->changeSettings(array(
			"maximum_number_displayed" => 0,
			"displayed_percentage_maximum" => 100,
			"add_percentage_points" => 0,
		));

		return true;
	}

	protected $user_defined_columns = array(
		'name', 'label', 'label_parsed', 'type', 'type_options', 'choice_list', 'optional', 'class', 'showif', 'value', 'order' // study_id is not among the user_defined columns
	);
	protected $choices_user_defined_columns = array(
		'list_name', 'name', 'label', 'label_parsed' // study_id is not among the user_defined columns
	);

	/**
	 * Get All choice lists in this survey with associated items
	 *
	 * @param array $specific An array if list_names which if defined, only lists specified in the array will be returned
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
				"INSERT INTO `survey_items` (study_id, name, label, label_parsed, type, type_options, choice_list, optional, class, showif, value, `order`) 
			VALUES (:study_id, :name, :label, :label_parsed, :type, :type_options, :choice_list, :optional, :class, :showif, :value, :order
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

			$val_errors = $item->validate();
			if (!empty($val_errors)) {
				$this->errors = $this->errors + $val_errors;
				unset($this->SPR->survey[$row_number], $oldItems[$item->name]);
				continue;
			}

			if (!$this->knittingNeeded($item->label)): // if the parsed label is constant
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
		try {
			if (!empty($this->errors)) {
				throw new Exception("No need to continue further if there are errors above");
			}

			if ($this->hasResultsTable() && !$this->confirmed_deletion) {
				// queries of the merge are included in opened transaction
				if ($this->backupResults() AND $this->mergeItems($keptItems, $newItems, $oldItems)) {
					$this->messages[] = "<strong>The old results table was modified and backed up.</strong>";
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
			log_exception($e, __CLASS__, $this->errors);
			return false;
		}
	}

	public function getItemsWithChoices() {
		$choice_lists = $this->getChoices();
		$this->item_factory = new ItemFactory($choice_lists);

		$raw_items = $this->getItems();

		$items = array();
		foreach ($raw_items as $row) {
			$item = $this->item_factory->make($row);
			$items[$item->name] = $item;
		}
		return $items;
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
			if (!$this->knittingNeeded($choice['label'])): // if the parsed label is constant
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
		}
		$this->messages[] = $deleted . " old choices deleted.";
		$this->messages[] = count($this->SPR->choices) . " choices were successfully loaded.";

		return true;
	}

	private function getResultsTableSyntax($columns) {
		$columns = array_filter($columns); // remove NULL, false, '' values (note, fork, submit, ...)

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
			$columns = "id, study_id, type, choice_list, type_options, name, label, label_parsed, optional, class, showif, value, `order`";
		}

		return $this->dbh->select($columns)
						->from('survey_items')
						->where(array('study_id' => $this->id))
						->order('CAST(`order` AS UNSIGNED)', 'asc')->order('id', 'asc')
						->fetchAll();
	}

	public function getItemsForSheet() {
		$get_items = $this->dbh->select('type, type_options, choice_list, name, label, optional, class, showif, value, order')
				->from('survey_items')
				->where(array('study_id' => $this->id))
				->order('CAST(`order` AS UNSIGNED)', 'asc')->order('id', 'asc')
				->statement();

		$results = array();
		while ($row = $get_items->fetch(PDO::FETCH_ASSOC)):
			$row["type"] = $row["type"] . " " . $row["type_options"] . " " . $row["choice_list"];
			unset($row["choice_list"]);
			unset($row["type_options"]);
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

	public function getResults() { // fixme: shouldnt be using wildcard operator here.
		$results_table = $this->results_table;
		$select = $this->dbh->select("survey_run_sessions.session, {$results_table}.*")
				->from($results_table)
				->leftJoin('survey_unit_sessions', "{$results_table}.session_id = survey_unit_sessions.id")
				->leftJoin('survey_run_sessions', 'survey_unit_sessions.run_session_id = survey_run_sessions.id')
				->statement();

		$results = array();
		while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
			unset($row['study_id']);
			$results[] = $row;
		}

		return $results;
	}

	public function getItemDisplayResults() {
		return $this->dbh->select('
		`survey_run_sessions`.session,
		`survey_items`.name,
		`survey_items_display`.answer,
		`survey_items_display`.created,
		`survey_items_display`.saved,
		`survey_items_display`.shown,
		`survey_items_display`.shown_relative,
		`survey_items_display`.answered,
		`survey_items_display`.answered_relative,
		`survey_items_display`.displaycount')
						->from('survey_items_display')
						->leftJoin('survey_unit_sessions', 'survey_unit_sessions.id = survey_items_display.session_id')
						->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
						->leftJoin('survey_items', 'survey_items_display.item_id = survey_items.id')
						->where('survey_items.study_id = :study_id')
						->order('survey_run_sessions.session')->order('survey_run_sessions.created')->order('survey_unit_sessions.created')->order('survey_items_display.item_id')
						->bindParams(array('study_id' => $this->id))
						->fetchAll();
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
						"SUM(`{$results_table}`.ended IS NULL)" => 'begun',
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

	public function delete() {
		if ($this->deleteResults()): // always back up
			$this->dbh->query("DROP TABLE IF EXISTS `{$this->results_table}`");
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
				if ($this->id === $study['id'])
					$selected = "selected";
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
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Survey">Save.</a>
				<a class="btn btn-default" href="' . admin_study_url($this->name, 'access') . '">Test</a>
			</p>';
//		elseif($studies):
		} else {
			$dialog .= '<p>
				<div class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Pause">Save.</a>
				</div>
			</p>';
		}

		$dialog = $prepend . $dialog;
		return parent::runDialog($dialog, 'fa-pencil-square');
	}

	/**
	 * Merge survey items. Each parameter is an associative array indexed by the names of the items in the survey
	 * If $oldItems is empty, then we are creating a new survey table. If $oldItems is not empty, then we delete all items which are not 'keepable'
	 * All non NULL entries represent the MySQL data type definition of the fields as they should be in the survey results table
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

}
