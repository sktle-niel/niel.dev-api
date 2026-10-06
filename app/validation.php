<?php
// Input validation helpers — the SERVER is the source of truth.
//
// These mirror the frontend rules in codekathaxwebsite/src/lib/validation.ts.
// Goal: reject obvious junk (keyboard-mashing like "wakjdk", letters in a phone
// field, numbers in a name) without rejecting real names / brands.

declare(strict_types=1);

const CKX_VOWELS = [
    'a', 'e', 'i', 'o', 'u', 'y',
    'á', 'é', 'í', 'ó', 'ú', 'à', 'è', 'ì', 'ò', 'ù', 'ä', 'ë', 'ï', 'ö', 'ü',
];

function ckx_letters_only(string $s): string
{
    return (string) preg_replace('/[^\p{L}]/u', '', $s);
}

function ckx_is_vowel(string $ch): bool
{
    return in_array(mb_strtolower($ch, 'UTF-8'), CKX_VOWELS, true);
}

/** Longest run of consecutive consonants (keyboard-mash signal). */
function ckx_max_consonant_run(string $s): int
{
    $run = 0;
    $max = 0;
    $len = mb_strlen($s, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($s, $i, 1, 'UTF-8');
        if (preg_match('/\p{L}/u', $ch) && !ckx_is_vowel($ch)) {
            $run++;
            if ($run > $max) {
                $max = $run;
            }
        } else {
            $run = 0;
        }
    }
    return $max;
}

/** Share of letters that are vowels (real words sit around 0.3-0.5). */
function ckx_vowel_ratio(string $s): float
{
    $letters = ckx_letters_only($s);
    $len = mb_strlen($letters, 'UTF-8');
    if ($len === 0) {
        return 0.0;
    }
    $v = 0;
    for ($i = 0; $i < $len; $i++) {
        if (ckx_is_vowel(mb_substr($letters, $i, 1, 'UTF-8'))) {
            $v++;
        }
    }
    return $v / $len;
}

function ckx_token_looks_fake(string $token, int $runLimit): bool
{
    $letters = ckx_letters_only($token);
    if (mb_strlen($letters, 'UTF-8') < 4) {
        return false;
    }
    if (ckx_max_consonant_run($token) >= $runLimit) {
        return true;
    }
    if (ckx_vowel_ratio($token) === 0.0) {
        return true;
    }
    return false;
}

function ckx_validate_name(string $raw): ?string
{
    $s = trim($raw);
    $len = mb_strlen($s, 'UTF-8');
    if ($len < 2) {
        return 'Please enter your full name.';
    }
    if ($len > 120) {
        return 'That name looks too long.';
    }
    if (preg_match('/[0-9]/', $s)) {
        return 'Names should not contain numbers.';
    }
    if (preg_match("/[^\p{L}\s.'’-]/u", $s)) {
        return 'Please use letters only.';
    }
    if (ckx_vowel_ratio($s) === 0.0) {
        return 'Please enter a real name.';
    }
    foreach (preg_split('/\s+/u', $s) ?: [] as $tok) {
        if (ckx_token_looks_fake($tok, 4)) {
            return "That doesn't look like a real name.";
        }
    }
    return null;
}

function ckx_validate_email(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return 'Please enter your email.';
    }
    if (mb_strlen($s, 'UTF-8') > 160) {
        return 'That email looks too long.';
    }
    if (!filter_var($s, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    return null;
}

/** "Phone / Messenger": a phone number OR a messenger link / @handle. */
function ckx_validate_contact(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return null; // optional
    }
    if (mb_strlen($s, 'UTF-8') > 60) {
        return "That's too long.";
    }
    if (preg_match('#^https?://#i', $s)) {
        return null;
    }
    if (preg_match('/^@[\w.]+$/', $s)) {
        return null;
    }
    if (preg_match('/(facebook|fb\.com|m\.me|messenger|t\.me|wa\.me|viber)/i', $s)) {
        return null;
    }
    $digits = (string) preg_replace('/\D/', '', $s);
    if (strlen($digits) >= 7 && strlen($digits) <= 15 && preg_match('/^[0-9+()\-\s]+$/', $s)) {
        return null;
    }
    return 'Enter a valid phone number or Messenger link.';
}

/** Free text (business name, project title, industry, company...). */
function ckx_validate_text(string $raw, string $label, int $min = 2, int $max = 160, int $runLimit = 5): ?string
{
    $s = trim($raw);
    $len = mb_strlen($s, 'UTF-8');
    if ($len < $min) {
        return "Please enter $label.";
    }
    if ($len > $max) {
        return "That $label looks too long.";
    }
    if (!preg_match('/\p{L}/u', $s)) {
        return "Please enter a valid $label.";
    }
    foreach (preg_split('/\s+/u', $s) ?: [] as $tok) {
        if (ckx_token_looks_fake($tok, $runLimit)) {
            return "That $label doesn't look right.";
        }
    }
    return null;
}

function ckx_validate_description(string $raw): ?string
{
    $s = trim($raw);
    $len = mb_strlen($s, 'UTF-8');
    if ($len < 15) {
        return 'Please add a little more detail (at least 15 characters).';
    }
    if ($len > 4000) {
        return "That's too long.";
    }
    if (ckx_vowel_ratio($s) < 0.2) {
        return 'Please write a clear description.';
    }
    return null;
}

function ckx_validate_custom_budget(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return 'Please enter your budget.';
    }
    if (mb_strlen($s, 'UTF-8') > 120) {
        return "That's too long.";
    }
    if (!preg_match('/\d/', $s)) {
        return 'Please include an amount, e.g. 4,500.';
    }
    return null;
}

/** A portfolio project link: must be a well-formed https:// URL. */
function ckx_validate_url(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return 'Please enter a link.';
    }
    if (mb_strlen($s, 'UTF-8') > 500) {
        return "That link's too long.";
    }
    if (!preg_match('#^https://#i', $s)) {
        return 'Please enter a link starting with https://';
    }
    if (!filter_var($s, FILTER_VALIDATE_URL)) {
        return 'Please enter a valid link.';
    }
    return null;
}

/** Optional downpayment the client proposes: numbers only, at least PHP 1,000. */
function ckx_validate_downpayment(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return null; // optional — the client can skip it
    }
    if (!preg_match('/^\d+$/', $s)) {
        return 'Numbers only, please.';
    }
    if ((int) $s < 1000) {
        return 'The lowest downpayment is PHP 1,000.';
    }
    return null;
}
