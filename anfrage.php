<?php
declare(strict_types=1);

/* =====================================================================
   Kontaktformular-Versand für kassen-orth.de
   1) Benachrichtigung an Kassen Orth (HTML + Text, Antwort geht an den Kunden)
   2) Bestätigung an den Kunden, wenn er eine E-Mail-Adresse angegeben hat
   Bei Bedarf anpassen: Konstanten unten.
   ===================================================================== */
const EMPFAENGER = 'info@kassen-orth.de';   // Wohin die Anfrage geht
const ABSENDER   = 'info@kassen-orth.de';   // Adresse der eigenen Domain (für SPF/DKIM)
const TELEFON    = '0176 4655 1183';
const TEL_HREF   = 'tel:+4917646551183';
const WHATSAPP   = 'https://wa.me/4917646551183';
const MAX_PRO_STUNDE = 5;                   // Anfragen je IP und Stunde, Schutz vor Missbrauch der Bestätigungsmail

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

// Spam-Schutz: Honeypot und Zeitfalle (Formular schneller als 3 s abgeschickt) -> Erfolg vortäuschen, nichts senden
$start = (int)($_POST['ts'] ?? 0);
if (trim((string)($_POST['website'] ?? '')) !== '' || ($start > 0 && (int)(microtime(true) * 1000) - $start < 3000)) {
    redirect('/danke/');
}

// Höchstens MAX_PRO_STUNDE Anfragen je IP und Stunde
$ipDatei = sys_get_temp_dir() . '/kassen-orth-anfrage-' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')) . '.txt';
$zeiten = array_values(array_filter(array_map('intval', @file($ipDatei, FILE_IGNORE_NEW_LINES) ?: []), fn($t) => $t > time() - 3600));
if (count($zeiten) >= MAX_PRO_STUNDE) {
    redirect('/?fehler=1#kontakt');
}

// Eingaben säubern (Zeilenumbrüche raus -> Header-Injection verhindern)
function clean(string $v): string {
    return trim(str_replace(["\r", "\n", "%0a", "%0d"], ' ', $v));
}
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$name      = clean((string)($_POST['Name'] ?? ''));
$email     = clean((string)($_POST['E-Mail'] ?? ''));
$telefon   = clean((string)($_POST['Telefon'] ?? ''));
$branche   = clean((string)($_POST['Branche'] ?? ''));
$nachricht = trim((string)($_POST['Nachricht'] ?? ''));
$paket     = clean((string)($_POST['Paket'] ?? ''));
$seite     = clean((string)($_POST['Seite'] ?? ''));
$anzahl    = clean((string)($_POST['Anzahl_Kassen'] ?? ''));

