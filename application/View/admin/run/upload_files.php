<?php

$js = '<script type="text/javascript">
// Dropzone.options.uploadFiles = {
//  paramName: "uploaded_files[]", // The name that will be used to transfer the file
//  maxFilesize: 50, // MB
//};
</script>';

Template::load('header', array('js' => $js));
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-lg-7 col-sm-8 col-md-9">
		
		<div class="transparent_well col-md-12" style="padding-bottom: 20px;">
		<form class="dropzone" enctype="multipart/form-data"  id="upload_files" name="upload_files" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name;?>/upload_files">

	<h2><i class="fa fa-file"></i> Upload files</h2>
	<ul class="fa-ul fa-ul-more-padding">
		<li><i class="fa-li fa fa-files-o"></i> Choose as many files as you'd like.</li>
		<li><i class="fa-li fa fa-link"></i> You will be able to browse them by name here, but you'll have to copy a randomly-generated link to embed them.</li> 
		<li><i class="fa-li fa fa-image"></i>	To embed images, use the following Markdown syntax: <code>![image description for blind users](image link)</code>, so in a concrete example <code>![Picture of a guitar](<?= asset_url("assets/tmp/admin/mkWpDTv5Um2ijGs1SJbH1uw9Bn2ctysD8N3tbkuwalOM.png")?>)</code>. You can embed images anywhere you can use Markdown (e.g. in item and choice labels, feedback, emails).</li>
		<li><i class="fa-li fa fa-cloud-upload"></i> We do not prevent users from sharing the links with others. 
			If your users see an image/video, there is no way of preventing them from re-sharing it, if you're not looking over their shoulders.<br>
			Users can always take a photo of the screen, even if you could prevent screenshots. Hence, we saw no point in generating single-use links for the images (so that users can't share the picture directly). Please be aware of this and don't use formr to show confidential information in an un-supervised setting. However, because the links are large random numbers, it's fairly safe to use formr to upload confidential information to be shown in the lab, the images cannot be discovered by people who don't have access to the study.</li>
	</ul>

		<div class="fallback">
		  	<div class="form-group">
		  		<label class="control-label" for="uploaded_files">
		  			<?php echo _("Files to upload:"); ?>
		  		</label>
		  		<div class="controls">
		  			<input required multiple type="file" accept="video/*,image/*,audio/*,text/*" name="uploaded_files[]" id="uploaded_files">
		  		</div>
		  	</div>
		  	<div class="form-group">
		  		<div class="controls">
					<button class="btn btn-default btn-success btn-lg" type="submit"><i class="fa-fw fa fa-upload fa-2x pull-left"></i> Upload all files</button>
		  		</div>
		  	</div>
		</div>
	  </form>
	</div>
	<?php
	if(!empty($files)):
		?>
	<div class="col-lg-11">
		<h3>Files uploaded in this run</h3>
		<table class='table table-striped'>
			<thead><tr>
		<?php
		foreach(current($files) AS $field => $value):
			if($field == 'id') continue;
			if($field == "original_file_name"):
				$field = 'File name';
			elseif($field == 'new_file_path'):
				$field = "Copy this link";
			endif;
		    echo "<th>{$field}</th>";
		endforeach;
		?>
			</tr></thead>
		<tbody>
			<?php
			// printing table rows
			foreach($files AS $row):
				unset($row['id']);
				$row['created'] = '<abbr title="'.$row['created'].'">'.timetostr(strtotime($row['created'])).'</abbr>';
				$row['modified'] = '<abbr title="'.$row['modified'].'">'.timetostr(strtotime($row['modified'])).'</abbr>';
				$row['new_file_path'] = '<a href="'. asset_url($row['new_file_path']) .'"><i class="fa fa-download"></i> Download/View</a>';
			    echo "<tr>";

			    foreach($row as $cell):
			        echo "<td>$cell</td>";
				endforeach;

			    echo "</tr>\n";
			endforeach;
			?>

		</tbody></table>
	</div>
<?php
	endif;
?>
</div>
</div>

<?php Template::load('footer');