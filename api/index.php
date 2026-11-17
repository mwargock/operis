<?php
// API Rejestru załadunków ESL – terminal (token) i panel administracyjny (sesja).
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

set_exception_handler(function (Throwable $e) { error_log('[ESL API] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine()); fail('Błąd serwera. Spróbuj ponownie.', 500); });
ensureSchema();

$r = (string) ($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
const PRAWA = ['operatorzy', 'korekta_palety', 'korekta_zamknij', 'korekta_usun', 'konfiguracja', 'komunikaty', 'dokumenty', 'terminale', 'eksport', 'dziennik', 'uzytkownicy'];
function roleList(): array { return array_map(function ($x) { $x += ['moduly' => ['zaladunki'], 'nadawana' => in_array($x['id'] ?? '', ['kierownik', 'podglad'], true) ? 1 : 0];
    if (($x['id'] ?? '') === 'oddzial' && is_array($x['prawa'] ?? null) && !array_key_exists('uzytkownicy', $x['prawa'])) $x['prawa']['uzytkownicy'] = true;
    if (($x['zakres'] ?? '') === 'wszystkie') $x['nadawana'] = 0; return $x; }, array_values((array) getSet('role'))); }
/** Rola, którą może nadać administrator oddziału */
function roleAssignable(string $id): bool { if (isMain()) return $id === 'glowny' || roleDef($id) !== null; $r = roleDef($id); return $r && $id !== 'glowny' && !empty($r['nadawana']) && ($r['zakres'] ?? '') !== 'wszystkie'; }
/** Konto, którym może zarządzać bieżący użytkownik */
function userManageable(array $u): bool { if (isMain()) return true; if (!can('uzytkownicy')) return false; $m = me(); if ((int) $u['id'] === (int) $m['id']) return false;
    $od = jd($u['oddzialy'], []); return roleAssignable((string) $u['rola']) && $od && !array_diff($od, $m['oddzialy']); }
function needUsers(): void { if (!isMain() && !can('uzytkownicy')) fail('Brak uprawnień', 403); }
/** Definicja roli (rola „glowny” jest wbudowana i ma pełny dostęp) */
function roleDef(string $id): ?array {
    if ($id === 'glowny') return ['id' => 'glowny', 'nazwa' => 'Administrator główny', 'opis' => 'Pełny dostęp', 'zakres' => 'wszystkie', 'widokDni' => 0, 'limitDni' => 0, 'prawa' => array_fill_keys(PRAWA, true), 'moduly' => modulyIds(), 'wbudowana' => 1];
    foreach (roleList() as $x) if (($x['id'] ?? '') === $id) return $x;
    return null;
}

/* =================== sesja panelu =================== */
function startSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('ESLPANEL');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}
function requirePost(): void {
    global $method;
    if ($method !== 'POST') fail('Wymagane POST', 405);
    if (($_SERVER['HTTP_X_ESL'] ?? '') !== '1') fail('Nieprawidłowe żądanie', 403);
}
function ipCheck(): void { global $ip; if (!ipAllowed($ip, (array) (getSet('bezp')['ipLista'] ?? []))) fail('Dostęp do panelu z tego adresu IP (' . $ip . ') jest zablokowany.', 403); }
function endSession(): void { if (!empty($_SESSION['sid'])) q('UPDATE sesje SET uniewazniona=1 WHERE id=?', [$_SESSION['sid']]); $_SESSION = []; session_destroy(); }
function me(bool $allowMust = false): array {
    static $u = null; if ($u && ($allowMust || empty($_SESSION['must']))) return $u;
    startSession(); ipCheck();
    $id = $_SESSION['uid'] ?? null; if (!$id) fail('Nie zalogowano', 401);
    $s = one('SELECT * FROM sesje WHERE id=?', [$_SESSION['sid'] ?? '']);
    if (!$s || (int) $s['uniewazniona'] === 1) { $_SESSION = []; session_destroy(); fail('Sesja została zakończona', 401); }
    $row = one('SELECT * FROM uzytkownicy WHERE id=? AND aktywny=1', [$id]);
    if (!$row) { endSession(); fail('Konto nieaktywne', 401); }
    $idle = (int) ($_SESSION['idle'] ?? 30);
    if ($idle > 0 && time() - (int) ($_SESSION['t'] ?? 0) > $idle * 60) { endSession(); fail('Sesja wygasła', 401); }
    $_SESSION['t'] = time();
    if (nowMs() - (int) $s['ostatnio'] > 30000) q('UPDATE sesje SET ostatnio=? WHERE id=?', [nowMs(), $s['id']]);
    $row['oddzialy'] = jd($row['oddzialy'], []);
    $rd = roleDef((string) $row['rola']) ?? ['nazwa' => '(rola usunięta)', 'zakres' => 'wybrane', 'widokDni' => 0, 'limitDni' => 0, 'prawa' => []];
    $row['prawa'] = []; foreach (PRAWA as $p) $row['prawa'][$p] = !empty($rd['prawa'][$p]);
    $row['moduly'] = $row['rola'] === 'glowny' ? modulyIds() : array_values(array_intersect((array) ($rd['moduly'] ?? ['zaladunki']), modulyIds()));
    $row['rolaNazwa'] = $rd['nazwa']; $row['zakres'] = $rd['zakres'] ?? 'wybrane'; $row['widokDni'] = (int) ($rd['widokDni'] ?? 0); $row['limit_dni'] = (int) ($rd['limitDni'] ?? 0);
    foreach (['hash', 'totp_secret'] as $k) unset($row[$k]);
    $row['totp_on'] = (int) $row['totp_on'];
    $u = $row;
    if (!empty($_SESSION['must']) && !$allowMust) fail('Wymagana akcja: ' . $_SESSION['must'], 403, ['wymagane' => $_SESSION['must']]);
    return $u;
}
function role(): string { return me()['rola']; }
function isMain(): bool { return role() === 'glowny'; }
function allLoks(): bool { $m = me(); return $m['rola'] === 'glowny' || $m['zakres'] === 'wszystkie'; }
function canMod(string $id): bool { $m = me(); return $m['rola'] === 'glowny' || in_array($id, $m['moduly'], true); }
function needMod(string $id): void { if (!canMod($id)) fail('Brak dostępu do modułu', 403); }
/** Tryb pracy terminala: 'dedykowany' (jeden moduł) albo 'wybor' (operator wybiera moduł przed logowaniem) */
function termTryb(array $t): string { return ($t['tryb'] ?? '') === 'wybor' ? 'wybor' : 'dedykowany'; }
/** Moduły aktywne na terminalu (przypisane, włączone w oddziale i gotowe); w trybie dedykowanym – tylko pierwszy */
function termMods(array $t): array { $l = lokRow($t['oddzial']); $tm = jd($t['moduly'] ?? null, null); if (!is_array($tm)) $tm = ['zaladunki']; $o = $l ? array_values(array_intersect($tm, lokMods($l))) : []; return termTryb($t) === 'wybor' ? $o : array_slice($o, 0, 1); }
/** Walidacja ustawień trybu i modułów terminala */
function termModCfg(array $in): array { $tr = ($in['tryb'] ?? '') === 'wybor' ? 'wybor' : 'dedykowany'; $md = modList((array) ($in['moduly'] ?? ['zaladunki']));
    if (!$md) fail('Wybierz co najmniej jeden moduł terminala.'); if ($tr === 'dedykowany') $md = array_slice($md, 0, 1); return [$tr, $md]; }
function needTermMod(array $t, string $id): void { if (!in_array($id, termMods($t), true)) fail('Moduł nie jest przypisany do tego terminala', 403); }
function modList(array $ids): array { return array_values(array_intersect(array_map('strval', $ids), modulyIds())); }
function can(string $p): bool { $m = me(); return $m['rola'] === 'glowny' || !empty($m['prawa'][$p]); }
function need(string $p): void { if (!can($p)) fail('Brak uprawnień', 403); }
function needMain(): void { if (!isMain()) fail('Tylko administrator główny', 403); }
function allowed(string $lok): bool { $m = me(); return allLoks() || in_array($lok, $m['oddzialy'], true); }
function needLok(string $lok): void { if (!lokRow($lok) || !allowed($lok)) fail('Brak dostępu do oddziału', 403); }
function who(): string { return me()['login']; }
function lokList(): array { return array_values(array_filter(all('SELECT * FROM oddzialy ORDER BY kolejnosc, nazwa'), fn($o) => allowed($o['id']))); }
function today(): string { return dayKey(nowMs()); }
function minDoba(): ?string { $m = me(); if ($m['rola'] !== 'glowny' && $m['widokDni'] > 0) return date('Y-m-d', strtotime(today()) - ($m['widokDni'] - 1) * 86400); return null; }
function corrLimitOk(string $doba): bool { $m = me(); if ($m['rola'] === 'glowny' || $m['limit_dni'] <= 0) return true; return $doba >= date('Y-m-d', strtotime(today()) - $m['limit_dni'] * 86400); }
function finalizeLogin(array $u): array {
    global $ip;
    session_regenerate_id(true);
    $sid = bin2hex(random_bytes(32));
    q('INSERT INTO sesje (id,user_id,ip,agent,utworzono,ostatnio,uniewazniona) VALUES (?,?,?,?,?,?,0)', [$sid, $u['id'], $ip, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), nowMs(), nowMs()]);
    $_SESSION['uid'] = (int) $u['id']; $_SESSION['sid'] = $sid; $_SESSION['t'] = time(); unset($_SESSION['pre']);
    $_SESSION['idle'] = max(5, min(480, (int) ($_SESSION['idleReq'] ?? (getSet('bezp')['sesjaMin'] ?? 30))));
    $dni = (int) (getSet('hasla')['dni'] ?? 0);
    $must = null;
    if ((int) $u['wymus_zmiane'] === 1 || ($dni > 0 && (int) $u['haslo_zmienione'] < nowMs() - $dni * 86400000)) $must = 'haslo';
    elseif ($u['rola'] === 'glowny' && !empty(getSet('bezp')['wymus2faGlowny']) && !(int) $u['totp_on']) $must = '2fa';
    if ($must) $_SESSION['must'] = $must; else unset($_SESSION['must']);
    q('UPDATE uzytkownicy SET ostatnio=? WHERE id=?', [nowMs(), $u['id']]);
    logAction($u['login'], 'login', null, 'Zalogowano z ' . $ip);
    return ['ok' => 1, 'wymagane' => $must];
}

/* =================== terminal =================== */
function terminal(): array {
    global $ip;
    $tok = (string) ($_SERVER['HTTP_X_TERMINAL'] ?? '');
    if (strlen($tok) < 32) fail('Terminal nieaktywowany', 401);
    $t = one('SELECT * FROM terminale WHERE token_hash=? AND aktywny=1', [hash('sha256', $tok)]);
    if (!$t) fail('Terminal zablokowany lub nieaktywowany', 401);
    q('UPDATE terminale SET ostatnio=?, ip=? WHERE id=?', [nowMs(), $ip, $t['id']]);
    return $t;
}
function opRow(array $o, bool $hashes): array {
    $r = ['id' => (int) $o['id'], 'lok' => $o['oddzial'], 'dod' => jd($o['oddzialy_dod'] ?? null, []), 'imie' => $o['imie'], 'nr' => $o['nr'], 'aktywny' => (int) $o['aktywny'], 'karta' => $o['rfid_hash'] ? 1 : 0, 'pin' => $o['pin_hash'] ? 1 : 0, 'udtDo' => $o['udt_do'] ?? null, 'moduly' => jd($o['moduly'] ?? null, null) ?? ['zaladunki']];
    if ($hashes) { $r['rfidHash'] = $o['rfid_hash']; $r['pinHash'] = $o['pin_hash']; }
    return $r;
}
function opsForLok(string $lok, bool $activeOnly): array {
    $rows = all('SELECT * FROM operatorzy' . ($activeOnly ? ' WHERE aktywny=1' : '') . ' ORDER BY imie');
    return array_values(array_filter($rows, fn($o) => $o['oddzial'] === $lok || in_array($lok, jd($o['oddzialy_dod'] ?? null, []), true)));
}
function lokOut(array $o): array { return ['id' => $o['id'], 'nazwa' => $o['nazwa'], 'doki' => jd($o['doki'], []), 'autoLogout' => (int) $o['auto_logout'], 'serwis' => $o['serwis_hash'] ? 1 : 0, 'ust' => lokSet($o)]; }
function activeMsgs(?string $lok): array {
    $n = nowMs(); $rows = all('SELECT * FROM komunikaty WHERE od_ms<=? AND (do_ms IS NULL OR do_ms>?) ORDER BY id DESC', [$n, $n]);
    return array_values(array_map(fn($k) => ['id' => (int) $k['id'], 'lok' => $k['oddzial'], 'tresc' => $k['tresc'], 'priorytet' => $k['priorytet'], 'od' => (int) $k['od_ms'], 'do' => $k['do_ms'] !== null ? (int) $k['do_ms'] : null],
        array_filter($rows, fn($k) => $lok === null || $k['oddzial'] === null || $k['oddzial'] === $lok)));
}

/* =================== dokumenty (instrukcje, schematy) =================== */
const DOK_TYPY = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel', 'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
function dokDir(): string { $d = dirname(__DIR__) . '/data/dokumenty'; if (!is_dir($d)) mkdir($d, 0750, true); return $d; }
function dokRow(array $d): array { return ['id' => (int) $d['id'], 'lok' => $d['oddzial'], 'modul' => $d['modul'], 'kategoria' => $d['kategoria'], 'tytul' => $d['tytul'], 'opis' => $d['opis'], 'nazwa' => $d['nazwa'], 'typ' => $d['typ'], 'rozmiar' => (int) $d['rozmiar'], 'kolejnosc' => (int) $d['kolejnosc'], 'aktywny' => (int) $d['aktywny'], 'autor' => $d['autor'], 'utworzono' => (int) $d['utworzono'], 'zmieniono' => (int) $d['zmieniono']]; }
function dokForTerm(array $t): array { $mods = termMods($t); return array_values(array_map('dokRow', array_filter(all('SELECT * FROM dokumenty WHERE aktywny=1 ORDER BY kategoria, kolejnosc, tytul'), fn($d) => ($d['oddzial'] === null || $d['oddzial'] === $t['oddzial']) && ($d['modul'] === null || in_array($d['modul'], $mods, true))))); }
function dokSend(array $d): void {
    $f = dokDir() . '/' . basename($d['plik']); if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: ' . (DOK_TYPY[$d['typ']] ?? 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=300');
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($d['nazwa'])); header('Content-Length: ' . filesize($f)); readfile($f); exit;
}
function dokSave(string $b64, string $nazwa): array {
    $ext = strtolower(pathinfo($nazwa, PATHINFO_EXTENSION)); if (!isset(DOK_TYPY[$ext])) fail('Dozwolone pliki: Excel (.xlsx, .xls), PDF, obrazy (.png, .jpg, .webp).');
    $bin = base64_decode(preg_replace('#^data:[^;]*;base64,#', '', $b64), true); if ($bin === false || $bin === '') fail('Nie udało się odczytać pliku.');
    if (strlen($bin) > 15 * 1024 * 1024) fail('Plik jest za duży (maks. 15 MB).');
    $sig = ['xlsx' => "PK\x03\x04", 'xls' => "\xD0\xCF\x11\xE0", 'pdf' => '%PDF', 'png' => "\x89PNG", 'jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'webp' => 'RIFF'];
    if (substr($bin, 0, strlen($sig[$ext])) !== $sig[$ext]) fail('Zawartość pliku nie zgadza się z jego rozszerzeniem.');
    $typ = $ext === 'jpeg' ? 'jpg' : $ext; $plik = bin2hex(random_bytes(12)) . '.' . $typ; file_put_contents(dokDir() . '/' . $plik, $bin);
    return [$plik, $typ, strlen($bin)];
}

switch ($r) {

/* ======================= TERMINAL ======================= */
case 't_aktywuj': {
    if ($method !== 'POST') fail('Wymagane POST', 405);
    $kod = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (input()['kod'] ?? '')));
    if ((int) one('SELECT COUNT(*) c FROM dziennik WHERE akcja=? AND opis=? AND kiedy>?', ['t_aktywacja_blad', $ip, nowMs() - 600000])['c'] >= 10) fail('Zbyt wiele prób. Spróbuj za 10 minut.', 429);
    $t = $kod ? one('SELECT * FROM terminale WHERE kod=? AND kod_wazny>? AND aktywny=1', [$kod, nowMs()]) : null;
    if (!$t) { logAction('terminal', 't_aktywacja_blad', null, $ip); fail('Nieprawidłowy lub wygasły kod aktywacyjny.', 403); }
    $token = bin2hex(random_bytes(32));
    q('UPDATE terminale SET token_hash=?, kod=NULL, kod_wazny=NULL, ostatnio=?, ip=? WHERE id=?', [hash('sha256', $token), nowMs(), $ip, $t['id']]);
    logAction('terminal:' . $t['nazwa'], 'terminal_aktywacja', $t['oddzial'], 'Aktywowano terminal ' . $t['nazwa']);
    out(['token' => $token, 'terminal' => ['id' => (int) $t['id'], 'nazwa' => $t['nazwa'], 'lok' => $t['oddzial']]]);
}
case 't_dokumenty': { $t = terminal(); out(['dokumenty' => dokForTerm($t)]); }
case 't_dokument': {
    $t = terminal(); $id = (int) ($_GET['id'] ?? 0); foreach (dokForTerm($t) as $d) if ($d['id'] === $id) dokSend(one('SELECT * FROM dokumenty WHERE id=?', [$id]));
    http_response_code(404); exit;
}
case 't_sync': {
    $t = terminal(); $lok = $t['oddzial'];
    $o = lokRow($lok); $mods = termMods($t);
    if (isset($_GET['kolejka'])) q('UPDATE terminale SET kolejka=? WHERE id=?', [(int) $_GET['kolejka'], $t['id']]);
    $res = ['czas' => nowMs(), 'terminal' => ['id' => (int) $t['id'], 'nazwa' => $t['nazwa'], 'lok' => $lok, 'tryb' => termTryb($t)], 'moduly' => $mods,
        'oddzial' => lokOut($o) + ['serwisHash' => $o['serwis_hash']], 'komunikaty' => activeMsgs($lok),
        'operatorzy' => array_map(fn($x) => opRow($x, true), opsForLok($lok, true)), 'dokumenty' => dokForTerm($t)];
    foreach (modulyGotowe() as $m) if (in_array($m['id'], $mods, true) && function_exists($m['prefiks'] . '_sync')) $res += ($m['prefiks'] . '_sync')($t, $lok);
    alertyTick();
    out($res);
}

