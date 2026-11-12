<?php
require_once __DIR__ . '/icons.php';

function renderDashboardStatCard(array $card): void
{
    foreach (['label', 'value', 'summary', 'href', 'tone'] as $requiredKey) {
        if (!array_key_exists($requiredKey, $card)) {
            throw new InvalidArgumentException('Missing dashboard stat card field: ' . $requiredKey);
        }
    }

    $tone = (string) $card['tone'];
    if (!in_array($tone, ['neutral', 'info', 'success', 'warning', 'danger'], true)) {
        throw new InvalidArgumentException('Invalid dashboard stat card tone: ' . $tone);
    }

    $classes = ['stat-card', 'dashboard-stat-card', 'dashboard-stat-card--' . $tone];
    $valueClass = !empty($card['compactValue']) ? ' dashboard-stat-value--compact' : '';
    if (!empty($card['problem']) && empty($card['active'])) {
        $classes[] = 'dashboard-stat-card--calm';
    }

    $escape = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    echo '<a class="' . $escape(implode(' ', $classes)) . '" href="' . $escape($card['href']) . '">';
    echo '<span class="stat-label dashboard-stat-label">' . $escape($card['label']) . '</span>';
    echo '<span class="stat-value dashboard-stat-value' . $valueClass . '">' . $escape($card['value']) . '</span>';
    echo '<span class="stat-sub dashboard-stat-sub">' . $escape($card['summary']) . '</span>';

    if (isset($card['progress'])) {
        $progress = $card['progress'];
        if (!is_array($progress) || !isset($progress['value'], $progress['label']) || !is_numeric($progress['value'])) {
            throw new InvalidArgumentException('Dashboard stat card progress requires a numeric value and label.');
        }

        $progressValue = round(max(0, min(100, (float) $progress['value'])), 1);
        echo '<span class="dashboard-stat-progress-label">' . $escape($progress['label']) . '</span>';
        echo '<span class="dashboard-stat-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $escape($progressValue) . '" aria-label="' . $escape($progress['label']) . '">';
        echo '<span class="dashboard-stat-progress-fill" style="width:' . $escape($progressValue) . '%"></span>';
        echo '</span>';
    }

    echo '<span class="dashboard-stat-chevron" aria-hidden="true">' . appIcon('chevron-right') . '</span>';
    echo '</a>';
}
