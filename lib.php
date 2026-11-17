<?php
// Wspólne funkcje: baza danych, migracje, ustawienia, odpowiedzi JSON, skróty, doba/zmiana, TOTP.
declare(strict_types=1);

const SCHEMA_VERSION = 8;

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
        if (is_file(__DIR__ . '/config.local.php')) $c = array_replace_recursive($c, require __DIR__ . '/config.local.php');
        date_default_timezone_set($c['timezone'] ?? 'Europe/Warsaw');
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $d = cfg()['db'];
    if ($d['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $d['sqlite']);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    } else {
        $pdo = new PDO('mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=utf8mb4', $d['user'], $d['pass'],
            [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}
function isSqlite(): bool { return cfg()['db']['driver'] === 'sqlite'; }
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function nowMs(): int { return (int) round(microtime(true) * 1000); }
function jd(?string $s, $def) { if ($s === null || $s === '') return $def; $v = json_decode($s, true); return $v === null ? $def : $v; }
function je($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }

/** Skrót PIN / karty / hasła serwisowego – zgodny z terminalem (SHA-256 z solą). */
function shash(string $kind, string $val): string { return hash('sha256', cfg()['salt'] . '|' . $kind . '|' . $val); }
function normRfid(string $v): string { return preg_replace('/[^0-9A-F]/', '', strtoupper($v)); }

/* ---------- ustawienia ---------- */
function defaultsGlobal(): array {
    return [
        'palety' => [['k' => 'euro', 'nazwa' => 'Palety EURO', 'skrot' => 'EURO', 'aktywny' => 1], ['k' => 'chep', 'nazwa' => 'Palety 1/2 CHEP', 'skrot' => '½ CHEP', 'aktywny' => 1], ['k' => 'qpal', 'nazwa' => 'Palety 1/4 Q-PAL', 'skrot' => '¼ Q-PAL', 'aktywny' => 1]],
        'hasla' => ['min' => 8, 'cyfra' => 1, 'wielka' => 0, 'specjalny' => 0, 'dni' => 0],
        'bezp' => ['wymus2faGlowny' => 0, 'ipLista' => [], 'sesjaMin' => 30],
        'przestoje' => ['Brak auta', 'Awaria wózka', 'Brak towaru', 'Ładowanie baterii', 'Przerwa', 'Inne'],
        'retencja' => ['lata' => 0],
        'role' => [
            ['id' => 'oddzial', 'nazwa' => 'Administrator oddziału', 'opis' => 'Pełne zarządzanie wybranymi oddziałami', 'zakres' => 'wybrane', 'widokDni' => 0, 'limitDni' => 30,
                'prawa' => ['operatorzy' => true, 'korekta_palety' => true, 'korekta_zamknij' => true, 'korekta_usun' => true, 'konfiguracja' => true, 'komunikaty' => true, 'dokumenty' => true, 'terminale' => true, 'eksport' => true, 'dziennik' => false, 'uzytkownicy' => true]],
            ['id' => 'kierownik', 'nazwa' => 'Kierownik zmiany', 'opis' => 'Bieżąca i poprzednia doba, korekty bez usuwania', 'zakres' => 'wybrane', 'widokDni' => 2, 'limitDni' => 2, 'nadawana' => 1,
                'prawa' => ['korekta_palety' => true, 'korekta_zamknij' => true, 'komunikaty' => true, 'eksport' => true]],
            ['id' => 'audytor', 'nazwa' => 'Audytor', 'opis' => 'Odczyt wszystkich oddziałów i dziennika zmian', 'zakres' => 'wszystkie', 'widokDni' => 0, 'limitDni' => 0, 'prawa' => ['eksport' => true, 'dziennik' => true]],
            ['id' => 'podglad', 'nazwa' => 'Podgląd', 'opis' => 'Tylko odczyt wybranych oddziałów', 'zakres' => 'wybrane', 'nadawana' => 1, 'widokDni' => 0, 'limitDni' => 0, 'prawa' => ['eksport' => true]],
        ],
        'raporty' => ['nadawca' => ''],
        'alerty' => ['wlaczone' => 0, 'email' => 1, 'sms' => 0, 'smsToken' => '', 'smsNadawca' => '',
            'reguly' => ['czas' => 1, 'terminal' => 1, 'przestoj' => 1, 'przestojMin' => 30, 'udt' => 0, 'udtDni' => 14]],
        'checklista' => [
            ['id' => 'loto', 'pl' => 'Czy zastosowałeś LOTO?', 'ua' => 'Чи застосував ти LOTO (блокування та маркування)?', 'en' => 'Have you applied LOTO?', 'aktywny' => 1],
            ['id' => 'klin', 'pl' => 'Czy jest klin pod kołem?', 'ua' => 'Чи є клин під колесом?', 'en' => 'Is the wheel chock in place?', 'aktywny' => 1],
            ['id' => 'naczepa', 'pl' => 'Czy naczepa nie ma wad mogących uszkodzić towar?', 'ua' => 'Чи напівпричіп без дефектів, які можуть пошкодити товар?', 'en' => 'Is the trailer free of defects that could damage the goods?', 'aktywny' => 1],
        ],
    ];
}
function defaultsLok(): array {
    return ['zmiany' => [6, 14, 22], 'lid' => ['min' => 0, 'max' => 0, 'prefix' => '', 'cyfry' => 0],
        'limity' => ['maxCzasMin' => 90, 'maxPalety' => 66, 'offlineMin' => 5],
        'wymagane' => ['rejestracja' => 'brak', 'uwagi' => 'opcja', 'zdjecie' => 'brak'],
        'jezyki' => ['pl', 'ua', 'en'], 'dokiOff' => new stdClass(), 'cel' => ['zaladunki' => 0, 'palety' => 0],
        'raport' => ['email' => '', 'dzienny' => 0, 'tygodniowy' => 0], 'moduly' => ['zaladunki'],
        'alerty' => ['email' => '', 'sms' => '']];
}
function &setCache(): array { static $c = []; return $c; }
function getSet(string $k) { $c = &setCache(); if (!array_key_exists($k, $c)) { $r = one('SELECT wartosc FROM ustawienia WHERE klucz=?', [$k]); $d = defaultsGlobal()[$k] ?? null; $v = $r ? jd($r['wartosc'], $d) : $d; if (is_array($d) && is_array($v) && $d && array_keys($d) !== range(0, count($d) - 1)) $v = array_replace($d, $v); $c[$k] = $v; } return $c[$k]; }
function setSet(string $k, $v): void { if (one('SELECT klucz FROM ustawienia WHERE klucz=?', [$k])) q('UPDATE ustawienia SET wartosc=? WHERE klucz=?', [je($v), $k]); else q('INSERT INTO ustawienia (klucz,wartosc) VALUES (?,?)', [$k, je($v)]); $c = &setCache(); unset($c[$k]); }
function lokSet(array $o): array { $d = defaultsLok(); $u = jd($o['ustawienia'] ?? null, []); foreach ($d as $k => $v) { if (!array_key_exists($k, $u)) $u[$k] = $v; elseif (is_array($v) && is_array($u[$k]) && array_keys($v) !== range(0, count($v) - 1)) $u[$k] = array_replace($v, $u[$k]); } if (is_array($u['dokiOff']) && !$u['dokiOff']) $u['dokiOff'] = new stdClass(); return $u; }
function lokRow(string $id): ?array { return one('SELECT * FROM oddzialy WHERE id=?', [$id]); }
function palTypes(bool $activeOnly = false): array { $p = getSet('palety'); return array_values(array_filter($p, fn($x) => !$activeOnly || !empty($x['aktywny']))); }

/** Doba i zmiana wg godzin zmian oddziału [I, II, III] */
function dayKey(int $ms, array $z = [6, 14, 22]): string { $t = intdiv($ms, 1000); if ((int) date('G', $t) < (int) $z[0]) $t -= 86400; return date('Y-m-d', $t); }
function shiftOf(int $ms, array $z = [6, 14, 22]): string { $h = (int) date('G', intdiv($ms, 1000)); return $h >= $z[0] && $h < $z[1] ? 'I' : ($h >= $z[1] && $h < $z[2] ? 'II' : 'III'); }

/* ---------- polityka haseł ---------- */
function pwCheck(string $pw): ?string {
    $r = getSet('hasla'); $min = max(6, (int) $r['min']);
    if (mb_strlen($pw) < $min) return "Hasło musi mieć co najmniej $min znaków.";
    if (!empty($r['cyfra']) && !preg_match('/\d/', $pw)) return 'Hasło musi zawierać cyfrę.';
    if (!empty($r['wielka']) && !preg_match('/\p{Lu}/u', $pw)) return 'Hasło musi zawierać wielką literę.';
    if (!empty($r['specjalny']) && !preg_match('/[^\p{L}\d]/u', $pw)) return 'Hasło musi zawierać znak specjalny.';
    return null;
}

/* ---------- TOTP (RFC 6238) ---------- */
function b32dec(string $b): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $b = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b)); $bits = ''; foreach (str_split($b) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT); $o = ''; foreach (str_split($bits, 8) as $x) if (strlen($x) === 8) $o .= chr(bindec($x)); return $o; }
function b32new(): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = ''; for ($i = 0; $i < 32; $i++) $s .= $a[random_int(0, 31)]; return $s; }
function totpCode(string $secret, int $step): string { $k = b32dec($secret); $h = hash_hmac('sha1', pack('N*', 0, $step), $k, true); $o = ord($h[19]) & 0xf; $v = ((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000; return str_pad((string) $v, 6, '0', STR_PAD_LEFT); }
function totpOk(string $secret, string $code): bool { $code = preg_replace('/\D/', '', $code); if (strlen($code) !== 6) return false; $t = intdiv(time(), 30); for ($i = -1; $i <= 1; $i++) if (hash_equals(totpCode($secret, $t + $i), $code)) return true; return false; }

/* ---------- IP ---------- */
function ipAllowed(string $ip, array $list): bool {
    if (!$list) return true;
    foreach ($list as $e) { $e = trim((string) $e); if ($e === '') continue;
        if (strpos($e, '/') !== false) { [$net, $bits] = explode('/', $e, 2); $a = @inet_pton($ip); $n = @inet_pton($net); if ($a === false || $n === false || strlen($a) !== strlen($n)) continue; $bits = (int) $bits; $bytes = intdiv($bits, 8); $rem = $bits % 8;
            if (substr($a, 0, $bytes) !== substr($n, 0, $bytes)) continue; if ($rem && ((ord($a[$bytes]) ^ ord($n[$bytes])) & (0xff << (8 - $rem)) & 0xff)) continue; return true; }
        if ($e === $ip || (substr($e, -1) === '.' && strpos($ip, $e) === 0)) return true; }
    return false;
}

/* ---------- odpowiedzi ---------- */
function out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400, array $extra = []): void { out(['blad' => $msg] + $extra, $code); }
function input(): array {
    static $in = null;
    if ($in === null) { $raw = file_get_contents('php://input'); $in = $raw ? (json_decode($raw, true) ?: []) : []; }
    return $in;
}
function str($v, int $max = 100): string { return mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max); }

