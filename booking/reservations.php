<?php
// ============================================================
// reservations.php — student booking portal (sign-in required)
// Identity, name and course come from the fees portal. Students build a
// kit, choose a pickup time, and staff review and issue from the admin side.
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/auth/student_auth.php';
require_once __DIR__ . '/reservation_helpers.php';
require_once __DIR__ . '/../shared/formatters.php';

studentRequireLogin();

$db = getDB();
$profile = studentLoadProfile($db);
if (!$profile) {
    studentEndSession();
    header('Location: ' . BOOKING_ROOT . '/student/login.php');
    exit;
}

$rules = studentBookingRules();
if ((int) ($profile['max_equipment'] ?? 0) > 0) {
    $rules['max_items'] = (int) $profile['max_equipment'];
}
$errors = [];
$fieldErrors = ['kit_name' => null, 'pickup' => null, 'items' => null, 'purpose' => null];
$flash = $_SESSION['student_flash'] ?? null;
unset($_SESSION['student_flash']);
$successReference = null;
if (is_array($flash) && ($flash[0] ?? '') === 'booking_success') {
    $successReference = (string) ($flash[1] ?? '');
    $flash = null;
}
$pickupDateValue = '';
$pickupTimeValue = '';
$restoredSelection = [];

$eligibility = null;   // reason the student cannot book, shown instead of the form
if (!$profile['fee_active'] || !$profile['user_active']) {
    $eligibility = 'Your student record is not active. Contact the fees office.';
} elseif (!in_array((int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('N'), $rules['booking_days'], true)) {
    $eligibility = 'Online booking is closed on Saturdays and Sundays. Please come back on a weekday to book (Monday pickups must be booked by Friday).';
} elseif (!$profile['can_reserve']) {
    $eligibility = 'Your borrowing tier (' . $profile['tier_name'] . ') does not allow online bookings. Contact inventory staff.';
} elseif ((float) $profile['fines_owed'] > 0) {
    $eligibility = 'Outstanding fines must be cleared before you can book equipment.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        $returnToMyBookings = ($_POST['return_to'] ?? '') === 'my-bookings';
        $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            $db->beginTransaction();
            $find = $db->prepare("
                SELECT reservation_id, status FROM reservations
                WHERE reservation_id = ? AND user_id = ? FOR UPDATE
            ");
            $find->execute([$reservationId, $profile['user_id']]);
            $row = $find->fetch();
            if (!$row || !in_array($row['status'], ['pending', 'approved'], true)) {
                throw new InvalidArgumentException('Only pending or approved bookings can be cancelled. Contact inventory staff for help.');
            }
            $db->prepare("UPDATE reservations SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP() WHERE reservation_id = ?")
               ->execute([$row['reservation_id']]);
            reservationLog($db, (int) $row['reservation_id'], 'cancelled_by_student');
            $db->commit();
            $_SESSION['student_flash'] = ['success', 'Your booking was cancelled.'];
            header('Location: ' . BOOKING_ROOT . ($returnToMyBookings ? '/student/my_bookings.php' : '/reservations.php'));
            exit;
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($returnToMyBookings) {
                $_SESSION['student_flash'] = ['error', $e->getMessage()];
                header('Location: ' . BOOKING_ROOT . '/student/my_bookings.php');
                exit;
            }
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Student cancellation failed: ' . $e->getMessage());
            if ($returnToMyBookings) {
                $_SESSION['student_flash'] = ['error', 'The cancellation could not be completed. Please try again.'];
                header('Location: ' . BOOKING_ROOT . '/student/my_bookings.php');
                exit;
            }
            $errors[] = 'The cancellation could not be completed. Please try again.';
        }
    } elseif ($action === 'submit') {
        $kitName  = trim($_POST['kit_name'] ?? '');
        $pickupDateValue = (string) ($_POST['pickup_date'] ?? '');
        $pickupTimeValue = (string) ($_POST['pickup_time'] ?? '');
        $pickup = $pickupDateValue . 'T' . $pickupTimeValue;
        $purpose  = trim($_POST['purpose'] ?? '');
        $requestKey = $_POST['request_key'] ?? '';

        // Items arrive as parallel arrays of group key + quantity.
        $wanted = [];
        $keys = (array) ($_POST['item_key'] ?? []);
        $qtys = (array) ($_POST['item_qty'] ?? []);
        foreach ($keys as $i => $key) {
            $qty = filter_var($qtys[$i] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $rules['max_items']]]);
            if (is_string($key) && preg_match('/^[a-f0-9]{16}$/', $key) && $qty) {
                $wanted[$key] = ($wanted[$key] ?? 0) + $qty;
            }
        }
        $restoredSelection = $wanted;
        $totalItems = array_sum($wanted);

        if ($eligibility) {
            $errors[] = $eligibility;
        }
        if (empty($_SESSION['student_submit_key']) || !hash_equals($_SESSION['student_submit_key'], $requestKey)) {
            $errors[] = 'This form was already submitted or has expired. Reload the page and try again.';
        }
        if (strlen($kitName) < 2 || strlen($kitName) > 120) {
            $fieldErrors['kit_name'] = 'Give your kit a name (2–120 characters).';
            $errors[] = $fieldErrors['kit_name'];
        }
        if ($pickupError = studentPickupError($pickup, $rules)) {
            $fieldErrors['pickup'] = $pickupError;
            $errors[] = $pickupError;
        }
        if ($totalItems < 1) {
            $fieldErrors['items'] = 'Add at least one item to your kit.';
            $errors[] = $fieldErrors['items'];
        } elseif ($totalItems > $rules['max_items']) {
            $fieldErrors['items'] = 'A kit can hold at most ' . $rules['max_items'] . ' items.';
            $errors[] = $fieldErrors['items'];
        }
        if ($totalItems > 1 && !$profile['can_kit']) {
            $fieldErrors['items'] = 'Your borrowing tier (' . $profile['tier_name'] . ') does not allow multi-item kits. Book a single item or contact inventory staff.';
            $errors[] = $fieldErrors['items'];
        }
        if ($purpose === '' || strlen($purpose) > 500) {
            $fieldErrors['purpose'] = 'Enter the purpose of borrowing (maximum 500 characters).';
            $errors[] = $fieldErrors['purpose'];
        }

        if (!$errors) {
            $daily = $db->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND submitted_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY");
            $daily->execute([$profile['user_id']]);
            $pending = $db->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'pending'");
            $pending->execute([$profile['user_id']]);
            if ((int) $daily->fetchColumn() >= 5 || (int) $pending->fetchColumn() >= 3) {
                $errors[] = 'You have reached the booking request limit. Wait for staff to process your existing requests.';
            }
        }

        if (!$errors) {
            try {
                $start = reservationParseLocalDateTime($pickup);
                $end = studentWindowEnd($start, (int) $profile['equip_loan_hrs']);

                $db->beginTransaction();
                $groups = studentItemGroups($db, $start, $end);
                $assetIds = [];
                $short = [];
                foreach ($wanted as $key => $qty) {
                    if (!isset($groups[$key])) {
                        throw new InvalidArgumentException('An item you selected no longer exists. Reload the page and rebuild your kit.');
                    }
                    if ($groups[$key]['free'] < $qty) {
                        $short[] = $groups[$key]['name'] . ' (' . $groups[$key]['free'] . ' free, you asked for ' . $qty . ')';
                        continue;
                    }
                    $assetIds = array_merge($assetIds, array_slice($groups[$key]['free_ids'], 0, $qty));
                }
                if ($short) {
                    throw new InvalidArgumentException('Not enough free for your pickup time: ' . implode('; ', $short) . '.');
                }
                reservationEnsureAvailable($db, $assetIds, $start, $end);   // locks rows, final check

                $type = count($assetIds) === 1 ? 'single' : 'custom';
                $reference = 'EQB-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
                $db->prepare("
                    INSERT INTO reservations
                        (booking_reference, user_id, reservation_type, asset_id, kit_name,
                         requested_start, requested_end, purpose, fee_student_id,
                         student_name_snapshot, programme_snapshot)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $reference, $profile['user_id'], $type,
                    $type === 'single' ? $assetIds[0] : null,
                    $kitName, $start, $end, $purpose, $profile['fee_student_id'],
                    $profile['full_name'], $profile['programme'],
                ]);
                $reservationId = (int) $db->lastInsertId();
                $saveItem = $db->prepare("
                    INSERT INTO reservation_items (reservation_id, asset_id, asset_code_snapshot, asset_name_snapshot)
                    SELECT ?, asset_id, asset_code, name FROM assets WHERE asset_id = ?
                ");
                foreach ($assetIds as $assetId) {
                    $saveItem->execute([$reservationId, $assetId]);
                }
                reservationLog($db, $reservationId, 'submitted');
                $db->commit();

                unset($_SESSION['student_submit_key']);
                $_SESSION['student_flash'] = ['booking_success', $reference];
                header('Location: ' . BOOKING_ROOT . '/reservations.php');
                exit;
            } catch (InvalidArgumentException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $fieldErrors['items'] = $e->getMessage();
                $errors[] = $e->getMessage();
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Student booking failed: ' . $e->getMessage());
                $errors[] = 'Your booking could not be saved. Please try again.';
            }
        }
    }
}

