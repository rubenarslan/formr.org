<?php
require_once INCLUDE_ROOT."Model/RunUnit.php";

class End extends RunUnit {
	public function exec()
	{
		return
			'
			
		<div class="row">
		    <div id="col-md-12">
		        <h1>End</h1>
		    </div>
		</div>
		<div class="row">
			<div class="col-md-12">
			<p>You reached the end. Thanks!</p>

			</div> <!-- end of col-md-12 div -->
		</div> <!-- end of row div -->';
	}
	protected function end()
	{
		// IT IS A MOCK UNIT WHICH CANT END IN THE DB
	}
}