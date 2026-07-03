<?php
// Couche d'acces DB pour la table Musiques: creation schema, insert/update et synchronisation fichiers.
declare(strict_types=1);

require_once __DIR__ . '/connexion.php';

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

if (isset($_SESSION['session']) && $_SESSION['session'] instanceof PDO) {
	unset($_SESSION['session']);
}

function get_database_pdo(): PDO
{
	static $pdo = null;

	if ($pdo instanceof PDO) {
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $pdo;
	}

	if (function_exists('connexion')) {
		$candidate = connexion();
		if ($candidate instanceof PDO) {
			$pdo = $candidate;
		}
	}

	if (!($pdo instanceof PDO) && isset($_SESSION['session']) && $_SESSION['session'] instanceof PDO) {
		$pdo = $_SESSION['session'];
		unset($_SESSION['session']);
	}

	if (!($pdo instanceof PDO)) {
		throw new RuntimeException('Connexion PDO indisponible');
	}

	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	return $pdo;
}

function ensure_music_table(PDO $pdo): void
{
	// Garantit le schema attendu et applique les migrations minimales si necessaire.
	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS Musiques (
			Id VARCHAR(191) NOT NULL,
			Titre VARCHAR(255) NOT NULL,
			Artiste VARCHAR(255) NOT NULL DEFAULT '',
			Utilisateur VARCHAR(100) NOT NULL DEFAULT '',
			Album VARCHAR(255) NOT NULL DEFAULT '',
			Duree INT NULL,
			AnneeParution SMALLINT NULL,
			Genre VARCHAR(120) NULL,
			NombreVue BIGINT UNSIGNED NOT NULL DEFAULT 0,
			NombreVueInterne BIGINT UNSIGNED NOT NULL DEFAULT 0,
			DateAjout DATETIME NOT NULL,
			PRIMARY KEY (Id),
			UNIQUE KEY uniq_music_identity (Titre, Artiste, Album)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);

	$columnsStmt = $pdo->query("SHOW COLUMNS FROM Musiques LIKE 'Id'");
	$idColumnExists = $columnsStmt !== false && $columnsStmt->fetch(PDO::FETCH_ASSOC) !== false;

	if (!$idColumnExists) {
		$pdo->exec("ALTER TABLE Musiques ADD COLUMN Id VARCHAR(191) NULL FIRST");
		$pdo->exec(
			"UPDATE Musiques
			 SET Id = LOWER(SHA2(CONCAT_WS('|', Titre, Artiste, Album, DateAjout), 256))
			 WHERE Id IS NULL OR Id = ''"
		);
		$pdo->exec("ALTER TABLE Musiques MODIFY COLUMN Id VARCHAR(191) NOT NULL");
	}

	$userColumnStmt = $pdo->query("SHOW COLUMNS FROM Musiques LIKE 'Utilisateur'");
	$userColumnExists = $userColumnStmt !== false && $userColumnStmt->fetch(PDO::FETCH_ASSOC) !== false;
	if (!$userColumnExists) {
		$pdo->exec("ALTER TABLE Musiques ADD COLUMN Utilisateur VARCHAR(100) NOT NULL DEFAULT '' AFTER Artiste");
	}

	$primaryStmt = $pdo->query(
		"SELECT COLUMN_NAME
		 FROM information_schema.KEY_COLUMN_USAGE
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME = 'Musiques'
		   AND CONSTRAINT_NAME = 'PRIMARY'
		 ORDER BY ORDINAL_POSITION"
	);
	$primaryColumns = $primaryStmt !== false ? $primaryStmt->fetchAll(PDO::FETCH_COLUMN) : [];

	if ($primaryColumns !== ['Id']) {
		$pdo->exec("ALTER TABLE Musiques DROP PRIMARY KEY");
		$pdo->exec("ALTER TABLE Musiques ADD PRIMARY KEY (Id)");
	}

	$uniqueStmt = $pdo->query("SHOW INDEX FROM Musiques WHERE Key_name = 'uniq_music_identity'");
	$hasIdentityUnique = $uniqueStmt !== false && $uniqueStmt->fetch(PDO::FETCH_ASSOC) !== false;
	if (!$hasIdentityUnique) {
		$pdo->exec("ALTER TABLE Musiques ADD UNIQUE KEY uniq_music_identity (Titre, Artiste, Album)");
	}
}

