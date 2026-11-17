<?php
/**
 * MODUŁ: ZAŁADUNKI (bramy załadunkowe)
 * Doki, Load ID, lista kontrolna, palety, przestoje, zdjęcia, raporty e-mail.
 * Hooki wywoływane przez rdzeń: zal_trasy, zal_sync, zal_ja, zal_ustawienia, zal_cron, zal_retencja.
 */
declare(strict_types=1);

function palOf(array $z): ?array {
    if ($z['palety'] === null) return null;
    $j = jd($z['pal_json'] ?? null, null);
    if (is_array($j)) return array_map('intval', $j);
    return ['euro' => (int) $z['pal_euro'], 'chep' => (int) $z['pal_chep'], 'qpal' => (int) $z['pal_qpal']];
}
function zRow(array $z): array {
    return ['uuid' => $z['uuid'], 'lok' => $z['oddzial'], 'doba' => $z['doba'], 'zmiana' => $z['zmiana'], 'dock' => $z['dok'], 'loadId' => $z['load_id'],
        'startMs' => (int) $z['start_ms'], 'startBy' => $z['start_op'] !== null ? (int) $z['start_op'] : null, 'startName' => $z['start_name'],
        'endMs' => $z['end_ms'] !== null ? (int) $z['end_ms'] : null, 'endBy' => $z['end_op'] !== null ? (int) $z['end_op'] : null, 'endName' => $z['end_name'],
        'status' => $z['status'], 'pal' => palOf($z), 'palety' => $z['palety'] !== null ? (int) $z['palety'] : null, 'korekta' => (int) $z['korekta'],
        'terminal' => $z['terminal_id'] !== null ? (int) $z['terminal_id'] : null, 'rejestracja' => $z['rejestracja'] ?? null, 'uwagi' => $z['uwagi'] ?? null, 'zdjecie' => !empty($z['zdjecie']) ? 1 : 0, 'checklista' => jd($z['checklista'] ?? null, null), 'zmieniono' => (int) $z['zmieniono']];
}
function pRow(array $p): array {
    return ['uuid' => $p['uuid'], 'lok' => $p['oddzial'], 'doba' => $p['doba'], 'zmiana' => $p['zmiana'], 'dock' => $p['dok'], 'powod' => $p['powod'], 'op' => $p['op'] !== null ? (int) $p['op'] : null, 'opName' => $p['op_name'],
        'startMs' => (int) $p['start_ms'], 'endMs' => $p['end_ms'] !== null ? (int) $p['end_ms'] : null, 'uwagi' => $p['uwagi'], 'terminal' => $p['terminal_id'] !== null ? (int) $p['terminal_id'] : null];
}
function cleanPal($p): array { $o = []; foreach (palTypes() as $t) { $o[$t['k']] = max(0, min(999, (int) (is_array($p) ? ($p[$t['k']] ?? 0) : 0))); } return $o; }
function palCols(array $p): array { return [$p['euro'] ?? 0, $p['chep'] ?? 0, $p['qpal'] ?? 0, array_sum($p), je($p)]; }

