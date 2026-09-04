<?php // admissions/partials/adm_footer.php ?>
</main>
</div>

<footer class="app-footer">
    <span>KIMC Eldoret Campus — Admissions Module &copy; <?= date('Y') ?></span>
    <span>Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong></span>
</footer>

<script src="<?= APP_ROOT ?>/assets/js/main.js"></script>
</body>
</html>
