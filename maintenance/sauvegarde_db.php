<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/

// Ton fichier de connexion
require_once '/var/www/html/src/php/connexion.php';

// Dossier où seront stockées les sauvegardes.
// IMPORTANT : utilise de préférence un chemin ABSOLU.
$dossierSauvegarde = '/mnt/sauv/musiques/db';

/*
|--------------------------------------------------------------------------
| CONNEXION À LA BASE
|--------------------------------------------------------------------------
*/

try {
    $pdo = new PDO(
        'mysql:host=' . serveur . ';dbname=' . nom_bd . ';charset=utf8mb4',
        db_user,
        db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage() . PHP_EOL);
}

/*
|--------------------------------------------------------------------------
| CRÉATION DU DOSSIER
|--------------------------------------------------------------------------
*/

if (!is_dir($dossierSauvegarde)) {
    if (!mkdir($dossierSauvegarde, 0750, true)) {
        die("Impossible de créer le dossier de sauvegarde.");
    }
}

if (!is_writable($dossierSauvegarde)) {
    die("Le dossier de sauvegarde n'est pas accessible en écriture.");
}

/*
|--------------------------------------------------------------------------
| NOM DU FICHIER
|--------------------------------------------------------------------------
*/

$filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';

$fichier = rtrim($dossierSauvegarde, DIRECTORY_SEPARATOR)
         . DIRECTORY_SEPARATOR
         . $filename;


/*
|--------------------------------------------------------------------------
| CRÉATION DU FICHIER
|--------------------------------------------------------------------------
*/

$handle = fopen($fichier, 'wb');

if ($handle === false) {
    die("Impossible de créer le fichier de sauvegarde.");
}


/*
|--------------------------------------------------------------------------
| EN-TÊTE SQL
|--------------------------------------------------------------------------
*/

fwrite($handle, "-- Sauvegarde MySQL\n");
fwrite($handle, "-- Date : " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "-- Base : " . nom_bd . "\n\n");

fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
fwrite($handle, "START TRANSACTION;\n\n");


/*
|--------------------------------------------------------------------------
| RÉCUPÉRATION DES TABLES
|--------------------------------------------------------------------------
*/

$tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
              ->fetchAll(PDO::FETCH_COLUMN);


/*
|--------------------------------------------------------------------------
| SAUVEGARDE DES TABLES
|--------------------------------------------------------------------------
*/

foreach ($tables as $table) {

    $tableQuoted = '`' . str_replace('`', '``', $table) . '`';

    /*
    |--------------------------------------------------------------------------
    | STRUCTURE
    |--------------------------------------------------------------------------
    */

    fwrite($handle, "-- --------------------------------------------------------\n");
    fwrite($handle, "-- Table : $table\n");
    fwrite($handle, "-- --------------------------------------------------------\n\n");

    fwrite($handle, "DROP TABLE IF EXISTS $tableQuoted;\n");

    $create = $pdo->query("SHOW CREATE TABLE $tableQuoted")->fetch();

    fwrite($handle, $create['Create Table'] . ";\n\n");


    /*
    |--------------------------------------------------------------------------
    | DONNÉES
    |--------------------------------------------------------------------------
    */

    $rows = $pdo->query("SELECT * FROM $tableQuoted");

    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {

        $columns = [];
        $values  = [];

        foreach ($row as $column => $value) {

            $columns[] = '`' . str_replace('`', '``', $column) . '`';

            if ($value === null) {
                $values[] = 'NULL';
            } else {
                $values[] = $pdo->quote((string) $value);
            }
        }

        $insert = "INSERT INTO $tableQuoted ("
                . implode(', ', $columns)
                . ") VALUES ("
                . implode(', ', $values)
                . ");\n";

        fwrite($handle, $insert);
    }

    fwrite($handle, "\n");
}


/*
|--------------------------------------------------------------------------
| FIN DU SQL
|--------------------------------------------------------------------------
*/