/** Trasy API modułu – wywoływane przez rdzeń dla nieznanych w rdzeniu operacji */
function zal_trasy(string $r): void {
    global $method, $ip;
    switch ($r) {
case 't_push': {
    if ($method !== 'POST') fail('Wymagane POST', 405);
    $t = terminal(); needTermMod($t, 'zaladunki'); $lok = $t['oddzial']; $ust = lokSet(lokRow($lok)); $zm = $ust['zmiany'];
    $ev = input()['zdarzenia'] ?? []; if (!is_array($ev) || count($ev) > 200) fail('Nieprawidłowe dane');
    $ok = []; $err = []; $opIds = array_map(fn($o) => (int) $o['id'], opsForLok($lok, false));
    $opOk = fn($id) => $id && in_array((int) $id, $opIds, true);
    foreach ($ev as $e) {
        $uuid = str($e['uuid'] ?? '', 40); if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uuid)) { $err[] = ['uuid' => $uuid, 'blad' => 'uuid']; continue; }
        $typ = $e['typ'] ?? '';
        if ($typ === 'start') {
            if (one('SELECT id FROM zaladunki WHERE uuid=?', [$uuid])) { $ok[] = $uuid; continue; }
            $sm = (int) ($e['startMs'] ?? 0); if ($sm < 1600000000000 || $sm > nowMs() + 86400000) { $err[] = ['uuid' => $uuid, 'blad' => 'czas']; continue; }
            if (!$opOk($e['startBy'] ?? 0)) { $err[] = ['uuid' => $uuid, 'blad' => 'operator']; continue; }
            $ck = null; if (isset($e['checklista']) && is_array($e['checklista'])) { $ck = []; foreach (array_slice($e['checklista'], 0, 30) as $c) $ck[] = ['id' => str($c['id'] ?? '', 30), 'tekst' => str($c['tekst'] ?? '', 200), 'ok' => !empty($c['ok']) ? 1 : 0]; $ck = je(['ms' => (int) ($e['checklistaMs'] ?? $sm), 'pozycje' => $ck]); }
            q('INSERT INTO zaladunki (uuid,oddzial,terminal_id,doba,zmiana,dok,load_id,start_ms,start_op,start_name,status,korekta,usuniety,zmieniono,checklista) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,?,?)',
                [$uuid, $lok, $t['id'], dayKey($sm, $zm), shiftOf($sm, $zm), str($e['dock'] ?? '', 40), strtoupper(str($e['loadId'] ?? '', 60)), $sm, (int) $e['startBy'], str($e['startName'] ?? ''), 'w_toku', nowMs(), $ck]);
            $ok[] = $uuid;
        } elseif ($typ === 'koniec') {
            $z = one('SELECT * FROM zaladunki WHERE uuid=? AND oddzial=?', [$uuid, $lok]);
            if (!$z) { $err[] = ['uuid' => $uuid, 'blad' => 'brak']; continue; }
            if ($z['status'] === 'zakonczony' || (int) $z['usuniety'] === 1) { $ok[] = $uuid; continue; }
            $p = cleanPal($e['pal'] ?? []); [$pe, $pc, $pq, $sum, $pj] = palCols($p);
            q('UPDATE zaladunki SET end_ms=?, end_op=?, end_name=?, status=?, pal_euro=?, pal_chep=?, pal_qpal=?, palety=?, pal_json=?, rejestracja=?, uwagi=?, zmieniono=? WHERE id=?',
                [(int) ($e['endMs'] ?? nowMs()), $opOk($e['endBy'] ?? 0) ? (int) $e['endBy'] : null, str($e['endName'] ?? ''), 'zakonczony', $pe, $pc, $pq, $sum, $pj,
                    strtoupper(str($e['rejestracja'] ?? '', 20)) ?: null, str($e['uwagi'] ?? '', 1000) ?: null, nowMs(), $z['id']]);
            $ok[] = $uuid;
        } elseif ($typ === 'przestoj_start') {
            if (one('SELECT id FROM przestoje WHERE uuid=?', [$uuid])) { $ok[] = $uuid; continue; }
            $sm = (int) ($e['startMs'] ?? 0); if ($sm < 1600000000000) { $err[] = ['uuid' => $uuid, 'blad' => 'czas']; continue; }
            q('INSERT INTO przestoje (uuid,oddzial,terminal_id,dok,powod,op,op_name,start_ms,doba,zmiana,usuniety,zmieniono) VALUES (?,?,?,?,?,?,?,?,?,?,0,?)',
                [$uuid, $lok, $t['id'], str($e['dock'] ?? '', 40) ?: null, str($e['powod'] ?? 'Inne', 80), $opOk($e['op'] ?? 0) ? (int) $e['op'] : null, str($e['opName'] ?? ''), $sm, dayKey($sm, $zm), shiftOf($sm, $zm), nowMs()]);
            $ok[] = $uuid;
        } elseif ($typ === 'przestoj_koniec') {
            q('UPDATE przestoje SET end_ms=?, uwagi=?, zmieniono=? WHERE uuid=? AND oddzial=? AND end_ms IS NULL', [(int) ($e['endMs'] ?? nowMs()), str($e['uwagi'] ?? '', 1000) ?: null, nowMs(), $uuid, $lok]);
            $ok[] = $uuid;
        } else $err[] = ['uuid' => $uuid, 'blad' => 'typ'];
    }
    out(['ok' => $ok, 'bledy' => $err]);
}
case 't_foto': {
    if ($method !== 'POST') fail('Wymagane POST', 405);
    $t = terminal(); needTermMod($t, 'zaladunki'); $in = input(); $uuid = str($in['uuid'] ?? '', 40);
    $z = one('SELECT id FROM zaladunki WHERE uuid=? AND oddzial=?', [$uuid, $t['oddzial']]); if (!$z) fail('brak', 404);
    $bin = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', (string) ($in['dane'] ?? '')), true);
    if (!$bin || strlen($bin) > 3000000 || substr($bin, 0, 3) !== "\xFF\xD8\xFF") fail('Nieprawidłowe zdjęcie');
    $dir = dirname(__DIR__) . '/data/zdjecia'; if (!is_dir($dir)) mkdir($dir, 0750, true);
    $name = preg_replace('/[^A-Za-z0-9_-]/', '', $uuid) . '.jpg'; file_put_contents("$dir/$name", $bin);
    q('UPDATE zaladunki SET zdjecie=?, zmieniono=? WHERE id=?', [$name, nowMs(), $z['id']]);
    out(['ok' => 1]);
}
case 'dane': case 'przestoje': {
    me(); needMod('zaladunki'); $od = (string) ($_GET['od'] ?? ''); $do = (string) ($_GET['do'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $do) || $od > $do) fail('Nieprawidłowy zakres dat');
    if ((strtotime($do) - strtotime($od)) / 86400 > 400) fail('Maksymalny zakres to 400 dni');
    if (($md = minDoba()) && $od < $md) $od = $md;
    $loks = array_column(lokList(), 'id'); if (!$loks) out([$r === 'dane' ? 'zaladunki' : 'przestoje' => []]);
    $in = implode(',', array_fill(0, count($loks), '?'));
    if ($r === 'dane') out(['zaladunki' => array_map('zRow', all("SELECT * FROM zaladunki WHERE usuniety=0 AND doba>=? AND doba<=? AND oddzial IN ($in) ORDER BY start_ms", array_merge([$od, $do], $loks))), 'od' => $od, 'czas' => nowMs()]);
    out(['przestoje' => array_map('pRow', all("SELECT * FROM przestoje WHERE usuniety=0 AND doba>=? AND doba<=? AND oddzial IN ($in) ORDER BY start_ms", array_merge([$od, $do], $loks))), 'od' => $od]);
}
case 'zdjecie': {
    me(); needMod('zaladunki'); $z = one('SELECT oddzial, zdjecie FROM zaladunki WHERE uuid=?', [str($_GET['uuid'] ?? '', 40)]);
    if (!$z || !$z['zdjecie'] || !allowed($z['oddzial'])) { http_response_code(404); exit; }
    $f = dirname(__DIR__) . '/data/zdjecia/' . basename($z['zdjecie']); if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); readfile($f); exit;
}
case 'korekta': {
    requirePost(); needMod('zaladunki'); $in = input(); $typ = (string) ($in['typ'] ?? '');
    if (!in_array($typ, ['palety', 'zamknij', 'usun'], true)) fail('Nieznany typ korekty');
    need('korekta_' . $typ);
    if (($in['rodzaj'] ?? '') === 'przestoj') {
        $p = one('SELECT * FROM przestoje WHERE uuid=? AND usuniety=0', [str($in['uuid'] ?? '', 40)]); if (!$p) fail('Nie znaleziono', 404); needLok($p['oddzial']);
        if (!corrLimitOk($p['doba'])) fail('Przekroczony limit dni na korekty dla Twojego konta.', 403);
        if ($typ === 'usun') q('UPDATE przestoje SET usuniety=1, zmieniono=? WHERE id=?', [nowMs(), $p['id']]);
        else q('UPDATE przestoje SET end_ms=COALESCE(end_ms,?), zmieniono=? WHERE id=?', [nowMs(), nowMs(), $p['id']]);
        logAction(who(), $typ === 'usun' ? 'przestoj_usun' : 'przestoj_zamknij', $p['oddzial'], $p['powod'] . ' (' . $p['doba'] . ')'); out(['ok' => 1]);
    }
    $z = one('SELECT * FROM zaladunki WHERE uuid=? AND usuniety=0', [str($in['uuid'] ?? '', 40)]); if (!$z) fail('Nie znaleziono załadunku', 404);
    needLok($z['oddzial']); if (($md = minDoba()) && $z['doba'] < $md) fail('Brak dostępu do tej doby.', 403);
    if (!corrLimitOk($z['doba'])) fail('Przekroczony limit dni na korekty dla Twojego konta (' . me()['limit_dni'] . ' dni).', 403);
    $desc = $z['dok'] . ' · ' . $z['load_id'] . ' (' . $z['doba'] . ')';
    if ($typ === 'usun') { q('UPDATE zaladunki SET usuniety=1, korekta=1, zmieniono=? WHERE id=?', [nowMs(), $z['id']]); logAction(who(), 'korekta_usun', $z['oddzial'], 'Usunięto: ' . $desc); out(['ok' => 1]); }
    if ($typ === 'zamknij' && $z['status'] !== 'w_toku') fail('Załadunek jest już zakończony.');
    $p = cleanPal($in['pal'] ?? []); [$pe, $pc, $pq, $sum, $pj] = palCols($p); $old = palOf($z); $m = me();
    q('UPDATE zaladunki SET pal_euro=?, pal_chep=?, pal_qpal=?, palety=?, pal_json=?, status=?, end_ms=?, end_name=COALESCE(end_name,?), korekta=1, zmieniono=? WHERE id=?',
        [$pe, $pc, $pq, $sum, $pj, 'zakonczony', $z['end_ms'] ?? nowMs(), 'Korekta: ' . $m['imie'], nowMs(), $z['id']]);
    $fmt = fn($x) => $x === null ? 'brak' : implode('/', array_values($x));
    logAction(who(), $typ === 'zamknij' ? 'korekta_zamknij' : 'korekta_palety', $z['oddzial'], $desc . ' · palety ' . $fmt($old) . ' → ' . $fmt($p));
    out(['ok' => 1]);
}
case 'operator_historia': {
    need('operatorzy'); needMod('zaladunki'); $o = one('SELECT * FROM operatorzy WHERE id=?', [(int) ($_GET['id'] ?? 0)]); if (!$o) fail('Nie znaleziono', 404); needLok($o['oddzial']);
    $dni = max(7, min(365, (int) ($_GET['dni'] ?? 90))); $od = date('Y-m-d', time() - ($dni - 1) * 86400);
    if (($md = minDoba()) && $od < $md) $od = $md;
    $z = all('SELECT * FROM zaladunki WHERE usuniety=0 AND doba>=? AND (start_op=? OR end_op=?) ORDER BY start_ms DESC', [$od, $o['id'], $o['id']]);
    $p = all('SELECT * FROM przestoje WHERE usuniety=0 AND doba>=? AND op=? ORDER BY start_ms DESC', [$od, $o['id']]);
    $k = all("SELECT kiedy,kto,akcja,opis FROM dziennik WHERE akcja LIKE 'operator_%' AND opis LIKE ? ORDER BY id DESC LIMIT 50", ['%' . $o['imie'] . '%']);
    // średnie oddziału w tym samym okresie (do porównania)
    $all = all("SELECT start_op, start_ms, end_ms, palety, korekta, doba FROM zaladunki WHERE usuniety=0 AND status='zakonczony' AND oddzial=? AND doba>=?", [$o['oddzial'], $od]);
    $ops = []; $t = 0; $pal = 0; $kor = 0; foreach ($all as $r) { $ops[(int) $r['start_op']] = 1; $t += (int) $r['end_ms'] - (int) $r['start_ms']; $pal += (int) $r['palety']; $kor += (int) $r['korekta']; }
    $n = count($all); $u = lokSet(lokRow($o['oddzial']));
    $sr = ['zaladunki' => $n, 'operatorow' => count($ops), 'naOperatora' => count($ops) ? round($n / count($ops), 1) : null, 'sredniCzasMs' => $n ? (int) round($t / $n) : null,
        'paletyH' => $t > 0 ? round($pal / ($t / 3600000), 2) : null, 'korektyPct' => $n ? round(100 * $kor / $n, 1) : null, 'dniDanych' => count(array_unique(array_column($all, 'doba'))), 'limitMin' => (int) ($u['limity']['maxCzasMin'] ?? 0)];
    out(['operator' => opRow($o, false), 'od' => $od, 'dni' => $dni, 'zaladunki' => array_map('zRow', $z), 'przestoje' => array_map('pRow', $p), 'zmiany' => $k, 'srednia' => $sr]);
}
case 'raport_test': {
    requirePost(); needMain(); $lok = (string) (input()['oddzial'] ?? ''); needLok($lok);
    $res = sendReport($lok, 'dzienny', true); if ($res !== true) fail($res);
    logAction(who(), 'raport_test', $lok, 'Wysłano raport testowy'); out(['ok' => 1]);
}
    }
}

