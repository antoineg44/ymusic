<?php
// Couche d'acces DB pour la table Musiques: creation schema, insert/update et synchronisation fichiers.
declare(strict_types=1);

require_once __DIR__ . '/../connexion.php';

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
			 SET NombreVueInterne = NombreVueInterne + 1
			 WHERE Id = :id'
		);

		$updateStmt->execute([
			':id' => $id,
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

function ensure_playlists_tables(PDO $pdo): void
{
	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS Playlist (
			idPlaylist INT UNSIGNED NOT NULL AUTO_INCREMENT,
			NomPlaylist VARCHAR(255) NOT NULL,
			Description VARCHAR(1000) NOT NULL DEFAULT '',
			Partage TINYINT(1) NOT NULL DEFAULT 0,
			DateDerniereModification DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			NombreVue BIGINT UNSIGNED NOT NULL DEFAULT 0,
			Utilisateur INT UNSIGNED NOT NULL,
			PRIMARY KEY (idPlaylist),
			KEY idx_playlist_utilisateur (Utilisateur),
			KEY idx_playlist_date_modification (DateDerniereModification)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);

	$partageColumnStmt = $pdo->query("SHOW COLUMNS FROM Playlist LIKE 'Partage'");
	$partageColumnExists = $partageColumnStmt !== false && $partageColumnStmt->fetch(PDO::FETCH_ASSOC) !== false;
	if (!$partageColumnExists) {
		$pdo->exec("ALTER TABLE Playlist ADD COLUMN Partage TINYINT(1) NOT NULL DEFAULT 0 AFTER Description");
	}

	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS MyPlaylistMusiques (
			IdPlaylist INT UNSIGNED NOT NULL,
			IdMusique VARCHAR(191) NOT NULL,
			PositionLecture INT UNSIGNED NOT NULL,
			PRIMARY KEY (IdPlaylist, IdMusique),
			KEY idx_my_playlist_musiques_position (IdPlaylist, PositionLecture),
			KEY idx_my_playlist_musiques_music (IdMusique)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);

	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS PlaylistsYoutube (
			IdPlaylist VARCHAR(191) NOT NULL,
			NomPlaylist VARCHAR(255) NOT NULL,
			UtilisateurCreateur VARCHAR(100) NOT NULL DEFAULT '',
			DateLecture DATETIME NOT NULL,
			PRIMARY KEY (IdPlaylist)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);

	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS PlaylistMusiques (
			IdPlaylist VARCHAR(191) NOT NULL,
			IdMusique VARCHAR(191) NOT NULL,
			PositionLecture INT UNSIGNED NULL,
			DateAjout DATETIME NOT NULL,
			PRIMARY KEY (IdPlaylist, IdMusique),
			KEY idx_playlist_musiques_music (IdMusique)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
}

function ensure_liked_musics_table(PDO $pdo): void
{
	// Associe les musiques aimees a un utilisateur (Utilisateurs.Id vers Musiques.Id).
	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS MusiquesAimees (
			IdUtilisateur INT UNSIGNED NOT NULL,
			IdMusique VARCHAR(191) NOT NULL,
			DateAjout DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (IdUtilisateur, IdMusique),
			KEY idx_musiques_aimees_musique (IdMusique)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
}

function add_liked_music(int $userId, string $musicId, ?PDO $pdo = null): array
{
	$db = $pdo ?? get_database_pdo();
	ensure_liked_musics_table($db);

	$musicId = trim($musicId);
	if ($userId <= 0 || $musicId === '') {
		throw new InvalidArgumentException('Utilisateur et musique requis');
	}

	$stmt = $db->prepare(
		'INSERT INTO MusiquesAimees (IdUtilisateur, IdMusique, DateAjout)
		 VALUES (:userId, :musicId, :dateAjout)
		 ON DUPLICATE KEY UPDATE DateAjout = DateAjout'
	);
	$stmt->execute([
		':userId' => $userId,
		':musicId' => $musicId,
		':dateAjout' => date('Y-m-d H:i:s'),
	]);

	return [
		'IdUtilisateur' => $userId,
		'IdMusique' => $musicId,
	];
}

function remove_liked_music(int $userId, string $musicId, ?PDO $pdo = null): array
{
	$db = $pdo ?? get_database_pdo();
	ensure_liked_musics_table($db);

	$musicId = trim($musicId);
	if ($userId <= 0 || $musicId === '') {
		throw new InvalidArgumentException('Utilisateur et musique requis');
	}

	$stmt = $db->prepare(
		'DELETE FROM MusiquesAimees WHERE IdUtilisateur = :userId AND IdMusique = :musicId'
	);
	$stmt->execute([
		':userId' => $userId,
		':musicId' => $musicId,
	]);

	return [
		'IdUtilisateur' => $userId,
		'IdMusique' => $musicId,
		'removed' => $stmt->rowCount() > 0,
	];
}

// Nombre maximum de musiques lues conservees par utilisateur.
const DERNIERES_MUSIQUES_LUES_MAX = 100;

function ensure_played_history_table(PDO $pdo): void
{
	// Une ligne par utilisateur: 100 paires (DateLectureN, IdMusiqueN), la position 1 etant la plus recente.
	$columns = ['IdUtilisateur INT UNSIGNED NOT NULL'];
	for ($i = 1; $i <= DERNIERES_MUSIQUES_LUES_MAX; $i++) {
		$columns[] = "DateLecture{$i} DATETIME NULL";
		$columns[] = "IdMusique{$i} VARCHAR(191) NULL";
	}
	$columns[] = 'PRIMARY KEY (IdUtilisateur)';

	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS DernieresMusiquesLues (\n\t\t\t"
		. implode(",\n\t\t\t", $columns)
		. "\n\t\t) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
}

function record_played_music(int $userId, string $musicId, ?PDO $pdo = null): array
{
	// Ajoute la lecture courante en tete et conserve les DERNIERES_MUSIQUES_LUES_MAX plus recentes.
	$db = $pdo ?? get_database_pdo();
	ensure_played_history_table($db);

	$musicId = trim($musicId);
	if ($userId <= 0 || $musicId === '') {
		throw new InvalidArgumentException('Utilisateur et musique requis');
	}

	$selectColumns = ['IdUtilisateur'];
	for ($i = 1; $i <= DERNIERES_MUSIQUES_LUES_MAX; $i++) {
		$selectColumns[] = "DateLecture{$i}";
		$selectColumns[] = "IdMusique{$i}";
	}

	$selectStmt = $db->prepare(
		'SELECT ' . implode(', ', $selectColumns) . ' FROM DernieresMusiquesLues WHERE IdUtilisateur = :userId LIMIT 1'
	);
	$selectStmt->execute([':userId' => $userId]);
	$existingRow = $selectStmt->fetch(PDO::FETCH_ASSOC) ?: [];

	$pairs = [];
	for ($i = 1; $i <= DERNIERES_MUSIQUES_LUES_MAX; $i++) {
		$id = trim((string) ($existingRow["IdMusique{$i}"] ?? ''));
		if ($id === '') {
			continue;
		}

		$date = trim((string) ($existingRow["DateLecture{$i}"] ?? ''));
		$pairs[] = ['date' => $date !== '' ? $date : null, 'id' => $id];
	}

	array_unshift($pairs, ['date' => date('Y-m-d H:i:s'), 'id' => $musicId]);
	$pairs = array_slice($pairs, 0, DERNIERES_MUSIQUES_LUES_MAX);

	$insertColumns = ['IdUtilisateur'];
	$placeholders = [':userId'];
	$updates = [];
	$params = [':userId' => $userId];

	for ($i = 1; $i <= DERNIERES_MUSIQUES_LUES_MAX; $i++) {
		$pair = $pairs[$i - 1] ?? null;
		$dateParam = ":date{$i}";
		$idParam = ":id{$i}";

		$insertColumns[] = "DateLecture{$i}";
		$insertColumns[] = "IdMusique{$i}";
		$placeholders[] = $dateParam;
		$placeholders[] = $idParam;
		$updates[] = "DateLecture{$i} = VALUES(DateLecture{$i})";
		$updates[] = "IdMusique{$i} = VALUES(IdMusique{$i})";

		$params[$dateParam] = $pair ? ($pair['date'] ?? date('Y-m-d H:i:s')) : null;
		$params[$idParam] = $pair['id'] ?? null;
	}

	$insertStmt = $db->prepare(
		'INSERT INTO DernieresMusiquesLues (' . implode(', ', $insertColumns) . ')'
		. ' VALUES (' . implode(', ', $placeholders) . ')'
		. ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
	);
	$insertStmt->execute($params);

	return [
		'IdUtilisateur' => $userId,
		'IdMusique' => $musicId,
		'TotalEnregistrees' => count($pairs),
	];
}

function get_played_history(int $userId, ?PDO $pdo = null): array
{
	// Retourne l'historique ordonne (plus recent d'abord) avec les details de chaque musique.
	$db = $pdo ?? get_database_pdo();
	ensure_played_history_table($db);

	if ($userId <= 0) {
		return [];
	}

	$selectColumns = [];
	for ($i = 1; $i <= DERNIERES_MUSIQUES_LUES_MAX; $i++) {
		$selectColumns[] = "DateLecture{$i}";
		$selectColumns[] = "IdMusique{$i}";
	}

	$stmt = $db->prepare(
		'SELECT ' . implode(', ', $selectColumns) . ' FROM DernieresMusiquesLues WHERE IdUtilisateur = :userId LIMIT 1'
	);
	$stmt->execute([':userId' => $userId]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

	$entries = [];
	$uniqueIds = [];
	for ($i = 1; $i <= DERNIERES_MUSIQUES_LUES_MAX; $i++) {
		$id = trim((string) ($row["IdMusique{$i}"] ?? ''));
		if ($id === '') {
			continue;
		}

		$entries[] = [
			'Id' => $id,
			'IdMusique' => $id,
			'DateLecture' => (string) ($row["DateLecture{$i}"] ?? ''),
		];
		$uniqueIds[$id] = true;
	}

	if (empty($entries)) {
		return [];
	}

	ensure_music_table($db);

	$placeholders = [];
	$params = [];
	foreach (array_keys($uniqueIds) as $index => $id) {
		$key = ":id{$index}";
		$placeholders[] = $key;
		$params[$key] = $id;
	}

	$detailsStmt = $db->prepare(
		'SELECT Id, Titre, Artiste, Album, Duree, NombreVue FROM Musiques WHERE Id IN (' . implode(', ', $placeholders) . ')'
	);
	$detailsStmt->execute($params);

	$details = [];
	foreach ($detailsStmt->fetchAll(PDO::FETCH_ASSOC) as $music) {
		$details[(string) $music['Id']] = $music;
	}

	foreach ($entries as &$entry) {
		$music = $details[$entry['IdMusique']] ?? [];
		$entry['Titre'] = (string) ($music['Titre'] ?? '');
		$entry['Artiste'] = (string) ($music['Artiste'] ?? '');
		$entry['Album'] = (string) ($music['Album'] ?? '');
		$entry['Duree'] = $music['Duree'] ?? null;
		$entry['NombreVue'] = (int) ($music['NombreVue'] ?? 0);
	}
	unset($entry);

	return $entries;
}

function save_played_playlist(array $payload, ?PDO $pdo = null): array
{
	$db = $pdo ?? get_database_pdo();
	ensure_playlists_tables($db);

	$playlistId = trim((string) ($payload['PlaylistId'] ?? ''));
	$playlistName = trim((string) ($payload['NomPlaylist'] ?? ''));
	$creator = trim((string) ($payload['UtilisateurCreateur'] ?? ''));

	if ($playlistId === '') {
		throw new InvalidArgumentException('PlaylistId requis');
	}

	if ($playlistName === '') {
		$playlistName = 'Playlist inconnue';
	}

	if ($creator === '' && !empty($_SESSION['user']['username'])) {
		$creator = trim((string) $_SESSION['user']['username']);
	}

	$musicIdsInput = $payload['MusicIds'] ?? [];
	if (is_string($musicIdsInput)) {
		$decoded = json_decode($musicIdsInput, true);
		if (is_array($decoded)) {
			$musicIdsInput = $decoded;
		}
	}

	$musicIds = [];
	if (is_array($musicIdsInput)) {
		foreach ($musicIdsInput as $musicId) {
			$value = trim((string) $musicId);
			if ($value !== '') {
				$musicIds[] = $value;
			}
		}
	}

	$musicIds = array_values(array_unique($musicIds));
	$now = date('Y-m-d H:i:s');

	$upsert = $db->prepare(
		'INSERT INTO PlaylistsYoutube (IdPlaylist, NomPlaylist, UtilisateurCreateur, DateLecture)
		 VALUES (:id, :name, :creator, :dateLecture)
		 ON DUPLICATE KEY UPDATE
			NomPlaylist = VALUES(NomPlaylist),
			UtilisateurCreateur = VALUES(UtilisateurCreateur),
			DateLecture = VALUES(DateLecture)'
	);

	$upsert->execute([
		':id' => $playlistId,
		':name' => $playlistName,
		':creator' => $creator,
		':dateLecture' => $now,
	]);

	$deleteLinks = $db->prepare('DELETE FROM PlaylistMusiques WHERE IdPlaylist = :id');
	$deleteLinks->execute([':id' => $playlistId]);

	if ($musicIds) {
		$insertLink = $db->prepare(
			'INSERT INTO PlaylistMusiques (IdPlaylist, IdMusique, PositionLecture, DateAjout)
			 VALUES (:playlistId, :musicId, :positionLecture, :dateAjout)'
		);

		foreach ($musicIds as $index => $musicId) {
			$insertLink->execute([
				':playlistId' => $playlistId,
				':musicId' => $musicId,
				':positionLecture' => $index + 1,
				':dateAjout' => $now,
			]);
		}
	}

	return [
		'PlaylistId' => $playlistId,
		'NomPlaylist' => $playlistName,
		'UtilisateurCreateur' => $creator,
		'TotalMusiques' => count($musicIds),
	];
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
