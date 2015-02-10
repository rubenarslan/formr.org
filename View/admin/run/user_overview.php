<?php
$js = '<script src="'.WEBROOT.'assets/run_users.js"></script>';
Template::load('header', array('js' => $js));
Template::load('acp_nav');
?>

<div class="row">
	<div class="col-md-12">
		<h1 class="drop_shadow">user overview <small><?=$pagination->maximum?> users</small></h1>
		<p class="lead">Here you can see users' progress (on which station they currently are).
			If you're not happy with their progress, you can send manual reminders, <a href="<?=WEBROOT.'admin/run/'.$run->name.'/edit_reminder'?>">customisable here</a>. <br>You can also shove them to a different position in a run if they veer off-track. </p>
			<p>Participants who have been stuck at the same survey, external link or email for 2 days or more are highlighted in yellow at the top. Being stuck at an email module usually means that the user somehow ended up there without a valid email address, so that the email cannot be sent. Being stuck at a survey or external link usually means that the user interrupted the survey/external part before completion, you probably want to remind them manually (if you have the means to do so).</p>
			<div class="row col-md-12">
				<form action="<?=WEBROOT.'admin/run/'.$run->name.'/user_overview'?>" method="get" accept-charset="utf-8">
				
				<div class="row">
				  <div class="col-lg-3">
				    <div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-user"></i></span>
					  <input type="search" placeholder="Session key" name="session" class="form-control" value="<?=isset($_GET['session'])?h($_GET['session']):'';?>">
				
				    </div><!-- /input-group -->
				  </div><!-- /.col-lg-6 -->
				  <div class="col-lg-3">
				    <div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-flag-checkered"></i></span>
						<input type="number" placeholder="Position" name="position" class="form-control round_right" value="<?=isset($_GET['position'])?h($_GET['position']):'';?>">
						
				    </div><!-- /input-group -->
				  </div><!-- /.col-lg-6 -->
				  
				  <div style="width:65px; float:left">
					<select class="form-control" name="position_lt">
						<option value="=" <?=($position_lt=='=')?'selected':'';?>>=</option>
						<option value="&lt;" <?=($position_lt=='<')?'selected':'';?>>&lt;</option>
						<option value="&gt;" <?=($position_lt=='>')?'selected':'';?>>&gt;</option>
					</select>
					  
				  </div>
				  
				  
				  <div class="col-lg-1">
				    <div class="input-group">
						<input type="submit" value="Search" class="btn">
						
				    </div><!-- /input-group -->
				  </div><!-- /.col-lg-6 -->
				</div><!-- /.row -->
				
				</form>
			</div>
	<?php
	
	if(!empty($users)):
		?>
		<table class='table table-striped'>
			<thead><tr>
		<?php
		foreach(current($users) AS $field => $value):
			if($field != 'hang')
			    echo "<th>{$field}</th>";
		endforeach;
		?>
			</tr></thead>
		<tbody>
			<?php
			// printing table rows
			foreach($users AS $row):
				if($row['hang'])
					echo '<tr class="warning">';
				else
				    echo "<tr>";
				unset($row['hang']);

			    // $row is array... foreach( .. ) puts every element
			    // of $row to $cell variable
			    foreach($row as $cell):
			        echo "<td>$cell</td>";
				endforeach;

			    echo "</tr>\n";
			endforeach;
			?>
		</tbody></table>
	<?php
	if(!empty($querystring)) $append = "?".http_build_query($querystring)."&";
	else $append = '';
	$pagination->render("admin/run/".$run->name."/user_overview".$append);
	
	endif;
	?>
	</div>
</div>
		

<?php Template::load('footer');