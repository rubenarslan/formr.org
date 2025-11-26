<?php Template::loadChild('admin/header');
$default_email = Config::get('default_admin_email');
$has_default_email= $default_email !== null && $default_email['host'] !== null;
?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>User Management <small>Superadmin</small></h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Formr Users </h3>
                        <form action="" method="post" class="form-inline pull-right">
                            <label class="sr-only">Name</label>
                            <div id="search-session" style="display: inline-block; position: relative;">
                                <div class="input-group single ">
                                    <div class="input-group-addon">SEARCH <i class="fa fa-user"></i></div>
                                    <input name="email" value="<?= $search_email ?>" type="text" class="form-control" placeholder="Enter Email" style="width: 250px;">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-default"><i class="fa fa-search"></i></button>
                        </form>
                    </div>
                    <div class="box-body table-responsive">
                        <?php Template::loadChild('public/alerts'); ?>
                        <?php if ($pdoStatement->rowCount()): ?>
                            <table class='table table-striped'>
                                <thead>
                                    <tr>
                                        <th>Email</th>
                                        <th>Created</th>
                                        <th>Modified</th>
                                        <th>Admin</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($userx = $pdoStatement->fetch(PDO::FETCH_ASSOC)): ?>
                                    <tr>
                                        <td>
                                            <a href="mailto:<?= h($userx['email']) ?>"><?= formr_search_highlight($search_email, $userx['email']) ?></a>
                                            <?php echo $userx['email_verified'] ? ' <i class="fa fa-check-circle-o"></i>' : ' <i class="fa fa-envelope-o"></i>'; ?>
                                        </td>
                                        <td><small class="hastooltip" title="<?= $userx['created'] ?>"><?= timetostr(strtotime((string)$userx['created'])) ?></small></td>
                                        <td><small class="hastooltip" title="<?= $userx['modified'] ?>"><?= timetostr(strtotime((string)$userx['modified'])) ?></small></td>
                                        <td>
                                            <form class="form-inline form-ajax" action="<?= site_url('admin/advanced/ajax_admin') ?>" method="post">
                                                <span class="input-group" style="width:160px">
                                                    <span class="input-group-btn">
                                                        <button type="submit" class="btn hastooltip" title="Give this level to this user"><i class="fa fa-hand-o-right"></i></button>
                                                    </span>
                                                    <input type="hidden" name="user_id" value="<?= $userx['id'] ?>">
                                                    <input type="number" name="admin_level" max="100" min="-1" value="<?= h($userx['admin']) ?>" class="form-control">
                                                </span>
                                            </form>
                                        </td>
                                        <td>
                                            <button type="button" class="btn api-btn hastooltip" title="Manage API Access" data-user="<?= $userx['id'] ?>" data-email="<?= h($userx['email']) ?>"><i class="fa fa-cloud"></i></button>
                                            <?php echo  $has_default_email ? '<button type="button" class="btn add-email-btn hastooltip" title="Add default email account" data-user="'. $userx['id']. '" data-email="'. h($userx['email']).'"><i class="fa fa-plus"></i> <i class="fa fa-envelope"></i></button>' :'' ?>
                                            <?php echo $userx['email_verified'] ? '' : '<button type="button" class="btn verify-email-btn hastooltip" title="Verify email address manually" data-user="'. $userx['id']. '" data-email="'. h($userx['email']).'"><i class="fa fa-envelope"></i><i class="fa fa-check"></i></button>' ?>
                                            <?php if ($userx['admin'] != 100): ?>
                                                <button type="button" class="btn reset-2fa-btn hastooltip" title="Reset 2FA" data-user="<?= $userx['id'] ?>" data-email="<?= h($userx['email']) ?>"><i class="fa fa-key"></i></button>
                                            <?php endif; ?>
                                            <button type="button" class="btn del-btn hastooltip" title="Delete User" data-user="<?= $userx['id'] ?>" data-email="<?= h($userx['email']) ?>"><i class="fa fa-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            <div class="pagination">
                                <?php $pagination->render("admin/advanced/user_management"); ?>
                            </div>

                        <?php endif; ?>

                    </div>
                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<script id="tpl-user-api" type="text/formr">
