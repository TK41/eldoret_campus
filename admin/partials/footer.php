<?php
// ============================================================
// admin/partials/footer.php
// Closes the main content + layout wrapper opened in header.php
// Include at the very bottom of every admin page
// ============================================================
?>

</main><!-- end .main-content -->
</div><!-- end .layout -->

<!-- ── Footer bar ── -->
<footer class="app-footer">
    <span>KIMC Eldoret Campus Inventory System &copy; <?= date('Y') ?></span>
    <span>Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong></span>
</footer>

<!-- Main JavaScript -->
<script src="<?= APP_ROOT ?>/assets/js/main.js?v=<?= filemtime(__DIR__ . '/../../assets/js/main.js') ?>"></script>
</body>
</html>
