<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/student_auth.php';

$db = getDB();
$error = null;
$admission = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $admission = trim($_POST['admission_no'] ?? '');
    try {
        $error = studentActivate(
            $db,
            $admission,
            $_POST['proof'] ?? '',
            $_POST['password'] ?? '',
            $_POST['password_confirm'] ?? ''
        );
        if ($error === null) {
            header('Location: ' . BOOKING_ROOT . '/student/login.php?activated=1');
            exit;
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Student activation failed: ' . $e->getMessage());
        $error = 'Activation is unavailable right now. Please try again shortly.';
    }
}

$studentTitle = 'Create your password';
include __DIR__ . '/partials/header.php';
?>
<main class="auth">
    <h1>Create your password</h1>

    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form class="card card-body" method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <div class="form-group">
            <label for="admission_no">Admission number</label>
            <input class="form-control" id="admission_no" name="admission_no" required maxlength="30" autocapitalize="characters"
                   value="<?= htmlspecialchars($admission) ?>" placeholder="e.g. 00000/00">
        </div>
        <div class="form-group">
            <label for="proof">National ID or activation code</label>
            <input class="form-control" id="proof" name="proof" required maxlength="40" autocomplete="off">
        </div>
        <div class="form-group">
            <label for="password">New password</label>
            <input class="form-control" id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
            <div class="hint">At least 8 characters.</div>
        </div>
        <div class="form-group">
            <label for="password_confirm">Repeat password</label>
            <input class="form-control" id="password_confirm" name="password_confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
        </div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Save password</button>
        <p class="links"><a href="<?= BOOKING_ROOT ?>/student/login.php">Back to sign in</a></p>
    </form>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