/** Dane modułu dla terminala (t_sync) */
function zal_sync(array $t, string $lok): array {
    $from = date('Y-m-d', time() - 3 * 86400);
    return ['palety' => palTypes(true), 'powody' => getSet('przestoje'), 'checklista' => array_values(array_filter(getSet('checklista'), fn($x) => !empty($x['aktywny']))),
        'zaladunki' => array_map('zRow', all('SELECT * FROM zaladunki WHERE oddzial=? AND doba>=? AND usuniety=0', [$lok, $from])),
        'przestoje' => array_map('pRow', all('SELECT * FROM przestoje WHERE oddzial=? AND doba>=? AND usuniety=0', [$lok, $from]))];
}

/** Dane modułu dla panelu (ja) */
function zal_ja(): array { return canMod('zaladunki') ? ['palety' => palTypes(), 'powody' => getSet('przestoje')] : ['palety' => [], 'powody' => []]; }

/** Ustawienia systemowe modułu; zwraca true, jeśli klucz należy do modułu */
function zal_ustawienia(string $k, $v): bool {
    switch ($k) {
        case 'palety':
            $seen = []; $o = [];
            foreach ((array) $v as $t) { $kk = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($t['k'] ?? ''))); $nz = str($t['nazwa'] ?? '', 40);
                if ($kk === '' || $nz === '' || isset($seen[$kk])) fail('Każdy rodzaj palety musi mieć unikalny klucz i nazwę.'); $seen[$kk] = 1;
                $o[] = ['k' => $kk, 'nazwa' => $nz, 'skrot' => str($t['skrot'] ?? $nz, 12), 'aktywny' => !empty($t['aktywny']) ? 1 : 0]; }
            if (!array_filter($o, fn($x) => $x['aktywny'])) fail('Co najmniej jeden rodzaj palet musi być aktywny.'); if (count($o) > 12) fail('Maksymalnie 12 rodzajów palet.');
            setSet('palety', $o); break;
        case 'przestoje': $l = array_values(array_unique(array_filter(array_map(fn($x) => str($x, 80), (array) $v), fn($x) => $x !== ''))); if (!$l) fail('Podaj co najmniej jeden powód przestoju.'); setSet('przestoje', $l); break;
        case 'checklista':
            $o = []; $seen = [];
            foreach ((array) $v as $c) { $pl = str($c['pl'] ?? '', 200); if ($pl === '') continue;
                $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($c['id'] ?? ''))) ?: 'p' . substr(bin2hex(random_bytes(4)), 0, 6); if (isset($seen[$id])) $id .= substr(bin2hex(random_bytes(2)), 0, 3); $seen[$id] = 1;
                $o[] = ['id' => $id, 'pl' => $pl, 'ua' => str($c['ua'] ?? '', 200), 'en' => str($c['en'] ?? '', 200), 'aktywny' => !empty($c['aktywny']) ? 1 : 0]; }
            if (count($o) > 20) fail('Maksymalnie 20 pozycji listy kontrolnej.'); setSet('checklista', $o); break;
        default: return false;
    }
    return true;
}

