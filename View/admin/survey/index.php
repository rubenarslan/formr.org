<?php
	Template::load('header');
	Template::load('acp_nav');
?>

<div class="row">
	<div class="col-lg-8 col-md-9 col-sm-10 ">
		
		<div class="transparent_well col-md-12" style="padding-bottom: 20px;">
		<h2 class="drop_shadow"><?=_('Survey settings'); ?></h2>
		<p>
			These are some settings for advanced users. You'll mostly need the "Import items" and the "Export results" options to the left.
		</p>
	
		<form method="POST" action="<?php echo admin_study_url($study->name); ?>">
			<table class="table table-striped editstudies">
				<thead>
					<tr>
						<th>Option</th>
						<th style='width:200px'>Value</th>
					</tr>
				</thead>
				<tbody>
		<?php
			foreach( $study->settings as $key => $value ):
				echo "<tr>";
				$help = '';
				if($key == "expire_after") $help = ' <i class="fa fa-info-circle hastooltip" title="Should the survey expire after a certain number of minutes of inactivity? Specify 0 if not. If a user is never active for x minutes or if the last activity is more than x minutes ago, the run will automatically move on."></i>';
				elseif($key == "enable_instant_validation") $help = ' <i class="fa fa-info-circle hastooltip" title="Instant validation means that users will be alerted if their survey input is invalid right after entering their information. Otherwise, validation messages will only be shown once the user tries to submit."></i>';
				elseif($key == "maximum_number_displayed") $help = ' <i class="fa fa-info-circle hastooltip" title="Do you want a certain number of items on each page? We prefer speciyfing pages manually (by adding submit buttons items when we want a pagebreaks) because this gives us greater manual control."></i>';
				elseif($key == "add_percentage_points") $help = ' <i class="fa fa-info-circle hastooltip" title="Sometimes, in complex studies where several surveys are linked, you\'ll want to let the progress bar that the user sees only vary in a given range (e.g. first survey 0-40, second survey 40-100). This is the lower limit for this survey."></i>';
				elseif($key == "displayed_percentage_maximum") $help = ' <i class="fa fa-info-circle hastooltip" title="Sometimes, in complex studies where several surveys are linked, you\'ll want to let the progress bar that the user sees only vary in a given range (e.g. first survey 0-40, second survey 40-100). This is the upper limit for this survey."></i>';
				echo "<td>".h( str_replace("_"," ",$key)). $help . "</td>";

				echo "<td><input class=\"form-control\" type=\"text\" size=\"50\" name=\"".h($key)."\" value=\"".h($value)."\"/></td>";
				echo "</tr>";
			endforeach;
		?>
				</tbody>
			</table>
			<div class="row col-md-4">
				<input type="submit" value="Save settings" class="btn">
			</div>
		</form>
		</div>
	</div>
</div>
<?php Template::load('footer');
