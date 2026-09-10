        </main>
        <footer class="text-center text-muted small py-3 border-top bg-white">
            &copy; <?= date('Y') ?> <?= h(APP_NAME) ?> — Tous droits réservés.
        </footer>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/script.js"></script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
