<?php

// Endpoint d'authentification: login, logout, check session et gestion des utilisateurs admin.

declare(strict_types=1);

require_once __DIR__ . '/connexion.php';

const AUTH_SESSION_LIFETIME = 2592000; // 30 jours

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_set_cookie_params([
        'lifetime' => AUTH_SESSION_LIFETIME,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (isset($_SESSION['session']) && $_SESSION['session'] instanceof PDO) {
    unset($_SESSION['session']);
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

        case 'list_users':
            require_admin();
            handle_list_users();
            break;

        case 'create_user':
            require_admin();
            handle_create_user();
            break;

        case 'update_user':
            require_admin();
            handle_update_user();
            break;

        case 'delete_user':
            require_admin();
            handle_delete_user();
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

function resolve_auth_pdo(): PDO
{
    // Recupere une connexion PDO via le connecteur principal, avec fallback legacy session.
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
        unset($_SESSION['session']);
    }

    if (!($pdo instanceof PDO)) {
        throw new RuntimeException(
            'Connexion base de donnees indisponible. Verifiez web/php/connexion.php et les variables YMUSIC_DB_*.'
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

function require_admin(): void
{
    if (empty($_SESSION['user']) || (string) ($_SESSION['user']['role'] ?? '') !== 'admin') {
        respond_json(403, ['success' => false, 'message' => 'Acces refuse']);
    }
}

function sanitize_user(array $row): array
{
    return [
        'id' => (int) $row['Id'],
        'username' => (string) $row['NomUtilisateur'],
        'role' => (string) $row['RoleUtilisateur'],
    ];
}

function refresh_session_cookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
        return;
    }

    $params = session_get_cookie_params();
    $expiry = time() + AUTH_SESSION_LIFETIME;

    setcookie(session_name(), session_id(), [
        'expires' => $expiry,
        'path' => (string) ($params['path'] ?? '/'),
        'domain' => (string) ($params['domain'] ?? ''),
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => (bool) ($params['httponly'] ?? true),
        'samesite' => (string) ($params['samesite'] ?? 'Lax'),
    ]);
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

function count_admin_users(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM Utilisateurs WHERE RoleUtilisateur = 'admin'");
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
    unset($_SESSION['session']);
    refresh_session_cookie();
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

    $updateStmt = $pdo->prepare(
        'UPDATE Utilisateurs
         SET DateMiseAJour = NOW()
         WHERE Id = :id'
    );
    $updateStmt->execute([
        ':id' => (int) $user['Id'],
    ]);

    session_regenerate_id(true);
    $_SESSION['user'] = sanitize_user($user);
    unset($_SESSION['session']);
    refresh_session_cookie();
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
        refresh_session_cookie();
        respond_json(200, ['success' => true, 'user' => $_SESSION['user']]);
    }

    respond_json(200, ['success' => false]);
}

function handle_list_users(): void
{
    $pdo = resolve_auth_pdo();
    ensure_users_table($pdo);

    $stmt = $pdo->query(
        'SELECT
            Id,
            NomUtilisateur,
            RoleUtilisateur,
            Actif,
            DateCreation,
            DateMiseAJour
         FROM Utilisateurs
         ORDER BY Id ASC'
    );

    $users = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $users[] = [
            'id' => (int) $row['Id'],
            'username' => (string) $row['NomUtilisateur'],
            'role' => (string) $row['RoleUtilisateur'],
            'active' => (int) $row['Actif'] === 1,
            'createdAt' => (string) $row['DateCreation'],
            'updatedAt' => (string) $row['DateMiseAJour'],
        ];
    }

    respond_json(200, ['success' => true, 'users' => $users]);
}

function handle_create_user(): void
{
    $pdo = resolve_auth_pdo();
    ensure_users_table($pdo);

    $username = normalize_username((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? 'user');
    $active = filter_var($_POST['active'] ?? '1', FILTER_VALIDATE_BOOLEAN);

    if ($username === '' || strlen($username) < 3 || strlen($username) > 100) {
        respond_json(400, ['success' => false, 'message' => 'Identifiant invalide (3 a 100 caracteres)']);
    }

    if (strlen($password) < 6) {
        respond_json(400, ['success' => false, 'message' => 'Mot de passe trop court (6 caracteres minimum)']);
    }

    if (!in_array($role, ['admin', 'user'], true)) {
        respond_json(400, ['success' => false, 'message' => 'Role invalide']);
    }

    if (find_user_by_username($pdo, $username) !== null) {
        respond_json(409, ['success' => false, 'message' => 'Cet identifiant existe deja']);
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO Utilisateurs (NomUtilisateur, MotDePasseHash, RoleUtilisateur, Actif, DateCreation, DateMiseAJour)
         VALUES (:username, :hash, :role, :active, :dateCreation, :dateMiseAJour)'
    );
    $stmt->execute([
        ':username' => $username,
        ':hash' => password_hash($password, PASSWORD_BCRYPT),
        ':role' => $role,
        ':active' => $active ? 1 : 0,
        ':dateCreation' => $now,
        ':dateMiseAJour' => $now,
    ]);

    respond_json(200, ['success' => true]);
}

function handle_update_user(): void
{
    $pdo = resolve_auth_pdo();
    ensure_users_table($pdo);

    $id = (int) ($_POST['id'] ?? 0);
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? '');
    $activeValue = $_POST['active'] ?? null;
    $active = $activeValue === null
        ? null
        : filter_var($activeValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if ($id <= 0) {
        respond_json(400, ['success' => false, 'message' => 'ID utilisateur invalide']);
    }

    $stmt = $pdo->prepare('SELECT Id, RoleUtilisateur FROM Utilisateurs WHERE Id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        respond_json(404, ['success' => false, 'message' => 'Utilisateur introuvable']);
    }

    if ($role !== '' && !in_array($role, ['admin', 'user'], true)) {
        respond_json(400, ['success' => false, 'message' => 'Role invalide']);
    }

    if ((string) $current['RoleUtilisateur'] === 'admin' && $role === 'user' && count_admin_users($pdo) <= 1) {
        respond_json(400, ['success' => false, 'message' => 'Impossible de retrograder le dernier administrateur']);
    }

    if ((string) $current['RoleUtilisateur'] === 'admin' && $active === false && count_admin_users($pdo) <= 1) {
        respond_json(400, ['success' => false, 'message' => 'Impossible de desactiver le dernier administrateur']);
    }

    $fields = [];
    $params = [':id' => $id, ':dateMiseAJour' => date('Y-m-d H:i:s')];

    if ($password !== '') {
        if (strlen($password) < 6) {
            respond_json(400, ['success' => false, 'message' => 'Mot de passe trop court (6 caracteres minimum)']);
        }
        $fields[] = 'MotDePasseHash = :passwordHash';
        $params[':passwordHash'] = password_hash($password, PASSWORD_BCRYPT);
    }

    if ($role !== '') {
        $fields[] = 'RoleUtilisateur = :role';
        $params[':role'] = $role;
    }

    if ($active !== null) {
        $fields[] = 'Actif = :active';
        $params[':active'] = $active ? 1 : 0;
    }

    if (empty($fields)) {
        respond_json(200, ['success' => true, 'message' => 'Aucune modification']);
    }

    $fields[] = 'DateMiseAJour = :dateMiseAJour';
    $query = 'UPDATE Utilisateurs SET ' . implode(', ', $fields) . ' WHERE Id = :id';
    $update = $pdo->prepare($query);
    $update->execute($params);

    respond_json(200, ['success' => true]);
}

function handle_delete_user(): void
{
    $pdo = resolve_auth_pdo();
    ensure_users_table($pdo);

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond_json(400, ['success' => false, 'message' => 'ID utilisateur invalide']);
    }

    if (!empty($_SESSION['user']) && (int) ($_SESSION['user']['id'] ?? 0) === $id) {
        respond_json(400, ['success' => false, 'message' => 'Impossible de supprimer votre compte connecte']);
    }

    $stmt = $pdo->prepare('SELECT Id, RoleUtilisateur FROM Utilisateurs WHERE Id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        respond_json(404, ['success' => false, 'message' => 'Utilisateur introuvable']);
    }

    if ((string) $target['RoleUtilisateur'] === 'admin' && count_admin_users($pdo) <= 1) {
        respond_json(400, ['success' => false, 'message' => 'Impossible de supprimer le dernier administrateur']);
    }

    $delete = $pdo->prepare('DELETE FROM Utilisateurs WHERE Id = :id');
    $delete->execute([':id' => $id]);

    respond_json(200, ['success' => true]);
}
