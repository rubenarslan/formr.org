<?php
	$js = '<script src="'.asset_url('assets/'. (DEBUG?'js':'minified'). '/run_users.js').'"></script>';
    Template::load('header', array('js' => $js));
    Template::load('acp_nav');
?>
<div class="row">
	<div class="col-md-12">
		<h2 class="drop_shadow">log of user activity in this run</h2>
		<p class="lead">Here you can see users' history of participation, i.e. when they got to certain point in a study, how long they staid at each station and so forth. Earliest participants come first.</p>
	<div class="row col-md-12">
		<form action="<?=WEBROOT.'admin/run/'.$run->name.'/user_detail'?>" method="get" accept-charset="utf-8">
		
		<div class="row">
		  <div class="col-lg-3">
		    <div class="input-group">
			  <span class="input-group-addon"><i class="fa fa-user"></i></span>
			  <input type="search" placeholder="Session key" name="session" class="form-control" value="<?=isset($_GET['session'])?h($_GET['session']):'';?>">
		
		    </div><!-- /input-group -->
		  </div><!-- /.col-lg-6 -->
		  <div class="col-lg-3">
		    <div class="input-group">
			  <span class="input-group-addon" title="This refers to the user's current position!"><i class="fa fa-flag-checkered"></i></span>
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
	<?php if(!empty($users)): ?>

	<table class='table'>
		<thead><tr>
	<?php
	foreach(current($users) AS $field => $value):
		if($field === 'created' OR $field === 'ended' OR $field === 'expired')
			continue;
	    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		$last_ended = $last_user = $continued = $user_class = '';
		
		// printing table rows
		foreach($users AS $row):
			if($row['Session']!==$last_user): // next user
				$user_class = ($user_class=='') ? 'alternate' : '';
				$last_user = $row['Session'];
			elseif(round((strtotime($row['created']) - $last_ended)/30)==0): // same user
				$continued = ' immediately_continued';
			endif;
			$last_ended = strtotime($row['created']);
			
			unset($row['created']);
			unset($row['ended']);
			unset($row['expired']);
			
			
			echo '<tr class="'.$user_class.$continued.'">';
			$continued = '';
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
	$pagination->render("admin/run/".$run->name."/user_detail".$append);
	
	
	endif;
	?>
	</div>
</div>
		
<?php Template::load('footer');