<?php
class Survey extends RunUnit {

	public $id = null;
	public $name = null;
	public $run_name = null;
	public $items = array();
	public $unanswered = array();
	public $already_answered = 0;
	public $not_answered = 0;
	public $progress = 0;
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
	public $admin_usage = false;
	public $result_count = 0;
	private $confirmed_deletion = false;
	public $item_factory = null;

	/**
	 * @var DB
	 */
	public $dbh;

	/**
	 * @var ParsedownExtra
	 */
	public $parsedown;

	public function __construct($fdb, $session, $unit, $run_session = NULL) {
		$this->dbh = $fdb;
		if (isset($unit['name']) AND ! isset($unit['unit_id'])): // when called via URL
			global $user;
			$id = $this->dbh->findValue('survey_studies', array('name' => $unit['name'], 'user_id' => $user->id), array('id'));
			$this->id = $id;
			$unit['unit_id'] = $this->id; // parent::__construct needs this
		endif;

		parent::__construct($fdb, $session, $unit, $run_session);

		if ($this->id):
			$this->load();
		endif;

		if ($this->beingTestedByOwner()) {
			$this->admin_usage = true;
	}
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

			$this->settings['maximum_number_displayed'] = (int) $vars['maximum_number_displayed'];
			$this->settings['displayed_percentage_maximum'] = (int) $vars['displayed_percentage_maximum'];
			$this->settings['add_percentage_points'] = (int) $vars['add_percentage_points'];

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
			if(isset($options['unit_id']) AND $options['unit_id']!='') {
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
		$js = (isset($js) ? $js : '') . '<script src="' . WEBROOT . 'assets/'. (DEBUG?'js':'minified'). '/survey.js"></script>';

		$ret = '
	<div class="row">
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

		unset($posted['id']); // cant overwrite your session
		unset($posted['session']); // cant overwrite your session
		unset($posted['session_id']); // cant overwrite your session ID
		unset($posted['study_id']); // cant overwrite your study ID
		unset($posted['created']); // cant overwrite
		unset($posted['modified']); // cant overwrite
		unset($posted['ended']); // cant overwrite
		
		if(isset($posted["_item_views"]["shown"])):
			$posted["_item_views"]["shown"] = array_filter($posted["_item_views"]["shown"]);
			$posted["_item_views"]["shown_relative"] = array_filter($posted["_item_views"]["shown_relative"]);
			$posted["_item_views"]["answered"] = array_filter($posted["_item_views"]["answered"]);
			$posted["_item_views"]["answered_relative"] = array_filter($posted["_item_views"]["answered_relative"]);
		endif;

		$answered_q = $this->dbh->prepare(
			"UPDATE `survey_items_display` SET 
				answer = :answer, 
				saved = :saved,
				shown = :shown,
				shown_relative = :shown_relative,
				answered = :answered,
				answered_relative = :answered_relative,
				displaycount = displaycount+1
			WHERE session_id = :session_id AND item_id = :item_id");
		$answered_q->bindParam(":session_id", $this->session_id);

		try {
			$this->dbh->beginTransaction();
			foreach ($posted AS $name => $value) {
				if (!isset($this->unanswered[$name])) {
					continue;
				}

				$item_saved = true;
				if ($this->unanswered[$name]->save_in_results_table) {
					$value = $this->unanswered[$name]->validateInput($value);
					if ($this->unanswered[$name]->error) {
						$this->errors[$name] = $this->unanswered[$name]->error;
						continue;
					}

					$post_form = $this->dbh->prepare(
						"UPDATE `{$this->results_table}` SET `$name` = :$name 
						WHERE session_id = :session_id AND study_id = :study_id AND `$name` IS NULL;
					");

					$post_form->bindValue(":$name", $value);
					$post_form->bindValue(":session_id", $this->session_id);
					$post_form->bindValue(":study_id", $this->id);
					$item_saved = $post_form->execute();
				}
				// update item display table
				$answered_q->bindParam(":item_id", $this->unanswered[$name]->id);
				$answered_q->bindParam(":answer", $value);

				if(isset($posted["_item_views"]["shown"][$this->unanswered[$name]->id],
						 $posted["_item_views"]["shown_relative"][$this->unanswered[$name]->id])):
	 				$shown = $posted["_item_views"]["shown"][$this->unanswered[$name]->id];	 
	 				$shown_relative = $posted["_item_views"]["shown_relative"][$this->unanswered[$name]->id];	 
				else:
					$shown = mysql_now();
					$shown_relative = null; // and where this is null, performance.now wasn't available
				endif;
				if(isset($posted["_item_views"]["answered"][$this->unanswered[$name]->id], // separately to "shown" because of items like "note"
					 	 $posted["_item_views"]["answered_relative"][$this->unanswered[$name]->id])):
					$answered = $posted["_item_views"]["answered"][$this->unanswered[$name]->id];
					$answered_relative = $posted["_item_views"]["answered_relative"][$this->unanswered[$name]->id];
				else:
					$answered = $shown; // this way we can identify items where JS time failed because answered and show time are exactly identical
					$answered_relative = null;
				endif;
				$answered_q->bindValue(":saved", mysql_now());
				$answered_q->bindParam(":shown", $shown);
				$answered_q->bindParam(":shown_relative", $shown_relative);
				$answered_q->bindParam(":answered", $answered);
				$answered_q->bindParam(":answered_relative", $answered_relative);
				$item_answered = $answered_q->execute();

				if (!$item_saved OR !$item_answered) {
					throw new Exception("Survey item '$name' could not be saved with value '$value' in table '{$this->results_table}' (FieldType: {$this->unanswered[$name]->getResultField()})");
				}
				unset($this->unanswered[$name]);
			} //endforeach
			$this->dbh->commit();
		} catch (Exception $e) {
			$this->dbh->rollBack();
			formr_log($e->getMessage());
			formr_log($e->getTraceAsString());
		}

		if (empty($this->errors) AND ! empty($posted) AND $redirect) {
			// PRG
			redirect_to($this->run_name);
		}
	}

	protected function getProgress() {
		$answered = $this->dbh->select(array('COUNT(`survey_items_display`.saved)' => 'count', 'study_id', 'session_id'))
			->from('survey_items')
			->leftJoin('survey_items_display', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
			->where('survey_items_display.session_id IS NOT NULL')
			->where('survey_items.study_id = :study_id')
			->where("survey_items.type NOT IN ('mc_heading', 'submit')")
			->where("`survey_items_display`.saved IS NOT NULL")
			->bindParams(array('session_id' => $this->session_id, 'study_id' => $this->id))
			->fetch();

		$this->already_answered = $answered['count'];
		$this->not_answered = array_filter($this->unanswered, function ($item) {
			if (
					in_array($item->type, array('submit', 'mc_heading')) OR // these items require no user interaction and thus don't count against progress
					( $item->type == 'note' AND $item->hasBeenRendered()) OR // item is a note and has already been viewed
					!$item->willBeShown($this) // item was skipped
			) {
				return false;
			}

			return true;
		});
		$this->not_answered = count($this->not_answered);

// todo: in the medium term it may be more intuitive to treat notes as item that are answered by viewing but that can linger in a special case, might require less extra logic. but they shouldn't go in the results table.. so maybe not.
		/* 		$seen_notes = array_filter($this->unanswered, function ($item)
		  { // notes stay in the unanswered batch
		  if(
		  ! $item->hidden											 // item wasn't skipped
		  AND ($item->type == 'note' AND $item->displaycount > 0) 		 // item is a note and has already been viewed
		  )
		  return true;
		  else
		  return false;
		  }
		  );
		  $this->already_answered += count($seen_notes);
		 */

		$all_items = $this->already_answered + $this->not_answered;

		if ($all_items !== 0) {
			$this->progress = $this->already_answered / $all_items;
			return $this->progress;
		} else {
			$this->errors[] = _('Something went wrong, there are no items in this survey!');
			$this->progress = 0;
			return 0;
		}
	}

	protected function getAndRenderChoices() {
		// get and render choices
		// todo: should only get & render choices for items on this page
		$items = $this->dbh->select('list_name, name, label, label_parsed')
						->from('survey_item_choices')
						->where(array('study_id' => $this->id))
						->order('id', 'ASC')->fetchAll();

		$choice_lists = array();
		foreach ($items as $row) {
			if (!isset($choice_lists[$row['list_name']])) {
				$choice_lists[$row['list_name']] = array();
			}

			// FixMe:
			// - Because were not using this much yet, I haven't really made any effort to efficiently only calculate this when necessary
			// - Maybe gather all labels and send in a 'box' and opencpu returns parsed labels in same box
			if ($row['label_parsed'] === null) {
				$opencpu_vars = $this->getUserDataInRun($this->dataNeeded($this->dbh, $row['label']));
				$markdown = opencpu_knitdisplay($row['label'], $opencpu_vars);

				if (mb_substr_count($markdown, "</p>") === 1 AND preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)): // simple wraps are eliminated
					$row['label_parsed'] = $matches[1];
				else:
					$row['label_parsed'] = $markdown;
				endif;
			}

			$choice_lists[$row['list_name']][$row['name']] = $row['label_parsed'];
		}
		return $choice_lists;
	}

	/**
	 * This function first restricts the number of items to walk through by only requesting those from the DB which have not yet been answered
	 * - first caveat: items like mc_heading, note and submit are special and don't just get eliminated when "answered".
	 * The function proceeds to get all choices and render them – there is room to economise here, but I considered this premature
	 * for now. to be clear: getting the choices has little cost, rendering them has high costs if they're dynamic (need openCPU), but we do that rarely if ever now.
	 * 
	 */
	protected function getNextItems() {
		if (!isset($this->settings["maximum_number_displayed"]) OR trim($this->settings["maximum_number_displayed"]) == "" OR !is_numeric($this->settings["maximum_number_displayed"])) {
			$this->settings["maximum_number_displayed"] = null;
		}

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
		->where("survey_items.study_id = :study_id AND (survey_items_display.saved IS NULL OR survey_items.type = 'note')")
		->order('survey_items.order', 'ASC')->order('survey_items.id', 'ASC')
		->bindParams(array('session_id' => $this->session_id, 'study_id' => $this->id))
		->statement();
		
		$choice_lists = $this->getAndRenderChoices();
		$this->item_factory = new ItemFactory($choice_lists);

		while ($item_array = $get_items->fetch(PDO::FETCH_ASSOC)):
			$name = $item_array['name'];
			$item = $this->item_factory->make($item_array);

			if ($item->no_user_input_required AND $item->needsDynamicValue() AND $item->mightBeShown($this)) { // determine value if there is a dynamic one and no user input is required
				$item->determineDynamicValue($this);
			}
			$this->unanswered[$name] = $item;
			if ($item->no_user_input_required AND isset($item->input_attributes['value']) AND $item->mightBeShown($this)):
				$this->post(array($item->name => $item->input_attributes['value']), false); # add this data but don't reload
			endif;
		endwhile;
	}

	protected function renderNextItems() {

		$this->dbh->beginTransaction();

		$view_query = "INSERT INTO `survey_items_display` (item_id,  session_id, displaycount, created)
			VALUES(:item_id, :session_id, 0, NOW() ) 
		ON DUPLICATE KEY UPDATE displaycount = displaycount + 1";
		$view_update = $this->dbh->prepare($view_query);
		$view_update->bindValue(":session_id", $this->session_id);

		$itemsDisplayed = 0;
		$item_will_be_rendered = true;

		$this->rendered_items = array();
		try {
			foreach ($this->unanswered as &$item) {
				if ($this->settings['maximum_number_displayed'] != null AND $itemsDisplayed >= $this->settings['maximum_number_displayed']) {
				$item_will_be_rendered = false;
				}
			if ($item_will_be_rendered AND $item->mightBeShown($this)) {
				if ($item->type === 'submit') {
					if ($itemsDisplayed === 0):
						continue; // skip submit buttons once everything before them was dealt with	
					else:
						$item_will_be_rendered = false;
					endif;
					} elseif ($item->type === "note") {
						
						$next = current($this->unanswered);
						/**
						 * if item was displayed before AND
						 * this is the end of the survey OR the next item is hidden OR the next item isn't a normal item
						 * @todo: should actually be checking if all following items up to the next note are hidden, but at least it's displayed once like this and doesn't block progress
						 */
						if ($item->hasBeenRendered() AND ($next === false OR in_array($next->type, array('note', 'submit', 'mc_heading')) OR !$next->willBeShown($this, $item->showif))) {
						continue; // skip this note							
					}
				} else if ($item->type === "mc_heading") {
					$next = current($this->unanswered);
						/**
						 * If this is the end of the survey OR the next item is hidden OR the next item isn't a mc item
						 * then skip mc_heading
						 */
						if ($next === false OR !in_array($next->type, array('mc', 'mc_multiple', 'mc_button', 'mc_multiple_button')) OR !$next->willBeShown($this, $item->showif)) {
						continue; // skip this mc_heading
					}
				}
				
				$view_update->bindParam(":item_id", $item->id);
				$view_update->execute(); // if it's rendered, we send it along here.
				
				if (!$item->hidden) {
					$itemsDisplayed++;
				}

				$this->rendered_items[] = $item;
			}
		}
		$this->dbh->commit();
		$this->not_answered_on_current_page = array_filter($this->rendered_items, function ($item) {
				/**
				 * If item was skipped OR 
				 * these items require no user interaction and thus don't count against progress OR
				 * item is a note and has already been viewed
				 * Then item is not answered on current page
				 */
				if (in_array($item->type, array('submit', 'mc_heading')) OR ($item->type == 'note' AND $item->hasBeenRendered()) OR !$item->willBeShown($this)) {
				return false;
		}
				return true;
			});

		$this->not_answered_on_current_page = count($this->not_answered_on_current_page);
		} catch (Exception $e) {
			$this->dbh->rollBack();
			log_exception($e, __CLASS__);
			return false;
	}

	}

	protected function render_form_header() {
		$action = run_url($this->run_name);
		$enctype = 'multipart/form-data'; # maybe make this conditional application/x-www-form-urlencoded

		$ret = '<form action="' . $action . '" method="post" class="form-horizontal" accept-charset="utf-8" enctype="' . $enctype . '">';

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
				  <div data-percentage-minimum="' . $this->settings["add_percentage_points"] . '" data-percentage-maximum="' . $this->settings["displayed_percentage_maximum"] . '" data-already-answered="' . $this->already_answered . '"  data-items-left="' . ($this->not_answered - $this->not_answered_on_current_page) . '" class="progress-bar" style="width: ' . $prog . '%;">' . $prog . '%</div>
			</div>
			</div>';

		if (!empty($this->errors)) {
			$ret .= '
			<div class="form-group has-error form-message">
				<div class="control-label"><i class="fa fa-exclamation-triangle pull-left fa-2x"></i>' . implode("<br>", array_unique($this->errors)) . '</div>'.
			'</div>';
		}

		return $ret;
	}

	protected function render_items() {
		$ret = '';

		foreach ($this->rendered_items AS $item) {
			// determine value if there is a dynamic one and no user input is required
			if (!$item->no_user_input_required AND $item->needsDynamicValue() ) {
				$item->determineDynamicValue($this);
			}

			// item label has to be dynamically generated with user data
			if ($item->needsDynamicLabel()) {
				$item->determineDynamicLabel($this);
			}
			$ret .= $item->render();
		}

		// if the last item was not a submit button, add a default one
		if (isset($item) AND $item->type !== "submit") {
			$sub_sets = array('label_parsed' => '<i class="fa fa-arrow-circle-right pull-left fa-2x"></i> Go on to the<br>next page!', 'class_input' => 'btn-info');
			$item = new Item_submit($sub_sets);
			$ret .= $item->render();
		}

		return $ret;
	}

	protected function render_form_footer() {
		return "</form>"; /* close form */
	}

	public function end() {
		$ended = $this->dbh->exec(
			"UPDATE `{$this->results_table}` SET `ended` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS NULL", 
			array('session_id' => $this->session_id, 'study_id' => $this->id)
		);
		return parent::end();
	}

	public function exec() {
		if ($this->called_by_cron) {
			return true; // never show to the cronjob
		}

		// execute survey unit in a try catch block
		// @todo Do same for other run units
		try {
			$this->startEntry();
			$this->getNextItems();
			$this->post(array_merge($_POST, $_FILES));

			if ($this->getProgress() === 1) {
				$this->end();
				return false;
			}

			$this->renderNextItems();

			return array('title' => null, 'body' => $this->render());
		} catch (Exception $e) {
			log_exception($e, __CLASS__);
			return array('title' => null, 'body' => '');
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

		if ($errors) {
			return false;
		}

		$this->dbh->update('survey_studies', array(
			'maximum_number_displayed' => $key_value_pairs['maximum_number_displayed'],
			'displayed_percentage_maximum' => $key_value_pairs['displayed_percentage_maximum'],
			'add_percentage_points' => $key_value_pairs['add_percentage_points'],
		), array(
			'id' => $this->id,
		));

		alert('Survey settings updated', 'alert-success', true);
		}

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

	public function getChoices() {
		$get_item_choices = $this->dbh->select('list_name, name, label')
				->from('survey_item_choices')
				->where(array('study_id' => $this->id))
				->order('id', 'ASC')->statement();

		$choice_lists = array();
		while ($row = $get_item_choices->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($choice_lists[$row['list_name']])) {
				$choice_lists[$row['list_name']] = array();
			}
			$choice_lists[$row['list_name']][$row['name']] = $row['label'];
		}

		return $choice_lists;
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
		$this->parsedown->setBreaksEnabled(true);

		$this->addChoices();
		$choice_lists = $this->getChoices();
		$this->item_factory = new ItemFactory($choice_lists);

		// Get old items, mark them as false meaning all are vulnerable for delete.
		// When the loop over survey items ends you will know which should be deleted.
		$items = $this->getItems('name, type');
		$oldItems = $keptItems = $newItems = array();
		foreach ($items as $item) {
			$item['skip_more_options'] = true;
			if (($object = $this->item_factory->make($item)) !== false) {
				$oldItems[$item['name']] = $object;
			}
		}

		$UPDATES = implode(', ', get_duplicate_update_string($this->user_defined_columns));
		$add_items = $this->dbh->prepare("
			INSERT INTO `survey_items` (study_id, name, label, label_parsed, type, type_options, choice_list, optional, class, showif, value, `order`) 
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
			$columns_string = '';# create a results tabel with only the access times
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

	/**
	 * @deprecated
	 */
	private function getOldSyntax() {
		$resC = $this->getResultCount();
		if ($resC == array('finished' => 0, 'begun' => 0)):
			$this->messages[] = __("The results table was empty.", array_sum($resC));
			return null;
		endif;

		$old_items = $this->getItems();
		$choice_lists = $this->getChoices();
		$this->item_factory = new ItemFactory($choice_lists);

		$old_result_columns = array();
		foreach ($old_items AS $row) {
			$item = $this->item_factory->make($row);
			if (!$item) {
				if (isset($row['type'])) {
					$type = $row['type'];
				} else {
					$type = "<em>missing</em>";
				}
				alert("While trying to recreate old results table: Item type " . h($row['type']) . " not found.", 'alert-danger');
				return false;
			}
			$old_result_columns[] = $item->getResultField();
		}

		return $this->getResultsTableSyntax($old_result_columns);
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
				->order('order', 'asc')->order('id', 'asc')
				->fetchAll();
		}

	public function getItemsForSheet() {
		$get_items = $this->dbh->select('type, type_options, choice_list, name, label, optional, class, showif, value, order')
				->from('survey_items')
				->where(array('study_id' => $this->id))
				->order('order', 'asc')->order('id', 'asc')
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

	public function deleteResults() {
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

		$delete = $this->dbh->query("TRUNCATE TABLE `{$this->results_table}`");
		$this->dbh->delete('survey_unit_sessions', array('unit_id' => $this->id));

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
		if ($this->dbh->table_exists($results_table)):
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
			
			if($this->id === null)
				$dialog .= '<option value=""></option>';
			foreach ($studies as $study):
				$selected = "";
				if($this->id === $study['id']) $selected = "selected";
				$dialog .= "<option value=\"{$study['id']}\" $selected>{$study['name']}</option>";
			endforeach;
			$dialog .= "</select>";
			$dialog .= '</div>';
		else:
			$dialog = "<h5>No studies. <a href='" .  admin_study_url() . "'>Add some first</a></h5>";
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
			$dialog .= '<br><p class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Pause">Save.</a>
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
			if (($field_definition = $item->getResultField()) !== null) {
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
			formr_log("\nMerge Survey {$this->name} \n ALTER: $altQ");
			$this->dbh->query($altQ);
		}

		// Create query for deleting items from survey_items table
		if ($toDelete) {
			$toDelete = implode(',', array_map(array($this->dbh, 'quote'), $toDelete));
			$studyId = (int) $this->id;
			$delQ = "DELETE FROM survey_items WHERE `name` IN ($toDelete) AND study_id = $studyId";
			formr_log("\nMerge Survey {$this->name} \n DELETE: $delQ");
			$this->dbh->query($delQ);
		}

		return true;
	}

}