/* ======================= PANEL – logowanie i bezpieczeństwo ======================= */
case 'login': {
    requirePost(); startSession(); ipCheck();
    $in = input(); $login = strtolower(str($in['login'] ?? '', 40)); $pw = (string) ($in['haslo'] ?? '');
    if ((int) one('SELECT COUNT(*) c FROM dziennik WHERE akcja=? AND opis=? AND kiedy>?', ['login_blad', $ip, nowMs() - 900000])['c'] >= 8) fail('Zbyt wiele nieudanych prób logowania. Spróbuj za 15 minut.', 429);
    $u = one('SELECT * FROM uzytkownicy WHERE login=? AND aktywny=1', [$login]);
    if (!$u || !password_verify($pw, $u['hash'])) { logAction($login ?: '?', 'login_blad', null, $ip); usleep(400000); fail('Nieprawidłowy login lub hasło.', 401); }
    if (password_needs_rehash($u['hash'], PASSWORD_DEFAULT)) q('UPDATE uzytkownicy SET hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
    $_SESSION['idleReq'] = (int) ($in['bezczynnosc'] ?? 0) ?: null;
    if ((int) $u['totp_on'] === 1) { $_SESSION['pre'] = (int) $u['id']; $_SESSION['preT'] = time(); out(['krok' => '2fa']); }
    out(finalizeLogin($u));
}
case 'login_2fa': {
    requirePost(); startSession(); ipCheck();
    $id = (int) ($_SESSION['pre'] ?? 0); if (!$id || time() - (int) ($_SESSION['preT'] ?? 0) > 300) fail('Sesja logowania wygasła – zaloguj się ponownie.', 401);
    $u = one('SELECT * FROM uzytkownicy WHERE id=? AND aktywny=1', [$id]); if (!$u) fail('Konto nieaktywne', 401);
    if (!totpOk((string) $u['totp_secret'], (string) (input()['kod'] ?? ''))) { logAction($u['login'], 'login_blad', null, $ip); fail('Nieprawidłowy kod weryfikacyjny.', 401); }
    out(finalizeLogin($u));
}
case 'logout': { startSession(); endSession(); out(['ok' => 1]); }
case 'ja': {
    $m = me(true);
    out(['ja' => $m, 'role' => roleList(), 'prawaLista' => PRAWA, 'wymagane' => $_SESSION['must'] ?? null, 'oddzialy' => array_map('lokOut', lokList()), 'hasla' => getSet('hasla'), 'modulyDef' => array_map(fn($x) => ['id' => $x['id'], 'nazwa' => $x['nazwa'], 'opis' => $x['opis'], 'gotowy' => $x['gotowy']], modulyDef()), 'alertyOn' => (int) alertSet()['wlaczone'], 'alertyKanaly' => ['email' => (int) alertSet()['email'], 'sms' => (int) alertSet()['sms']]] + array_merge(...array_values(modHook('ja')) ?: [[]]));
}
case 'haslo_zmien': {
    requirePost(); $m = me(true); $in = input();
    $u = one('SELECT hash FROM uzytkownicy WHERE id=?', [$m['id']]);
    if (!password_verify((string) ($in['stare'] ?? ''), $u['hash'])) fail('Obecne hasło jest nieprawidłowe.');
    $n = (string) ($in['nowe'] ?? ''); if ($e = pwCheck($n)) fail($e); if (password_verify($n, $u['hash'])) fail('Nowe hasło musi się różnić od obecnego.');
    q('UPDATE uzytkownicy SET hash=?, wymus_zmiane=0, haslo_zmienione=? WHERE id=?', [password_hash($n, PASSWORD_DEFAULT), nowMs(), $m['id']]);
    if (($_SESSION['must'] ?? '') === 'haslo') { $_SESSION['must'] = ($m['rola'] === 'glowny' && !empty(getSet('bezp')['wymus2faGlowny']) && !$m['totp_on']) ? '2fa' : null; if (!$_SESSION['must']) unset($_SESSION['must']); }
    logAction($m['login'], 'haslo_zmiana', null, 'Zmiana własnego hasła'); out(['ok' => 1, 'wymagane' => $_SESSION['must'] ?? null]);
}
case 'totp_start': {
    requirePost(); $m = me(true); $sec = b32new(); $_SESSION['totp_pending'] = $sec;
    $label = rawurlencode('ESL Załadunki:' . $m['login']);
    out(['secret' => $sec, 'uri' => "otpauth://totp/$label?secret=$sec&issuer=" . rawurlencode('ESL Załadunki') . '&digits=6&period=30']);
}
case 'totp_wlacz': {
    requirePost(); $m = me(true); $sec = (string) ($_SESSION['totp_pending'] ?? '');
    if (!$sec || !totpOk($sec, (string) (input()['kod'] ?? ''))) fail('Nieprawidłowy kod – sprawdź godzinę w telefonie i spróbuj ponownie.');
    q('UPDATE uzytkownicy SET totp_secret=?, totp_on=1 WHERE id=?', [$sec, $m['id']]); unset($_SESSION['totp_pending']);
    if (($_SESSION['must'] ?? '') === '2fa') unset($_SESSION['must']);
    logAction($m['login'], '2fa_wlaczone', null, 'Włączono weryfikację dwuetapową'); out(['ok' => 1]);
}
case 'totp_wylacz': {
    requirePost(); $m = me(); $u = one('SELECT hash FROM uzytkownicy WHERE id=?', [$m['id']]);
    if (!password_verify((string) (input()['haslo'] ?? ''), $u['hash'])) fail('Nieprawidłowe hasło.');
    if ($m['rola'] === 'glowny' && !empty(getSet('bezp')['wymus2faGlowny'])) fail('Weryfikacja dwuetapowa jest wymagana dla administratorów głównych.');
    q('UPDATE uzytkownicy SET totp_secret=NULL, totp_on=0 WHERE id=?', [$m['id']]); logAction($m['login'], '2fa_wylaczone', null, 'Wyłączono weryfikację dwuetapową'); out(['ok' => 1]);
}
case 'sesje': {
    $m = me(); $cut = nowMs() - 7 * 86400000;
    $rows = isMain() ? all('SELECT s.*, u.login, u.imie FROM sesje s JOIN uzytkownicy u ON u.id=s.user_id WHERE s.uniewazniona=0 AND s.ostatnio>? ORDER BY s.ostatnio DESC', [$cut])
        : all('SELECT s.*, u.login, u.imie FROM sesje s JOIN uzytkownicy u ON u.id=s.user_id WHERE s.uniewazniona=0 AND s.ostatnio>? AND s.user_id=? ORDER BY s.ostatnio DESC', [$cut, $m['id']]);
    out(['sesje' => array_map(fn($s) => ['id' => substr($s['id'], 0, 16), 'login' => $s['login'], 'imie' => $s['imie'], 'ip' => $s['ip'], 'agent' => $s['agent'], 'od' => (int) $s['utworzono'], 'ostatnio' => (int) $s['ostatnio'], 'biezaca' => $s['id'] === ($_SESSION['sid'] ?? '') ? 1 : 0], $rows)]);
}
case 'sesja_zakoncz': {
    requirePost(); $m = me(); $pre = str(input()['id'] ?? '', 16); if (strlen($pre) < 16) fail('Nieprawidłowe dane');
    $s = one('SELECT s.*, u.login FROM sesje s JOIN uzytkownicy u ON u.id=s.user_id WHERE s.id LIKE ?', [$pre . '%']); if (!$s) fail('Nie znaleziono', 404);
    if (!isMain() && (int) $s['user_id'] !== (int) $m['id']) fail('Brak uprawnień', 403);
    q('UPDATE sesje SET uniewazniona=1 WHERE id=?', [$s['id']]); logAction($m['login'], 'sesja_zakonczona', null, 'Zakończono sesję: ' . $s['login'] . ' (' . $s['ip'] . ')'); out(['ok' => 1]);
}

/* ======================= PANEL – dane ======================= */
case 'alerty_log': {
    me(); if (!isMain() && !can('konfiguracja')) fail('Brak uprawnień', 403);
    $loks = array_column(lokList(), 'id'); if (!$loks) out(['alerty' => []]); $in = implode(',', array_fill(0, count($loks), '?'));
    $w = isMain() ? "(oddzial IN ($in) OR oddzial IS NULL)" : "oddzial IN ($in)";
    out(['alerty' => array_map('alertRow', all("SELECT * FROM alerty WHERE $w ORDER BY id DESC LIMIT 150", $loks))]);
}
case 'alerty_test': {
    requirePost(); needMain(); $in = input(); $k = (string) ($in['kanal'] ?? ''); $to = (string) ($in['do'] ?? '');
    if ($k === 'email') { $em = alertEmails($to); if (!$em) fail('Podaj prawidłowy adres e-mail.'); $r = alertMail($em, 'ESL – test alertów', alertHtml('test', [['kiedy' => nowMs(), 'typ' => 'test', 'tresc' => 'Wiadomość testowa – konfiguracja alertów e-mail działa.']])); }
    elseif ($k === 'sms') { $tel = alertPhones($to); if (!$tel) fail('Podaj prawidłowy numer telefonu (np. 600 100 200).'); $r = smsSend($tel, 'ESL Operacje: SMS testowy - konfiguracja alertow dziala.'); }
    else fail('Nieznany kanał');
    if ($r !== true) fail('Nie wysłano: ' . $r);
    logAction(who(), 'alerty_test', null, 'Test ' . $k . ' → ' . $to); out(['ok' => 1]);
}
case 'alerty_teraz': { requirePost(); needMain(); out(['log' => alertyRun(true)]); }
case 'terminale': {
    me(); alertyTick(); $loks = array_column(lokList(), 'id'); if (!$loks) out(['terminale' => []]);
    $in = implode(',', array_fill(0, count($loks), '?')); $sk = can('terminale');
    $rows = all("SELECT * FROM terminale WHERE oddzial IN ($in) ORDER BY oddzial,nazwa", $loks);
    out(['terminale' => array_map(fn($t) => ['id' => (int) $t['id'], 'lok' => $t['oddzial'], 'nazwa' => $t['nazwa'], 'aktywny' => (int) $t['aktywny'], 'ostatnio' => $t['ostatnio'] ? (int) $t['ostatnio'] : null,
        'ip' => $t['ip'], 'kolejka' => $t['kolejka'] !== null ? (int) $t['kolejka'] : null, 'aktywowany' => $t['token_hash'] ? 1 : 0, 'moduly' => jd($t['moduly'] ?? null, null) ?? ['zaladunki'], 'tryb' => termTryb($t),
        'kod' => ($sk && $t['kod'] && $t['kod_wazny'] > nowMs()) ? $t['kod'] : null, 'kodWazny' => $sk && $t['kod_wazny'] ? (int) $t['kod_wazny'] : null], $rows), 'czas' => nowMs()]);
}

/* ======================= PANEL – operatorzy ======================= */
case 'operatorzy': {
    need('operatorzy'); $lok = (string) ($_GET['oddzial'] ?? ''); needLok($lok);
    out(['operatorzy' => array_map(fn($o) => opRow($o, false), opsForLok($lok, false))]);
}
case 'operator_zapisz': case 'operator_import': {
    requirePost(); need('operatorzy'); $in = input();
    $list = $r === 'operator_import' ? (array) ($in['lista'] ?? []) : [$in];
    if (count($list) > 1000) fail('Maksymalnie 1000 operatorów w jednym imporcie.');
    $wyn = ['dodano' => 0, 'zmieniono' => 0, 'bledy' => []];
    foreach ($list as $i => $x) {
        try {
            $id = (int) ($x['id'] ?? 0); $cur = $id ? one('SELECT * FROM operatorzy WHERE id=?', [$id]) : null;
            if ($id && !$cur) throw new Exception('Nie znaleziono operatora');
            if (!$cur && $r === 'operator_import' && ($x['nr'] ?? '') !== '') $cur = one('SELECT * FROM operatorzy WHERE nr=? AND oddzial=?', [str($x['nr'], 40), (string) ($in['oddzial'] ?? '')]);
            $lok = $cur ? $cur['oddzial'] : (string) ($x['oddzial'] ?? ($in['oddzial'] ?? '')); if (!lokRow($lok) || !allowed($lok)) throw new Exception('Brak dostępu do oddziału');
            $imie = str($x['imie'] ?? ($cur['imie'] ?? '')); if ($imie === '') throw new Exception('Brak imienia i nazwiska');
            $nr = str($x['nr'] ?? ($cur['nr'] ?? ''), 40);
            $akt = array_key_exists('aktywny', $x) ? ((int) $x['aktywny'] ? 1 : 0) : ($cur ? (int) $cur['aktywny'] : 1);
            $dod = $cur ? jd($cur['oddzialy_dod'], []) : [];
            if (array_key_exists('dod', $x)) { $dod = array_values(array_filter(array_unique((array) $x['dod']), fn($l) => $l !== $lok && lokRow((string) $l) && allowed((string) $l))); }
            $udt = $cur['udt_do'] ?? null; if (array_key_exists('udtDo', $x)) { $u = trim((string) $x['udtDo']); if ($u !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $u)) throw new Exception('Data UDT w formacie RRRR-MM-DD'); $udt = $u ?: null; }
            $md = $cur ? (jd($cur['moduly'] ?? null, null) ?? ['zaladunki']) : ['zaladunki']; if (array_key_exists('moduly', $x)) $md = modList((array) $x['moduly']);
            $rh = $cur['rfid_hash'] ?? null; $ph = $cur['pin_hash'] ?? null;
            if (array_key_exists('karta', $x) && !($r === 'operator_import' && trim((string) $x['karta']) === '')) { $k = normRfid((string) $x['karta']); if ($x['karta'] !== '' && strlen($k) < 4) throw new Exception('Nieprawidłowy numer karty'); $rh = $k !== '' ? shash('rfid', $k) : null; }
            if (array_key_exists('pin', $x) && !($r === 'operator_import' && trim((string) $x['pin']) === '')) { $pn = trim((string) $x['pin']); if ($pn !== '' && !preg_match('/^\d{4,6}$/', $pn)) throw new Exception('PIN musi mieć 4–6 cyfr'); $ph = $pn !== '' ? shash('pin', $pn) : null; }
            if (!$rh && !$ph) throw new Exception('Operator musi mieć kartę RFID lub PIN');
            $oid = $cur ? (int) $cur['id'] : 0;
            if ($rh && ($d = one('SELECT o.imie FROM operatorzy o WHERE o.rfid_hash=? AND o.id<>?', [$rh, $oid]))) throw new Exception('Karta przypisana: ' . $d['imie']);
            if ($ph) foreach (array_merge([$lok], $dod) as $l) foreach (opsForLok($l, false) as $o2) if ((int) $o2['id'] !== $oid && $o2['pin_hash'] === $ph) throw new Exception('PIN zajęty w oddziale ' . lokRow($l)['nazwa']);
            if ($cur) { q('UPDATE operatorzy SET imie=?, nr=?, rfid_hash=?, pin_hash=?, aktywny=?, oddzialy_dod=?, udt_do=?, moduly=? WHERE id=?', [$imie, $nr, $rh, $ph, $akt, je($dod), $udt, je($md), $oid]); $wyn['zmieniono']++; if ($r !== 'operator_import') logAction(who(), 'operator_zmiana', $lok, $imie); }
            else { q('INSERT INTO operatorzy (oddzial,imie,nr,rfid_hash,pin_hash,aktywny,utworzono,oddzialy_dod,udt_do,moduly) VALUES (?,?,?,?,?,?,?,?,?,?)', [$lok, $imie, $nr, $rh, $ph, $akt, nowMs(), je($dod), $udt, je($md)]); $wyn['dodano']++; if ($r !== 'operator_import') logAction(who(), 'operator_dodanie', $lok, $imie); }
        } catch (Exception $e) { if ($r !== 'operator_import') fail($e->getMessage()); $wyn['bledy'][] = ['wiersz' => $i + 2, 'blad' => $e->getMessage(), 'imie' => $x['imie'] ?? '']; }
    }
    if ($r === 'operator_import') logAction(who(), 'operator_import', (string) ($in['oddzial'] ?? ''), 'Dodano ' . $wyn['dodano'] . ', zmieniono ' . $wyn['zmieniono'] . ', błędy ' . count($wyn['bledy']));
    out(['ok' => 1] + $wyn);
}
case 'operator_usun': {
    requirePost(); need('operatorzy'); $o = one('SELECT * FROM operatorzy WHERE id=?', [(int) (input()['id'] ?? 0)]); if (!$o) fail('Nie znaleziono', 404);
    needLok($o['oddzial']); q('DELETE FROM operatorzy WHERE id=?', [$o['id']]); logAction(who(), 'operator_usun', $o['oddzial'], $o['imie']); out(['ok' => 1]);
}

