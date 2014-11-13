<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";

class Shuffle extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $groups = 2;
	public $ended = false;
	public $type = 'Shuffle';
	public $icon = "fa-random";
	
	
	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL) 
	{
		parent::__construct($fdb,$session,$unit, $run_session);

		if($this->id):
			$data = $this->dbh->prepare("SELECT groups FROM `survey_shuffles` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->groups = $vars['groups'];
				$this->valid = true;
			endif;
		endif;

	}
	public function create($options)
	{
		$this->dbh->beginTransaction();
		if(!$this->id)
			$this->id = parent::create('Shuffle');
		else
			$this->modify($this->id);
		
		if(isset($options['groups']))
		{
			$this->groups = $options['groups'];
		}
		
		$create = $this->dbh->prepare("INSERT INTO `survey_shuffles` (`id`, `groups`)
			VALUES (:id, :groups)
		ON DUPLICATE KEY UPDATE
			`groups` = :groups2
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':groups',$this->groups);
		$create->bindParam(':groups2',$this->groups);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		$dialog = '<h5>Randomly assign to one of <input style="width:100px" class="form-control" type="number" placeholder="2" name="groups" value="'.h($this->groups).'"> groups counting from one.</h5>
			<p>You can later read the assigned group using <code>shuffle$group</code>. <br>
		You can then for example use a SkipForward to send one group to a different arm/path in the run or use a showif in a survey to show certain items/stimuli to one group only.</p>
		';
#			'<p><input type="hidden" name="end" value="0"><label><input type="checkbox" name="end" value="1"'.($this->can_be_ended ?' checked ':'').'> allow user to continue after viewing page</label></p>';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Shuffle">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Shuffle">Preview</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'fa-random fa-1-5x');
	}
	public function removeFromRun()
	{
		return $this->delete();		
	}
	public function randomise_into_group()
	{
		return mt_rand(1,$this->groups);
	}
	public function test()
	{
		
		echo '<h3>Randomisation</h3>
			<p>We just generated fifty random group assignments:</p>';
		for($i=0; $i < 50; $i++):
			echo $this->randomise_into_group() . '&nbsp; ';
		endfor;
		echo '<p>Remember that we start counting at one (1), so if you have two groups you will check <code>shuffle$group == 1</code> and <code>shuffle$group == 2</code>. You can read a person\'s 
			group using <code>shuffle$group</code>. If you generate more than one
		random group in a run, you might have to use the last one <code>tail(shuffle$group,1)</code>, but
		usually you shouldn\'t do this.</p>';
	}
	public function exec()
	{
		$group = $this->randomise_into_group();
		$set_group = $this->dbh->prepare("INSERT INTO `shuffle` 
			(`session_id`, `unit_id`, `group`, `created`) 
	VALUES  (:session_id, :unit_id, :group,  NOW()) ");
		$set_group->bindParam(":unit_id",$this->id);
		$set_group->bindParam(":group",$group);
		$set_group->bindParam(":session_id",$this->session_id);
		$set_group->execute();
		
		$this->end();
		return false;
	}
}