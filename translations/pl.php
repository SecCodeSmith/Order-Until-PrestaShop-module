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
// Open = before today's cutoff; closed = after it (ships the next working day).
$_MODULE[$m . md5('Available, order by {cutoff}, delivery {when}*')]
    = 'Dostępny, zamów do {cutoff}, dostawa {when}*';
$_MODULE[$m . md5('Available, order before {shipwhen} {cutoff}*')]
    = 'Dostępny, zamów {shipwhen} do {cutoff}*';

// A few back-office / display strings.
$_MODULE[$m . md5('SCM Order Until — Delivery Countdown')]
    = 'SCM Order Until — Odliczanie do wysyłki';
$_MODULE[$m . md5('Fast dispatch')] = 'Szybka wysyłka';

// Self-update panel (Back Office).
$_MODULE[$m . md5('Module updates')] = 'Aktualizacje modułu';
$_MODULE[$m . md5('Installed version: %s')] = 'Zainstalowana wersja: %s';
$_MODULE[$m . md5('Updates are pulled from %s.')] = 'Aktualizacje pobierane są z %s.';
$_MODULE[$m . md5('A new version is available: %s.')] = 'Dostępna jest nowa wersja: %s.';
$_MODULE[$m . md5('Release notes')] = 'Informacje o wydaniu';
$_MODULE[$m . md5('Download & install %s')] = 'Pobierz i zainstaluj %s';
$_MODULE[$m . md5('You are running the latest version.')] = 'Masz najnowszą wersję.';
$_MODULE[$m . md5('Check for updates')] = 'Sprawdź aktualizacje';
$_MODULE[$m . md5('Module updated to %s.')] = 'Zaktualizowano moduł do %s.';
$_MODULE[$m . md5('You are already running the latest version.')] = 'Masz już najnowszą wersję.';
$_MODULE[$m . md5('Could not reach GitHub to check for updates.')]
    = 'Nie udało się połączyć z GitHub, aby sprawdzić aktualizacje.';
