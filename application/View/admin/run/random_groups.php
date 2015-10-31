<?php
Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-md-12">
		<h2>randomisation results</h2>

<?php if(!empty($users)) { ?>
<div class="row">
	<div class="list-group col-md-7">
		<h5>Export as</h5>
	  <div class="list-group-item">
	    <h4 class="list-group-item-heading"><a href="<?=WEBROOT?>admin/run/<?=$run->name?>/random_groups_export?format=csv"><i class="fa fa-floppy-o fa-fw"></i> CSV</a></h4>
	    <p class="list-group-item-text">good for big files, problematic to import into German Excel (comma-separated)</p>
	  </div>

	  <div class="list-group-item">
	    <h4 class="list-group-item-heading"><a href="<?=WEBROOT?>admin/run/<?=$run->name?>/random_groups_export?format=csv_german"><i class="fa fa-floppy-o fa-fw"></i> German CSV</a></h4>
	    <p class="list-group-item-text">good for German Excel (semicolon-separated)</p>
	  </div>
  
	  <div class="list-group-item">
	    <h4 class="list-group-item-heading"><a href="<?=WEBROOT?>admin/run/<?=$run->name?>/random_groups_export?format=tsv"><i class="fa fa-floppy-o fa-fw"></i> TSV</a></h4>
	    <p class="list-group-item-text">tab-separated, human readable as plaintext</p>
	  </div>
  
	  <div class="list-group-item">
	    <h4 class="list-group-item-heading"><a href="<?=WEBROOT?>admin/run/<?=$run->name?>/random_groups_export?format=xls"><i class="fa fa-floppy-o fa-fw"></i> XLS</a></h4>
	    <p class="list-group-item-text">old excel format, won't work with more than 16384 rows or 256 columns</p>
	  </div>
  
	  <div class="list-group-item">
	    <h4 class="list-group-item-heading"><a href="<?=WEBROOT?>admin/run/<?=$run->name?>/random_groups_export?format=xlsx"><i class="fa fa-floppy-o fa-fw"></i> XLSX</a></h4>
	    <p class="list-group-item-text">new excel format, higher limits</p>
	  </div>
  
	  <div class="list-group-item">
	    <h4 class="list-group-item-heading"><a href="<?=WEBROOT?>admin/run/<?=$run->name?>/random_groups_export?format=json"><i class="fa fa-floppy-o fa-fw"></i> JSON</a></h4>
	    <p class="list-group-item-text">not particularly human-readable, but machines love it. This is probably the fastest way to get your data into R, just use <pre><code class="r hljs">data = as.data.frame(jsonlite::fromJSON( "/path/to/exported_file.json" ))</code></pre></p>
	  </div>
	</div>
</div>
	<table class='table'>
		<thead><tr>
	<?php
	foreach(current($users) AS $field => $value):
	    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		$last_user = '';
		$tr_class = '';
		
		// printing table rows
		foreach($users AS $row):
			if($row['session']!==$last_user):
				$tr_class = ($tr_class=='') ? 'alternate' : '';
				$last_user = $row['session'];
			endif;
			echo '<tr class="'.$tr_class.'">';

		    // $row is array... foreach( .. ) puts every element
		    // of $row to $cell variable
		    foreach($row as $cell):
		        echo "<td>$cell</td>";
			endforeach;

		    echo "</tr>\n";
		endforeach;
	}
		?>

	</tbody></table>
	</div>
</div>

<?php Template::load('footer');