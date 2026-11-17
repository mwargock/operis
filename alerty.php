<?php
/**
 * ALERTY (e-mail / SMS) – wspólne dla wszystkich modułów.
 * Włącza i konfiguruje wyłącznie administrator główny (Ustawienia systemu → Alerty).
 * Odbiorców podaje się w ustawieniach oddziału.
 * Reguły rdzenia: terminal bez kontaktu, wygasające uprawnienia UDT.
 * Reguły modułów: hook <prefiks>_alerty(array $reguly): array (np. przekroczony czas załadunku, długi przestój).
 * Uruchamianie: automatycznie przy synchronizacji terminali i odświeżaniu panelu (nie częściej niż co 60 s)
 * oraz przez CRON: cron.php?klucz=...&tryb=alerty (zalecane co 5–10 min).
 */
declare(strict_types=1);

const ALERT_TYPY = ['czas' => 'Przekroczony czas załadunku', 'terminal' => 'Terminal bez kontaktu', 'przestoj' => 'Długi przestój', 'udt' => 'Uprawnienia UDT', 'test' => 'Test'];

function alertSet(): array { $s = getSet('alerty'); $s['reguly'] = array_replace(defaultsGlobal()['alerty']['reguly'], (array) ($s['reguly'] ?? [])); return $s; }

/** Ustawienia do wysłania do panelu – bez tokenu bramki SMS */
function alertSetPublic(): array { $s = alertSet(); $s['smsTokenSet'] = $s['smsToken'] !== '' ? 1 : 0; $s['smsToken'] = ''; return $s; }

function alertSave($v): void {
    $old = alertSet(); $r = (array) ($v['reguly'] ?? []);
    $tok = trim((string) ($v['smsToken'] ?? '')); if ($tok === '') $tok = $old['smsToken']; if (!empty($v['smsTokenUsun'])) $tok = '';
    $n = ['wlaczone' => !empty($v['wlaczone']) ? 1 : 0, 'email' => !empty($v['email']) ? 1 : 0, 'sms' => !empty($v['sms']) ? 1 : 0,
        'smsToken' => str($tok, 200), 'smsNadawca' => preg_replace('/[^A-Za-z0-9 .-]/', '', str($v['smsNadawca'] ?? '', 11)),
        'reguly' => ['czas' => !empty($r['czas']) ? 1 : 0, 'terminal' => !empty($r['terminal']) ? 1 : 0, 'przestoj' => !empty($r['przestoj']) ? 1 : 0,
            'przestojMin' => max(5, min(1440, (int) ($r['przestojMin'] ?? 30))), 'udt' => !empty($r['udt']) ? 1 : 0, 'udtDni' => max(1, min(90, (int) ($r['udtDni'] ?? 14)))]];
    if ($n['wlaczone'] && !$n['email'] && !$n['sms']) fail('Wybierz co najmniej jeden kanał: e-mail lub SMS.');
    if ($n['sms'] && $n['smsToken'] === '') fail('Do wysyłki SMS podaj token bramki SMSAPI.pl.');
    setSet('alerty', $n);
}

