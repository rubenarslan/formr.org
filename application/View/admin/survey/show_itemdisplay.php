<?php
Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-md-12">
		<h2>
			Detailed result times <small style="color: #fff"> <?= (int) $resultCount['finished'] ?> complete, <?= (int) $resultCount['begun'] ?> begun </small>
		</h2>

		<h4><a href="<?= WEBROOT ?>admin/survey/<?= $study->name ?>/show_results"><i class="fa fa-tasks fa-fw"></i> Go back to main results.</a></h4>
	</div>
</div>
<div class="row">

	<div class="list-group col-md-7">
		<h5>Export as</h5>
		<div class="list-group-item">
			<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=csv')?>"><i class="fa fa-floppy-o fa-fw"></i> CSV</a></h4>
			<p class="list-group-item-text">good for big files, problematic to import into German Excel (comma-separated)</p>
		</div>

		<div class="list-group-item">
			<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=csv_german')?>"><i class="fa fa-floppy-o fa-fw"></i> German CSV</a></h4>
			<p class="list-group-item-text">good for German Excel (semicolon-separated)</p>
		</div>

		<div class="list-group-item">
			<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=tsv')?>"><i class="fa fa-floppy-o fa-fw"></i> TSV</a></h4>
			<p class="list-group-item-text">tab-separated, human readable as plaintext</p>
		</div>

		<div class="list-group-item">
			<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=xls')?>"><i class="fa fa-floppy-o fa-fw"></i> XLS</a></h4>
			<p class="list-group-item-text">old excel format, won't work with more than 16384 rows or 256 columns</p>
		</div>

		<div class="list-group-item">
			<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=xlsx')?>"><i class="fa fa-floppy-o fa-fw"></i> XLSX</a></h4>
			<p class="list-group-item-text">new excel format, higher limits</p>
		</div>

		<div class="list-group-item">
			<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=json')?>"><i class="fa fa-floppy-o fa-fw"></i> JSON</a></h4>
			<p class="list-group-item-text">not particularly human-readable, but machines love it. This is probably the fastest way to get your data into R, just use <pre><code class="r hljs">data = as.data.frame(jsonlite::fromJSON("/path/to/exported_file.json"))</code></pre></p>
		</div>
	</div>
</div>
<?php if (count($results) > 0): ?>
	<div class="col-md-12">

		<table class='table table-striped'>
			<thead>
				<tr>
					<?php
						foreach (current($results) as $field => $value):
							if (in_array($field, array("shown_relative", "answered_relative", "item_id", "display_order", "hidden"))) {
								continue;
							}
							echo "<th>{$field}</th>";
						endforeach;
					?>
				</tr>
			</thead>
			<tbody>
			<?php
				// printing table rows
				$last_sess = null;
				foreach ($results as $row):
					$row['created'] = '<abbr title="' . $row['created'] . '">' . timetostr(strtotime($row['created'])) . '</abbr>';
					$row['shown'] = '<abbr title="' . $row['shown'] . ' relative: ' . $row['shown_relative'] . '">' . timetostr(strtotime($row['shown'])) . '</abbr> ';

					if ($row['hidden'] === 1) {
						$row['shown'] .= "<small><em>not shown</em></small>";
					} elseif ($row['hidden'] === null) {
						$row['shown'] .= $row['shown'] . "<small><em>not yet</em></small>";
					}

					$row['saved'] = '<abbr title="' . $row['saved'] . '">' . timetostr(strtotime($row['saved'])) . '</abbr>';
					$row['answered'] = '<abbr title="' . $row['answered'] . ' relative: ' . $row['answered_relative'] . '">' . timetostr(strtotime($row['answered'])) . '</abbr>';
					unset($row['shown_relative'], $row['answered_relative'], $row['item_id'], $row['display_order'], $row['hidden']);

					// open row
					echo $last_sess == $row['unit_session_id'] ? '<tr>' : '<tr class="thick_border_top">';
					$last_sess = $row['unit_session_id'];

					// print cells of row
					// $row is array... foreach( .. ) puts every element of $row to $cell variable
					foreach ($row as $cell):
						echo "<td>$cell</td>";
					endforeach;

					// close row
					echo "</tr>\n";
				endforeach;
			?>
			</tbody>
		</table>

		<div class="text-center">
			<?php $pagination->render("admin/survey/{$study_name}/show_itemdisplay"); ?>
		</div>
	</div>

<?php endif; ?>

<?php
Template::load('footer');
