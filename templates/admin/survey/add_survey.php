<?php Template::loadChild('admin/header'); ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>Surveys <small>Add New</small></h1>
    </section>

    <!-- Main content -->
    <section class="content">

        <div class="callout callout-info">
            <h4>Please keep this in mind when uploading surveys!</h4>
            <ul class="fa-ul fa-ul-more-padding">
                <li>
                    <i class="fa-li fa fa-table"></i> The format must be one of <abbr title="" data-original-title="Old-style Excel spreadsheets">.xls</abbr>, <abbr title="" data-original-title="New-style Excel spreadsheets, Office Open XML">.xlsx</abbr>, <abbr title="" data-original-title="OpenOffice spreadsheets / Open document format for Office Applications">.ods</abbr>, <abbr title="" data-original-title="extensible markup language">.xml</abbr>, <abbr title="" data-original-title="text files">.txt</abbr>, or <abbr title="" data-original-title=".csv-files (comma-separated value) have to use the comma as a separator, &quot;&quot; as escape characters and UTF-8 as the charset. Because there are inconsistencies when creating CSV files using various spreadsheet programs (e.g. German excel), you should probably steer clear of this.">.csv</abbr>.
                </li>

                <li>
                    <i class="fa-li fa fa-exclamation-triangle"></i> The survey shorthand will be derived from the filename. 
                    <ul class="fa-ul">
                        <li><i class="fa-li fa fa-check"></i> If your spreadsheet was named <code>survey_1-v2.xlsx</code> the name would be <code>survey_1</code>.</li> 
                        <li><i class="fa-li fa fa-check"></i> The name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. The name has to at least 2, at most 64 characters long. You can't use spaces, periods or dashes in the name.</li><li>
                        </li><li><i class="fa-li fa fa-check"></i> It needs to start with a letter.</li>
                        <li><i class="fa-li fa fa-check"></i> As shown above, you can add version numbers (or anything) after a dash, they will be ignored.</li>
                    </ul>
                </li>
                <li>
                    <i class="fa-li fa fa-unlock-alt"></i> The name you choose here cannot be changed. It will be used to refer to this survey's results in many places.<br>
                    <strong>Make it meaningful.</strong>
                </li>
            </ul>
        </div>
        <?php Template::loadChild('public/alerts'); ?>
        <div class="col-md-5">
            <h3><i class="fa fa-upload"></i> Upload an item table</h3>
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Select a file</h3>
                </div>
                <form role="form" class="" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?php echo admin_url('survey/add_survey'); ?>">
                    <div class="box-body">

                        <div class="form-group">
                            <input type="hidden" name="new_study" value="1">
                            <input required name="uploaded" type="file" id="file_upload">
                            <small class="help-block"><i class="fa fa-info-circle"></i> Did you know, that on many computers you can also drag and drop a file on this box instead of navigating there through the file browser?</small>
                        </div>
                    </div>
                    <!-- /.box-body -->

                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
            <p>&nbsp;</p>
            <a href="<?= site_url('documentation/#sample_survey_sheet') ?>" target="_blank"><i class="fa fa-question-circle"></i> more help on creating survey sheets</a>
        </div>
        <div class="col-md-1"><h3 style="line-height: 100px;">OR</h3></div>
        <div class="col-md-6">
            <h3><i class="fa fa-download"></i> Import a Googlesheet</h3>
            <div class="box box-primary">
                <form role="form" id="add_study_google" name="add_study" method="post" action="<?php echo admin_url('survey/add_survey'); ?>">
                    <div class="box-body">
                        <div class="form-group">
                            <label>Survey Name</label>
                            <input name="survey_name" type="text" class="form-control" placeholder="Survey Name">
                            <small class="help-block"><i class="fa fa-info-circle"></i> Enter a survey name following the hints above..</small>
                        </div>
                        <div class="form-group">
                            <label>Sheet link</label>
                            <textarea name="google_sheet" class="form-control" rows="3" placeholder="Enter Googlesheet share link"></textarea>
                            <small class="help-block"><i class="fa fa-info-circle"></i> Make sure this sheet is accessible by anyone with the link</small>
                        </div>
                    </div>
                    <!-- /.box-body -->

                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary">Import</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php Template::loadChild('admin/footer'); ?>

