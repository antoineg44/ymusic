<?php
declare(strict_types=1);

require_once __DIR__ . '/connexion.php';

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

function get_database_pdo(): PDO
{
	if (!isset($_SESSION['session']) || !($_SESSION['session'] instanceof PDO)) {
		connexion();
	}

	if (!($_SESSION['session'] instanceof PDO)) {
		throw new RuntimeException('Connexion PDO indisponible');
	}

	$pdo = $_SESSION['session'];
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	return $pdo;
}

function ensure_music_table(PDO $pdo): void
{
	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS Musiques (
			Id VARCHAR(191) NOT NULL,
			Titre VARCHAR(255) NOT NULL,
			Artiste VARCHAR(255) NOT NULL DEFAULT '',
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
	$db = $pdo ?? get_database_pdo();
	ensure_music_table($db);

	$titre = trim((string) ($payload['Titre'] ?? ''));
	if ($titre === '') {
		throw new InvalidArgumentException('Titre requis');
	}

	$artiste = trim((string) ($payload['Artiste'] ?? ''));
	$album = trim((string) ($payload['Album'] ?? ''));
	$duree = get_int_or_null($payload['Duree'] ?? null);
	$anneeParution = get_int_or_null($payload['AnneeParution'] ?? null);
	$genre = isset($payload['Genre']) ? trim((string) $payload['Genre']) : null;
	if ($genre === '') {
		$genre = null;
	}

	$nombreVue = get_int_or_null($payload['NombreVue'] ?? 0) ?? 0;
	$nombreVueInterne = get_int_or_null($payload['NombreVueInterne'] ?? 0) ?? 0;
	$dateAjoutRaw = trim((string) ($payload['DateAjout'] ?? ''));
	$dateAjout = $dateAjoutRaw !== '' ? date('Y-m-d H:i:s', strtotime($dateAjoutRaw)) : date('Y-m-d H:i:s');
	$idRaw = trim((string) ($payload['Id'] ?? ''));
	$id = $idRaw !== '' ? $idRaw : build_music_id($titre, $artiste, $album);

	$stmt = $db->prepare(
		'INSERT INTO Musiques (
			Id,
			Titre,
			Artiste,
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
			:album,
			:duree,
			:anneeParution,
			:genre,
			:nombreVue,
			:nombreVueInterne,
			:dateAjout
		) ON DUPLICATE KEY UPDATE
			Duree = VALUES(Duree),
			AnneeParution = VALUES(AnneeParution),
			Genre = VALUES(Genre),
			NombreVue = VALUES(NombreVue),
			NombreVueInterne = VALUES(NombreVueInterne),
			DateAjout = VALUES(DateAjout)'
	);

	$stmt->execute([
		':id' => $id,
		':titre' => $titre,
		':artiste' => $artiste,
		':album' => $album,
		':duree' => $duree,
		':anneeParution' => $anneeParution,
		':genre' => $genre,
		':nombreVue' => max(0, $nombreVue),
		':nombreVueInterne' => max(0, $nombreVueInterne),
		':dateAjout' => $dateAjout,
	]);

	return [
		'Id' => $id,
		'Titre' => $titre,
		'Artiste' => $artiste,
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
	ensure_music_table($pdo);

	$baseDir = __DIR__ . '/data';
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
			:album,
			:duree,
			:anneeParution,
			:genre,
			0,
			0,
			:dateAjout
		) ON DUPLICATE KEY UPDATE
			DateAjout = VALUES(DateAjout),
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
		$relativePath = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen(__DIR__) + 1));

		[$artist, $title] = parse_artist_and_title(pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME));
		$dateAjout = date('Y-m-d H:i:s', (int) $fileInfo->getMTime());
		$id = build_music_id($title, $artist, $album, $relativePath);

		$stmt->execute([
			':id' => $id,
			':titre' => $title,
			':artiste' => $artist,
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