// Validierung: Name und Telefon ODER E-Mail
$mailOk = (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
$telOk  = strlen(preg_replace('/\D/', '', $telefon)) >= 6;
if ($name === '' || (!$mailOk && !$telOk) || ($email !== '' && !$mailOk)) {
    redirect('/?fehler=1#kontakt');
}

$zeitpunkt = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y, H:i') . ' Uhr';

// Angaben des Kunden (für beide Mails)
$angaben = array_filter([
    'Name'          => $name,
    'Telefon'       => $telefon,
    'E-Mail'        => $email,
    'Branche'       => $branche,
    'Anzahl Kassen' => $anzahl,
    'Paket'         => $paket,
], fn($v) => $v !== '');

/* ---------- Bausteine für HTML-Mails (Tabellen + Inline-Styles für alle Mailprogramme) ---------- */
function mailHtml(string $titel, string $inhalt): string {
    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($titel) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f2ece2;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2ece2;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#fffcf7;border-radius:6px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#3a3d42;">'
        . '<tr><td style="background:#1c1f24;padding:20px 28px;">'
        . '<span style="display:inline-block;width:14px;height:18px;background:#e2672a;vertical-align:-2px;margin-right:10px;"></span>'
        . '<span style="font-size:18px;font-weight:bold;letter-spacing:1px;color:#ffffff;text-transform:uppercase;">Kassen Orth</span>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 28px 8px;font-size:15px;line-height:1.6;">' . $inhalt . '</td></tr>'
        . '<tr><td style="padding:20px 28px 26px;border-top:1px dashed #c9bfae;font-size:13px;line-height:1.6;color:#565a61;">'
        . '<strong style="color:#1c1f24;">Lucas Orth · Kassen Orth</strong><br>Hundscheiderweg 4, 66679 Losheim am See<br>'
        . 'Telefon <a href="' . TEL_HREF . '" style="color:#ae4313;">' . TELEFON . '</a> · <a href="' . WHATSAPP . '" style="color:#ae4313;">WhatsApp</a> · <a href="https://kassen-orth.de" style="color:#ae4313;">kassen-orth.de</a>'
        . '</td></tr></table></td></tr></table></body></html>';
}
function angabenTabelle(array $angaben, string $nachricht): string {
    $z = '';
    foreach ($angaben as $k => $v) {
        $wert = h($v);
        if ($k === 'Telefon') $wert = '<a href="tel:' . h(preg_replace('/[^\d+]/', '', $v)) . '" style="color:#ae4313;">' . $wert . '</a>';
        if ($k === 'E-Mail')  $wert = '<a href="mailto:' . h($v) . '" style="color:#ae4313;">' . $wert . '</a>';
        $z .= '<tr><td width="130" style="width:130px;padding:8px 12px 8px 0;color:#565a61;font-size:14px;white-space:nowrap;vertical-align:top;">' . h($k) . '</td>'
            . '<td style="padding:8px 0;color:#1c1f24;font-size:15px;font-weight:bold;">' . $wert . '</td></tr>';
    }
    if ($nachricht !== '') {
        $z .= '<tr><td colspan="2" style="padding:12px 0 4px;color:#565a61;font-size:14px;">Nachricht</td></tr>'
            . '<tr><td colspan="2" style="padding:12px 14px;background:#f2ece2;border-radius:4px;color:#1c1f24;font-size:15px;line-height:1.6;">' . nl2br(h($nachricht)) . '</td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 20px;">' . $z . '</table>';
}
function angabenText(array $angaben, string $nachricht): string {
    $t = '';
    foreach ($angaben as $k => $v) $t .= str_pad($k . ':', 15) . $v . "\n";
    if ($nachricht !== '') $t .= "\nNachricht:\n" . $nachricht . "\n";
    return $t;
}
function enc(string $s): string {
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}
function sende(string $an, string $betreff, string $text, string $html, array $kopf): bool {
    $grenze = 'ko-' . bin2hex(random_bytes(8));
    $kopf[] = 'MIME-Version: 1.0';
    $kopf[] = 'Content-Type: multipart/alternative; boundary="' . $grenze . '"';
    $kopf[] = 'X-Mailer: PHP/kassen-orth';
    $body = "--$grenze\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$grenze\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
          . "--$grenze--\r\n";
    return @mail($an, enc($betreff), $body, implode("\r\n", $kopf), '-f' . ABSENDER);
}

/* ---------- 1) Benachrichtigung an Kassen Orth ---------- */
// Wird beim Antworten zitiert und geht dann an den Kunden: deshalb neutral und ordentlich formuliert.
$hinweis = $mailOk ? '' : '<p style="margin:0 0 16px;padding:10px 14px;background:#fbeee4;border-radius:4px;color:#1c1f24;"><strong>Keine E-Mail angegeben:</strong> bitte telefonisch zurückrufen.</p>';
$inhalt1 = '<h1 style="margin:0 0 6px;font-size:22px;color:#1c1f24;">Anfrage über kassen-orth.de</h1>'
    . '<p style="margin:0 0 16px;color:#565a61;font-size:14px;">Eingegangen am ' . h($zeitpunkt) . '</p>'
    . $hinweis . angabenTabelle($angaben, $nachricht)
    . '<p style="margin:0 0 8px;color:#8a8d92;font-size:12px;">Gesendet über das Formular auf kassen-orth.de' . ($seite !== '' ? ' (Seite ' . h($seite) . ')' : '') . '.</p>';
$text1 = "Anfrage über kassen-orth.de\nEingegangen am $zeitpunkt\n" . ($mailOk ? '' : "\nKeine E-Mail angegeben: bitte telefonisch zurückrufen.\n") . "\n"
    . angabenText($angaben, $nachricht) . "\nGesendet über das Formular auf kassen-orth.de" . ($seite !== '' ? " (Seite $seite)" : '') . ".\n";

$kopf1 = ['From: ' . enc('Kassen Orth – Website') . ' <' . ABSENDER . '>'];
if ($mailOk) $kopf1[] = 'Reply-To: ' . enc($name) . ' <' . $email . '>';
$ok = sende(EMPFAENGER, 'Kassen-Anfrage von ' . $name, $text1, mailHtml('Anfrage über kassen-orth.de', $inhalt1), $kopf1);

/* ---------- 2) Bestätigung an den Kunden ---------- */
if ($ok && $mailOk) {
    $rueckweg = $telOk ? 'rufe Sie unter ' . $telefon . ' zurück' : 'antworte Ihnen per E-Mail';
    $schritte = [
        ['Heute', 'Ihre Anfrage ist angekommen.'],
        ['Spätestens nächster Werktag', 'Ich ' . $rueckweg . '. Wir klären kurz, was Ihr Betrieb braucht.'],
        ['Danach', 'Sie bekommen ein schriftliches Festpreis-Angebot. Kostenlos und unverbindlich.'],
    ];
    $sHtml = '';
    $sText = '';
    foreach ($schritte as $i => [$wann, $was]) {
        $sHtml .= '<tr><td style="width:34px;vertical-align:top;padding:0 0 14px;"><span style="display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:12px;background:#e2672a;color:#1c1f24;font-size:13px;font-weight:bold;">' . ($i + 1) . '</span></td>'
            . '<td style="vertical-align:top;padding:2px 0 14px;"><span style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#ae4313;font-weight:bold;">' . h($wann) . '</span><br>' . h($was) . '</td></tr>';
        $sText .= ($i + 1) . '. ' . $wann . ': ' . $was . "\n";
    }
    $inhalt2 = '<h1 style="margin:0 0 16px;font-size:22px;color:#1c1f24;">Danke für Ihre Anfrage!</h1>'
        . '<p style="margin:0 0 16px;">Hallo ' . h($name) . ',</p>'
        . '<p style="margin:0 0 20px;">Ihre Anfrage bei Kassen Orth ist angekommen. Ich melde mich <strong>spätestens am nächsten Werktag</strong> persönlich bei Ihnen.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px;">' . $sHtml . '</table>'
        . '<p style="margin:0 0 6px;font-size:14px;color:#565a61;">Ihre Angaben</p>'
        . angabenTabelle($angaben, $nachricht)
        . '<p style="margin:0 0 20px;">Eilt es? Rufen Sie mich direkt an unter <a href="' . TEL_HREF . '" style="color:#ae4313;font-weight:bold;">' . TELEFON . '</a> oder schreiben Sie mir per <a href="' . WHATSAPP . '" style="color:#ae4313;font-weight:bold;">WhatsApp</a>.</p>'
        . '<p style="margin:0 0 20px;">Viele Grüße<br>Lucas Orth</p>'
        . '<p style="margin:0 0 8px;color:#8a8d92;font-size:12px;">Sie erhalten diese E-Mail, weil Sie auf kassen-orth.de eine Anfrage gestellt haben. Sie können einfach auf diese E-Mail antworten.</p>';
    $text2 = "Hallo $name,\n\nIhre Anfrage bei Kassen Orth ist angekommen. Ich melde mich spätestens am nächsten Werktag persönlich bei Ihnen.\n\n"
        . $sText . "\nIhre Angaben:\n" . angabenText($angaben, $nachricht)
        . "\nEilt es? Rufen Sie mich an unter " . TELEFON . " oder schreiben Sie per WhatsApp: " . WHATSAPP . "\n\nViele Grüße\nLucas Orth\n\n"
        . "Lucas Orth · Kassen Orth\nHundscheiderweg 4, 66679 Losheim am See\nTelefon " . TELEFON . " · kassen-orth.de\n";
    $kopf2 = ['From: ' . enc('Lucas Orth | Kassen Orth') . ' <' . ABSENDER . '>', 'Reply-To: ' . ABSENDER];
    sende($email, 'Ihre Anfrage bei Kassen Orth', $text2, mailHtml('Ihre Anfrage bei Kassen Orth', $inhalt2), $kopf2);
}

if ($ok) {
    $zeiten[] = time();
    @file_put_contents($ipDatei, implode("\n", $zeiten), LOCK_EX);
}

redirect($ok ? '/danke/' : '/?fehler=1#kontakt');