/** Lista telefonów: tylko cyfry, z prefiksem 48 dla numerów 9-cyfrowych */
function alertPhones(string $s): array {
    $o = [];
    foreach (preg_split('/[,;\n]+/', $s) as $p) { $d = preg_replace('/\D/', '', $p); if ($d === '') continue; if (strlen($d) === 9) $d = '48' . $d; if (strlen($d) >= 11 && strlen($d) <= 15) $o[] = $d; }
    return array_values(array_unique($o));
}
function alertEmails(string $s): array { return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', $s)), fn($e) => (bool) filter_var($e, FILTER_VALIDATE_EMAIL)))); }

function plAscii(string $s): string { return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z', '–' => '-', '·' => '-', '…' => '...']); }

/** Wysyłka SMS przez SMSAPI.pl. Zwraca true albo opis błędu. */
function smsSend(array $to, string $txt) {
    $s = alertSet(); if ($s['smsToken'] === '') return 'brak tokenu bramki SMS';
    if (!$to) return 'brak numerów';
    $txt = mb_substr(plAscii($txt), 0, 459);
    $f = ['to' => implode(',', $to), 'message' => $txt, 'format' => 'json', 'encoding' => 'utf-8'];
    if ($s['smsNadawca'] !== '') $f['from'] = $s['smsNadawca'];
    $body = http_build_query($f); $hdr = "Authorization: Bearer " . $s['smsToken'] . "\r\nContent-Type: application/x-www-form-urlencoded\r\n";
    $res = null;
    if (function_exists('curl_init')) {
        $c = curl_init('https://api.smsapi.pl/sms.do');
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $s['smsToken'], 'Content-Type: application/x-www-form-urlencoded']]);
        $res = curl_exec($c); $err = curl_error($c); curl_close($c); if ($res === false) return 'błąd połączenia z bramką SMS: ' . $err;
    } else {
        $res = @file_get_contents('https://api.smsapi.pl/sms.do', false, stream_context_create(['http' => ['method' => 'POST', 'header' => $hdr, 'content' => $body, 'timeout' => 8, 'ignore_errors' => true]]));
        if ($res === false) return 'błąd połączenia z bramką SMS';
    }
    $j = json_decode((string) $res, true);
    if (is_array($j) && isset($j['error'])) return 'bramka SMS: ' . ($j['message'] ?? ('kod ' . $j['error']));
    if (!is_array($j) || !isset($j['count'])) return 'nieoczekiwana odpowiedź bramki SMS';
    return true;
}

/** Wysyłka e-mail (funkcja mail serwera). Zwraca true albo opis błędu. */
function alertMail(array $to, string $subj, string $html) {
    if (!$to) return 'brak adresów';
    $from = trim((string) (getSet('raporty')['nadawca'] ?? ''));
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n" . ($from !== '' ? "From: ESL Operacje <$from>\r\n" : '');
    $sb = '=?UTF-8?B?' . base64_encode($subj) . '?=';
    if (!function_exists('mail') || !@mail(implode(',', $to), $sb, $html, $headers)) return 'serwer nie wysłał wiadomości (mail) – sprawdź adres nadawcy';
    return true;
}

function alertHtml(string $lokName, array $items): string {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $o = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#101b2b;max-width:640px"><h2 style="color:#b32626;margin:0 0 6px">⚠ Alert – ' . $h($lokName) . '</h2><table cellspacing="0" style="border-collapse:collapse;width:100%">';
    foreach ($items as $a) $o .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #e6ebf1;font-size:13px;color:#4b5a6e;white-space:nowrap">' . date('H:i', (int) ($a['kiedy'] / 1000)) . '</td><td style="padding:8px 10px;border-bottom:1px solid #e6ebf1;font-size:14px"><b>' . $h(ALERT_TYPY[$a['typ']] ?? $a['typ']) . '</b><br>' . $h($a['tresc']) . '</td></tr>';
    return $o . '</table><p style="color:#7d8a9c;font-size:12px">Wiadomość wysłana automatycznie przez ESL Operacje. Alerty konfiguruje administrator główny.</p></div>';
}

/** Kandydaci na alerty (rdzeń + moduły) */
function alertCandidates(array $reg): array {
    $c = []; $now = nowMs(); $day = 86400000;
    if (!empty($reg['terminal'])) {
        foreach (all('SELECT * FROM terminale WHERE aktywny=1 AND token_hash IS NOT NULL AND ostatnio IS NOT NULL') as $t) {
            $o = lokRow($t['oddzial']); if (!$o) continue; $lim = (int) (lokSet($o)['limity']['offlineMin'] ?? 5); $el = $now - (int) $t['ostatnio'];
            if ($el > $lim * 60000 && $el < $day) $c[] = ['lok' => $t['oddzial'], 'typ' => 'terminal', 'klucz' => 'term:' . $t['id'] . ':' . $t['ostatnio'],
                'tresc' => 'Terminal ' . $t['nazwa'] . ' bez kontaktu od ' . date('H:i', (int) ((int) $t['ostatnio'] / 1000)) . ' (' . round($el / 60000) . ' min)' . ($t['kolejka'] ? ', niewysłane dane: ' . $t['kolejka'] : '') . '.'];
        }
    }
    if (!empty($reg['udt'])) {
        $lim = date('Y-m-d', time() + (int) $reg['udtDni'] * 86400); $today = date('Y-m-d');
        foreach (all("SELECT * FROM operatorzy WHERE aktywny=1 AND udt_do IS NOT NULL AND udt_do<>'' AND udt_do<=?", [$lim]) as $op) {
            $d = (string) $op['udt_do']; $pl = date('d.m.Y', strtotime($d));
            $c[] = ['lok' => $op['oddzial'], 'typ' => 'udt', 'klucz' => 'udt:' . $op['id'] . ':' . $d . ($d < $today ? ':po' : ''),
                'tresc' => $op['imie'] . ($op['nr'] ? ' (nr ' . $op['nr'] . ')' : '') . ': uprawnienia UDT ' . ($d < $today ? 'wygasły ' . $pl . ' – logowanie na terminalu zablokowane.' : 'wygasają ' . $pl . '.')];
        }
    }
    foreach (modHook('alerty', $reg) as $l) foreach ((array) $l as $x) $c[] = $x;
    return $c;
}

