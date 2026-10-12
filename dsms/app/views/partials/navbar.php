<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= e(url('home/index')) ?>">
            <?= e($config['app_name']) ?> <span class="fw-normal opacity-75 d-none d-md-inline">| UIS Vehicle Section</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="<?= e(url('home/index')) ?>">Home</a></li>
                <!-- Menu items per role are added in the next steps. -->
            </ul>
            <span class="navbar-text small">
                <?php if (Auth::check()): ?>
                    <?= e(Auth::user()['name']) ?> (<?= e(Auth::role()) ?>)
                <?php else: ?>
                    Not logged in
                <?php endif; ?>
            </span>
        </div>
    </div>
</nav>
