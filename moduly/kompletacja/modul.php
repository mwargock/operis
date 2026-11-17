<?php
/**
 * MODUŁ: STREFA KOMPLETACJI
 * Operator rozpoczyna kompletację (Load ID → Potwierdź → wylogowanie), a po ponownym zalogowaniu kończy ją,
 * podając ilości: CAR (kartony), CRT (skrzynki), TRA (zgrzewki), KEG (kegi) i opcjonalnie liczbę palet.
 * Hooki rdzenia: kmp_trasy, kmp_sync, kmp_ja, kmp_retencja.
 */
declare(strict_types=1);

const KMP_JEDN = ['car' => 'CAR (kartony)', 'crt' => 'CRT (skrzynki)', 'tra' => 'TRA (zgrzewki)', 'keg' => 'KEG (kegi)'];

function kmpRow(array $k): array {
    return ['uuid' => $k['uuid'], 'lok' => $k['oddzial'], 'doba' => $k['doba'], 'zmiana' => $k['zmiana'], 'loadId' => $k['load_id'],
        'startMs' => (int) $k['start_ms'], 'startBy' => $k['start_op'] !== null ? (int) $k['start_op'] : null, 'startName' => $k['start_name'],
        'endMs' => $k['end_ms'] !== null ? (int) $k['end_ms'] : null, 'endBy' => $k['end_op'] !== null ? (int) $k['end_op'] : null, 'endName' => $k['end_name'],
        'status' => $k['status'], 'car' => $k['car'] !== null ? (int) $k['car'] : null, 'crt' => $k['crt'] !== null ? (int) $k['crt'] : null,
        'tra' => $k['tra'] !== null ? (int) $k['tra'] : null, 'keg' => $k['keg'] !== null ? (int) $k['keg'] : null, 'palety' => $k['palety'] !== null ? (int) $k['palety'] : null,
        'korekta' => (int) $k['korekta'], 'terminal' => $k['terminal_id'] !== null ? (int) $k['terminal_id'] : null, 'zmieniono' => (int) $k['zmieniono']];
}
/** Ilości z wejścia: cztery jednostki wymagane (0–99999), palety opcjonalne (null = nie podano) */
function kmpQty($e): array {
    $o = []; foreach (array_keys(KMP_JEDN) as $j) { if (!isset($e[$j]) || $e[$j] === '' || !is_numeric($e[$j])) return []; $o[$j] = max(0, min(99999, (int) $e[$j])); }
    $o['palety'] = (isset($e['palety']) && $e['palety'] !== '' && is_numeric($e['palety'])) ? max(0, min(999, (int) $e['palety'])) : null;
    return $o;
}

