<?php

declare(strict_types=1);

// Initie la connexion SSO (flux OAuth2 + PKCE) vers le systeme central compte.partitions.ovh.
// Equivalent de video/public/connexion.php, adapte au client 'music'.

require_once __DIR__ . '/config.php';

sso_start_session();

// Mode de retour: application mobile (deep link), fenetre popup web, ou page web classique.
$client = (string) ($_GET['client'] ?? '');
$isMobile = $client === 'mobile';
$isPopup = $client === 'popup';

if (!$isMobile && isset($_SESSION['utilisateur_id'])) {
    if ($isPopup) {
        sso_render_popup_close();
    }
    header('Location: /');
    exit;
}

// Elements PKCE pour cette tentative de connexion.
$verifier = sso_base64url(random_bytes(32));
$challenge = sso_base64url(hash('sha256', $verifier, true));
$state = sso_base64url(random_bytes(32));

$_SESSION['sso_music_pending'] = [
    'verifier' => $verifier,
    'state' => $state,
    'mobile' => $isMobile,
    'popup' => $isPopup,
];

$parametres = [
    'client_id' => SSO_CLIENT_ID,
    'redirect_uri' => SSO_REDIRECT_URI,
    'response_type' => 'code',
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'state' => $state,
];

$url = SSO_AUTHORIZE_URL . '?'
    . http_build_query($parametres, '', '&', PHP_QUERY_RFC3986);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: ' . $url);
exit;