/** Zadanie cykliczne modułu: raporty e-mail */
function zal_cron(): array {
    $log = [];
    foreach (all('SELECT * FROM oddzialy ORDER BY kolejnosc') as $o) {
        if (!in_array('zaladunki', lokMods($o), true)) continue; $u = lokSet($o);
        if (!empty($u['raport']['dzienny'])) { $r = sendReport($o['id'], 'dzienny'); $log[] = $o['nazwa'] . ' dzienny: ' . ($r === true ? 'wysłano' : $r); }
        if (!empty($u['raport']['tygodniowy']) && date('N') === '1') { $r = sendReport($o['id'], 'tygodniowy'); $log[] = $o['nazwa'] . ' tygodniowy: ' . ($r === true ? 'wysłano' : $r); }
    }
    return $log;
}
function zal_retencja(): int { return retentionRun(); }

/** Kandydaci na alerty modułu (wywołuje alerty.php) */
function zal_alerty(array $reg): array {
    $c = []; $now = nowMs(); $from = $now - 86400000;
    foreach (all('SELECT * FROM oddzialy') as $o) {
        if (!in_array('zaladunki', lokMods($o), true)) continue; $u = lokSet($o); $mx = (int) ($u['limity']['maxCzasMin'] ?? 0);
        if (!empty($reg['czas']) && $mx > 0)
            foreach (all("SELECT * FROM zaladunki WHERE oddzial=? AND status='w_toku' AND usuniety=0 AND start_ms>? AND start_ms<?", [$o['id'], $from, $now - $mx * 60000]) as $z)
                $c[] = ['lok' => $o['id'], 'typ' => 'czas', 'klucz' => 'czas:' . $z['uuid'],
                    'tresc' => $z['dok'] . ' · Load ID ' . $z['load_id'] . ' · ' . ($z['start_name'] ?: 'operator') . ': załadunek trwa ' . round(($now - (int) $z['start_ms']) / 60000) . ' min (limit ' . $mx . ' min).'];
        if (!empty($reg['przestoj'])) { $pm = (int) ($reg['przestojMin'] ?? 30);
            foreach (all('SELECT * FROM przestoje WHERE oddzial=? AND end_ms IS NULL AND usuniety=0 AND start_ms>? AND start_ms<?', [$o['id'], $from, $now - $pm * 60000]) as $p)
                $c[] = ['lok' => $o['id'], 'typ' => 'przestoj', 'klucz' => 'prz:' . $p['uuid'],
                    'tresc' => 'Przestój „' . $p['powod'] . '”' . ($p['dok'] ? ' · ' . $p['dok'] : '') . ($p['op_name'] ? ' · ' . $p['op_name'] : '') . ' trwa ' . round(($now - (int) $p['start_ms']) / 60000) . ' min (próg ' . $pm . ' min).']; }
    }
    return $c;
}

