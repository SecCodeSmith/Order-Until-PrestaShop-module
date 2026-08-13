<?php
/**
 * Polish translations for the scmorderuntil module ($this->l() strings used to
 * build the countdown text). Keys follow PrestaShop's legacy module-translation
 * format: '<{modulename}prestashop>' . source . '_' . md5(englishSource).
 * We compute md5() inline so the keys are always correct.
 */
global $_MODULE;
$_MODULE = array();

$m = '<{scmorderuntil}prestashop>scmorderuntil_';

// Day / date phrases (weekday phrases use the correct Polish grammatical case).
$_MODULE[$m . md5('today')] = 'dziś';
$_MODULE[$m . md5('tomorrow')] = 'jutro';
$_MODULE[$m . md5('d')] = 'd';
$_MODULE[$m . md5('on Sunday')] = 'w niedzielę';
$_MODULE[$m . md5('on Monday')] = 'w poniedziałek';
$_MODULE[$m . md5('on Tuesday')] = 'we wtorek';
$_MODULE[$m . md5('on Wednesday')] = 'w środę';
$_MODULE[$m . md5('on Thursday')] = 'w czwartek';
$_MODULE[$m . md5('on Friday')] = 'w piątek';
$_MODULE[$m . md5('on Saturday')] = 'w sobotę';

// Fallback sentence templates (when the per-language TPL_* fields are empty).
$_MODULE[$m . md5('Available. Order by {cutoff}, delivered {when}*')]
    = 'Dostępny. Zamów do {cutoff}, {when} u Ciebie*';
$_MODULE[$m . md5('Order by {cutoff}, ships {shipwhen}, delivered {when}')]
    = 'Zamów do {cutoff}, wysyłka {shipwhen}, dostawa {when}';

// A few back-office / display strings.
$_MODULE[$m . md5('SCM Order Until — Delivery Countdown')]
    = 'SCM Order Until — Odliczanie do wysyłki';
$_MODULE[$m . md5('Fast dispatch')] = 'Szybka wysyłka';