/* ======================= PANEL – oddziały, ustawienia, terminale, komunikaty ======================= */
case 'oddzial_zapisz': {
    requirePost(); need('konfiguracja'); $in = input(); $lok = (string) ($in['id'] ?? ''); needLok($lok); $o = lokRow($lok);
    $doki = array_values(array_unique(array_filter(array_map(fn($d) => str($d, 40), (array) ($in['doki'] ?? [])), fn($d) => $d !== '')));
    if (!$doki || count($doki) > 60) fail('Podaj od 1 do 60 doków.');
    $al = max(1, min(60, (int) ($in['autoLogout'] ?? 5)));
    $u = lokSet($o); $n = (array) ($in['ust'] ?? []);
    if (isset($n['zmiany'])) { $z = array_map('intval', (array) $n['zmiany']); if (count($z) !== 3 || !($z[0] >= 0 && $z[0] < $z[1] && $z[1] < $z[2] && $z[2] <= 23)) fail('Godziny zmian: trzy rosnące godziny 0–23 (np. 6, 14, 22).'); $u['zmiany'] = $z; }
    if (isset($n['lid'])) { $l = (array) $n['lid']; $u['lid'] = ['min' => max(0, min(60, (int) ($l['min'] ?? 0))), 'max' => max(0, min(60, (int) ($l['max'] ?? 0))), 'prefix' => str($l['prefix'] ?? '', 20), 'cyfry' => !empty($l['cyfry']) ? 1 : 0]; }
    if (isset($n['limity'])) { $l = (array) $n['limity']; $u['limity'] = ['maxCzasMin' => max(0, min(1440, (int) ($l['maxCzasMin'] ?? 90))), 'maxPalety' => max(1, min(999, (int) ($l['maxPalety'] ?? 66))), 'offlineMin' => max(1, min(240, (int) ($l['offlineMin'] ?? 5)))]; }
    if (isset($n['wymagane'])) { $w = (array) $n['wymagane']; $ok = ['brak', 'opcja', 'wymagane']; foreach (['rejestracja', 'uwagi', 'zdjecie'] as $k) $u['wymagane'][$k] = in_array($w[$k] ?? 'brak', $ok, true) ? $w[$k] : 'brak'; }
    if (isset($n['jezyki'])) { $j = array_values(array_intersect(['pl', 'ua', 'en'], (array) $n['jezyki'])); if (!$j) fail('Wybierz co najmniej jeden język terminala.'); $u['jezyki'] = $j; }
    if (isset($n['dokiOff'])) { $d = []; foreach ((array) $n['dokiOff'] as $k => $v) if (in_array($k, $doki, true)) $d[$k] = ['do' => !empty($v['do']) ? (int) $v['do'] : null, 'powod' => str($v['powod'] ?? '', 100)]; $u['dokiOff'] = $d ?: new stdClass(); }
    if (isset($n['cel'])) { $c = (array) $n['cel']; $u['cel'] = ['zaladunki' => max(0, (int) ($c['zaladunki'] ?? 0)), 'palety' => max(0, (int) ($c['palety'] ?? 0))]; }
    if (isset($n['raport'])) { $c = (array) $n['raport']; $em = implode(',', array_filter(array_map('trim', explode(',', str($c['email'] ?? '', 500))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $u['raport'] = ['email' => $em, 'dzienny' => !empty($c['dzienny']) ? 1 : 0, 'tygodniowy' => !empty($c['tygodniowy']) ? 1 : 0]; }
    if (isset($n['alerty'])) { $c = (array) $n['alerty']; $u['alerty'] = ['email' => implode(', ', alertEmails(str($c['email'] ?? '', 500))), 'sms' => implode(', ', array_map(fn($x) => '+' . $x, alertPhones(str($c['sms'] ?? '', 300))))]; }
    q('UPDATE oddzialy SET doki=?, auto_logout=?, ustawienia=? WHERE id=?', [je($doki), $al, je($u), $lok]);
    if (!empty($in['serwisHaslo'])) { $sp = (string) $in['serwisHaslo']; if (strlen($sp) < 4) fail('Hasło serwisowe: min. 4 znaki.'); q('UPDATE oddzialy SET serwis_hash=? WHERE id=?', [shash('admin', $sp), $lok]); }
    logAction(who(), 'oddzial_ustawienia', $lok, 'Doki: ' . implode(', ', $doki) . ' · zmiany ' . implode('/', $u['zmiany']) . ' · auto-wylogowanie ' . $al . ' min' . (!empty($in['serwisHaslo']) ? ' · nowe hasło serwisowe' : ''));
    out(['ok' => 1]);
}
case 'ustawienia': {
    me(); if (!isMain() && !can('dziennik')) fail('Brak uprawnień', 403);
    $o = []; foreach (array_keys(defaultsGlobal()) as $k) $o[$k] = getSet($k); $o['alerty'] = alertSetPublic(); out(['ustawienia' => $o]);
}
case 'ustawienia_zapisz': {
    requirePost(); needMain(); $in = input(); $k = (string) ($in['klucz'] ?? ''); $v = $in['wartosc'] ?? null;
    switch ($k) {
        case 'hasla': setSet('hasla', ['min' => max(8, min(64, (int) ($v['min'] ?? 8))), 'cyfra' => !empty($v['cyfra']) ? 1 : 0, 'wielka' => !empty($v['wielka']) ? 1 : 0, 'specjalny' => !empty($v['specjalny']) ? 1 : 0, 'dni' => max(0, min(365, (int) ($v['dni'] ?? 0)))]); break;
        case 'bezp':
            $lst = array_values(array_filter(array_map(fn($x) => str($x, 50), (array) ($v['ipLista'] ?? [])), fn($x) => $x !== ''));
            if ($lst && !ipAllowed($ip, $lst)) fail('Lista IP musi zawierać Twój obecny adres (' . $ip . '), inaczej utracisz dostęp do panelu.');
            if (!empty($v['wymus2faGlowny']) && !me()['totp_on']) fail('Najpierw włącz weryfikację dwuetapową na własnym koncie (Moje konto).');
            setSet('bezp', ['wymus2faGlowny' => !empty($v['wymus2faGlowny']) ? 1 : 0, 'ipLista' => $lst, 'sesjaMin' => max(5, min(480, (int) ($v['sesjaMin'] ?? 30)))]); break;
        case 'retencja': setSet('retencja', ['lata' => max(0, min(20, (int) ($v['lata'] ?? 0)))]); break;
        case 'alerty': alertSave((array) $v); break;
        case 'raporty': $e = str($v['nadawca'] ?? '', 120); if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('Nieprawidłowy adres nadawcy.'); setSet('raporty', ['nadawca' => $e]); break;
        default: if (!in_array(true, modHook('ustawienia', $k, $v), true)) fail('Nieznane ustawienie');
    }
    logAction(who(), 'ustawienia', null, 'Zmieniono ustawienia: ' . $k); out(['ok' => 1]);
}
case 'terminal_dodaj': case 'terminal_kod': {
    requirePost(); need('terminale'); $in = input();
    $kod = ''; $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; for ($i = 0; $i < 8; $i++) $kod .= $abc[random_int(0, strlen($abc) - 1)];
    $exp = nowMs() + 48 * 3600000;
    if ($r === 'terminal_dodaj') {
        $lok = (string) ($in['oddzial'] ?? ''); needLok($lok); $n = str($in['nazwa'] ?? '', 60); if ($n === '') fail('Podaj nazwę terminala (np. Rampa 1).');
        [$tr, $md] = termModCfg($in);
        q('INSERT INTO terminale (oddzial,nazwa,kod,kod_wazny,aktywny,utworzono,moduly,tryb) VALUES (?,?,?,?,1,?,?,?)', [$lok, $n, $kod, $exp, nowMs(), je($md), $tr]);
        logAction(who(), 'terminal_dodanie', $lok, $n);
    } else {
        $t = one('SELECT * FROM terminale WHERE id=?', [(int) ($in['id'] ?? 0)]); if (!$t) fail('Nie znaleziono', 404); needLok($t['oddzial']);
        q('UPDATE terminale SET kod=?, kod_wazny=?, token_hash=NULL, aktywny=1 WHERE id=?', [$kod, $exp, $t['id']]);
        logAction(who(), 'terminal_nowy_kod', $t['oddzial'], $t['nazwa'] . ' (poprzednia aktywacja unieważniona)');
    }
    out(['ok' => 1, 'kod' => $kod, 'wazny' => $exp]);
}
case 'terminal_blokuj': case 'terminal_usun': {
    requirePost(); need('terminale'); $t = one('SELECT * FROM terminale WHERE id=?', [(int) (input()['id'] ?? 0)]); if (!$t) fail('Nie znaleziono', 404); needLok($t['oddzial']);
    if ($r === 'terminal_usun') { q('DELETE FROM terminale WHERE id=?', [$t['id']]); logAction(who(), 'terminal_usun', $t['oddzial'], $t['nazwa']); }
    else { $a = (int) $t['aktywny'] ? 0 : 1; q('UPDATE terminale SET aktywny=? WHERE id=?', [$a, $t['id']]); logAction(who(), $a ? 'terminal_odblokuj' : 'terminal_blokuj', $t['oddzial'], $t['nazwa']); }
    out(['ok' => 1]);
}
case 'komunikaty': {
    me(); $loks = array_column(lokList(), 'id'); $n = nowMs() - 30 * 86400000;
    $rows = all('SELECT * FROM komunikaty WHERE do_ms IS NULL OR do_ms>? ORDER BY id DESC', [$n]);
    out(['komunikaty' => array_values(array_map(fn($k) => ['id' => (int) $k['id'], 'lok' => $k['oddzial'], 'tresc' => $k['tresc'], 'priorytet' => $k['priorytet'], 'od' => (int) $k['od_ms'], 'do' => $k['do_ms'] !== null ? (int) $k['do_ms'] : null, 'autor' => $k['autor'], 'utworzono' => (int) $k['utworzono']],
        array_filter($rows, fn($k) => $k['oddzial'] === null || in_array($k['oddzial'], $loks, true))))]);
}
case 'komunikat_zapisz': case 'komunikat_usun': {
    requirePost(); $in = input(); $m = me();
    if ($r === 'komunikat_usun') { $k = one('SELECT * FROM komunikaty WHERE id=?', [(int) ($in['id'] ?? 0)]); if (!$k) fail('Nie znaleziono', 404);
        if ($k['oddzial'] === null) needMain(); else { need('komunikaty'); needLok($k['oddzial']); }
        q('DELETE FROM komunikaty WHERE id=?', [$k['id']]); logAction($m['login'], 'komunikat_usun', $k['oddzial'], mb_substr($k['tresc'], 0, 80)); out(['ok' => 1]); }
    $lok = ($in['oddzial'] ?? '') ?: null; if ($lok === null) needMain(); else { need('komunikaty'); needLok($lok); }
    $t = str($in['tresc'] ?? '', 300); if ($t === '') fail('Podaj treść komunikatu.');
    $pr = in_array($in['priorytet'] ?? 'info', ['info', 'wazny', 'pilny'], true) ? $in['priorytet'] : 'info';
    $od = (int) ($in['od'] ?? 0) ?: nowMs(); $do = (int) ($in['do'] ?? 0) ?: null; if ($do && $do <= $od) fail('Data końca musi być późniejsza niż początek.');
    q('INSERT INTO komunikaty (oddzial,tresc,priorytet,od_ms,do_ms,autor,utworzono) VALUES (?,?,?,?,?,?,?)', [$lok, $t, $pr, $od, $do, $m['imie'], nowMs()]);
    logAction($m['login'], 'komunikat_dodanie', $lok, mb_substr($t, 0, 80)); out(['ok' => 1]);
}

/* ======================= PANEL – użytkownicy ======================= */
case 'uzytkownicy': {
    needUsers();
    out(['uzytkownicy' => array_map(fn($u) => ['id' => (int) $u['id'], 'login' => $u['login'], 'imie' => $u['imie'], 'rola' => $u['rola'], 'oddzialy' => jd($u['oddzialy'], []),
        'aktywny' => (int) $u['aktywny'], 'ostatnio' => $u['ostatnio'] ? (int) $u['ostatnio'] : null, 'totp' => (int) $u['totp_on'], 'wymusZmiane' => (int) $u['wymus_zmiane'],
        'hasloZmienione' => $u['haslo_zmienione'] ? (int) $u['haslo_zmienione'] : null], array_values(array_filter(all('SELECT * FROM uzytkownicy ORDER BY rola, imie'), 'userManageable')))]);
}
case 'uzytkownik_zapisz': {
    requirePost(); needUsers(); $in = input(); $m = me();
    $id = (int) ($in['id'] ?? 0); $cur = $id ? one('SELECT * FROM uzytkownicy WHERE id=?', [$id]) : null; if ($id && !$cur) fail('Nie znaleziono', 404);
    if ($cur && !userManageable($cur)) fail('Brak uprawnień do tego konta.', 403);
    $login = $cur ? $cur['login'] : strtolower(str($in['login'] ?? '', 40));
    if (!$cur && !preg_match('/^[a-z0-9._-]{3,40}$/', $login)) fail('Login: 3–40 znaków (litery, cyfry, . _ -).');
    if (!$cur && one('SELECT id FROM uzytkownicy WHERE login=?', [$login])) fail('Użytkownik o tym loginie już istnieje.');
    $imie = str($in['imie'] ?? ''); if ($imie === '') fail('Podaj imię i nazwisko.');
    $rola = (string) ($in['rola'] ?? ''); $rd = roleDef($rola); if (!$rd) fail('Nieprawidłowa rola.');
    if (!roleAssignable($rola)) fail('Nie możesz nadać tej roli. Role dostępne dla administratora oddziału ustala administrator główny.', 403);
    $valid = array_column(all('SELECT id FROM oddzialy'), 'id'); $full = ($rd['zakres'] ?? '') === 'wszystkie';
    $odd = $full ? [] : array_values(array_intersect((array) ($in['oddzialy'] ?? []), $valid));
    if (!$full && !$odd) fail('Wybierz co najmniej jeden oddział.');
    if (!isMain() && array_diff($odd, $m['oddzialy'])) fail('Możesz przypisać tylko swoje oddziały.', 403);
    $akt = array_key_exists('aktywny', $in) ? ((int) $in['aktywny'] ? 1 : 0) : ($cur ? (int) $cur['aktywny'] : 1);
    $wym = array_key_exists('wymusZmiane', $in) ? (!empty($in['wymusZmiane']) ? 1 : 0) : ($cur ? (int) $cur['wymus_zmiane'] : 1);
    $pw = (string) ($in['haslo'] ?? ''); if (!$cur || $pw !== '') { if ($e = pwCheck($pw)) fail($e); }
    if ($cur && $cur['rola'] === 'glowny' && ($rola !== 'glowny' || !$akt) && (int) one("SELECT COUNT(*) c FROM uzytkownicy WHERE rola='glowny' AND aktywny=1 AND id<>?", [$id])['c'] === 0) fail('Musi pozostać co najmniej jeden aktywny administrator główny.');
    if ($cur && (int) $cur['id'] === (int) $m['id'] && (!$akt || $rola !== 'glowny')) fail('Nie możesz odebrać sobie roli ani wyłączyć własnego konta.');
    if ($cur) {
        q('UPDATE uzytkownicy SET imie=?, rola=?, oddzialy=?, aktywny=?, wymus_zmiane=? WHERE id=?', [$imie, $rola, je($odd), $akt, $wym, $id]);
        if ($pw !== '') q('UPDATE uzytkownicy SET hash=?, haslo_zmienione=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), nowMs(), $id]);
        if (!empty($in['reset2fa'])) q('UPDATE uzytkownicy SET totp_secret=NULL, totp_on=0 WHERE id=?', [$id]);
        if (!$akt || $pw !== '' || !empty($in['reset2fa'])) q('UPDATE sesje SET uniewazniona=1 WHERE user_id=? AND id<>?', [$id, $_SESSION['sid'] ?? '']);
        logAction(who(), 'uzytkownik_zmiana', $odd[0] ?? null, $login . ' · ' . $rola . ($pw !== '' ? ' · nowe hasło' : '') . (!empty($in['reset2fa']) ? ' · reset 2FA' : ''));
    } else {
        q('INSERT INTO uzytkownicy (login,imie,hash,rola,oddzialy,prawa,aktywny,utworzono,haslo_zmienione,wymus_zmiane) VALUES (?,?,?,?,?,?,?,?,?,?)', [$login, $imie, password_hash($pw, PASSWORD_DEFAULT), $rola, je($odd), '{}', $akt, nowMs(), nowMs(), $wym]);
        logAction(who(), 'uzytkownik_dodanie', $odd[0] ?? null, $login . ' · ' . $rola);
    }
    out(['ok' => 1]);
}
case 'role_zapisz': {
    requirePost(); needMain(); $in = input(); $role = roleList(); $id = (string) ($in['id'] ?? '');
    $nazwa = str($in['nazwa'] ?? '', 60); if ($nazwa === '') fail('Podaj nazwę roli.');
    foreach ($role as $x) if (mb_strtolower($x['nazwa']) === mb_strtolower($nazwa) && $x['id'] !== $id) fail('Rola o tej nazwie już istnieje.');
    if (mb_strtolower($nazwa) === 'administrator główny') fail('Ta nazwa jest zarezerwowana dla roli wbudowanej.');
    $pr = []; foreach (PRAWA as $p) $pr[$p] = !empty(($in['prawa'] ?? [])[$p]);
    $def = ['nazwa' => $nazwa, 'opis' => str($in['opis'] ?? '', 200), 'zakres' => ($in['zakres'] ?? '') === 'wszystkie' ? 'wszystkie' : 'wybrane',
        'widokDni' => max(0, min(3650, (int) ($in['widokDni'] ?? 0))), 'limitDni' => max(0, min(3650, (int) ($in['limitDni'] ?? 0))), 'prawa' => $pr, 'moduly' => modList((array) ($in['moduly'] ?? [])), 'nadawana' => !empty($in['nadawana']) && ($in['zakres'] ?? '') !== 'wszystkie' ? 1 : 0];
    if ($id !== '') { $f = false; foreach ($role as &$x) if ($x['id'] === $id) { $x = ['id' => $id] + $def; $f = true; } unset($x); if (!$f) fail('Nie znaleziono roli', 404); }
    else { if (count($role) >= 30) fail('Maksymalnie 30 ról.');
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(strtr($nazwa, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z','Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z']))), '_') ?: 'rola';
        $id = substr($base, 0, 30); $ids = array_column($role, 'id'); $n = 2; while ($id === 'glowny' || in_array($id, $ids, true)) $id = substr($base, 0, 27) . '_' . $n++;
        $role[] = ['id' => $id] + $def; }
    setSet('role', $role); logAction(who(), 'rola_zapis', null, $nazwa . ' (' . $id . ')'); out(['ok' => 1, 'id' => $id]);
}
case 'role_usun': {
    requirePost(); needMain(); $id = (string) (input()['id'] ?? ''); $role = roleList();
    $x = roleDef($id); if (!$x || $id === 'glowny') fail('Nie znaleziono roli', 404);
    $n = (int) one('SELECT COUNT(*) c FROM uzytkownicy WHERE rola=?', [$id])['c']; if ($n) fail('Rola jest przypisana do ' . $n . ' użytkowników – najpierw zmień im rolę.');
    setSet('role', array_values(array_filter($role, fn($r) => $r['id'] !== $id))); logAction(who(), 'rola_usun', null, $x['nazwa']); out(['ok' => 1]);
}
case 'uzytkownik_usun': {
    requirePost(); needUsers(); $u = one('SELECT * FROM uzytkownicy WHERE id=?', [(int) (input()['id'] ?? 0)]); if (!$u) fail('Nie znaleziono', 404); if (!userManageable($u)) fail('Brak uprawnień do tego konta.', 403);
    if ((int) $u['id'] === (int) me()['id']) fail('Nie możesz usunąć własnego konta.');
    if ($u['rola'] === 'glowny' && (int) one("SELECT COUNT(*) c FROM uzytkownicy WHERE rola='glowny' AND aktywny=1 AND id<>?", [$u['id']])['c'] === 0) fail('Musi pozostać co najmniej jeden aktywny administrator główny.');
    q('DELETE FROM uzytkownicy WHERE id=?', [$u['id']]); q('UPDATE sesje SET uniewazniona=1 WHERE user_id=?', [$u['id']]); logAction(who(), 'uzytkownik_usun', jd($u['oddzialy'], [])[0] ?? null, $u['login']); out(['ok' => 1]);
}
case 'dziennik': {
    me(); if (!isMain() && !can('dziennik')) fail('Brak uprawnień', 403); $lim = max(50, min(5000, (int) ($_GET['limit'] ?? 1000)));
    out(['wpisy' => all("SELECT kiedy,kto,akcja,oddzial,opis FROM dziennik ORDER BY id DESC LIMIT $lim")]);
}

/* ======================= PANEL – administracja techniczna ======================= */
case 'kopia': {
    needMain(); $o = ['app' => 'ESL-kopia', 'wersja' => SCHEMA_VERSION, 'utworzono' => nowMs(), 'tabele' => []];
    foreach (['oddzialy', 'uzytkownicy', 'operatorzy', 'terminale', 'zaladunki', 'przestoje', 'komunikaty', 'dokumenty', 'ustawienia', 'dziennik', 'alerty', 'kompletacje'] as $t) $o['tabele'][$t] = all("SELECT * FROM $t");
    logAction(who(), 'kopia_pobrana', null, 'Pobrano kopię zapasową bazy'); header('Content-Disposition: attachment; filename="ESL_kopia_' . date('Y-m-d_Hi') . '.json"'); out($o);
}
case 'przywroc': {
    requirePost(); needMain(); $in = input(); $m = me();
    $u = one('SELECT hash FROM uzytkownicy WHERE id=?', [$m['id']]); if (!password_verify((string) ($in['haslo'] ?? ''), $u['hash'])) fail('Nieprawidłowe hasło.');
    $k = $in['kopia'] ?? null; if (!is_array($k) || ($k['app'] ?? '') !== 'ESL-kopia' || !is_array($k['tabele'] ?? null)) fail('Nieprawidłowy plik kopii.');
    if (empty($k['tabele']['uzytkownicy'])) fail('Kopia nie zawiera użytkowników – przywracanie przerwane.');
    db()->beginTransaction();
    try {
        foreach (['oddzialy', 'uzytkownicy', 'operatorzy', 'terminale', 'zaladunki', 'przestoje', 'komunikaty', 'dokumenty', 'ustawienia', 'dziennik', 'alerty', 'kompletacje'] as $t) {
            if (!isset($k['tabele'][$t])) continue; db()->exec("DELETE FROM $t");
            foreach ($k['tabele'][$t] as $row) { $cols = array_keys($row); foreach ($cols as $c) if (!preg_match('/^[a-z_]+$/', (string) $c)) throw new Exception('kolumna');
                q("INSERT INTO $t (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row)); }
        }
        db()->exec('UPDATE sesje SET uniewazniona=1'); db()->commit();
    } catch (Throwable $e) { db()->rollBack(); fail('Przywracanie nie powiodło się: ' . $e->getMessage()); }
    migrate(); logAction($m['login'], 'kopia_przywrocona', null, 'Przywrócono kopię z ' . date('Y-m-d H:i', intdiv((int) ($k['utworzono'] ?? 0), 1000))); out(['ok' => 1]);
}
case 'retencja_uruchom': { requirePost(); needMain(); $n = array_sum(modHook('retencja')); logAction(who(), 'retencja', null, 'Usunięto rekordów: ' . $n); out(['ok' => 1, 'usunieto' => $n]); }

case 'dokumenty': {
    me(); $loks = array_column(lokList(), 'id');
    out(['dokumenty' => array_values(array_map('dokRow', array_filter(all('SELECT * FROM dokumenty ORDER BY kategoria, kolejnosc, tytul'), fn($d) => ($d['oddzial'] === null || in_array($d['oddzial'], $loks, true)) && ($d['modul'] === null || canMod($d['modul'])))))]);
}
case 'dokument_plik': {
    me(); $d = one('SELECT * FROM dokumenty WHERE id=?', [(int) ($_GET['id'] ?? 0)]);
    if (!$d || ($d['oddzial'] !== null && !allowed($d['oddzial'])) || ($d['modul'] !== null && !canMod($d['modul']))) { http_response_code(404); exit; }
    dokSend($d);
}
case 'dokument_zapisz': {
    requirePost(); need('dokumenty'); $in = input(); $m = me(); $id = (int) ($in['id'] ?? 0);
    $cur = $id ? one('SELECT * FROM dokumenty WHERE id=?', [$id]) : null; if ($id && !$cur) fail('Nie znaleziono', 404);
    $lok = array_key_exists('oddzial', $in) ? (($in['oddzial'] ?? '') ?: null) : ($cur['oddzial'] ?? null);
    if ($lok === null) { if (!isMain()) fail('Dokument dla wszystkich oddziałów może dodać tylko administrator główny – wybierz oddział.'); } else needLok($lok);
    if ($cur && $cur['oddzial'] !== null) needLok($cur['oddzial']); if ($cur && $cur['oddzial'] === null && !isMain()) fail('Brak uprawnień', 403);
    $mod = array_key_exists('modul', $in) ? (($in['modul'] ?? '') ?: null) : ($cur['modul'] ?? null); if ($mod !== null && !in_array($mod, modulyIds(), true)) fail('Nieznany moduł.');
    $tyt = str($in['tytul'] ?? ($cur['tytul'] ?? ''), 120); if ($tyt === '') fail('Podaj tytuł dokumentu.');
    $kat = str($in['kategoria'] ?? ($cur['kategoria'] ?? ''), 60) ?: 'Schematy załadunku';
    $opis = str($in['opis'] ?? ($cur['opis'] ?? ''), 500) ?: null; $kol = (int) ($in['kolejnosc'] ?? ($cur['kolejnosc'] ?? 0));
    $akt = array_key_exists('aktywny', $in) ? (!empty($in['aktywny']) ? 1 : 0) : ($cur ? (int) $cur['aktywny'] : 1);
    $plik = $cur['plik'] ?? null; $typ = $cur['typ'] ?? null; $roz = (int) ($cur['rozmiar'] ?? 0); $nazwa = $cur['nazwa'] ?? null;
    if (!empty($in['plik']['dane'])) { $nazwa = str($in['plik']['nazwa'] ?? 'plik', 160); [$plik, $typ, $roz] = dokSave((string) $in['plik']['dane'], $nazwa); if ($cur) @unlink(dokDir() . '/' . basename($cur['plik'])); }
    if (!$plik) fail('Wybierz plik do wgrania.');
    if ($cur) q('UPDATE dokumenty SET oddzial=?, modul=?, kategoria=?, tytul=?, opis=?, plik=?, nazwa=?, typ=?, rozmiar=?, kolejnosc=?, aktywny=?, zmieniono=? WHERE id=?', [$lok, $mod, $kat, $tyt, $opis, $plik, $nazwa, $typ, $roz, $kol, $akt, nowMs(), $id]);
    else q('INSERT INTO dokumenty (oddzial,modul,kategoria,tytul,opis,plik,nazwa,typ,rozmiar,kolejnosc,aktywny,autor,utworzono,zmieniono) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$lok, $mod, $kat, $tyt, $opis, $plik, $nazwa, $typ, $roz, $kol, $akt, $m['imie'], nowMs(), nowMs()]);
    logAction(who(), $cur ? 'dokument_zmiana' : 'dokument_dodanie', $lok, $kat . ' · ' . $tyt); out(['ok' => 1]);
}
case 'dokument_usun': {
    requirePost(); need('dokumenty'); $d = one('SELECT * FROM dokumenty WHERE id=?', [(int) (input()['id'] ?? 0)]); if (!$d) fail('Nie znaleziono', 404);
    if ($d['oddzial'] === null) needMain(); else needLok($d['oddzial']);
    q('DELETE FROM dokumenty WHERE id=?', [$d['id']]); @unlink(dokDir() . '/' . basename($d['plik'])); logAction(who(), 'dokument_usun', $d['oddzial'], $d['tytul']); out(['ok' => 1]);
}
case 'moduly_zapisz': {
    requirePost(); needMain(); $in = input(); $lok = (string) ($in['oddzial'] ?? ''); $o = lokRow($lok); if (!$o) fail('Nie znaleziono oddziału', 404);
    $u = jd($o['ustawienia'] ?? null, []); if (!is_array($u)) $u = []; $u['moduly'] = modList((array) ($in['moduly'] ?? []));
    q('UPDATE oddzialy SET ustawienia=? WHERE id=?', [je($u), $lok]); logAction(who(), 'moduly_oddzial', $lok, 'Moduły: ' . (implode(', ', $u['moduly']) ?: 'brak')); out(['ok' => 1]);
}
case 'terminal_moduly': {
    requirePost(); need('terminale'); $in = input(); $t = one('SELECT * FROM terminale WHERE id=?', [(int) ($in['id'] ?? 0)]); if (!$t) fail('Nie znaleziono', 404); needLok($t['oddzial']);
    [$tr, $md] = termModCfg($in); q('UPDATE terminale SET moduly=?, tryb=? WHERE id=?', [je($md), $tr, $t['id']]);
    logAction(who(), 'terminal_moduly', $t['oddzial'], $t['nazwa'] . ': ' . ($tr === 'wybor' ? 'wybór przed logowaniem – ' : 'dedykowany – ') . implode(', ', $md)); out(['ok' => 1]);
}
case 'operatorzy_moduly': {
    requirePost(); need('operatorzy'); $in = input(); $n = 0;
    foreach (array_slice((array) ($in['zmiany'] ?? []), 0, 500) as $z) {
        $o = one('SELECT * FROM operatorzy WHERE id=?', [(int) ($z['id'] ?? 0)]); if (!$o) continue; needLok($o['oddzial']);
        $md = modList((array) ($z['moduly'] ?? [])); q('UPDATE operatorzy SET moduly=? WHERE id=?', [je($md), $o['id']]); $n++;
        logAction(who(), 'operator_moduly', $o['oddzial'], $o['imie'] . ': ' . (implode(', ', $md) ?: 'brak modułów'));
    }
    out(['ok' => 1, 'zmieniono' => $n]);
}

default:
    foreach (modulyGotowe() as $m) { $f = $m['prefiks'] . '_trasy'; if (function_exists($f)) $f($r); }
    fail('Nieznana operacja', 404);
}