function kmp_trasy(string $r): void {
    global $method;
    switch ($r) {
case 'kmp_push': {
    if ($method !== 'POST') fail('Wymagane POST', 405);
    $t = terminal(); needTermMod($t, 'kompletacja'); $lok = $t['oddzial']; $zm = lokSet(lokRow($lok))['zmiany'];
    $ev = input()['zdarzenia'] ?? []; if (!is_array($ev) || count($ev) > 200) fail('Nieprawidłowe dane');
    $ok = []; $err = []; $opIds = array_map(fn($o) => (int) $o['id'], opsForLok($lok, false)); $opOk = fn($id) => $id && in_array((int) $id, $opIds, true);
    foreach ($ev as $e) {
        $uuid = str($e['uuid'] ?? '', 40); if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uuid)) { $err[] = ['uuid' => $uuid, 'blad' => 'uuid']; continue; }
        $typ = $e['typ'] ?? '';
        if ($typ === 'kmp_start') {
            if (one('SELECT id FROM kompletacje WHERE uuid=?', [$uuid])) { $ok[] = $uuid; continue; }
            $sm = (int) ($e['startMs'] ?? 0); if ($sm < 1600000000000 || $sm > nowMs() + 86400000) { $err[] = ['uuid' => $uuid, 'blad' => 'czas']; continue; }
            if (!$opOk($e['startBy'] ?? 0)) { $err[] = ['uuid' => $uuid, 'blad' => 'operator']; continue; }
            q('INSERT INTO kompletacje (uuid,oddzial,terminal_id,doba,zmiana,load_id,start_ms,start_op,start_name,status,korekta,usuniety,zmieniono) VALUES (?,?,?,?,?,?,?,?,?,?,0,0,?)',
                [$uuid, $lok, $t['id'], dayKey($sm, $zm), shiftOf($sm, $zm), strtoupper(str($e['loadId'] ?? '', 60)), $sm, (int) $e['startBy'], str($e['startName'] ?? ''), 'w_toku', nowMs()]);
            $ok[] = $uuid;
        } elseif ($typ === 'kmp_koniec') {
            $k = one('SELECT * FROM kompletacje WHERE uuid=? AND oddzial=?', [$uuid, $lok]);
            if (!$k) { $err[] = ['uuid' => $uuid, 'blad' => 'brak']; continue; }
            if ($k['status'] === 'zakonczona' || (int) $k['usuniety'] === 1) { $ok[] = $uuid; continue; }
            $qv = kmpQty($e); if (!$qv) { $err[] = ['uuid' => $uuid, 'blad' => 'ilosci']; continue; }
            q('UPDATE kompletacje SET end_ms=?, end_op=?, end_name=?, status=?, car=?, crt=?, tra=?, keg=?, palety=?, zmieniono=? WHERE id=?',
                [(int) ($e['endMs'] ?? nowMs()), $opOk($e['endBy'] ?? 0) ? (int) $e['endBy'] : null, str($e['endName'] ?? ''), 'zakonczona', $qv['car'], $qv['crt'], $qv['tra'], $qv['keg'], $qv['palety'], nowMs(), $k['id']]);
            $ok[] = $uuid;
        } else $err[] = ['uuid' => $uuid, 'blad' => 'typ'];
    }
    out(['ok' => $ok, 'bledy' => $err]);
}
case 'kmp_dane': {
    me(); needMod('kompletacja'); $od = (string) ($_GET['od'] ?? ''); $do = (string) ($_GET['do'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $do) || $od > $do) fail('Nieprawidłowy zakres dat');
    if ((strtotime($do) - strtotime($od)) / 86400 > 400) fail('Maksymalny zakres to 400 dni');
    if (($md = minDoba()) && $od < $md) $od = $md;
    $loks = array_column(lokList(), 'id'); if (!$loks) out(['kompletacje' => [], 'od' => $od]);
    $in = implode(',', array_fill(0, count($loks), '?'));
    out(['kompletacje' => array_map('kmpRow', all("SELECT * FROM kompletacje WHERE usuniety=0 AND doba>=? AND doba<=? AND oddzial IN ($in) ORDER BY start_ms", array_merge([$od, $do], $loks))), 'od' => $od]);
}
case 'kmp_korekta': {
    requirePost(); needMod('kompletacja'); $in = input(); $typ = (string) ($in['typ'] ?? '');
    if (!in_array($typ, ['ilosci', 'zamknij', 'usun'], true)) fail('Nieznany typ korekty');
    need($typ === 'usun' ? 'korekta_usun' : ($typ === 'zamknij' ? 'korekta_zamknij' : 'korekta_palety'));
    $k = one('SELECT * FROM kompletacje WHERE uuid=? AND usuniety=0', [str($in['uuid'] ?? '', 40)]); if (!$k) fail('Nie znaleziono kompletacji', 404);
    needLok($k['oddzial']); if (($md = minDoba()) && $k['doba'] < $md) fail('Brak dostępu do tej doby.', 403);
    if (!corrLimitOk($k['doba'])) fail('Przekroczony limit dni na korekty dla Twojego konta.', 403);
    $desc = 'Kompletacja ' . $k['load_id'] . ' (' . $k['doba'] . ')';
    if ($typ === 'usun') { q('UPDATE kompletacje SET usuniety=1, korekta=1, zmieniono=? WHERE id=?', [nowMs(), $k['id']]); logAction(who(), 'kmp_usun', $k['oddzial'], 'Usunięto: ' . $desc); out(['ok' => 1]); }
    if ($typ === 'zamknij' && $k['status'] !== 'w_toku') fail('Kompletacja jest już zakończona.');
    $qv = kmpQty($in); if (!$qv) fail('Podaj ilości CAR, CRT, TRA i KEG (0, jeśli brak).');
    $fmt = fn($x) => implode('/', [$x['car'] ?? '—', $x['crt'] ?? '—', $x['tra'] ?? '—', $x['keg'] ?? '—']) . ' pal. ' . ($x['palety'] ?? '—');
    q('UPDATE kompletacje SET car=?, crt=?, tra=?, keg=?, palety=?, status=?, end_ms=?, end_name=COALESCE(end_name,?), korekta=1, zmieniono=? WHERE id=?',
        [$qv['car'], $qv['crt'], $qv['tra'], $qv['keg'], $qv['palety'], 'zakonczona', $k['end_ms'] ?? nowMs(), 'Korekta: ' . me()['imie'], nowMs(), $k['id']]);
    logAction(who(), $typ === 'zamknij' ? 'kmp_zamknij' : 'kmp_ilosci', $k['oddzial'], $desc . ' · ' . $fmt($k) . ' → ' . $fmt($qv));
    out(['ok' => 1]);
}
    }
}

/** Dane dla terminala: kompletacje z ostatnich 3 dób */
function kmp_sync(array $t, string $lok): array {
    return ['kompletacje' => array_map('kmpRow', all('SELECT * FROM kompletacje WHERE oddzial=? AND doba>=? AND usuniety=0', [$lok, date('Y-m-d', time() - 3 * 86400)]))];
}
function kmp_ja(): array { return ['kmpJednostki' => KMP_JEDN]; }
function kmp_retencja(): int {
    $l = (int) (getSet('retencja')['lata'] ?? 0); if ($l <= 0) return 0;
    return q('DELETE FROM kompletacje WHERE doba<?', [date('Y-m-d', strtotime("-$l years"))])->rowCount();
}
