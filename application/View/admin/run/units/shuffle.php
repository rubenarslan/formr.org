<?php echo $prepend ?>

<h5> 
	Randomly assign to one of 
	<input style="width:100px" class="form-control" type="number" placeholder="2" name="groups" value="<?= h($groups) ?>"> 
	groups counting from one.
</h5>

<div class="col-md-10 no-padding">
	You can later read the assigned group using <code>shuffle$group</code>. <br />
	You can then for example use a SkipForward to send one group to a different arm/path in the run or use a show-if in a survey to show certain items/stimuli to one group only.
</div>
<div class="clearfix"></div><br />

<p class="btn-group">
	<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Shuffle">Save</a>
	<a class="btn btn-default unit_test" href="ajax_test_unit?type=Shuffle">Test</a>
</p>