/* ---------- raporty e-mail ---------- */
// Raporty e-mail (dzienny / tygodniowy) i retencja danych – używane przez cron.php i API.

function rptPal(array $z): array { $j = jd($z['pal_json'] ?? null, null); return is_array($j) ? $j : ['euro' => (int) $z['pal_euro'], 'chep' => (int) $z['pal_chep'], 'qpal' => (int) $z['pal_qpal']]; }

function reportHtml(string $lok, string $od, string $do, string $tytul): string {
    $o = lokRow($lok); $u = lokSet($o); $types = palTypes();
    $z = all('SELECT * FROM zaladunki WHERE oddzial=? AND doba>=? AND doba<=? AND usuniety=0 ORDER BY start_ms', [$lok, $od, $do]);
    $p = all('SELECT * FROM przestoje WHERE oddzial=? AND doba>=? AND doba<=? AND usuniety=0', [$lok, $od, $do]);
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $stat = function (array $rows) use ($types) { $dn = array_filter($rows, fn($r) => $r['status'] === 'zakonczony'); $by = []; foreach ($types as $t) $by[$t['k']] = 0; $czas = 0;
        foreach ($dn as $r) { foreach (rptPal($r) as $k => $v) $by[$k] = ($by[$k] ?? 0) + (int) $v; $czas += ((int) $r['end_ms'] - (int) $r['start_ms']); }
        return ['n' => count($rows), 'dn' => count($dn), 'pal' => array_sum($by), 'by' => $by, 'avg' => count($dn) ? round($czas / count($dn) / 60000) : null]; };
    $all = $stat($z);
    $th = 'style="background:#0f2747;color:#fff;padding:6px 10px;text-align:left;font-size:13px"'; $td = 'style="padding:6px 10px;border-bottom:1px solid #dde3ec;font-size:13px"';
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#14202f;max-width:760px"><h2 style="color:#0f2747;margin:0 0 4px">' . $h($tytul) . '</h2>';
    $html .= '<p style="margin:0 0 14px;color:#5b6b80">Oddział: <b>' . $h($o['nazwa']) . '</b> · okres: ' . $h($od) . ($od !== $do ? ' – ' . $h($do) : '') . '</p>';
    $html .= '<table cellspacing="0" style="border-collapse:collapse;margin-bottom:16px"><tr><th ' . $th . '>Załadunki zakończone</th><th ' . $th . '>W toku</th><th ' . $th . '>Palety razem</th><th ' . $th . '>Śr. czas</th>';
    foreach ($types as $t) $html .= '<th ' . $th . '>' . $h($t['skrot']) . '</th>';
    $html .= '</tr><tr><td ' . $td . '><b>' . $all['dn'] . '</b>' . ($u['cel']['zaladunki'] && $od === $do ? ' / cel ' . $u['cel']['zaladunki'] : '') . '</td><td ' . $td . '>' . ($all['n'] - $all['dn']) . '</td><td ' . $td . '><b>' . $all['pal'] . '</b>' . ($u['cel']['palety'] && $od === $do ? ' / cel ' . $u['cel']['palety'] : '') . '</td><td ' . $td . '>' . ($all['avg'] === null ? '—' : $all['avg'] . ' min') . '</td>';
    foreach ($types as $t) $html .= '<td ' . $td . '>' . ($all['by'][$t['k']] ?? 0) . '</td>';
    $html .= '</tr></table><h3 style="color:#0f2747;margin:8px 0">Zmiany</h3><table cellspacing="0" style="border-collapse:collapse;margin-bottom:16px"><tr><th ' . $th . '>Zmiana</th><th ' . $th . '>Zakończone</th><th ' . $th . '>Palety</th><th ' . $th . '>Śr. czas</th></tr>';
    foreach (['I', 'II', 'III'] as $zm) { $s = $stat(array_filter($z, fn($r) => $r['zmiana'] === $zm)); $html .= '<tr><td ' . $td . '>' . $zm . '</td><td ' . $td . '>' . $s['dn'] . '</td><td ' . $td . '>' . $s['pal'] . '</td><td ' . $td . '>' . ($s['avg'] === null ? '—' : $s['avg'] . ' min') . '</td></tr>'; }
    $html .= '</table>';
    $ops = []; foreach ($z as $r) if ($r['status'] === 'zakonczony') { $k = $r['start_name']; $ops[$k] = $ops[$k] ?? ['n' => 0, 'p' => 0]; $ops[$k]['n']++; $ops[$k]['p'] += (int) $r['palety']; }
    uasort($ops, fn($a, $b) => $b['n'] <=> $a['n']);
    if ($ops) { $html .= '<h3 style="color:#0f2747;margin:8px 0">Operatorzy</h3><table cellspacing="0" style="border-collapse:collapse;margin-bottom:16px"><tr><th ' . $th . '>Operator</th><th ' . $th . '>Załadunki</th><th ' . $th . '>Palety</th></tr>';
        foreach (array_slice($ops, 0, 20, true) as $n => $v) $html .= '<tr><td ' . $td . '>' . $h($n) . '</td><td ' . $td . '>' . $v['n'] . '</td><td ' . $td . '>' . $v['p'] . '</td></tr>'; $html .= '</table>'; }
    if ($p) { $pp = []; foreach ($p as $x) { $k = $x['powod']; $pp[$k] = ($pp[$k] ?? 0) + (int) round((((int) ($x['end_ms'] ?? nowMs())) - (int) $x['start_ms']) / 60000); } arsort($pp);
        $html .= '<h3 style="color:#0f2747;margin:8px 0">Przestoje</h3><table cellspacing="0" style="border-collapse:collapse;margin-bottom:16px"><tr><th ' . $th . '>Powód</th><th ' . $th . '>Łącznie [min]</th></tr>';
        foreach ($pp as $k => $v) $html .= '<tr><td ' . $td . '>' . $h($k) . '</td><td ' . $td . '>' . $v . '</td></tr>'; $html .= '</table>'; }
    $long = array_filter($z, fn($r) => $r['status'] === 'w_toku' && $u['limity']['maxCzasMin'] > 0 && nowMs() - (int) $r['start_ms'] > $u['limity']['maxCzasMin'] * 60000);
    if ($long) $html .= '<p style="color:#c63a3a"><b>Uwaga:</b> ' . count($long) . ' załadunków w toku dłużej niż ' . $u['limity']['maxCzasMin'] . ' min.</p>';
    return $html . '<p style="color:#8a97a8;font-size:12px">Wiadomość wygenerowana automatycznie przez Rejestr załadunków ESL.</p></div>';
}

