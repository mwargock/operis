<?php
// ============================================================
//  KONFIGURACJA – uzupełnij dane bazy MySQL z panelu home.pl
// ============================================================
return [
    'db' => [
        'driver' => 'mysql8',            // 'mysql' (home.pl)  |  'sqlite' (tylko testy lokalne)
        'host'   => 'mysql8',        // host bazy z panelu home.pl, np. 'mysqlXX.homepl.pl' lub 'localhost'
        'name'   => '00435192_operis',
        'user'   => '00435192_operis',
        'pass'   => 'Fast2k26!@26',
        'sqlite' => __DIR__ . '/data/esl.sqlite',
    ],
    // Klucz wymagany do uruchomienia instalatora: install.php?klucz=...
    // ZMIEŃ na własny, losowy ciąg znaków przed wgraniem na serwer.
    'install_key' => 'Nin12xcH3747r4tXMYuj',
    // Nie zmieniaj po uruchomieniu systemu (używane do skrótów PIN/kart).
    'salt' => 'ESL-zal-v1',
    'timezone' => 'Europe/Warsaw',
];
