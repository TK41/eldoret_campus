<?php // exams/partials/footer.php ?>
</main>
</div>

<footer class="app-footer">
    <span>KIMC Eldoret Campus — Exam Results Module &copy; <?= date('Y') ?></span>
    <span>Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong></span>
</footer>

<script src="<?= APP_ROOT ?>/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/main.js') ?>"></script>
</body>
</html>