if (empty($_SESSION['student_submit_key'])) {
    $_SESSION['student_submit_key'] = bin2hex(random_bytes(32));
}

$fieldErrorMessages = array_values(array_filter($fieldErrors));
$globalErrors = array_values(array_filter($errors, function ($error) use ($fieldErrorMessages) {
    return !in_array($error, $fieldErrorMessages, true);
}));

$tz = new DateTimeZone('Africa/Nairobi');
$nowLocal = new DateTimeImmutable('now', $tz);
$pickupDates = [];
$todayLocal = $nowLocal->setTime(0, 0);
for ($offset = $rules['min_days_ahead']; $offset <= $rules['max_advance_days']; $offset++) {
    $date = $todayLocal->modify('+' . $offset . ' days');
    if (in_array((int) $date->format('N'), $rules['open_days'], true)) {
        $pickupDates[] = [
            'value' => $date->format('Y-m-d'),
            'label' => $date->format('D, j M Y'),
        ];
    }
}
$allowedPickupDates = array_column($pickupDates, 'value');
$pickupDateValue = in_array($pickupDateValue, $allowedPickupDates, true) ? $pickupDateValue : '';
$pickupTimeOptions = [];
$pickupSlot = new DateTimeImmutable('today ' . $rules['open_time'], $tz);
$pickupClose = new DateTimeImmutable('today ' . $rules['close_time'], $tz);
while ($pickupSlot < $pickupClose) {
    $pickupTimeOptions[] = $pickupSlot->format('H:i');
    $pickupSlot = $pickupSlot->modify('+' . $rules['slot_minutes'] . ' minutes');
}
$pickupTimeValue = in_array($pickupTimeValue, $pickupTimeOptions, true) ? $pickupTimeValue : '';
$loanHours = max(1, min((int) $profile['equip_loan_hrs'], $rules['max_loan_hours']));
$loanText = $loanHours % 24 === 0 ? ($loanHours / 24) . ' day' . ($loanHours === 24 ? '' : 's') : $loanHours . ' hours';

