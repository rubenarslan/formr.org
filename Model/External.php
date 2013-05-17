<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";

class External extends RunUnit {
	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	private $address = null;
	
	public function __construct($fdb, $session = null, $unit = null) 
	{
		parent::__construct($fdb,$session,$unit);

		if($this->id):
			$data = $this->dbh->prepare("SELECT id,address FROM `survey_externals` WHERE id = :id LIMIT 1");
			$data->bindParam(":id",$this->id);
			$data->execute() or die(print_r($data->errorInfo(), true));
			$vars = $data->fetch(PDO::FETCH_ASSOC);
			
			if($vars):
				$this->address = $vars['address'];
				$this->valid = true;
			endif;
		endif;
		
	}
	public function create($options)
	{
		$this->dbh->beginTransaction();
		if(!$this->id)
			$this->id = parent::create('External');
		
		if(isset($options['address']))
		{
			$this->address = $options['address'];
		}
		
		$create = $this->dbh->prepare("INSERT INTO `survey_externals` (`id`, `address`)
			VALUES (:id, :address)
		ON DUPLICATE KEY UPDATE
			`address` = :address 
		;");
		$create->bindParam(':id',$this->id);
		$create->bindParam(':address',$this->address);
		$create->execute() or die(print_r($create->errorInfo(), true));
		$this->dbh->commit();
		$this->valid = true;
		
		return true;
	}
	public function displayForRun($prepend = '')
	{
		$dialog = '<p><label>Address: <br>
			<input style="width:300px" type="text" placeholder="http://examp.org?code=%s" name="address" value="'.$this->address.'"></label></p>'
		;
		$dialog .= '<p><a class="btn unit_save" href="ajax_save_run_unit?type=External">Save.</a></p>';
		$dialog .= '<p><a class="btn unit_test" href="ajax_test_unit?type=External">Preview.</a></p>';
		

		$dialog = $prepend . $dialog;
		
		return parent::runDialog($dialog,'icon-external-link');
	}
	public function removeFromRun($run_id)
	{
		return $this->delete();		
	}
	public function test()
	{
		$this->address = __($this->address, "TESTCODE");
		echo "<p><a href='{$this->address}'>{$this->address}</a></p>";
	} 
	public function exec()
	{
		$this->end();
		redirect_to(__($this->address,  $this->session));
		return false;
	}
}