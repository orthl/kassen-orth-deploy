<?php
declare(strict_types=1);

/* =====================================================================
   Kontaktformular-Versand für kassen-orth.de
   Bei Bedarf anpassen: $empfaenger und $absender.
   ===================================================================== */
$empfaenger = 'info@kassen-orth.de';               // Wohin die Anfrage geht
$absender   = 'info@kassen-orth.de';               // Adresse der eigenen Domain (für SPF/DKIM)

// Fetch vom Formular (Accept: application/json) bekommt JSON statt Weiterleitung
$alsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function redirect(string $to): void {
    global $alsJson;
    if ($alsJson) {
        $ok = str_starts_with($to, '/danke');
        http_response_code($ok ? 200 : 400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $ok]);
        exit;
    }
    header('Location: ' . $to, true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('/');
}

// Spam-Honeypot: von Bots ausgefüllt -> stillschweigend abbrechen (Erfolg vortäuschen)
if (trim((string)($_POST['website'] ?? '')) !== '') {
    redirect('/danke/');
}

// Eingaben säubern (Zeilenumbrüche raus -> Header-Injection verhindern)
function clean(string $v): string {
    return trim(str_replace(["\r", "\n", "%0a", "%0d"], ' ', $v));
}

$name      = clean((string)($_POST['Name'] ?? ''));
$email     = clean((string)($_POST['E-Mail'] ?? ''));
$telefon   = clean((string)($_POST['Telefon'] ?? ''));
$branche   = clean((string)($_POST['Branche'] ?? ''));
$nachricht = trim((string)($_POST['Nachricht'] ?? ''));
$paket     = clean((string)($_POST['Paket'] ?? ''));
$seite     = clean((string)($_POST['Seite'] ?? ''));
$anzahl    = clean((string)($_POST['Anzahl_Kassen'] ?? ''));

// Validierung
$fehler = false;
if ($name === '') $fehler = true;
// Telefon ODER E-Mail reicht (Formular seit 24.11.2026); Datenschutz als Hinweis, kein Pflicht-Haken mehr
$mailOk = (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
$telOk  = strlen(preg_replace('/\D/', '', $telefon)) >= 6;
if (!$mailOk && !$telOk) $fehler = true;
if ($email !== '' && !$mailOk) $fehler = true;
if ($fehler) {
    redirect('/?fehler=1#kontakt');
}

// Nachricht zusammenbauen
$zeilen = [
    'Neue Anfrage über kassen-orth.de',
    str_repeat('-', 32),
    'Name:          ' . $name,
    'E-Mail:        ' . ($email !== '' ? $email : '(keine Angabe, bitte anrufen)'),
    'Telefon:       ' . ($telefon !== '' ? $telefon : '(keine Angabe)'),
    'Branche:       ' . ($branche !== '' ? $branche : '(keine Angabe)'),
    'Anzahl Kassen: ' . ($anzahl !== '' ? $anzahl : '(keine Angabe)'),
    'Paket:         ' . ($paket !== '' ? $paket : '(keines gewählt)'),
    'Seite:         ' . ($seite !== '' ? $seite : '(unbekannt)'),
];
$zeilen[] = '';
$zeilen[] = 'Nachricht:';
$zeilen[] = $nachricht !== '' ? $nachricht : '(keine)';
$body = implode("\r\n", $zeilen);

$betreff = 'Kassen-Anfrage von ' . $name;

$headers   = [];
$headers[] = 'From: Kassen Orth <' . $absender . '>';
if ($mailOk) $headers[] = 'Reply-To: ' . $email;
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'X-Mailer: PHP/kassen-orth';

$betreffEnc = '=?UTF-8?B?' . base64_encode($betreff) . '?=';

$ok = @mail($empfaenger, $betreffEnc, $body, implode("\r\n", $headers));

redirect($ok ? '/danke/' : '/?fehler=1#kontakt');