/** Główna procedura: wykrywa nowe zdarzenia i wysyła alerty. Zwraca listę opisów (dla CRON). */
function alertyRun(bool $force = false): array {
    $s = alertSet(); if (empty($s['wlaczone'])) return ['alerty wyłączone'];
    $last = (int) (getSet('alerty_run') ?? 0); if (!$force && nowMs() - $last < 60000) return [];
    setSet('alerty_run', nowMs());
    $new = [];
    foreach (alertCandidates($s['reguly']) as $a) {
        if (one('SELECT id FROM alerty WHERE klucz=?', [$a['klucz']])) continue;
        try { q('INSERT INTO alerty (kiedy,oddzial,typ,klucz,tresc,kanaly,status) VALUES (?,?,?,?,?,?,?)', [nowMs(), $a['lok'], $a['typ'], mb_substr($a['klucz'], 0, 120), $a['tresc'], null, 'oczekuje']); }
        catch (Throwable $e) { continue; }   // równoległe wywołanie – już zapisany
        $a['id'] = (int) db()->lastInsertId(); $a['kiedy'] = nowMs(); $new[$a['lok']][] = $a;
    }
    $log = [];
    foreach ($new as $lok => $items) {
        $o = lokRow((string) $lok); $u = $o ? lokSet($o) : defaultsLok(); $nm = $o['nazwa'] ?? (string) $lok;
        $em = alertEmails((string) ($u['alerty']['email'] ?? '')); $tel = alertPhones((string) ($u['alerty']['sms'] ?? ''));
        $st = []; $kan = [];
        if ($s['email']) { if ($em) { $r = alertMail($em, 'ESL – alert: ' . $nm . ' (' . count($items) . ')', alertHtml($nm, $items)); $st[] = 'e-mail: ' . ($r === true ? 'wysłano' : $r); $kan[] = 'email'; } else $st[] = 'e-mail: brak adresów w ustawieniach oddziału'; }
        if ($s['sms']) { if ($tel) { $txt = 'ESL ' . $nm . ': ' . implode(' | ', array_map(fn($a) => $a['tresc'], $items)); $r = smsSend($tel, $txt); $st[] = 'SMS: ' . ($r === true ? 'wysłano' : $r); $kan[] = 'sms'; } else $st[] = 'SMS: brak numerów w ustawieniach oddziału'; }
        $status = implode('; ', $st) ?: 'brak kanałów';
        foreach ($items as $a) q('UPDATE alerty SET kanaly=?, status=? WHERE id=?', [implode(',', $kan), mb_substr($status, 0, 200), $a['id']]);
        $log[] = $nm . ': ' . count($items) . ' alert(y) – ' . $status;
    }
    q('DELETE FROM alerty WHERE kiedy<?', [nowMs() - 180 * 86400000]);
    return $log;
}

/** Bezpieczne wywołanie w tle żądania (bez przerywania odpowiedzi przy błędzie) */
function alertyTick(): void { try { alertyRun(false); } catch (Throwable $e) { /* alerty nie mogą blokować pracy */ } }

function alertRow(array $a): array { return ['id' => (int) $a['id'], 'kiedy' => (int) $a['kiedy'], 'lok' => $a['oddzial'], 'typ' => $a['typ'], 'typNazwa' => ALERT_TYPY[$a['typ']] ?? $a['typ'], 'tresc' => $a['tresc'], 'kanaly' => $a['kanaly'], 'status' => $a['status']]; }
