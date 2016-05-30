<?php
Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-lg-10 col-sm-12 col-md-10">
		
		<div class="transparent_well col-md-12" style="padding-bottom: 20px;"> 
		<div class="row">
			<div class="col-md-12">
				<h2 class="drop_shadow">Results <small>
						<?=(int)$resultCount['finished']?> complete,
						<?=(int)$resultCount['begun']?> begun,
						<?=(int)$resultCount['testers']?> testers
				</small></h2>
				<h4><a href="<?php echo admin_study_url($study->name, 'show_itemdisplay'); ?>"><i class="fa fa-table fa-fw"></i> Detailed table of item display and answer times.</a></h4>
			</div>
		</div>
		<div class="row">
			<div class="col-md-12">
			<div class="list-group col-md-11">
				<h5>Export as</h5>
			  <div class="list-group-item">
			    <h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=csv'); ?>"><i class="fa fa-floppy-o fa-fw"></i> CSV</a></h4>
			    <p class="list-group-item-text">good for big files, problematic to import into German Excel (comma-separated)</p>
			  </div>

			  <div class="list-group-item">
			    <h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=csv_german'); ?>"><i class="fa fa-floppy-o fa-fw"></i> German CSV</a></h4>
			    <p class="list-group-item-text">good for German Excel (semicolon-separated)</p>
			  </div>

			  <div class="list-group-item">
			    <h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=tsv'); ?>"><i class="fa fa-floppy-o fa-fw"></i> TSV</a></h4>
			    <p class="list-group-item-text">tab-separated, human readable as plaintext</p>
			  </div>

			  <div class="list-group-item">
			    <h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=xls'); ?>"><i class="fa fa-floppy-o fa-fw"></i> XLS</a></h4>
			    <p class="list-group-item-text">old excel format, won't work with more than 16384 rows or 256 columns</p>
			  </div>

			  <div class="list-group-item">
			    <h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=xlsx'); ?>"><i class="fa fa-floppy-o fa-fw"></i> XLSX</a></h4>
			    <p class="list-group-item-text">new excel format, higher limits</p>
			  </div>

			  <div class="list-group-item">
			    <h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=json'); ?>"><i class="fa fa-floppy-o fa-fw"></i> JSON</a></h4>
			    <p class="list-group-item-text">not particularly human-readable, but machines love it. This is probably the fastest way to get your data into R, just use <pre><code class="r hljs">data = as.data.frame(jsonlite::fromJSON("/path/to/exported_file.json"))</code></pre></p>
			  </div>
			</div>
			</div>
		</div>

<?php if($results): ?>
	<h3>Results for '<?= $study_name ?>'</h3>
	<div class="row col-md-12">
		<form action="<?= admin_study_url($study_name, 'show_results')?>" method="get" accept-charset="utf-8">

			<div class="row">
				<div class="col-lg-10">
					<div class="input-group">
						<span class="input-group-addon">Search by session <i class="fa fa-user"></i></span>
						<input type="search" placeholder="Session key" name="session" class="form-control user-success" value="<?= $session ?>">
					</div><!-- /input-group -->
				</div>

				<div class="col-lg-1">
					<div class="input-group">
						<input type="submit" value="Search" class="btn">
					</div><!-- /input-group -->
				</div>
			</div><!-- /.row -->
		</form>
	</div>
	<div class="clearfix"></div>
	<hr />
			
	<div class="row col-md-12">

		<table class='table table-striped'>
			<?php
				$print_header = true;
				foreach($results as $row) {
					unset($row['study_id']);
					if ($print_header) {
						echo '<thead><tr>';
						foreach ($row as $field => $value) {
							echo '<td>' . $field . '</td>';
						}
						echo '</tr></thead>';
						echo '<tbody>';
						$print_header = false;
					}

					if(isset($row['created'])):
						$row['created'] = '<abbr title="'.$row['created'].'">'.timetostr(strtotime($row['created'])).'</abbr>';
						$row['ended'] = '<abbr title="'.$row['ended'].'">'.timetostr(strtotime($row['ended'])).'</abbr>';
						$row['modified'] = '<abbr title="'.$row['modified'].'">'.timetostr(strtotime($row['modified'])).'</abbr>';
						$row['expired'] = '<abbr title="'.$row['expired'].'">'.timetostr(strtotime($row['expired'])).'</abbr>';
					endif;
					echo '<tr>';
						foreach($row as $cell) {
							echo '<td>' . $cell . '</td>';
						}
					echo '</tr>';
				}
				echo '</tbody>';
			?>
		</table>

		<div class="text-center">
			<?php $pagination->render("admin/survey/{$study_name}/show_results"); ?>
		</div>
	</div>

	
<?php endif; ?>

</div>
</div>

<?php Template::load('footer');
