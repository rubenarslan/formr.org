<?php

if( !empty($_FILES) ) {
	if(isset($_FILES['uploaded_files']))
	{
		if($run->uploadFiles($_FILES['uploaded_files']))
		{
			alert('<strong>Success.</strong> The files were uploaded.','alert-success');
			if(!empty($run->messages)) alert('<strong>These files were overwritten:</strong> '.implode($run->messages),'alert-info');
			redirect_to("admin/run/".$run->name."/upload_files");
		}
		else
		{
			alert('<strong>Sorry, files could not be uploaded.</strong> '.implode($run->errors),'alert-danger');
		}
	}
}
$files = $run->getUploadedFiles();
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
	<div class="col-lg-6 col-md-8 col-sm-9 col-lg-offset-1 well">
		<form class="dropzone" enctype="multipart/form-data"  id="upload_files" name="upload_files" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name;?>/upload_files">

	<h2><i class="fa fa-file"></i> Upload files</h2>
	<ul class="fa-ul fa-ul-more-padding">
		<li><i class="fa-li fa fa-files-o"></i> Choose as many files as you'd like.</li>
		<li><i class="fa-li fa fa-link"></i> You will be able to browse them by name here, but you'll have to copy a randomly-generated link to embed them.</li>
		<li><i class="fa-li fa fa-cloud-upload"></i> We do not prevent users from sharing the images with others. If your users see an image/video, there is no way of preventing them from re-sharing it, if you're not looking over their shoulders. They can always take a picture of the screen with a smartphone, take a screenshot, etc. Because almost everyone can do this nowadays, we saw no point in generating single-use links for the images (so that users can't share the picture directly). Please be aware of this and don't use formr to show confidential information in an un-supervised setting (online). However, because the links are large random numbers, it is fairly safe to use formr to upload confidential information to be shown in the lab, the images cannot easily be discovered by people who don't have access to the study including them.</li>
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
			if($field != 'hang')
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
				$row['new_file_path'] = '<a href="'. WEBROOT.$row['new_file_path'].'"><i class="fa fa-download"></i> Download/View</a>';
				
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