/** Zwraca true albo komunikat błędu. */
function sendReport(string $lok, string $typ, bool $test = false) {
    $o = lokRow($lok); if (!$o) return 'Brak oddziału';
    $u = lokSet($o); $to = trim((string) $u['raport']['email']); if ($to === '') return 'Brak adresów e-mail w ustawieniach raportów oddziału.';
    $d = dayKey(nowMs() - 86400000, $u['zmiany']);   // poprzednia doba
    if ($typ === 'tygodniowy') { $od = date('Y-m-d', strtotime($d) - 6 * 86400); $tyt = 'Raport tygodniowy załadunków'; } else { $od = $d; $tyt = 'Raport dzienny załadunków'; }
    $html = reportHtml($lok, $od, $d, ($test ? '[TEST] ' : '') . $tyt . ' – ' . $o['nazwa']);
    $from = trim((string) (getSet('raporty')['nadawca'] ?? ''));
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n" . ($from !== '' ? "From: Rejestr załadunków ESL <$from>\r\n" : '');
    $subj = '=?UTF-8?B?' . base64_encode(($test ? '[TEST] ' : '') . $tyt . ' – ' . $o['nazwa'] . ' – ' . $d) . '?=';
    if (!function_exists('mail') || !@mail($to, $subj, $html, $headers)) return 'Serwer nie wysłał wiadomości (funkcja mail). Sprawdź adres nadawcy w Ustawieniach systemu.';
    return true;
}

function retentionRun(): int {
    $l = (int) (getSet('retencja')['lata'] ?? 0); if ($l <= 0) return 0;
    $cut = date('Y-m-d', strtotime("-$l years")); $cutMs = strtotime($cut) * 1000; $n = 0;
    foreach (all('SELECT zdjecie FROM zaladunki WHERE doba<? AND zdjecie IS NOT NULL', [$cut]) as $z) @unlink(__DIR__ . '/data/zdjecia/' . basename($z['zdjecie']));
    $n += q('DELETE FROM zaladunki WHERE doba<?', [$cut])->rowCount();
    $n += q('DELETE FROM przestoje WHERE doba<?', [$cut])->rowCount();
    $n += q('DELETE FROM dziennik WHERE kiedy<?', [$cutMs])->rowCount();
    $n += q('DELETE FROM sesje WHERE ostatnio<?', [nowMs() - 30 * 86400000])->rowCount();
    return $n;
}