function logAction(string $who, string $action, ?string $lok, string $desc): void {
    q('INSERT INTO dziennik (kiedy,kto,akcja,oddzial,opis) VALUES (?,?,?,?,?)', [nowMs(), mb_substr($who, 0, 100), $action, $lok, mb_substr($desc, 0, 2000)]);
}

/* ---------- schemat i migracje ---------- */
function schema(): array {
    $s = isSqlite();
    $pk = $s ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $s ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $sql = [
        "CREATE TABLE IF NOT EXISTS oddzialy (id VARCHAR(20) PRIMARY KEY, nazwa VARCHAR(60) NOT NULL, kolejnosc INT NOT NULL DEFAULT 0, doki TEXT NOT NULL, auto_logout INT NOT NULL DEFAULT 5, serwis_hash VARCHAR(64) NULL)$eng",
        "CREATE TABLE IF NOT EXISTS uzytkownicy (id $pk, login VARCHAR(40) NOT NULL UNIQUE, imie VARCHAR(100) NOT NULL, hash VARCHAR(255) NOT NULL, rola VARCHAR(10) NOT NULL, oddzialy TEXT NOT NULL, prawa TEXT NOT NULL, aktywny INT NOT NULL DEFAULT 1, utworzono BIGINT NOT NULL, ostatnio BIGINT NULL)$eng",
        "CREATE TABLE IF NOT EXISTS operatorzy (id $pk, oddzial VARCHAR(20) NOT NULL, imie VARCHAR(100) NOT NULL, nr VARCHAR(40) NULL, rfid_hash CHAR(64) NULL, pin_hash CHAR(64) NULL, aktywny INT NOT NULL DEFAULT 1, utworzono BIGINT NOT NULL)$eng",
        "CREATE TABLE IF NOT EXISTS terminale (id $pk, oddzial VARCHAR(20) NOT NULL, nazwa VARCHAR(60) NOT NULL, token_hash CHAR(64) NULL, kod VARCHAR(12) NULL, kod_wazny BIGINT NULL, aktywny INT NOT NULL DEFAULT 1, ostatnio BIGINT NULL, ip VARCHAR(45) NULL, kolejka INT NULL, utworzono BIGINT NOT NULL)$eng",
        "CREATE TABLE IF NOT EXISTS zaladunki (id $pk, uuid VARCHAR(40) NOT NULL UNIQUE, oddzial VARCHAR(20) NOT NULL, terminal_id INT NULL, doba VARCHAR(10) NOT NULL, zmiana VARCHAR(3) NOT NULL, dok VARCHAR(40) NOT NULL, load_id VARCHAR(60) NOT NULL, start_ms BIGINT NOT NULL, start_op INT NULL, start_name VARCHAR(100) NULL, end_ms BIGINT NULL, end_op INT NULL, end_name VARCHAR(100) NULL, status VARCHAR(12) NOT NULL, pal_euro INT NULL, pal_chep INT NULL, pal_qpal INT NULL, palety INT NULL, korekta INT NOT NULL DEFAULT 0, usuniety INT NOT NULL DEFAULT 0, zmieniono BIGINT NOT NULL)$eng",
        "CREATE TABLE IF NOT EXISTS dziennik (id $pk, kiedy BIGINT NOT NULL, kto VARCHAR(100) NOT NULL, akcja VARCHAR(40) NOT NULL, oddzial VARCHAR(20) NULL, opis TEXT NULL)$eng",
        // wersja 2
        "CREATE TABLE IF NOT EXISTS ustawienia (klucz VARCHAR(40) PRIMARY KEY, wartosc TEXT NOT NULL)$eng",
        "CREATE TABLE IF NOT EXISTS sesje (id CHAR(64) PRIMARY KEY, user_id INT NOT NULL, ip VARCHAR(45) NULL, agent VARCHAR(200) NULL, utworzono BIGINT NOT NULL, ostatnio BIGINT NOT NULL, uniewazniona INT NOT NULL DEFAULT 0)$eng",
        "CREATE TABLE IF NOT EXISTS przestoje (id $pk, uuid VARCHAR(40) NOT NULL UNIQUE, oddzial VARCHAR(20) NOT NULL, terminal_id INT NULL, dok VARCHAR(40) NULL, powod VARCHAR(80) NOT NULL, op INT NULL, op_name VARCHAR(100) NULL, start_ms BIGINT NOT NULL, end_ms BIGINT NULL, uwagi TEXT NULL, doba VARCHAR(10) NOT NULL, zmiana VARCHAR(3) NOT NULL, usuniety INT NOT NULL DEFAULT 0, zmieniono BIGINT NOT NULL)$eng",
        "CREATE TABLE IF NOT EXISTS dokumenty (id $pk, oddzial VARCHAR(20) NULL, modul VARCHAR(20) NULL, kategoria VARCHAR(60) NOT NULL, tytul VARCHAR(120) NOT NULL, opis TEXT NULL, plik VARCHAR(120) NOT NULL, nazwa VARCHAR(160) NOT NULL, typ VARCHAR(10) NOT NULL, rozmiar INT NOT NULL, kolejnosc INT NOT NULL DEFAULT 0, aktywny INT NOT NULL DEFAULT 1, autor VARCHAR(100) NOT NULL, utworzono BIGINT NOT NULL, zmieniono BIGINT NOT NULL)$eng",
        "CREATE TABLE IF NOT EXISTS alerty (id $pk, kiedy BIGINT NOT NULL, oddzial VARCHAR(20) NULL, typ VARCHAR(20) NOT NULL, klucz VARCHAR(120) NOT NULL, tresc TEXT NOT NULL, kanaly VARCHAR(60) NULL, status VARCHAR(200) NULL)$eng",
        "CREATE TABLE IF NOT EXISTS kompletacje (id $pk, uuid VARCHAR(40) NOT NULL UNIQUE, oddzial VARCHAR(20) NOT NULL, terminal_id INT NULL, doba VARCHAR(10) NOT NULL, zmiana VARCHAR(3) NOT NULL, load_id VARCHAR(60) NOT NULL, start_ms BIGINT NOT NULL, start_op INT NULL, start_name VARCHAR(100) NULL, end_ms BIGINT NULL, end_op INT NULL, end_name VARCHAR(100) NULL, status VARCHAR(12) NOT NULL, car INT NULL, crt INT NULL, tra INT NULL, keg INT NULL, palety INT NULL, korekta INT NOT NULL DEFAULT 0, usuniety INT NOT NULL DEFAULT 0, zmieniono BIGINT NOT NULL)$eng",
        "CREATE TABLE IF NOT EXISTS komunikaty (id $pk, oddzial VARCHAR(20) NULL, tresc TEXT NOT NULL, priorytet VARCHAR(10) NOT NULL DEFAULT 'info', od_ms BIGINT NOT NULL, do_ms BIGINT NULL, autor VARCHAR(100) NOT NULL, utworzono BIGINT NOT NULL)$eng",
    ];
    $idx = [
        'CREATE INDEX ix_op_odd ON operatorzy (oddzial)',
        'CREATE INDEX ix_zal_odd_doba ON zaladunki (oddzial, doba)',
        'CREATE INDEX ix_zal_zm ON zaladunki (zmieniono)',
        'CREATE INDEX ix_dz_kiedy ON dziennik (kiedy)',
        'CREATE INDEX ix_prz_odd_doba ON przestoje (oddzial, doba)',
        'CREATE INDEX ix_ses_user ON sesje (user_id)',
        'CREATE UNIQUE INDEX ix_al_klucz ON alerty (klucz)',
        'CREATE INDEX ix_kmp_odd_doba ON kompletacje (oddzial, doba)',
        'CREATE INDEX ix_al_kiedy ON alerty (kiedy)',
    ];
    $cols = [   // [tabela, kolumna, definicja]
        ['oddzialy', 'ustawienia', 'TEXT NULL'],
        ['uzytkownicy', 'wymus_zmiane', 'INT NOT NULL DEFAULT 0'], ['uzytkownicy', 'haslo_zmienione', 'BIGINT NULL'],
        ['uzytkownicy', 'totp_secret', 'VARCHAR(64) NULL'], ['uzytkownicy', 'totp_on', 'INT NOT NULL DEFAULT 0'], ['uzytkownicy', 'limit_dni', 'INT NOT NULL DEFAULT 0'],
        ['operatorzy', 'oddzialy_dod', 'TEXT NULL'], ['operatorzy', 'udt_do', 'VARCHAR(10) NULL'],
        ['zaladunki', 'pal_json', 'TEXT NULL'], ['zaladunki', 'rejestracja', 'VARCHAR(20) NULL'], ['zaladunki', 'uwagi', 'TEXT NULL'], ['zaladunki', 'zdjecie', 'VARCHAR(80) NULL'], ['zaladunki', 'checklista', 'TEXT NULL'],
        ['operatorzy', 'moduly', 'TEXT NULL'], ['terminale', 'moduly', 'TEXT NULL'], ['terminale', 'tryb', 'VARCHAR(12) NULL'],
    ];
    return [$sql, $idx, $cols];
}
function migrate(): void {
    [$tables, $idx, $cols] = schema();
    foreach ($tables as $s) db()->exec($s);
    foreach ($cols as [$t, $c, $def]) { try { db()->exec("ALTER TABLE $t ADD COLUMN $c $def"); } catch (Throwable $e) { /* kolumna istnieje */ } }
    foreach ($idx as $s) { try { db()->exec($s); } catch (Throwable $e) { /* indeks istnieje */ } }
    // stare uprawnienie 'korekty' -> trzy osobne
    foreach (all('SELECT id, prawa FROM uzytkownicy') as $u) { $p = jd($u['prawa'], []); if (is_array($p) && array_key_exists('korekty', $p)) { $v = !empty($p['korekty']); unset($p['korekty']); $p += ['korekta_palety' => $v, 'korekta_zamknij' => $v, 'korekta_usun' => $v, 'terminale' => !empty($p['konfiguracja'])]; q('UPDATE uzytkownicy SET prawa=? WHERE id=?', [je($p ?: new stdClass()), $u['id']]); } }
    q('UPDATE uzytkownicy SET haslo_zmienione=? WHERE haslo_zmienione IS NULL', [nowMs()]);
    setSet('schemat', SCHEMA_VERSION);
}
/* =================== MODUŁY =================== */
/** Rejestr modułów aplikacji. gotowy=1 – moduł wdrożony (ma katalog moduly/<id>/modul.php i prefiks hooków). */
function modulyDef(): array {
    return [
        ['id' => 'zaladunki', 'nazwa' => 'Załadunki', 'opis' => 'Bramy załadunkowe: doki, Load ID, lista kontrolna, palety, przestoje, raporty', 'gotowy' => 1, 'prefiks' => 'zal'],
        ['id' => 'surowce', 'nazwa' => 'Magazyn materiałów i surowców', 'opis' => 'Obsługa magazynu materiałów i surowców', 'gotowy' => 0, 'prefiks' => 'sur'],
        ['id' => 'opakowania', 'nazwa' => 'Magazyn Pustych Opakowań', 'opis' => 'Obsługa magazynu pustych opakowań', 'gotowy' => 0, 'prefiks' => 'mpo'],
        ['id' => 'kompletacja', 'nazwa' => 'Strefa Kompletacji', 'opis' => 'Kompletacja zleceń: Load ID, ilości CAR / CRT / TRA / KEG i palety', 'gotowy' => 1, 'prefiks' => 'kmp'],
        ['id' => 'przepakowania', 'nazwa' => 'Przepakowania', 'opis' => 'Rejestracja przepakowań', 'gotowy' => 0, 'prefiks' => 'prz'],
        ['id' => 'utylizacja', 'nazwa' => 'Utylizacja Piwa', 'opis' => 'Rejestracja utylizacji piwa', 'gotowy' => 0, 'prefiks' => 'uty'],
        ['id' => 'inne', 'nazwa' => 'Inne czynności', 'opis' => 'Pozostałe czynności magazynowe', 'gotowy' => 0, 'prefiks' => 'inn'],
    ];
}
function modulyGotowe(): array { return array_values(array_filter(modulyDef(), fn($m) => !empty($m['gotowy']))); }
function modulyIds(): array { return array_column(modulyGotowe(), 'id'); }
/** Moduły włączone w oddziale (tylko gotowe) */
function lokMods(array $o): array { $u = lokSet($o); return array_values(array_intersect(array_values((array) ($u['moduly'] ?? ['zaladunki'])), modulyIds())); }
/** Wywołuje hook we wszystkich gotowych modułach; zwraca wyniki [id => wynik] */
function modHook(string $hook, ...$args): array { $o = []; foreach (modulyGotowe() as $m) { $f = $m['prefiks'] . '_' . $hook; if (function_exists($f)) $o[$m['id']] = $f(...$args); } return $o; }
foreach (modulyGotowe() as $m) { $f = __DIR__ . '/moduly/' . $m['id'] . '/modul.php'; if (is_file($f)) require_once $f; }
require_once __DIR__ . '/alerty.php';

function ensureSchema(): void {
    try { $v = (int) (one("SELECT wartosc FROM ustawienia WHERE klucz='schemat'")['wartosc'] ?? 0); } catch (Throwable $e) { $v = 0; }
    if ($v < SCHEMA_VERSION) migrate();
}
