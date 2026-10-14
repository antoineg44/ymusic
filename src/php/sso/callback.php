<?php

declare(strict_types=1);

// Retour du fournisseur SSO central: valide le code d'autorisation, verifie le droit d'acces
// au service 'music' et ouvre la session ymusic. Equivalent de video/public/sso/callback.php.

require_once __DIR__ . '/config.php';

sso_start_session();

function sso_callback_erreur(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$pending = $_SESSION['sso_music_pending'] ?? null;

if (
    !is_array($pending)
    || !isset($pending['verifier'], $pending['state'])
    || !is_string($pending['verifier'])
    || !is_string($pending['state'])
    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code)
    || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $state)
    || !hash_equals($pending['state'], $state)
) {
    sso_callback_erreur(400, 'Reponse SSO invalide ou expiree. Veuillez reessayer.');
}

$isMobile = !empty($pending['mobile']);
$isPopup = !empty($pending['popup']);
$challengeAttendu = sso_base64url(hash('sha256', $pending['verifier'], true));

try {
    $pdo->beginTransaction();

    // Verrouiller le code pendant sa validation pour empecher sa reutilisation.
    $requete = $pdo->prepare(
        'SELECT utilisateur_id, code_challenge
         FROM sso_codes
         WHERE code_hash = :code_hash
           AND client_id = :client_id
           AND date_expiration > NOW()
           AND date_utilisation IS NULL
         LIMIT 1
         FOR UPDATE'
    );

    $requete->execute([
        'code_hash' => hash('sha256', $code),
        'client_id' => SSO_CLIENT_ID,
    ]);

    $autorisation = $requete->fetch();

    if (
        !$autorisation
        || !hash_equals((string) $autorisation['code_challenge'], $challengeAttendu)
    ) {
        $pdo->rollBack();
        unset($_SESSION['sso_music_pending']);
        sso_callback_erreur(400, 'Code SSO invalide, expire ou deja utilise.');
    }

    $utilisateurId = (int) $autorisation['utilisateur_id'];

    if (!utilisateurAAccesService($pdo, $utilisateurId, SSO_SERVICE)) {
        $pdo->rollBack();
        unset($_SESSION['sso_music_pending']);
        sso_callback_erreur(403, 'Acces refuse : ce compte ne dispose pas des droits musique.');
    }

    $requete = $pdo->prepare(
        'SELECT id, identifiant, email, role
         FROM utilisateurs
         WHERE id = :id
         LIMIT 1'
    );
    $requete->execute(['id' => $utilisateurId]);
    $utilisateur = $requete->fetch();

    if (!$utilisateur) {
        $pdo->rollBack();
        unset($_SESSION['sso_music_pending']);
        sso_callback_erreur(403, 'Le compte utilisateur n\'existe plus.');
    }

    // Marquer le code comme utilise avant de terminer la transaction.
    $requete = $pdo->prepare(
        'UPDATE sso_codes
         SET date_utilisation = NOW()
         WHERE code_hash = :code_hash
           AND date_utilisation IS NULL'
    );
    $requete->execute(['code_hash' => hash('sha256', $code)]);

    if ($requete->rowCount() !== 1) {
        $pdo->rollBack();
        unset($_SESSION['sso_music_pending']);
        sso_callback_erreur(400, 'Ce code SSO a deja ete utilise.');
    }

    // Pour le mobile, generer un jeton a usage unique echange ensuite contre une session webview.
    $mobileToken = null;
    if ($isMobile) {
        sso_ensure_mobile_tokens_table($pdo);
        $mobileToken = sso_base64url(random_bytes(32));
        $insert = $pdo->prepare(
            'INSERT INTO music_jetons_mobiles (jeton_hash, utilisateur_id, date_expiration)
             VALUES (:jeton_hash, :utilisateur_id, DATE_ADD(NOW(), INTERVAL 5 MINUTE))'
        );
        $insert->execute([
            'jeton_hash' => hash('sha256', $mobileToken),
            'utilisateur_id' => $utilisateurId,
        ]);
    }

    $pdo->commit();

    unset($_SESSION['sso_music_pending']);

    // Mobile: on ne depose pas de session ici (navigateur systeme), on repasse le jeton a l'app.
    if ($isMobile && $mobileToken !== null) {
        $deepLink = SSO_MOBILE_DEEP_LINK . '?token=' . rawurlencode($mobileToken);
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $deepLink);
        exit;
    }

    // Web: ouvrir la session et revenir a l'application.
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Impossible de renouveler la session musique.');
    }

    sso_apply_user_session($utilisateur);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Popup web: notifier la fenetre parente et se fermer; sinon retour a l'application.
    if ($isPopup) {
        sso_render_popup_close();
    }

    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: /');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erreur callback SSO musique : ' . $e->getMessage());
    http_response_code(500);
    exit('Une erreur technique est survenue pendant la connexion.');
}
