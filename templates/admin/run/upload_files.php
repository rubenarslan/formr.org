<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1><?php echo $run->name; ?> <small><a target="_blank" title="The official link to your run, which you can share with prospective users." href="<?php echo run_url($run->name, null, null) ?>"><?php echo run_url($run->name, null, null) ?></a></small> </h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::loadChild('admin/run/menu'); ?>
            </div>
            <div class="col-md-10">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Upload Files </h3>
                    </div>

                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <div class="callout callout-primary">
                            <ul class="fa-ul fa-ul-more-padding">
                                <li><i class="fa-li fa fa-files-o"></i> Choose as many files as you'd like.</li>
                                <li><i class="fa-li fa fa-lock"></i> You have to have the necessary rights to upload the file here. Do not upload pictures of people without their consent, respect the license conditions of software etc.</li>
                                <li><i class="fa-li fa fa-link"></i> You will be able to browse them by name here, but you'll have to copy a randomly-generated link to embed them.</li> 
                                <li><i class="fa-li fa fa-image"></i>	To embed images, use the following Markdown syntax: <code>![image description for blind users](image link)</code>, so in a concrete example <code>![Picture of a guitar](https://formr.org/assets/tmp/admin/mkWpDTv5Um2ijGs1SJbH1uw9Bn2ctysD8N3tbkuwalOM.png)</code>. You can embed images anywhere you can use Markdown (e.g. in item and choice labels, feedback, emails).</li>
                                <li><i class="fa-li fa fa-cloud-upload"></i> We do not prevent users from sharing the links with others. 
                                    If your users see an image/video, there is no way of preventing them from re-sharing it, if you're not looking over their shoulders.<br>
                                    Users can always take a photo of the screen, even if you could prevent screenshots. Hence, we saw no point in generating single-use links for the images (so that users can't share the picture directly). Please be aware of this and don't use formr to show confidential information in an un-supervised setting. However, because the links are large random numbers, it's fairly safe to use formr to upload confidential information to be shown in the lab, the images cannot be discovered by people who don't have access to the study.</li>
                                <li><i class="fa-li fa fa-file"></i>The following file types are allowed: <?php echo  implode(", ", Config::get('allowed_file_endings_for_run_upload')); ?></li>
                            </ul>
                            <?php
                                echo Site::getSettings('content:file_upload_terms'); 
                            ?>
                        </div>

                        <h4>Files to upload: </h4>
                        <form action="<?= admin_run_url($run->name, 'upload_files') ?>" class="dropzone form-inline" enctype="multipart/form-data"  id="upload_files" name="upload_files" method="post">
                        <p>
                                <?php
                                $val = Site::getSettings('content:file_upload_require_active_consent', 'false') === "true";
                                $checked = $val ? 'checked="checked"' : ''; 
                                ?>
                            <label><input type="checkbox" required value="1" name="confirm_rights" <?php echo $checked;  ?>>
                            I confirm that I will only upload files that fulfill the conditions above (have the necessary rights, respect privacy).</label>
                            </p>
                    <p>

                                <input required multiple type="file" accept="video/*,image/*,audio/*,text/*" name="uploaded_files[]" id="uploaded_files"/>
                    </p> 
                    <p>

                            <button type="submit" class="btn btn-default"><i class="fa fa-upload"></i> Upload all files</button>
                    </p>
                        </form>

                        <hr />
                        <h3>Files uploaded in this run</h3>
                        <?php if ($files): ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>File Name</th>
                                        <th>Created</th>
                                        <th>Modified</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($files as $row) { 
                                        ?>
                                        <tr>
                                            <td><?php echo $row['original_file_name']; ?></td>
                                            <td><abbr title="<?php echo $row['created']; ?>"><?php echo timetostr(strtotime($row['created'])); ?></abbr></td>
                                            <td><abbr title="<?php echo $row['modified']; ?>"><?php echo timetostr(strtotime($row['modified'] ?? $row['created'])); ?></abbr></td>
                                            <td>
                                                <a href="<?php echo asset_url($row['new_file_path']); ?>" class="btn btn-sm btn-default"><i class="fa fa-eye"></i> View File</a>
                                                <a href="javascript:void(0);" data-url="<?php echo asset_url($row['new_file_path']); ?>" class="btn btn-sm btn-primary copy-url"><i class="fa fa-copy"></i> Copy URL</a>
                                                <a href="<?php echo admin_run_url($run->name, 'delete_file', array('file' => $row['original_file_name'], 'id' => $row['id'])); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this file?');"><i class="fa fa-trash"></i> Delete File</a>	
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        <p>&nbsp;</p>
                    </div>
                    <!-- /.box-body -->

                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php Template::loadChild('admin/footer'); ?>