$studentTitle = 'Book equipment';
$studentName = formatPersonName($profile['full_name']);
include __DIR__ . '/student/partials/header.php';
?>
<main class="student-wrap">
    <section class="hero">
        <div class="hero-text">
            <h1 class="page-title">Hello, <?= htmlspecialchars(trim(explode(' ', $studentName)[0])) ?></h1>
        </div>
        <div class="profile-meta">
            <span><small>Admission no.</small><b class="mono"><?= htmlspecialchars($profile['student_id']) ?></b></span>
            <span><small>Course</small><b><?= htmlspecialchars(formatTitleCase($profile['programme'] ?: 'Not recorded')) ?></b></span>
            <span><small>Group</small><b><?= htmlspecialchars(formatTitleCase(($profile['group_name'] ?? '') ?: 'Not recorded') . ($profile['year_label'] ? ' · ' . $profile['year_label'] : '')) ?></b></span>
            <span><small>Loan period</small><b><?= htmlspecialchars($loanText) ?></b></span>
        </div>
    </section>

    <?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>
    <?php if ($successReference !== null): ?>
        <section class="booking-success" role="status" aria-live="polite">
            <span class="success-icon"><?= appIcon('check') ?></span>
            <div class="success-content">
                <h2>Booking request sent</h2>
                <p>Your reference is <strong class="mono"><?= htmlspecialchars($successReference) ?></strong>.</p>
                <p>Staff will review your request. Track its status in My bookings and wait for approval before collecting.</p>
                <div class="success-actions">
                    <a class="btn btn-primary btn-sm" href="<?= BOOKING_ROOT ?>/student/my_bookings.php">View my bookings</a>
                    <a class="btn btn-ghost btn-sm" href="#booking-form">Make another booking</a>
                </div>
            </div>
        </section>
    <?php endif; ?>
    <?php foreach ($globalErrors as $error): ?>
        <div class="alert alert-error inline-alert" role="alert"><?= appIcon('alert-triangle') ?><span><?= htmlspecialchars($error) ?></span></div>
    <?php endforeach; ?>

    <div class="student-grid">
        <form class="card builder" method="post" id="booking-form" autocomplete="off">
            <div class="card-body">

            <?php if ($eligibility): ?>
                <div class="alert alert-warn" role="status"><?= appIcon('alert-triangle') ?><span><?= htmlspecialchars($eligibility) ?></span></div>
            <?php else: ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="request_key" value="<?= htmlspecialchars($_SESSION['student_submit_key']) ?>">
            <input type="hidden" name="action" value="submit">
            <input type="hidden" id="pickup" value="">

            <nav class="booking-stepper" aria-label="Booking steps">
                <ol>
                    <li data-step="1"><a href="#step-1"><span class="step-num">1</span><span>Name &amp; pickup</span></a></li>
                    <li data-step="2"><a href="#step-2"><span class="step-num">2</span><span>Choose items</span></a></li>
                    <li data-step="3"><a href="#step-3"><span class="step-num">3</span><span>Review</span></a></li>
                </ol>
                <p class="stepper-status">Step <strong id="step-current">1</strong> of 3</p>
            </nav>

            <section class="booking-step" id="step-1" data-booking-step="1" aria-labelledby="step-1-title">
            <div class="step-head"><div><h2 id="step-1-title">Name your kit &amp; choose pickup</h2></div></div>
            <div class="two">
                <div class="form-group">
                    <label for="kit_name">Kit name</label>
                    <input class="form-control" id="kit_name" name="kit_name" required minlength="2" maxlength="120" placeholder="e.g. Documentary shoot"
                           aria-describedby="kit-name-help kit-name-error" value="<?= htmlspecialchars($_POST['kit_name'] ?? '') ?>">
                    <p class="field-help" id="kit-name-help">Use 2–120 characters.</p>
                    <p class="field-error" id="kit-name-error" role="alert" <?= $fieldErrors['kit_name'] ? '' : 'hidden' ?>><?= appIcon('alert-triangle') ?><span><?= htmlspecialchars($fieldErrors['kit_name'] ?? '') ?></span></p>
                </div>
                <div class="form-group">
                    <label for="pickup-display">Pickup date</label>
                    <div class="pickup-picker" id="pickup-picker"
                         data-allowed-dates="<?= htmlspecialchars(json_encode($allowedPickupDates), ENT_QUOTES, 'UTF-8') ?>"
                         data-initial-date="<?= htmlspecialchars($pickupDateValue) ?>">
                        <div class="pickup-date-control">
                            <input class="form-control" id="pickup-display" type="text" readonly required
                                   aria-haspopup="dialog" aria-expanded="false" aria-controls="pickup-calendar"
                                   aria-describedby="pickup-rules pickup-error"
                                   placeholder="Choose a pickup date" value="">
                            <button class="calendar-toggle" id="pickup-calendar-toggle" type="button" aria-label="Choose pickup date" aria-controls="pickup-calendar">
                                <?= appIcon('calendar') ?>
                            </button>
                        </div>
                        <input type="hidden" id="pickup-date" name="pickup_date" value="<?= htmlspecialchars($pickupDateValue) ?>">
                        <div class="date-picker" id="pickup-calendar" role="dialog" aria-label="Choose a pickup date" hidden>
                            <div class="date-picker-head">
                                <button class="calendar-nav" id="calendar-prev" type="button" aria-label="Previous month"><?= appIcon('arrow-left') ?></button>
                                <strong id="calendar-month"></strong>
                                <button class="calendar-nav" id="calendar-next" type="button" aria-label="Next month"><?= appIcon('chevron-right') ?></button>
                            </div>
                            <div class="calendar-weekdays" aria-hidden="true"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
                            <div class="calendar-days" id="calendar-days" role="grid" aria-labelledby="calendar-month"></div>
                        </div>
                    </div>
                    <p class="field-error" id="pickup-error" role="alert" <?= $fieldErrors['pickup'] ? '' : 'hidden' ?>><?= appIcon('alert-triangle') ?><span><?= htmlspecialchars($fieldErrors['pickup'] ?? '') ?></span></p>
                </div>
            </div>
            <div class="form-group time-slot-group">
                <label for="pickup_time">Pickup time</label>
                <select class="form-control" id="pickup_time" name="pickup_time" required aria-describedby="pickup-time-help pickup-time-error">
                    <option value="">Choose a time slot</option>
                    <?php foreach ($pickupTimeOptions as $time): ?>
                        <option value="<?= htmlspecialchars($time) ?>" <?= $pickupTimeValue === $time ? 'selected' : '' ?>><?= htmlspecialchars($time) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="field-help" id="pickup-time-help">15-minute slots; last pickup is 16:45.</p>
                <p class="field-error" id="pickup-time-error" role="alert" hidden><?= appIcon('alert-triangle') ?><span>Choose a pickup time slot.</span></p>
            </div>
            <div class="rules-callout" id="pickup-rules" role="note">
                <?= appIcon('calendar') ?>
                <div><strong>Pickup rules</strong><p>Pickups: Mon–Sat, 08:00–17:00. Requests: Mon–Fri, 1–<?= (int) $rules['max_advance_days'] ?> days ahead.</p></div>
            </div>
            </section>

            <section class="booking-step" id="step-2" data-booking-step="2" aria-labelledby="step-2-title">
            <div class="step-head"><div><h2 id="step-2-title">Choose your equipment</h2></div></div>
            <div class="form-group" id="kit-builder"
                 data-api="<?= BOOKING_ROOT ?>/api/student-items.php"
                 data-max-items="<?= (int) $rules['max_items'] ?>"
                 data-can-kit="<?= $profile['can_kit'] ? '1' : '0' ?>"
                 data-selected="<?= htmlspecialchars(json_encode($restoredSelection), ENT_QUOTES, 'UTF-8') ?>">
                <div class="chips" id="type-chips" role="group" aria-label="Filter by equipment type"></div>
                <label for="item-search">Search equipment</label>
                <div class="combo">
                    <input class="form-control" id="item-search" type="text" role="combobox" aria-expanded="false"
                           aria-controls="item-results" aria-autocomplete="list" aria-describedby="search-status items-error" autocomplete="off"
                           placeholder="Search equipment by name or model">
                    <span class="combo-arrow" aria-hidden="true"><?= appIcon('chevron-down') ?></span>
                    <ul id="item-results" class="listbox" role="listbox" aria-label="Available equipment" hidden></ul>
                </div>
                <div class="hint" id="search-status" role="status" aria-live="polite"></div>
                <div class="kit-head">
                    <strong>Your items</strong><span class="hint" id="item-count"></span>
                </div>
                <ul class="picked" id="picked-items" aria-label="Selected equipment"></ul>
                <div id="picked-inputs"></div>
                <p class="field-error" id="items-error" role="alert" <?= $fieldErrors['items'] ? '' : 'hidden' ?>><?= appIcon('alert-triangle') ?><span><?= htmlspecialchars($fieldErrors['items'] ?? '') ?></span></p>
            </div>
            </section>

            <section class="booking-step" id="step-3" data-booking-step="3" aria-labelledby="step-3-title">
            <div class="step-head"><div><h2 id="step-3-title">Review your request</h2></div></div>
            <div class="form-group">
                <label for="purpose">Purpose of borrowing</label>
                <textarea class="form-control" id="purpose" name="purpose" required maxlength="500" rows="3" aria-describedby="purpose-error" placeholder="e.g. Filming my documentary assignment"><?= htmlspecialchars($_POST['purpose'] ?? '') ?></textarea>
                <p class="field-error" id="purpose-error" role="alert" <?= $fieldErrors['purpose'] ? '' : 'hidden' ?>><?= appIcon('alert-triangle') ?><span><?= htmlspecialchars($fieldErrors['purpose'] ?? '') ?></span></p>
            </div>
            </section>
            <?php endif; ?>
            </div>
        </form>

        <aside class="side">
        <?php if (!$eligibility): ?>
        <section class="card summary" aria-label="Your selection">
            <div class="card-body">
                <h2 class="summary-title">Your selection</h2>
                <dl class="summary-list">
                    <div><dt>Kit</dt><dd id="sum-kit">—</dd></div>
                    <div><dt>Pickup</dt><dd id="sum-pickup">Not chosen</dd></div>
                    <div><dt>Loan period</dt><dd><?= htmlspecialchars($loanText) ?></dd></div>
                    <div><dt>Items</dt><dd id="sum-items">0</dd></div>
                </dl>
                <button class="btn btn-primary btn-block submit-button" type="submit" form="booking-form" disabled>Send booking request</button>
                <p class="submit-reason hint" id="submit-reason">Add at least one item and choose a pickup time.</p>
            </div>
        </section>
        <?php endif; ?>

        </aside>
    </div>
    <?php if (!$eligibility): ?>
    <div class="mobile-booking-bar" aria-label="Booking summary">
        <div class="mobile-booking-copy">
            <strong id="mobile-items">0 items</strong>
            <span class="submit-reason" id="mobile-submit-reason">Add at least one item and choose a pickup time.</span>
        </div>
        <button class="btn btn-primary submit-button" type="submit" form="booking-form" disabled>Send booking request</button>
    </div>
    <?php endif; ?>
</main>
<script src="<?= BOOKING_ROOT ?>/assets/js/student_booking.js?v=<?= filemtime(__DIR__ . '/assets/js/student_booking.js') ?>" defer></script>
<?php include __DIR__ . '/student/partials/footer.php'; ?>
