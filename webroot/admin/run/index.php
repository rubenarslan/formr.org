<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";

$js = '<script src="'.WEBROOT.'assets/run.js"></script>';

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="col-md-11">
<form class="form-horizontal" enctype="multipart/form-data"  id="edit_run" name="edit_run" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name ;?>/" data-units='<?php
	echo json_encode($run->getAllUnitIds());	
	?>'>

<div class="col-md-5 run_dialog">
	
	<h2 class="row" id="run_dialog_heading">
		<?php echo __("%s <small>run</small>" , $run->name); ?>
		<input type="hidden" value="<?=$run->name?>" name="old_run_name" id="run_name">
	</h2>
	<h2>
		<span class="btn-group">
			<a href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_run_cron_toggle" class="btn btn-default run-toggle hastooltip <?=($run->cron_active)?'btn-checked':''?>" title="Turn the run on. If this is not checked, you won't be able to receive email reminders etc. Only turn off for testing.">
				<i class="fa fa-play"></i> Play
			</a>
			<a href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_run_public_toggle" class="btn btn-default run-toggle hastooltip <?=($run->public)?'btn-checked':''?>" title="Make publicly visible and accessible on the front page">
				<i class="fa fa-volume-up"></i> Public
			</a>
			<a href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_run_service_message_toggle" class="btn btn-default run-toggle hastooltip <?=($run->being_serviced)?'btn-checked':''?>" title="Show a service message while you fix the already public run">
				<i class="fa fa-eject"></i> Interrupt
			</a>
		</span>
		
	</h2>
	<h4>
		Api-Secret: <small><?= $run->getApiSecret($user); ?></small>
	</h4>
	<p>&nbsp;</p>
	<div class="row" id="run_dialog_choices">
		<div class="col-md-2">
			<a class="reorder_units btn btn-lg hastooltip" title="Save new positions" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_reorder">
				<i class="fa fa-exchange fa-rotate-90 fa-larger"></i>
			</a>
		</div>
	  	<div class="form-group span7">
			<div class="btn-group">
				<a class="add_survey add_run_unit btn btn-lg hastooltip" title="Add survey" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=Survey">
					<i class="fa fa-question-circle fa-2x"></i>
				</a>
				<a class="add_external add_run_unit  btn btn-lg hastooltip" title="Add external link" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=External">
					<i class="fa fa-external-link-square fa-2x"></i>
				</a>
				<a class="add_email add_run_unit btn btn-lg hastooltip" title="Add email" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=Email">
					<i class="fa fa-envelope fa-2x"></i>
				</a>
				<a class="add_pause add_run_unit btn btn-lg hastooltip" title="Add time-branch" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=TimeBranch">
					<i class="fa fa-backward fa-2x"></i>
				</a>
				<a class="add_pause add_run_unit btn btn-lg hastooltip" title="Add pause" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=Pause">
					<i class="fa fa-pause fa-2x"></i>
				</a>
				<a class="add_pause add_run_unit btn btn-lg hastooltip" title="Add time-branch" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=TimeBranch">
					<i class="fa fa-forward fa-2x"></i>
				</a>
				<a class="add_page add_run_unit btn btn-lg hastooltip" title="Add end page" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=Page">
					<i class="fa fa-stop fa-2x"></i>
				</a>
			</div>
		</div>
  	</div>
</div>