function build_music_id(string $title, string $artist, string $album, string $salt = ''): string
{
	$raw = strtolower(trim($title)) . '|' . strtolower(trim($artist)) . '|' . strtolower(trim($album)) . '|' . trim($salt);
	return hash('sha256', $raw);
}

function get_int_or_null($value): ?int
{
	if ($value === null || $value === '') {
		return null;
	}

	if (!is_numeric($value)) {
		return null;
	}

	return (int) $value;
}

function add_music_to_database(array $payload, ?PDO $pdo = null): array
{
	// Upsert metadonnees d'une musique avec protection des champs sensibles (Album, NombreVue).
	$db = $pdo ?? get_database_pdo();
	ensure_music_table($db);

	$titre = trim((string) ($payload['Titre'] ?? ''));
	if ($titre === '') {
		throw new InvalidArgumentException('Titre requis');
	}

	$artiste = trim((string) ($payload['Artiste'] ?? ''));
	$utilisateur = trim((string) ($payload['Utilisateur'] ?? ''));
	if ($utilisateur === '' && !empty($_SESSION['user']['username'])) {
		$utilisateur = trim((string) $_SESSION['user']['username']);
	}
	$album = trim((string) ($payload['Album'] ?? ''));
	$duree = get_int_or_null($payload['Duree'] ?? null);
	$anneeParution = get_int_or_null($payload['AnneeParution'] ?? null);
	$genre = isset($payload['Genre']) ? trim((string) $payload['Genre']) : null;
	if ($genre === '') {
		$genre = null;
	}

	$nombreVueInput = get_int_or_null($payload['NombreVue'] ?? null);
	$nombreVue = $nombreVueInput ?? 0;
	$nombreVueForUpdate = ($nombreVueInput !== null && $nombreVueInput > 0)
		? max(0, $nombreVueInput)
		: null;
	$nombreVueInterneRaw = get_int_or_null($payload['NombreVueInterne'] ?? null);
	$nombreVueInterne = $nombreVueInterneRaw ?? 1;
	$dateAjoutRaw = trim((string) ($payload['DateAjout'] ?? ''));
	$dateAjout = $dateAjoutRaw !== '' ? date('Y-m-d H:i:s', strtotime($dateAjoutRaw)) : date('Y-m-d H:i:s');
	$idRaw = trim((string) ($payload['Id'] ?? ''));
	$id = $idRaw !== '' ? $idRaw : build_music_id($titre, $artiste, $album);

	$existsStmt = $db->prepare('SELECT Id FROM Musiques WHERE Id = :id LIMIT 1');
	$existsStmt->execute([':id' => $id]);
	$alreadyExists = $existsStmt->fetch(PDO::FETCH_ASSOC) !== false;

	if ($alreadyExists) {
		$updateStmt = $db->prepare(
			'UPDATE Musiques
			 SET
				Artiste = CASE WHEN :artiste <> "" THEN :artiste ELSE Artiste END,
				Utilisateur = :utilisateur,
				Album = CASE WHEN :album <> "" AND LOWER(TRIM(:album)) <> "temp" THEN :album ELSE Album END,
				Duree = COALESCE(:duree, Duree),
				AnneeParution = COALESCE(:anneeParution, AnneeParution),
				Genre = COALESCE(:genre, Genre),
				NombreVue = COALESCE(:nombreVue, NombreVue),
				NombreVueInterne = NombreVueInterne + 1,
				DateAjout = :dateAjout
			 WHERE Id = :id'
		);

		$updateStmt->execute([
			':id' => $id,
			':artiste' => $artiste,
			':utilisateur' => $utilisateur,
			':album' => $album,
			':duree' => $duree,
			':anneeParution' => $anneeParution,
			':genre' => $genre,
			':nombreVue' => $nombreVueForUpdate,
			':dateAjout' => $dateAjout,
		]);

		return [
			'Id' => $id,
			'Titre' => $titre,
			'Artiste' => $artiste,
			'Utilisateur' => $utilisateur,
			'Album' => $album,
		];
	}

	$stmt = $db->prepare(
		'INSERT INTO Musiques (
			Id,
			Titre,
			Artiste,
			Utilisateur,
			Album,
			Duree,
			AnneeParution,
			Genre,
			NombreVue,
			NombreVueInterne,
			DateAjout
		) VALUES (
			:id,
			:titre,
			:artiste,
			:utilisateur,
			:album,
			:duree,
			:anneeParution,
			:genre,
			:nombreVue,
			:nombreVueInterne,
			:dateAjout
		)'
	);

	$stmt->execute([
		':id' => $id,
		':titre' => $titre,
		':artiste' => $artiste,
		':utilisateur' => $utilisateur,
		':album' => $album,
		':duree' => $duree,
		':anneeParution' => $anneeParution,
		':genre' => $genre,
		':nombreVue' => max(0, $nombreVue),
		':nombreVueInterne' => max(1, $nombreVueInterne),
		':dateAjout' => $dateAjout,
	]);

	return [
		'Id' => $id,
		'Titre' => $titre,
		'Artiste' => $artiste,
		'Utilisateur' => $utilisateur,
		'Album' => $album,
	];
}

