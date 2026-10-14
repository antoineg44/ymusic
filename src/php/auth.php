<?php

declare(strict_types=1);

$requestOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

function is_allowed_origin(string $origin): bool
{
    if ($origin === 'null') {
        return true;
    }

    if (preg_match('#^https?://(musiques\.partitions\.ovh|www\.musiques\.partitions\.ovh|music\.partitions\.ovh|www\.music\.partitions\.ovh|localhost|127\.0\.0\.1|192\.168\.1\.10)(:\d+)?$#', $origin) === 1) {
        return true;
    }

    return preg_match('#^(capacitor|ionic)://localhost$#', $origin) === 1;
}

function apply_cors_headers(string $origin): void
{
    if ($origin !== '' && is_allowed_origin($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Vary: Origin');
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    apply_cors_headers($requestOrigin);
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

apply_cors_headers($requestOrigin);

// Authentification deleguee au SSO central (compte.partitions.ovh). Ce fichier expose
// uniquement les actions JSON consommees par l'application: etat de session et deconnexion.
require_once __DIR__ . '/sso/config.php';

sso_start_session();

$action = (string) ($_REQUEST['action'] ?? '');

try {
    switch ($action) {
        case 'logout':
            handle_logout();
            break;

        case 'check':
            handle_check();
            break;

        case 'mobile_login':
            handle_mobile_login();
            break;

        case 'remember_login':
            handle_remember_login();
            break;

        default:
            respond_json(400, ['success' => false, 'message' => 'Action non supportee']);
    }
} catch (Throwable $error) {
    respond_json(500, ['success' => false, 'message' => $error->getMessage()]);
}

function respond_json(int $status, array $payload): void
{
    // Reponse JSON uniforme pour toutes les actions de ce fichier.
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sso_login_url(): string
{
    // URL de redirection a suivre pour (re)lancer l'authentification SSO.
    return '/php/sso/login.php';
}

// Verifie que l'utilisateur en session dispose toujours du droit d'acces au service musique.
function sso_session_user_with_access(PDO $pdo): ?array
{
    $id = (int) ($_SESSION['utilisateur_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    if (!utilisateurAAccesService($pdo, $id, SSO_SERVICE)) {
        return null;
    }

    return [
        'id' => $id,
        'username' => (string) ($_SESSION['utilisateur_identifiant'] ?? ''),
        'role' => (string) ($_SESSION['utilisateur_role'] ?? 'user'),
    ];
}

function handle_check(): void
{
    $user = sso_session_user_with_access($GLOBALS['pdo']);

    if ($user !== null) {
        respond_json(200, ['success' => true, 'user' => $user, 'loginUrl' => sso_login_url()]);
    }

    respond_json(200, ['success' => false, 'loginUrl' => sso_login_url()]);
}

function handle_logout(): void
{
    // Revoque le jeton de persistance fourni par l'app pour empecher toute reconnexion silencieuse.
    $rememberToken = (string) ($_POST['rememberToken'] ?? '');
    if ($rememberToken !== '') {
        try {
            sso_revoke_remember_token($GLOBALS['pdo'], $rememberToken);
        } catch (Throwable $error) {
            // La deconnexion locale ne doit pas echouer si la revocation serveur echoue.
            error_log('Revocation remember-token echouee: ' . $error->getMessage());
        }
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    respond_json(200, ['success' => true, 'loginUrl' => sso_login_url()]);
}

// Echange un jeton a usage unique (issu du deep link mobile) contre une session authentifiee.
function handle_mobile_login(): void
{
    $token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
    if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
        respond_json(400, ['success' => false, 'message' => 'Jeton invalide']);
    }

    $pdo = $GLOBALS['pdo'];
    sso_ensure_mobile_tokens_table($pdo);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT utilisateur_id
             FROM music_jetons_mobiles
             WHERE jeton_hash = :hash
               AND date_expiration > NOW()
               AND date_utilisation IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            respond_json(401, ['success' => false, 'message' => 'Jeton expire ou deja utilise']);
        }

        $utilisateurId = (int) $row['utilisateur_id'];

        $used = $pdo->prepare(
            'UPDATE music_jetons_mobiles
             SET date_utilisation = NOW()
             WHERE jeton_hash = :hash
               AND date_utilisation IS NULL'
        );
        $used->execute(['hash' => hash('sha256', $token)]);

        if ($used->rowCount() !== 1) {
            $pdo->rollBack();
            respond_json(401, ['success' => false, 'message' => 'Jeton deja utilise']);
        }

        if (!utilisateurAAccesService($pdo, $utilisateurId, SSO_SERVICE)) {
            $pdo->rollBack();
            respond_json(403, ['success' => false, 'message' => 'Acces refuse au service musique']);
        }

        $userStmt = $pdo->prepare(
            'SELECT id, identifiant, email, role FROM utilisateurs WHERE id = :id LIMIT 1'
        );
        $userStmt->execute(['id' => $utilisateurId]);
        $utilisateur = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$utilisateur) {
            $pdo->rollBack();
            respond_json(403, ['success' => false, 'message' => 'Compte introuvable']);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respond_json(500, ['success' => false, 'message' => $error->getMessage()]);
    }

    session_regenerate_id(true);
    sso_apply_user_session($utilisateur);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    session_write_close();

    // Jeton de persistance (remember-me) pour rester connecte entre les lancements de l'app.
    $rememberToken = sso_issue_remember_token($GLOBALS['pdo'], (int) $utilisateur['id']);

    respond_json(200, ['success' => true, 'user' => $_SESSION['user'], 'rememberToken' => $rememberToken]);
}

// Reconnexion silencieuse via un jeton de persistance rotatif (sans mot de passe, revocable).
function handle_remember_login(): void
{
    $token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
    if ($token === '') {
        respond_json(400, ['success' => false, 'message' => 'Jeton requis']);
    }

    $pdo = $GLOBALS['pdo'];

    try {
        $result = sso_rotate_remember_token($pdo, $token);
    } catch (Throwable $error) {
        respond_json(500, ['success' => false, 'message' => $error->getMessage()]);
    }

    if ($result === null) {
        respond_json(401, ['success' => false, 'message' => 'Jeton invalide ou expire']);
    }

    $utilisateurId = (int) $result['utilisateur_id'];

    if (!utilisateurAAccesService($pdo, $utilisateurId, SSO_SERVICE)) {
        sso_revoke_remember_token($pdo, $result['token']);
        respond_json(403, ['success' => false, 'message' => 'Acces refuse au service musique']);
    }

    $stmt = $pdo->prepare('SELECT id, identifiant, email, role FROM utilisateurs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $utilisateurId]);
    $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$utilisateur) {
        sso_revoke_remember_token($pdo, $result['token']);
        respond_json(403, ['success' => false, 'message' => 'Compte introuvable']);
    }

    session_regenerate_id(true);
    sso_apply_user_session($utilisateur);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    session_write_close();

    respond_json(200, ['success' => true, 'user' => $_SESSION['user'], 'rememberToken' => $result['token']]);
}