<div class="well col-md-5 explanations">
	<h5>Explanations for the modules</h5>
	<!-- Nav tabs -->
	<ul class="nav nav-tabs tiny-tabs" style="font-size:10px">
	  <li><a href="#survey" data-toggle="tab">Survey</a></li>
	  <li><a href="#external" data-toggle="tab">External links</a></li>
	  <li><a href="#email" data-toggle="tab">Email</a></li>
	  <li><a href="#pause" data-toggle="tab">Pause</a></li>
	  <li><a href="#branch" data-toggle="tab">Branch</a></li>
	  <li><a href="#timebranch" data-toggle="tab">TimeBranch</a></li>
	  <li><a href="#endpage" data-toggle="tab">End page</a></li>
	  <li><a href="#knitr" data-toggle="tab">Knitr &amp; Markdown</a></li>
	</ul>

	<!-- Tab panes -->
	<div class="tab-content col-md-11">
		  <div class="tab-pane fade" id="branch">
			 <p>
				 <i class="fa fa-code-fork fa-huge fa-fw fa-flip-vertical pull-right"></i>
				 Branches are components that allow you to evaluate R conditions on a user's data. Depending on whether the condition is true or false, you can jump to different positions in the run - these can be later or earlier in the run. If the condition can lead to an earlier position, you create loops, for e.g. diaries, training interventions and so on. If the condition can lead to an end page, you're using the Branch akin to a filter mechanism - only some participants get past the barrier.
			 </p>
		  </div>
		  <div class="tab-pane fade" id="survey"><p>
			 <i class="fa fa-question-circle fa-huge fa-fw pull-right"></i>
			  
			  Surveys are series of questions that are created using simple spreadsheets/<strong>item tables</strong> (eg. Excel).</p>

		<p>You can add the same survey to a run several times or even loop them using branches.<br>
		You can also use the same survey across different runs. For example this would allow you to ask respondents for their demographic information only once. You'd do this by using a Branch with the condition <code>nrow(demographics) &gt; 0</code> and skipping over the demographics survey, if true.</p>

		<p>Survey names may only contain the characters from a to z (both lower- and uppercase), 0 to 9 and the underscore <code>_</code> and need to start with a letter.</p>
			</div>
		  <div class="tab-pane fade" id="endpage">
			  <p>
	 			 <i class="fa fa-stop fa-huge fa-fw pull-right"></i>
				  
				  You will always need at least one. These are end points in your run, where you can give short or complex feedback, ranging from "You're not eligible to participate." to "This is the scatter plot of your mood and your alcohol consumption across the last two weeks". If you combine these end points with branches, you may have several in your run. 
				  See the <a href="#knitr" data-toggle="tab">Knitr &amp; Markdown</a> section to find out how to generate personalised feedback, including plots.
			</p>
		</div>
		  <div class="tab-pane fade" id="external">
			  <p>
	 			 <i class="fa fa-external-link-square fa-huge fa-fw pull-right"></i>
				  
				  These are simple external links - use them to send users to other, specialised data collection modules, such as a social network generator. If you insert the placeholder <code>%s</code>, it will be replaced by the users run_session code, allowing you to link data later. You can either choose to "finish" this component <em>before</em> the user is redirected (the simple way) or enable your external module to call our <abbr class="initialism" title="Application programming interface.">API</abbr> to close it only, when the external component is finished (the proper way).
			  </p>
		  </div>
		  <div class="tab-pane fade" id="email">
 			 <i class="fa fa-envelope fa-huge fa-fw pull-right"></i>
			  
			  <p>Using an SMTP account (most email addresses come with one) that you can <a href="<?= WEBROOT ?>admin/mail/">set up in the mail section</a>, you can send email to your participants (or yourself). Using the tag <code>{{login_link}}</code>, you can send users a personalised link to the run. You can also use <code>{{login_code}}</code> to use the session code to create custom links, e.g. for inviting peers to rate this person (informants). See the <a href="#knitr" data-toggle="tab">Knitr &amp; Markdown</a> section to find out how to generate personalised email, which contain feedback, including plots.
			  </p>
		  </div>
		  <div class="tab-pane fade" id="pause">
			  <p>
	 			 <i class="fa fa-pause fa-huge fa-fw pull-right"></i>
				  
				  This simple component allows you to delay the continuation of the run until a certain date, time of day or to wait relative to a date that a user specified (such as her graduation date or the last time he cut his nails). See the <a href="#knitr" data-toggle="tab">Knitr &amp; Markdown</a> section to find out how to personalise the text shown while waiting.
			  </p>
		  </div>
		  <div class="tab-pane fade" id="timebranch">
			  <p>
	 			 <i class="fa fa-fast-forward fa-huge fa-fw pull-right"></i>
				  
				  These components are the bastard children of a Pause + Branch: If the user accesses the run within the specified time frame (like a Pause), the run jumps to one position in the run (like a Branch). If the user doesn't, the run progresses to a different position in the run (e.g. to a reminder email). This component is useful, if you need to set up a period during which a survey is accessible or if you want to automatically send reminders after some time elapsed. <br>
		See the <a href="#knitr" data-toggle="tab">Knitr &amp; Markdown</a> section to find out how to customise the text shown while waiting.
			</p>
		</div>
	  <div class="tab-pane fade in active" id="knitr">
			  <h5>Markdown</h5>
			  <p>
 	 			 <i class="fa fa-bar-chart-o fa-huge fa-fw pull-right"></i>
				  
			You can format your text in a simple way using <a href="http://daringfireball.net/projects/markdown/syntax" title="Go to this link for a more exhaustive guide">Markdown</a>. The philosophy is that you should simply write like you would in a plain-text email and Markdown turns it nice. Specifically:<br>
			 <pre>* list item 1
* list item 2</pre> will turn into a nice list. 
			 <code>*<em>italics</em>* and __<strong>bold</strong>__</code> are also easy to do, as are 
			 <code>[<a href="http://yihui.name/knitr/">links</a>](http://yihui.name/knitr/)</code>.
 		</p>
			  <h5>Knitr</h5>
   		<p>
			If you want to customise the text or generate custom feedback, including plots, you can use <a href="http://yihui.name/knitr/">Knitr</a>. You can freely mix Markdown and chunks of Knitr. Some examples:
		</p>
		<ul class="list-unstyled">
			<li>
				<code>`r date()`</code> shows today's date.<br>
			</li>
			<li>
				<code>`r demographics$name`</code> shows the variable "name" from the survey "demographics".<br>
			</li>
			<li>You can also plot someone's extraversion on the standard normal distribution.
				<pre>```{r}
big5$extraversion = (rowSums(big5$extraversion1, big5$extraversion2, big5$extraversion3) - 3 ) / 2
library(ggplot2)
qplot(rnorm(1000),geom="density") + geom_vline(xintercept=big5$extraversion)
```</pre><br>
			</li>
   		</p>
			 
			 
	</div>
	</div>
</div>

<div class="clearfix"></div>

  </form>
</div>
  <?php
  require_once INCLUDE_ROOT . "View/footer.php";
