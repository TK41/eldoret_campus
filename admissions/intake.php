<?php
// Shared automatic admissions intake calendar.
function getNextAdmissionsIntake($today = null): array {
    $today = $today ?: new DateTimeImmutable('today');
    $year = (int) $today->format('Y');
    $currentMonth = (int) $today->format('n');

    foreach ([3 => 'March', 5 => 'May', 9 => 'September'] as $month => $name) {
        if ($month >= $currentMonth) {
            return ['month' => $month, 'name' => $name, 'year' => $year, 'label' => $name . ' ' . $year];
        }
    }

    return ['month' => 3, 'name' => 'March', 'year' => $year + 1, 'label' => 'March ' . ($year + 1)];
}
