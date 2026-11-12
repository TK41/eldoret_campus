<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/student_auth.php';
require_once __DIR__ . '/../reservation_helpers.php';
require_once __DIR__ . '/../../shared/formatters.php';

studentRequireLogin();

$db = getDB();
$profile = studentLoadProfile($db);
if (!$profile) {
    studentEndSession();
    header('Location: ' . BOOKING_ROOT . '/student/login.php');
    exit;
}

$flash = $_SESSION['student_flash'] ?? null;
unset($_SESSION['student_flash']);

$bookingStmt = $db->prepare("
    SELECT r.reservation_id, r.booking_reference, r.kit_name, r.status,
           r.requested_start, r.requested_end, r.rejection_reason
    FROM reservations r
    WHERE r.user_id = ?
    ORDER BY r.submitted_at DESC, r.reservation_id DESC
    LIMIT 20
");
$bookingStmt->execute([$profile['user_id']]);
$bookings = $bookingStmt->fetchAll();
$bookingItems = [];
if ($bookings) {
    $ids = array_column($bookings, 'reservation_id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $itemStmt = $db->prepare("
        SELECT reservation_id, asset_code_snapshot, asset_name_snapshot
        FROM reservation_items WHERE reservation_id IN ($in) ORDER BY asset_name_snapshot
    ");
    $itemStmt->execute($ids);
    foreach ($itemStmt->fetchAll() as $item) {
        $bookingItems[$item['reservation_id']][] = $item;
    }
}

$bookingStatusLabels = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'issued' => 'Collected',
    'returned' => 'Returned',
    'cancelled' => 'Cancelled',
    'expired' => 'Expired',
];

$studentTitle = 'My bookings';
$studentName = formatPersonName($profile['full_name']);
include __DIR__ . '/partials/header.php';
?>
<main class="student-wrap bookings-page">
    <section class="hero bookings-hero">
        <div class="hero-text">
            <h1 class="page-title">My bookings</h1>
            <p class="hero-sub">Track your equipment requests and their status.</p>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="alert alert-<?= ($flash[0] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash[1] ?? '') ?></div>
    <?php endif; ?>

    <section class="card bookings-card" aria-labelledby="bookings-title">
        <div class="card-header">
            <h2 class="card-title" id="bookings-title">Booking history</h2>
            <a class="btn btn-primary btn-sm" href="<?= BOOKING_ROOT ?>/reservations.php#booking-form">New booking</a>
        </div>
        <div class="card-body">
            <?php if (!$bookings): ?>
                <div class="empty-state">
                    <span class="empty-state-icon"><?= appIcon('package') ?></span>
                    <p>No bookings yet.</p>
                    <a class="btn btn-primary btn-sm" href="<?= BOOKING_ROOT ?>/reservations.php#booking-form">Create a booking</a>
                </div>
            <?php endif; ?>
            <?php foreach ($bookings as $b): ?>
                <article class="booking <?= htmlspecialchars($b['status']) ?>">
                    <div class="top">
                        <div>
                            <strong><?= htmlspecialchars($b['kit_name'] ?: 'Equipment booking') ?></strong>
                            <div class="ref mono"><?= htmlspecialchars($b['booking_reference']) ?></div>
                        </div>
                        <span class="tag status <?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars($bookingStatusLabels[$b['status']] ?? ucfirst($b['status'])) ?></span>
                    </div>
                    <div class="when">
                        Pickup <?= htmlspecialchars(reservationFormatLocalDateTime($b['requested_start'])) ?>
                        <?php if (in_array($b['status'], ['approved', 'issued'], true)): ?>
                            <br>Return by <?= htmlspecialchars(reservationFormatLocalDateTime($b['requested_end'])) ?>
                        <?php endif; ?>
                    </div>
                    <ul>
                        <?php foreach ($bookingItems[$b['reservation_id']] ?? [] as $item): ?>
                            <li><?= htmlspecialchars($item['asset_name_snapshot']) ?> <span class="hint mono"><?= htmlspecialchars($item['asset_code_snapshot']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($b['rejection_reason']): ?>
                        <div class="alert alert-warn" style="margin:10px 0 0">Staff note: <?= htmlspecialchars($b['rejection_reason']) ?></div>
                    <?php endif; ?>
                    <?php if (in_array($b['status'], ['pending', 'approved'], true)): ?>
                        <form method="post" action="<?= BOOKING_ROOT ?>/reservations.php" onsubmit="return confirm('Cancel this booking?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="return_to" value="my-bookings">
                            <input type="hidden" name="reservation_id" value="<?= (int) $b['reservation_id'] ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">Cancel booking</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
