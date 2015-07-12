<?php Template::load('header_nav'); ?>

<div class="row">
	<div class="col-lg-8 col-lg-offset-1 col-sm-9 col-sm-offset-1 col-xs-12">
		<div class="jumbotron formr-jumbo">
			<h1>
				<span class="formr-brand">formr <small>survey framework</small></span>
			</h1>
			<p>
					chain simple forms &amp; surveys into long runs, use the power of <abbr title="A statistics environment. Nice plots abound!">R</abbr> to generate pretty feedback and complex designs
			</p>
			<p>
				<a class="btn btn-primary btn-lg" role="button" href="<?=WEBROOT?>public/register">Sign up (it's free!)</a>
			</p>
		</div>
		<div class="">
			<ul class="nav nav-tabs">
			  <li><a href="#features" data-toggle="tab">What can formr do?</a></li>
			  <li class="active"><a href="#options" data-toggle="tab">What can I do here?</a></li>
			</ul>
			<div class="tab-content">
				<div class="well tab-pane fade" id="features">
					<?php Template::load('features'); ?>
				</div>
				
				<div class="well tab-pane fade in active" id="options">
					<ul class="fa-ul lead">
						<li><i class="fa fa-li fa-plug"></i> 
							You can <a href="<?=WEBROOT?>public/register">register</a> for free and use formr to conduct your own studies. 
						</li>
						<li>
							<i class="fa fa-li fa-pencil-square"></i> You can <a href="<?=WEBROOT?>public/studies">take some of the published studies for a test run</a>.
						</li>
						<li>
							<i class="fa fa-li fa-file"></i> 
							You can read the <a href="<?=WEBROOT?>public/documentation" title="hopefully you'll get some idea of what formr can do for you">super exciting docs</a>.
						</li>
						<li>
							<i class="fa fa-li fa-life-ring"></i> 
							You can follow one of <a href="https://github.com/rubenarslan/formr.org/wiki" title="illustrated step by step tutorials on some of the most common tasks">our HowTos</a>.
						</li>
						<li><i class="fa fa-li fa-github-alt"></i> 
							You can <a href="https://github.com/rubenarslan/formr" title="If you don't know what a Github repository is yet, this is probably not the option for you, but for your local techie type. ">check out the Github repo</a>. It's open source and free-to-use.
						</li>
						<li><i class="fa fa-li fa-google"></i> 
							You can <a href="https://groups.google.com/forum/#!forum/formr" title="you can ask and answer other admin users' questions here">join or browse our community help mailing list</a>.
						</li>
					</ul>
				</div>
			</div>
			
		</div>
	</div>
	
</div>

<?php Template::load('footer'); ?>
