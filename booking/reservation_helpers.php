<?php

function reservationParseLocalDateTime(string $value): ?string
{
    $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Africa/Nairobi'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$local || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        return null;
    }

    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function reservationFormatLocalDateTime(string $value): string
{
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Africa/Nairobi'))
        ->format('d M Y H:i');
}

function reservationLoadAssetIds(PDO $db, string $type, int $itemId): array
{
    if ($type === 'single') {
        $stmt = $db->prepare("
            SELECT asset_id FROM assets
            WHERE asset_id = ? AND asset_type = 'equipment' AND status != 'retired'
        ");
        $stmt->execute([$itemId]);
        $assetId = $stmt->fetchColumn();
        return $assetId ? [(int) $assetId] : [];
    }

    if ($type !== 'kit') {
        return [];
    }

    $stmt = $db->prepare("
        SELECT a.asset_id
        FROM kits k
        JOIN kit_components kc ON kc.kit_id = k.kit_id
        JOIN assets a ON a.asset_id = kc.asset_id
        WHERE k.kit_id = ?
          AND k.status NOT IN ('maintenance', 'incomplete')
          AND a.asset_type = 'equipment'
          AND a.status != 'retired'
        ORDER BY a.asset_id
        FOR UPDATE
    ");
    $stmt->execute([$itemId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function reservationEnsureAvailable(PDO $db, array $assetIds, string $start, string $end, ?int $excludeReservationId = null): void
{
    $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
    sort($assetIds);
    if (!$assetIds) {
        throw new InvalidArgumentException('The selected item or kit has no reservable equipment.');
    }

    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
    $lock = $db->prepare("
        SELECT asset_id, asset_code, name, status, condition_rating
        FROM assets
        WHERE asset_id IN ($placeholders)
        ORDER BY asset_id
        FOR UPDATE
    ");
    $lock->execute($assetIds);
    $assets = $lock->fetchAll();
    if (count($assets) !== count($assetIds)) {
        throw new InvalidArgumentException('One or more selected assets are no longer available.');
    }

    $reservationConflictSql = "
        SELECT 1
        FROM reservation_items ri
        JOIN reservations r ON r.reservation_id = ri.reservation_id
        WHERE ri.asset_id = ?
          AND r.status IN ('approved', 'issued')
          AND r.requested_start < ?
          AND r.requested_end > ?
    ";
    if ($excludeReservationId !== null) {
        $reservationConflictSql .= ' AND r.reservation_id != ?';
    }
    $reservationConflict = $db->prepare($reservationConflictSql . ' LIMIT 1');
    $transactionConflict = $db->prepare("
        SELECT 1
        FROM transactions
        WHERE asset_id = ?
          AND returned_at IS NULL
          AND checkout_at < ?
          AND (due_at > ? OR due_at < UTC_TIMESTAMP())
        LIMIT 1
    ");
    $activeLoan = $db->prepare("
        SELECT 1 FROM transactions
        WHERE asset_id = ? AND returned_at IS NULL
        LIMIT 1
    ");

    $conflicts = [];
    foreach ($assets as $asset) {
        if (!in_array($asset['status'], ['available', 'checked_out'], true) || $asset['condition_rating'] === 'damaged') {
            $conflicts[] = $asset['asset_code'] . ' (' . $asset['status'] . ')';
            continue;
        }

        if ($asset['status'] === 'checked_out') {
            $activeLoan->execute([$asset['asset_id']]);
            if (!$activeLoan->fetchColumn()) {
                $conflicts[] = $asset['asset_code'] . ' has an inconsistent checked-out status';
                continue;
            }
        }

        $conflictParams = [$asset['asset_id'], $end, $start];
        if ($excludeReservationId !== null) {
            $conflictParams[] = $excludeReservationId;
        }
        $reservationConflict->execute($conflictParams);
        $transactionConflict->execute([$asset['asset_id'], $end, $start]);
        if ($reservationConflict->fetchColumn() || $transactionConflict->fetchColumn()) {
            $conflicts[] = $asset['asset_code'] . ' (' . $asset['name'] . ')';
        }
    }

    if ($conflicts) {
        throw new InvalidArgumentException('Unavailable during the requested period: ' . implode(', ', $conflicts) . '.');
    }
}

function reservationLog(PDO $db, int $reservationId, string $event, ?int $staffId = null, ?string $detail = null): void
{
    $db->prepare("
        INSERT INTO reservation_events (reservation_id, event_type, staff_id, detail)
        VALUES (?, ?, ?, ?)
    ")->execute([$reservationId, $event, $staffId, $detail]);
}

// ============================================================
// Student online booking helpers
// ============================================================

/** Booking rules. Edit the values here to change pickup hours or limits. */
function studentBookingRules(): array
{
    return [
        'min_days_ahead'    => 1,              // book the day before: earliest pickup is tomorrow (any working hour)
        'max_advance_days'  => 14,             // latest pickup: 14 days ahead
        'open_time'         => '08:00',        // pickup hours (Africa/Nairobi)
        'close_time'        => '17:00',
        'slot_minutes'      => 15,             // pickup slots are offered every 15 minutes
        'open_days'         => [1, 2, 3, 4, 5, 6], // pickup days, ISO weekdays: 1 = Monday ... 7 = Sunday
        'booking_days'      => [1, 2, 3, 4, 5],    // days students may submit a booking (no Saturday/Sunday)
        'max_items'         => 10,             // total items in one kit
        'max_loan_hours'    => 336,            // cap on the computed window (14 days)
    ];
}

/** Returns an error message, or null when the local pickup time is acceptable. */
function studentPickupError(string $local, array $rules): ?string
{
    $tz = new DateTimeZone('Africa/Nairobi');
    $pickup = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $tz);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$pickup || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        return 'Choose a valid pickup date and time.';
    }
    $now = new DateTimeImmutable('now', $tz);
    if (!in_array((int) $now->format('N'), $rules['booking_days'], true)) {
        return 'Online booking is closed on Saturdays and Sundays. Please book on a weekday.';
    }
    $earliestDay = $now->setTime(0, 0)->modify('+' . $rules['min_days_ahead'] . ' days');
    if ($pickup < $earliestDay) {
        return 'Bookings must be made at least ' . $rules['min_days_ahead'] . ' day before pickup. Choose '
            . $earliestDay->format('D, d M') . ' or later.';
    }
    $latestPickupDate = $now->setTime(0, 0)->modify('+' . $rules['max_advance_days'] . ' days');
    if ($pickup->format('Y-m-d') > $latestPickupDate->format('Y-m-d')) {
        return 'You can book up to ' . $rules['max_advance_days'] . ' days ahead.';
    }
    if (!in_array((int) $pickup->format('N'), $rules['open_days'], true)) {
        return 'The inventory office is closed for pickups on that day. Choose Monday to Saturday.';
    }
    $time = $pickup->format('H:i');
    if ($time < $rules['open_time'] || $time >= $rules['close_time']) {
        return 'Pickup must be between ' . $rules['open_time'] . ' and ' . $rules['close_time'] . '.';
    }
    if ((int) $pickup->format('i') % (int) $rules['slot_minutes'] !== 0) {
        return 'Choose one of the available 15-minute pickup slots.';
    }
    return null;
}

/** End of the reserved window = pickup + the student's tier loan period (UTC strings). */
function studentWindowEnd(string $startUtc, int $loanHours): string
{
    $rules = studentBookingRules();
    $hours = max(1, min($loanHours, $rules['max_loan_hours']));
    return (new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))
        ->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s');
}

/** UTC datetime -> value for <input type="datetime-local"> in Nairobi time. */
function reservationLocalInputValue(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('Y-m-d\TH:i');
}

/** Display title for a reservation row (needs asset/kit joins on the row). */
function reservationDisplayTitle(array $row): string
{
    if ($row['reservation_type'] === 'kit') {
        return ($row['kit_code'] ?? '') . ' — ' . ($row['kit_name'] ?? '');
    }
    if ($row['reservation_type'] === 'custom') {
        return ($row['kit_name'] ?: 'Custom kit') . ' (student kit)';
    }
    return ($row['asset_code'] ?? '') . ' — ' . ($row['asset_name'] ?? '');
}

/**
 * SQL condition (alias `a` = assets) true when an asset is bookable for a window.
 * Mirrors the rules in reservationEnsureAvailable(). Bind: [$end, $start, $end, $start].
 */
function reservationFreeCondition(): string
{
    return "(a.status IN ('available','checked_out') AND a.condition_rating <> 'damaged'
        AND NOT EXISTS (
            SELECT 1 FROM reservation_items ri
            JOIN reservations r ON r.reservation_id = ri.reservation_id
            WHERE ri.asset_id = a.asset_id AND r.status IN ('approved','issued')
              AND r.requested_start < ? AND r.requested_end > ?)
        AND NOT EXISTS (
            SELECT 1 FROM transactions t
            WHERE t.asset_id = a.asset_id AND t.returned_at IS NULL
              AND t.checkout_at < ? AND (t.due_at > ? OR t.due_at < UTC_TIMESTAMP())))";
}

/**
 * Equipment grouped by name + model with availability for a window.
 * Returns key => [key, name, detail, total, free, free_ids[]]; optional text filter.
 */
function studentItemGroups(PDO $db, string $startUtc, string $endUtc, string $filter = ''): array
{
    $stmt = $db->prepare("
        SELECT a.asset_id, a.asset_code, a.name, a.model, a.manufacturer,
               " . reservationFreeCondition() . " AS is_free
        FROM assets a
        WHERE a.asset_type = 'equipment' AND a.status NOT IN ('retired','missing')
        ORDER BY a.name, a.asset_code
    ");
    $stmt->execute([$endUtc, $startUtc, $endUtc, $startUtc]);

    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = substr(md5(strtolower(trim($row['name'])) . '|' . strtolower(trim((string) $row['model']))), 0, 16);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key'      => $key,
                'name'     => $row['name'],
                'detail'   => trim(($row['manufacturer'] ?? '') . ' ' . ($row['model'] ?? '')),
                'total'    => 0,
                'free'     => 0,
                'free_ids' => [],
                'haystack' => '',
            ];
        }
        $groups[$key]['total']++;
        $groups[$key]['haystack'] .= ' ' . strtolower($row['asset_code']);
        if ((int) $row['is_free']) {
            $groups[$key]['free']++;
            $groups[$key]['free_ids'][] = (int) $row['asset_id'];
        }
    }

    $filter = strtolower(trim($filter));
    if ($filter !== '') {
        $groups = array_filter($groups, function ($g) use ($filter) {
            return strpos(strtolower($g['name'] . ' ' . $g['detail']) . $g['haystack'], $filter) !== false;
        });
        uasort($groups, function ($a, $b) use ($filter) {
            $aStarts = strpos(strtolower($a['name']), $filter) === 0 ? 0 : 1;
            $bStarts = strpos(strtolower($b['name']), $filter) === 0 ? 0 : 1;
            return [$aStarts, $a['name']] <=> [$bStarts, $b['name']];
        });
    }
    return $groups;
}

/** Marks an issued reservation returned once none of its transactions are outstanding. */
function completeReservationReturn(PDO $db, ?int $reservationId, int $staffId): void {
    if (!$reservationId) return;

    $remaining = $db->prepare("
        SELECT COUNT(*) FROM transactions
        WHERE reservation_id = ? AND returned_at IS NULL
    ");
    $remaining->execute([$reservationId]);
    if ((int) $remaining->fetchColumn() === 0) {
        $db->prepare("
            UPDATE reservations
            SET status = 'returned', returned_at = UTC_TIMESTAMP()
            WHERE reservation_id = ? AND status = 'issued'
        ")->execute([$reservationId]);
        reservationLog($db, $reservationId, 'returned', $staffId);
    }
}