fwrite($handle, "COMMIT;\n");
fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");

fclose($handle);


/*
|--------------------------------------------------------------------------
| ROTATION DES SAUVEGARDES
|--------------------------------------------------------------------------
|
| On conserve :
|
| - 7 derniers jours
| - 7 dernières semaines
| - 7 derniers mois
| - 7 dernières années
|
|--------------------------------------------------------------------------
*/


$fichiers = glob(
    rtrim($dossierSauvegarde, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'backup_*.sql'
);

if ($fichiers === false) {
    $fichiers = [];
}


/*
|--------------------------------------------------------------------------
| TRI DU PLUS RÉCENT AU PLUS ANCIEN
|--------------------------------------------------------------------------
*/

usort($fichiers, function ($a, $b) {
    return filemtime($b) <=> filemtime($a);
});


/*
|--------------------------------------------------------------------------
| TABLES DE ROTATION
|--------------------------------------------------------------------------
*/

$joursConserves = [];
$semainesConservees = [];
$moisConserves = [];
$anneesConservees = [];

$maintenir = [];


/*
|--------------------------------------------------------------------------
| ANALYSE DES FICHIERS
|--------------------------------------------------------------------------
*/

$maintenant = new DateTimeImmutable();

foreach ($fichiers as $fichierBackup) {

    $dateFichier = DateTimeImmutable::createFromFormat(
        'U',
        (string) filemtime($fichierBackup)
    );

    if (!$dateFichier) {
        continue;
    }

    $age = $maintenant->diff($dateFichier)->days;

    /*
    |--------------------------------------------------------------------------
    | 1. 7 DERNIERS JOURS
    |--------------------------------------------------------------------------
    */

    if ($age < 7) {

        $cleJour = $dateFichier->format('Y-m-d');

        if (!isset($joursConserves[$cleJour])) {

            $joursConserves[$cleJour] = true;
            $maintenir[$fichierBackup] = 'quotidien';
        }

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | 2. 7 DERNIÈRES SEMAINES
    |--------------------------------------------------------------------------
    */

    if ($age < 56) {

        $cleSemaine = $dateFichier->format('o-W');

        if (!isset($semainesConservees[$cleSemaine])) {

            $semainesConservees[$cleSemaine] = true;
            $maintenir[$fichierBackup] = 'hebdomadaire';
        }

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | 3. 7 DERNIERS MOIS
    |--------------------------------------------------------------------------
    */

    if ($age < 365) {

        $cleMois = $dateFichier->format('Y-m');

        if (!isset($moisConserves[$cleMois])) {

            $moisConserves[$cleMois] = true;
            $maintenir[$fichierBackup] = 'mensuel';
        }

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | 4. 7 DERNIÈRES ANNÉES
    |--------------------------------------------------------------------------
    */

    if ($age < 365 * 7) {

        $cleAnnee = $dateFichier->format('Y');

        if (!isset($anneesConservees[$cleAnnee])) {

            $anneesConservees[$cleAnnee] = true;
            $maintenir[$fichierBackup] = 'annuel';
        }

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | PLUS DE 7 ANS
    |--------------------------------------------------------------------------
    |
    | Le fichier ne sera pas ajouté à $maintenir et sera donc supprimé.
    |
    */
}


/*
|--------------------------------------------------------------------------
| SUPPRESSION DES ANCIENNES SAUVEGARDES
|--------------------------------------------------------------------------
*/

foreach ($fichiers as $fichierBackup) {

    if (!isset($maintenir[$fichierBackup])) {

        if (is_file($fichierBackup)) {
            unlink($fichierBackup);
        }
    }
}


/*
|--------------------------------------------------------------------------
| JOURNAL
|--------------------------------------------------------------------------
*/

echo "Sauvegarde terminée : " . basename($fichier) . PHP_EOL;
echo "Fichier : " . $fichier . PHP_EOL;
echo "Rotation effectuée." . PHP_EOL;

?>