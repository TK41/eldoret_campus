    </main>
</div>

<footer class="app-footer">
    <span>&copy; 2026 Nisat. All rights reserved. Licensed to KIMC Eldoret.</span>
    <span>Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong></span>
</footer>

<script src="<?= APP_ROOT ?>/assets/js/main.js?v=<?= filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
<script src="<?= APP_ROOT ?>/assets/js/mobile.js?v=<?= filemtime(__DIR__ . '/../assets/js/mobile.js') ?>"></script>
</body>
</html>
