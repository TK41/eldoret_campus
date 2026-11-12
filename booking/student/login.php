<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/student_auth.php';

if (!empty($_SESSION['student'])) {
    header('Location: ' . BOOKING_ROOT . '/reservations.php');
    exit;
}

$db = getDB();
$error = null;
$admission = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $admission = trim($_POST['admission_no'] ?? '');
    try {
        $result = studentAuthenticate($db, $admission, $_POST['password'] ?? '', $error);
        if ($result) {
            studentStartSession($result['fee'], $result['user_id']);
            header('Location: ' . BOOKING_ROOT . '/reservations.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log('Student sign-in failed: ' . $e->getMessage());
        $error = 'Sign-in is unavailable right now. Please try again shortly.';
    }
}

$studentTitle = 'Sign in';
include __DIR__ . '/partials/header.php';
?>
<main class="auth">
    <h1>Sign in to book equipment</h1>

    <?php if (isset($_GET['activated'])): ?><div class="alert alert-success">Password saved. You can sign in now.</div><?php endif; ?>
    <?php if (isset($_GET['timeout'])): ?><div class="alert alert-info">You were signed out after a period of inactivity.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form class="card card-body" method="post" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <div class="form-group">
            <label for="admission_no">Admission number</label>
            <input class="form-control" id="admission_no" name="admission_no" required maxlength="30" autocomplete="username"
                   autocapitalize="characters" value="<?= htmlspecialchars($admission) ?>" placeholder="e.g. 00000/00">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
        <p class="links">First time here? <a href="<?= BOOKING_ROOT ?>/student/activate.php">Create your password</a></p>
    </form>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
