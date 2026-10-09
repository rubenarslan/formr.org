<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper" id="admin-documentation">
    <section class="content-header">
        <h1>Admin documentation <small>for logged-in admins only</small></h1>
    </section>

    <section class="content">
        <?php Template::loadChild('public/alerts'); ?>
        <div class="row">
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-body">
                        <p>
                            These pages are not part of the <a href="<?= site_url('documentation') ?>">public documentation</a>:
                            they describe things that are easier to get around once they are widely known.
                            Please do not copy them to public places.
                        </p>
                        <ul class="list-unstyled">
                            <?php foreach ($pages as $slug => $page): ?>
                            <li>
                                <h4><a href="<?= admin_url('documentation/' . $slug) ?>"><i class="fa fa-book fa-fw"></i> <?= h($page['title']) ?></a></h4>
                                <p class="text-muted"><?= h($page['summary']) ?></p>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