<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="UserAPI" aria-hidden="true">
   <div class="modal-dialog">
      <div class="modal-content">
         <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            <h3>API Access %{user}</h3>
         </div>
         <div class="modal-body">
            <table class="table table-striped table-bordered">
               <tr>
                  <td><b>Client ID</b></td>
                  <td>%{client_id}</td>
               </tr>
               <tr>
                  <td><b>Client Secret</b></td>
                  <td>%{client_secret}</td>
               </tr>
               <tr>
                  <td><b>R command</b></td>
                  <td>
                     <pre><code class="r">formr_api_access_token("%{client_id}", "%{client_secret}")</code></pre>
                  </td>
               </tr>
            </table>
            <div class="clearfix"></div>
         </div>
         <div class="modal-footer">
            <button class="btn btn-success api-create" aria-hidden="true">Create Credentials</button>
            <button class="btn btn-default api-change" aria-hidden="true">Change Secret</button>
            <button class="btn btn-danger api-delete" aria-hidden="true">Revoke Credentials</button>
            <button class="btn api-close" data-dismiss="modal" aria-hidden="true">Close</button>
         </div>
      </div>
   </div>
</div>
</script>
<script id="tpl-user-delete" type="text/formr">
<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="UserDelete" aria-hidden="true">
   <div class="modal-dialog">
      <div class="modal-content">
         <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            <h3>Delete Account '%{user}'</h3>
         </div>
         <div class="modal-body">
            <h4>Are you sure you want to delete this account and all associated data?</h4>
            <div class="alert alert-danger">This action is irreversible and all the collected data for this user would be deleted.</div>
            <div class="clearfix"></div>
         </div>
         <div class="modal-footer">
            <button class="btn btn-danger user-delete" aria-hidden="true">Yes</button>
            <button class="btn user-close" data-dismiss="modal" aria-hidden="true">Cancel</button>
         </div>
      </div>
   </div>
</div>
</script>
<script id="tpl-reset-2fa" type="text/formr">
<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="Reset2FA" aria-hidden="true">
   <div class="modal-dialog">
      <div class="modal-content">
         <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            <h3>Reset 2FA for '%{user}'</h3>
         </div>
         <div class="modal-body">
            <h4>Are you sure you want to reset two-factor authentication for this user?</h4>
            <div class="alert alert-warning">This will disable 2FA for the user. They will need to set it up again to use 2FA.</div>
            <div class="form-group">
                <label>Enter your 2FA code to confirm:</label>
                <input type="text" class="form-control" name="admin_2fa_code" placeholder="Enter your 2FA code">
            </div>
            <div class="clearfix"></div>
         </div>
         <div class="modal-footer">
            <button class="btn btn-warning reset-2fa-confirm" aria-hidden="true">Reset 2FA</button>
            <button class="btn" data-dismiss="modal" aria-hidden="true">Cancel</button>
         </div>
      </div>
   </div>
</div>
</script>

<script type="text/javascript">
    var saAjaxUrl = <?php echo json_encode(site_url('admin/advanced/ajax_admin')); ?>;
    
    $(document).ready(function() {
        // Existing code...
        
        $('.reset-2fa-btn').click(function() {
            var userId = $(this).data('user');
            var userEmail = $(this).data('email');
            
            var template = $('#tpl-reset-2fa').html();
            template = template.replace(/%{user}/g, userEmail);
            
            var $modal = $(template);
            $modal.modal('show');
            
            $modal.find('.reset-2fa-confirm').click(function() {
                var adminCode = $modal.find('input[name="admin_2fa_code"]').val();
                
                $.post(saAjaxUrl, {
                    reset_2fa: true,
                    user_id: userId,
                    user_email: userEmail,
                    admin_2fa_code: adminCode
                }, function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert(response.message || 'Failed to reset 2FA');
                    }
                });
            });
        });
    });
</script>

<?php Template::loadChild('admin/footer'); ?>