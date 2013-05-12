<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";

class End extends RunUnit {
	public function exec()
	{
		return
			'
			
		<div class="row-fluid">
		    <div id="span12">
		        <h1>End</h1>
		    </div>
		</div>
		<div class="row-fluid">
			<div class="span12">
			<p>You reached the end. Thanks!</p>

			</div> <!-- end of span10 div -->
		</div> <!-- end of row-fluid div -->';
	}
	protected function end()
	{
		// IT IS A MOCK UNIT WHICH CANT END IN THE DB
	}
}