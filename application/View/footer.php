
		</div> <!-- end of main body -->
	</div> <!-- end of sidenav container -->
</div> <!-- end of main content div -->

<?php if ($site->inAdminArea()): ?>
	<script type="text/javascript" src="<?= WEBROOT ?>assets/<?=DEBUG?"lib":"minified"?>/ace/ace.js"></script>	
<?php endif; ?>
<script id="tpl-feedback-modal" type="text/formr">
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
</body>
</html>