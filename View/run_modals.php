<!-- Run templates needed for javascript -->
<script id="tpl-export-units" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="ExportUnits" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
				<form id="export_run_units" method="post">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h3>JSON export of modules</h3>
                </div>
                <div class="modal-body">
                    <h5>Select run units to export and give your export a name</h5>
					<div class="row"><div class="col-md-12">
						<input class="form-control" placeholder="Name Export (a to Z, 0 to 9, _ and spaces)" name="export_name" value="%{run_name}" />
						<input type="hidden" name="units">
					</div>
					</div>
                    <div class="row"><div class="col-md-12">%{export_html}</div></div>
                </div>
            <div class="modal-footer">
                <button class="btn btn-success confirm-export" aria-hidden="true" type="submit">Export</button>
                <button class="btn cancel-export" data-dismiss="modal" aria-hidden="true">Close</button>
            </div>
			</form>
        </div>
    </div>
</script>
<script id="tpl-test-modal" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="FormR.org Modal" aria-hidden="true">
        <div class="modal-dialog">                         
            <div class="modal-content">                              
                <div class="modal-header">                                 
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>                                 
                    <h3>%{header}</h3>                             
                </div>                             
                <div class="modal-body">%{body}</div>
                <div class="modal-footer">                             
                    <button class="btn" data-dismiss="modal" aria-hidden="true">Close</button>                         
                </div>                     
            </div>                 
        </div>
    </div>
</script>
<script id="tpl-export-unit-block" type="text/formr">
<div class="form-group run-export-unit-block">
	<div class="select btn" data-position="%{unit_pos}" data-selected="1">
		<i class="fa fa-check fa-2x"></i>
	</div>
	<div class="col-sm-12">
		<pre><code class="hljs json">%{unit_json}</code></pre>
	</div>
	<div class="clearfix"></fix>
</div>
</script>
<script id="tpl-import-units" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="ImportUnits" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h3>JSON import of modules</h3>
                </div>
                <div class="modal-body">
                    <div>%{content}</div>
					<div class="clearfix"></div>
                </div>
            <div class="modal-footer">
                <button class="btn btn-success confirm-import" aria-hidden="true">Import</button>
                <button class="btn cancel-export" data-dismiss="modal" aria-hidden="true">Close</button>
            </div>
        </div>
    </div>
</script>
