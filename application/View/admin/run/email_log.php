<?php Template::load('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1><?php echo $run->name; ?> <small><a target="_blank" title="The official link to your run, which you can share with prospective users." href="<?php echo run_url($run->name, null, null) ?>"><?php echo run_url($run->name, null, null) ?></a></small> </h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::load('admin/run/menu'); ?>
            </div>
            <div class="col-md-10">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Email Log </h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php if ($emails): ?>

                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <?php
                                        foreach (current($emails) AS $field => $value) {
                                            echo "<th>{$field}</th>";
                                        }
                                        ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // printing table rows
                                    foreach ($emails AS $row) {
                                        echo "<tr>";
                                        foreach ($row as $cell) {
                                            echo "<td>$cell</td>";
                                        }
                                        echo "</tr>\n";
                                    };
                                    ?>
                                </tbody>
                            </table>
                            <div class="pagination">
                                <?php $pagination->render("admin/run/{$run->name}/email_log"); ?>
                            </div>
                        <?php else: ?>
                            <h5 class="lead"><i>No E-mails yet</i></h5>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php Template::load('admin/footer'); ?>