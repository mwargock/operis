<?php
// Zadanie cykliczne (home.pl: Panel -> Zadania cykliczne / CRON), uruchamiane raz dziennie np. o 06:30:
//   https://TWOJA-DOMENA/zaladunki/cron.php?klucz=TWOJ_INSTALL_KEY
// Wysyła raporty dzienne (codziennie) i tygodniowe (w poniedziałki) oraz wykonuje retencję danych.
// Alerty (e-mail/SMS) – osobne zadanie co 5–10 min:
//   https://TWOJA-DOMENA/zaladunki/cron.php?klucz=TWOJ_INSTALL_KEY&tryb=alerty
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['klucz'] ?? ($argv[1] ?? '')) !== cfg()['install_key']) { http_response_code(403); exit("Brak dostępu\n"); }
ensureSchema();
if (($_GET['tryb'] ?? ($argv[2] ?? '')) === 'alerty') { $l = alertyRun(true); echo ($l ? implode("\n", $l) : 'Brak nowych alertów') . "\n"; exit; }
$log = [];
foreach (modHook('cron') as $id => $l) foreach ((array) $l as $x) $log[] = '[' . $id . '] ' . $x;
$log[] = 'Retencja: usunięto rekordów ' . array_sum(modHook('retencja'));
logAction('cron', 'cron', null, implode(' | ', $log));
echo implode("\n", $log) . "\n";
