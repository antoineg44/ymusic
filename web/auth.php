<?php

declare(strict_types=1);

require_once __DIR__ . '/connexion.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$action = (string) ($_REQUEST['action'] ?? '');

try {
    switch ($action) {
        case 'login':
            handle_login();
            break;

        case 'logout':
            handle_logout();
            break;

        case 'check':
            handle_check();
            break;

        case 'register':
            handle_register();
            break;

        default:
            respond_json(400, ['success' => false, 'message' => 'Action non supportee']);
    }
} catch (Throwable $error) {
    respond_json(500, ['success' => false, 'message' => $error->getMessage()]);
}

function respond_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function resolve_auth_pdo(): PDO
{
    $pdo = null;

    if (function_exists('connexion')) {
        $candidate = connexion();
        if ($candidate instanceof PDO) {
            $pdo = $candidate;
        }
    }

    // Compatibility path for legacy connectors that only set $_SESSION['session'].
    if (!($pdo instanceof PDO) && isset($_SESSION['session']) && $_SESSION['session'] instanceof PDO) {
        $pdo = $_SESSION['session'];
    }

    if (!($pdo instanceof PDO)) {
        throw new RuntimeException(
            'Connexion base de donnees indisponible. Verifiez web/connexion.php et les variables YMUSIC_DB_*.'
        );
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function ensure_users_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS Utilisateurs (
            Id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NomUtilisateur VARCHAR(100) NOT NULL,
            MotDePasseHash VARCHAR(255) NOT NULL,
            RoleUtilisateur VARCHAR(30) NOT NULL DEFAULT 'user',
            Actif TINYINT(1) NOT NULL DEFAULT 1,
            DateCreation DATETIME NOT NULL,
            DateMiseAJour DATETIME NOT NULL,
            PRIMARY KEY (Id),
            UNIQUE KEY uniq_nom_utilisateur (NomUtilisateur)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function normalize_username(string $value): string
{
    return trim(strtolower($value));
}

function sanitize_user(array $row): array
{
    return [
        'id' => (int) $row['Id'],
        'username' => (string) $row['NomUtilisateur'],
        'role' => (string) $row['RoleUtilisateur'],
    ];
}

function find_user_by_username(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare(
        'SELECT Id, NomUtilisateur, MotDePasseHash, RoleUtilisateur, Actif
         FROM Utilisateurs
         WHERE NomUtilisateur = :username
         LIMIT 1'
    );
    $stmt->execute([':username' => $username]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function count_users(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT COUNT(*) AS total FROM Utilisateurs');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['total'] ?? 0);
}

function handle_register(): void
{
    $pdo = resolve_auth_pdo();
    ensure_users_table($pdo);

    $usernameInput = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $username = normalize_username($usernameInput);

    if ($username === '' || $password === '') {
        respond_json(400, ['success' => false, 'message' => 'Identifiant et mot de passe requis']);
    }

    if (strlen($username) < 3 || strlen($username) > 100) {
        respond_json(400, ['success' => false, 'message' => 'Identifiant invalide (3 a 100 caracteres)']);
    }

    if (strlen($password) < 6) {
        respond_json(400, ['success' => false, 'message' => 'Mot de passe trop court (6 caracteres minimum)']);
    }

    if (find_user_by_username($pdo, $username) !== null) {
        respond_json(409, ['success' => false, 'message' => 'Cet identifiant existe deja']);
    }

    $role = count_users($pdo) === 0 ? 'admin' : 'user';
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO Utilisateurs (NomUtilisateur, MotDePasseHash, RoleUtilisateur, Actif, DateCreation, DateMiseAJour)
         VALUES (:username, :hash, :role, 1, :dateCreation, :dateMiseAJour)'
    );

    $stmt->execute([
        ':username' => $username,
        ':hash' => password_hash($password, PASSWORD_BCRYPT),
        ':role' => $role,
        ':dateCreation' => $now,
        ':dateMiseAJour' => $now,
    ]);

    $id = (int) $pdo->lastInsertId();

    $_SESSION['user'] = [
        'id' => $id,
        'username' => $username,
        'role' => $role,
    ];
    session_write_close();

    respond_json(201, [
        'success' => true,
        'message' => 'Compte cree avec succes',
        'user' => $_SESSION['user'],
    ]);
}

function handle_login(): void
{
    $pdo = resolve_auth_pdo();
    ensure_users_table($pdo);

    $username = normalize_username((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        respond_json(400, ['success' => false, 'message' => 'Identifiant et mot de passe requis']);
    }

    $user = find_user_by_username($pdo, $username);

    if ($user === null || (int) $user['Actif'] !== 1 || !password_verify($password, (string) $user['MotDePasseHash'])) {
        respond_json(401, ['success' => false, 'message' => 'Identifiant ou mot de passe incorrect']);
    }

    session_regenerate_id(true);
    $_SESSION['user'] = sanitize_user($user);
    session_write_close();

    respond_json(200, ['success' => true, 'user' => $_SESSION['user']]);
}

function handle_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    respond_json(200, ['success' => true]);
}

function handle_check(): void
{
    if (!empty($_SESSION['user'])) {
        respond_json(200, ['success' => true, 'user' => $_SESSION['user']]);
    }

    respond_json(200, ['success' => false]);
}
