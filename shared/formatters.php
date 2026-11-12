<?php

function formatNumber($value, int $decimals = 0): string
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('A numeric value is required.');
    }

    return number_format((float) $value, $decimals, '.', ',');
}

function formatCurrency($amount, bool $isFine = false): string
{
    return 'KES ' . formatNumber($amount, $isFine ? 2 : 0);
}

function formatDate($value, bool $includeTime = false): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    if ($value instanceof DateTimeInterface) {
        $date = DateTimeImmutable::createFromInterface($value);
    } else {
        $date = new DateTimeImmutable((string) $value);
    }

    return $date->format($includeTime ? 'j M Y, H:i' : 'j M Y');
}

function formatMark($value): string
{
    return formatNumber($value, 1);
}

function formatPercentage($value): string
{
    $formatted = formatNumber($value, 1);
    return rtrim(rtrim($formatted, '0'), '.') . '%';
}

function pluralizeCount($count, string $singular, ?string $plural = null): string
{
    if (!is_numeric($count)) {
        throw new InvalidArgumentException('A numeric count is required.');
    }

    $count = (int) $count;
    if (abs($count) === 1) {
        $word = $singular;
    } elseif ($plural !== null) {
        $word = $plural;
    } elseif (preg_match('/[^aeiou]y$/i', $singular)) {
        $word = substr($singular, 0, -1) . 'ies';
    } elseif (preg_match('/(s|x|z|ch|sh)$/i', $singular)) {
        $word = $singular . 'es';
    } else {
        $word = $singular . 's';
    }

    return formatNumber($count) . ' ' . $word;
}

function formatTitleCase(string $value): string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    if ($value === '') {
        return '';
    }

    return function_exists('mb_convert_case')
        ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value));
}

function formatPersonName(string $value): string
{
    $value = trim($value);
    if (preg_match('/^([^,]+),\s*(.+)$/u', $value, $parts)) {
        $value = trim($parts[2]) . ' ' . trim($parts[1]);
    }

    return formatTitleCase($value);
}