function parse_artist_and_title(string $fileNameWithoutExt): array
{
	$parts = explode(' - ', $fileNameWithoutExt, 2);

	if (count($parts) === 2) {
		return [trim($parts[0]), trim($parts[1])];
	}

	return ['', trim($fileNameWithoutExt)];
}

function sync_music_table(PDO $pdo): array
{
	// Indexe les fichiers audio locaux dans la base en conservant un identifiant stable.
	ensure_music_table($pdo);

	$webRoot = dirname(__DIR__);
	$baseDir = $webRoot . '/data';
	$allowedExtensions = ['mp3', 'm4a', 'aac', 'ogg', 'wav', 'flac', 'webm'];

	if (!is_dir($baseDir)) {
		return ['processed' => 0];
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	$stmt = $pdo->prepare(
		'INSERT INTO Musiques (
			Id,
			Titre,
			Artiste,
			Utilisateur,
			Album,
			Duree,
			AnneeParution,
			Genre,
			NombreVue,
			NombreVueInterne,
			DateAjout
		) VALUES (
			:id,
			:titre,
			:artiste,
			:utilisateur,
			:album,
			:duree,
			:anneeParution,
			:genre,
			0,
			0,
			:dateAjout
		) ON DUPLICATE KEY UPDATE
			DateAjout = VALUES(DateAjout),
			Utilisateur = VALUES(Utilisateur),
			Duree = COALESCE(VALUES(Duree), Duree),
			AnneeParution = COALESCE(VALUES(AnneeParution), AnneeParution),
			Genre = COALESCE(VALUES(Genre), Genre)'
	);

	$processed = 0;

	foreach ($iterator as $fileInfo) {
		if (!$fileInfo->isFile()) {
			continue;
		}

		$extension = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));

		if (!in_array($extension, $allowedExtensions, true)) {
			continue;
		}

		$folder = str_replace('\\', '/', substr($fileInfo->getPath(), strlen($baseDir) + 1));
		$album = trim($folder, '/');
		$relativePath = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($webRoot) + 1));

		[$artist, $title] = parse_artist_and_title(pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME));
		$dateAjout = date('Y-m-d H:i:s', (int) $fileInfo->getMTime());
		$id = build_music_id($title, $artist, $album, $relativePath);

		$stmt->execute([
			':id' => $id,
			':titre' => $title,
			':artiste' => $artist,
			':utilisateur' => '',
			':album' => $album,
			':duree' => null,
			':anneeParution' => null,
			':genre' => null,
			':dateAjout' => $dateAjout,
		]);

		$processed += 1;
	}

	return ['processed' => $processed];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	header('Content-Type: application/json');

	try {
		$result = sync_music_table(get_database_pdo());

		echo json_encode([
			'success' => true,
			'table' => 'Musiques',
			'processed' => $result['processed'],
		], JSON_UNESCAPED_UNICODE);
	} catch (Throwable $e) {
		echo json_encode([
			'success' => false,
			'error' => $e->getMessage(),
		], JSON_UNESCAPED_UNICODE);
	}
}
