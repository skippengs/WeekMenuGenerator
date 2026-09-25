<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/*
 * Web Push zonder bibliotheek: geen Composer op de server, dus VAPID
 * (RFC 8292) en de versleuteling van de inhoud (RFC 8291, aes128gcm) met
 * de openssl-extensie en hash_hkdf() uit PHP zelf.
 *
 * De VAPID-sleutels worden de eerste keer vanzelf aangemaakt en staan in
 * de tabel setting. Weg ermee betekent: iedereen moet meldingen opnieuw
 * aanzetten, want de abonnementen horen bij die sleutel.
 *
 * Net als Bring en de kortingen: nooit een harde afhankelijkheid. Mislukt
 * een melding, dan gaat de actie die hem veroorzaakte gewoon door.
 */

const PUSH_SETTING_PUBLIC  = 'vapid_public';
const PUSH_SETTING_PRIVATE = 'vapid_private';
const PUSH_SETTING_SITE    = 'site_url';

// SubjectPublicKeyInfo-kop voor een P-256 publieke sleutel; daarachter
// komen de 65 bytes van het punt zelf.
const PUSH_P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function b64urlDecode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/'), true);
}

/** Nieuw P-256 sleutelpaar: [private key, 65 bytes publieke sleutel]. */
function pushNewKeyPair(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($key === false) {
        throw new RuntimeException('openssl kan geen EC-sleutel maken.');
    }
    $ec = openssl_pkey_get_details($key)['ec'];
    $public = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    return [$key, $public];
}

function pushPublicKeyPem(string $raw): string
{
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode(hex2bin(PUSH_P256_SPKI_PREFIX) . $raw), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

/** ['public' => base64url, 'private' => pem], aangemaakt bij het eerste gebruik. */
function vapidKeys(PDO $pdo): array
{
    $public  = getSetting($pdo, PUSH_SETTING_PUBLIC);
    $private = getSetting($pdo, PUSH_SETTING_PRIVATE);

    if ($public === '' || $private === '') {
        [$key, $raw] = pushNewKeyPair();
        openssl_pkey_export($key, $private);
        $public = b64url($raw);
        setSetting($pdo, PUSH_SETTING_PUBLIC, $public);
        setSetting($pdo, PUSH_SETTING_PRIVATE, $private);
    }

    return ['public' => $public, 'private' => $private];
}

/** openssl geeft een DER-handtekening; een JWT wil r en s los achter elkaar. */
function pushDerToJose(string $der): string
{
    $offset = 2;
    $out    = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$offset + 1]);
        $int = ltrim(substr($der, $offset + 2, $len), "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $offset += 2 + $len;
    }
    return $out;
}

function pushVapidHeader(string $endpoint, array $keys, string $subject): string
{
    $parts    = parse_url($endpoint);
    $audience = $parts['scheme'] . '://' . $parts['host'];

    $header = b64url((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64url((string)json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject]));

    openssl_sign($header . '.' . $claims, $der, $keys['private'], OPENSSL_ALGO_SHA256);
    $jwt = $header . '.' . $claims . '.' . b64url(pushDerToJose($der));

    return 'vapid t=' . $jwt . ', k=' . $keys['public'];
}

/**
 * Versleutelt $payload voor één abonnement (RFC 8291). Geeft de complete
 * body terug: kop met salt en onze tijdelijke publieke sleutel, dan de
 * versleutelde inhoud.
 */
function pushEncrypt(string $payload, string $uaPublic, string $authSecret): string
{
    [$asKey, $asPublic] = pushNewKeyPair();

    $shared = openssl_pkey_derive(openssl_pkey_get_public(pushPublicKeyPem($uaPublic)), $asKey, 32);
    if ($shared === false) {
        throw new RuntimeException('Ongeldige sleutel in het abonnement.');
    }

    $salt  = random_bytes(16);
    $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    // \x02 = laatste (en enige) record, zonder opvulling.
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

    return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
}

/** Stuurt één melding. Geeft de http-status terug, 0 bij een netwerkfout. */
function pushSend(PDO $pdo, array $sub, array $message): int
{
    $keys    = vapidKeys($pdo);
    $subject = getSetting($pdo, PUSH_SETTING_SITE, 'mailto:weekmenu@example.com');
    $body    = pushEncrypt(
        (string)json_encode($message, JSON_UNESCAPED_UNICODE),
        b64urlDecode($sub['p256dh']),
        b64urlDecode($sub['auth'])
    );

    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . pushVapidHeader($sub['endpoint'], $keys, $subject),
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 86400',
            'Urgency: normal',
        ],
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status;
}

/**
 * Melding naar iedereen met minstens $minRole, behalve degene die het zelf
 * deed. $message: title, body, url (relatief aan de app).
 */
function notifyUsers(PDO $pdo, string $minRole, array $message, ?int $exceptUserId = null): void
{
    try {
        $roles = array_keys(array_filter(ROLES, static fn(int $level) => $level >= ROLES[$minRole]));
        $in    = implode(',', array_fill(0, count($roles), '?'));
        $stmt  = $pdo->prepare(
            "SELECT s.id, s.endpoint, s.p256dh, s.auth
               FROM {push_subscription} s
               JOIN {app_user} u ON u.id = s.user_id
              WHERE u.role IN ($in) AND u.id <> ?"
        );
        $stmt->execute([...$roles, $exceptUserId ?? 0]);

        $gone = $pdo->prepare('DELETE FROM {push_subscription} WHERE id = ?');
        foreach ($stmt as $sub) {
            // 404/410: dat apparaat heeft meldingen uitgezet of bestaat niet meer.
            if (in_array(pushSend($pdo, $sub, $message), [404, 410], true)) {
                $gone->execute([$sub['id']]);
            }
        }
    } catch (Throwable $e) {
        // Een melding is een extraatje; de actie zelf is al gelukt.
    }
}
