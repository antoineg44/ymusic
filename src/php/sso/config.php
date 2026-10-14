<?php

declare(strict_types=1);

// Configuration partagee du SSO ymusic (fournisseur central compte.partitions.ovh).
// L'authentification et la liste des comptes reposent desormais sur la table `utilisateurs`
// du systeme central, via les configs partagees /var/www/compte/config.

// Identifiants du client ymusic tels qu'enregistres cote compte.partitions.ovh.
const SSO_CLIENT_ID = 'music';
const SSO_SERVICE = 'music';
const SSO_AUTHORIZE_URL = 'https://compte.partitions.ovh/sso/authorize.php';
const SSO_REDIRECT_URI = 'https://musiques.partitions.ovh/php/sso/callback.php';

// Schema de deep link utilise pour revenir dans l'application mobile apres connexion.
const SSO_MOBILE_DEEP_LINK = 'ymusic://sso';

// Duree de vie de la session authentifiee (30 jours) pour rester connecte entre les lancements.
const SSO_SESSION_LIFETIME = 2592000;

// Configs partagees du systeme central: $pdo (PDO) + utilisateurAAccesService().
require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

function sso_is_https(): bool
{
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || $forwardedProto === 'https'
        || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https';
}

function sso_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = sso_is_https();

    session_set_cookie_params([
        'lifetime' => SSO_SESSION_LIFETIME,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        // SameSite=None requis pour les appels cross-site (webview mobile); Lax en clair.
        'samesite' => $isHttps ? 'None' : 'Lax',
    ]);

    session_start();
}

function sso_base64url(string $valeur): string
{
    return rtrim(strtr(base64_encode($valeur), '+/', '-_'), '=');
}

// Termine le flux popup: notifie la fenetre parente (l'app) puis ferme la fenetre.
function sso_render_popup_close(): never
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
        . '<title>Connexion reussie</title></head><body style="font-family:Arial,sans-serif;'
        . 'background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;'
        . 'height:100vh;margin:0">'
        . '<p>Connexion reussie. Vous pouvez fermer cette fenetre.</p>'
        . '<script>try{if(window.opener){window.opener.postMessage({type:"SSO_LOGIN_DONE"},"*");}}'
        . 'catch(e){}window.close();</script></body></html>';
    exit;
}

// Peuple la session apres une authentification reussie.
// On conserve deux formes: utilisateur_* (compatibilite SSO) et user{} (backend ymusic existant).
function sso_apply_user_session(array $utilisateur): void
{
    $id = (int) $utilisateur['id'];
    $identifiant = (string) ($utilisateur['identifiant'] ?? '');
    $role = (string) ($utilisateur['role'] ?? 'user');

    $_SESSION['utilisateur_id'] = $id;
    $_SESSION['utilisateur_identifiant'] = $identifiant;
    $_SESSION['utilisateur_email'] = (string) ($utilisateur['email'] ?? '');
    $_SESSION['utilisateur_role'] = $role;
    $_SESSION['utilisateur_admin'] = ($role === 'admin');

    // Forme attendue par le backend ymusic (interface.php, database_interface.php).
    $_SESSION['user'] = [
        'id' => $id,
        'username' => $identifiant,
        'role' => $role,
    ];
}

// Cree la table des jetons mobiles a usage unique (echange deep link -> session webview).
function sso_ensure_mobile_tokens_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS music_jetons_mobiles (
            jeton_hash CHAR(64) NOT NULL,
            utilisateur_id INT UNSIGNED NOT NULL,
            date_expiration DATETIME NOT NULL,
            date_utilisation DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (jeton_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Cree la table des jetons de persistance (remember-me) rotatifs: selecteur + hash du validateur.
function sso_ensure_remember_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS music_jetons_persistants (
            selecteur CHAR(32) NOT NULL,
            validateur_hash CHAR(64) NOT NULL,
            utilisateur_id INT UNSIGNED NOT NULL,
            date_expiration DATETIME NOT NULL,
            PRIMARY KEY (selecteur),
            KEY idx_utilisateur (utilisateur_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Emet un nouveau jeton de persistance (format selecteur:validateur) pour un utilisateur.
function sso_issue_remember_token(PDO $pdo, int $utilisateurId): string
{
    sso_ensure_remember_table($pdo);

    $selecteur = bin2hex(random_bytes(16));
    $validateur = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare(
        'INSERT INTO music_jetons_persistants (selecteur, validateur_hash, utilisateur_id, date_expiration)
         VALUES (:selecteur, :validateur_hash, :utilisateur_id, DATE_ADD(NOW(), INTERVAL :duree SECOND))'
    );
    $stmt->bindValue(':selecteur', $selecteur);
    $stmt->bindValue(':validateur_hash', hash('sha256', $validateur));
    $stmt->bindValue(':utilisateur_id', $utilisateurId, PDO::PARAM_INT);
    $stmt->bindValue(':duree', SSO_SESSION_LIFETIME, PDO::PARAM_INT);
    $stmt->execute();

    return $selecteur . ':' . $validateur;
}

// Valide et fait tourner un jeton de persistance. Retourne [utilisateur_id, nouveau_jeton] ou null.
function sso_rotate_remember_token(PDO $pdo, string $token): ?array
{
    $parts = explode(':', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$selecteur, $validateur] = $parts;
    if (!preg_match('/\A[a-f0-9]{32}\z/', $selecteur) || !preg_match('/\A[a-f0-9]{64}\z/', $validateur)) {
        return null;
    }

    sso_ensure_remember_table($pdo);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT validateur_hash, utilisateur_id
             FROM music_jetons_persistants
             WHERE selecteur = :selecteur
               AND date_expiration > NOW()
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':selecteur' => $selecteur]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !hash_equals((string) $row['validateur_hash'], hash('sha256', $validateur))) {
            // Jeton inconnu/vole: on supprime le selecteur par precaution.
            $pdo->prepare('DELETE FROM music_jetons_persistants WHERE selecteur = :selecteur')
                ->execute([':selecteur' => $selecteur]);
            $pdo->commit();
            return null;
        }

        $utilisateurId = (int) $row['utilisateur_id'];
        $nouveauValidateur = bin2hex(random_bytes(32));

        $update = $pdo->prepare(
            'UPDATE music_jetons_persistants
             SET validateur_hash = :validateur_hash,
                 date_expiration = DATE_ADD(NOW(), INTERVAL :duree SECOND)
             WHERE selecteur = :selecteur'
        );
        $update->bindValue(':validateur_hash', hash('sha256', $nouveauValidateur));
        $update->bindValue(':duree', SSO_SESSION_LIFETIME, PDO::PARAM_INT);
        $update->bindValue(':selecteur', $selecteur);
        $update->execute();

        $pdo->commit();

        return [
            'utilisateur_id' => $utilisateurId,
            'token' => $selecteur . ':' . $nouveauValidateur,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

// Revoque un jeton de persistance (deconnexion).
function sso_revoke_remember_token(PDO $pdo, string $token): void
{
    $selecteur = explode(':', $token, 2)[0] ?? '';
    if (!preg_match('/\A[a-f0-9]{32}\z/', $selecteur)) {
        return;
    }

    sso_ensure_remember_table($pdo);
    $pdo->prepare('DELETE FROM music_jetons_persistants WHERE selecteur = :selecteur')
        ->execute([':selecteur' => $selecteur]);
}
