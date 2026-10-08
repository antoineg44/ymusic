<?php

// Pour sauvegarder les fichiers pdf

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/

$source = '/var/www/html/src/data';
$destination = '/mnt/sauv/musiques/data';


/*
|--------------------------------------------------------------------------
| VÉRIFICATION DES DOSSIERS
|--------------------------------------------------------------------------
*/

if (!is_dir($source)) {
    die("ERREUR : Le dossier source n'existe pas : $source\n");
}

if (!is_readable($source)) {
    die("ERREUR : Le dossier source n'est pas lisible : $source\n");
}

if (!is_dir($destination)) {
    if (!mkdir($destination, 0750, true)) {
        die("ERREUR : Impossible de créer le dossier destination : $destination\n");
    }
}

if (!is_writable($destination)) {
    die("ERREUR : Le dossier destination n'est pas accessible en écriture : $destination\n");
}


/*
|--------------------------------------------------------------------------
| COMPTEURS
|--------------------------------------------------------------------------
*/

$ajoutes = 0;
$dejaPresents = 0;
$erreurs = 0;


/*
|--------------------------------------------------------------------------
| PARCOURS RÉCURSIF DU DOSSIER SOURCE
|--------------------------------------------------------------------------
*/

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $source,
        FilesystemIterator::SKIP_DOTS
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);


foreach ($iterator as $fichierSource) {

    if (!$fichierSource->isFile()) {
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | CHEMIN RELATIF
    |--------------------------------------------------------------------------
    */

    $cheminSource = $fichierSource->getPathname();

    $cheminRelatif = substr(
        $cheminSource,
        strlen($source) + 1
    );


    /*
    |--------------------------------------------------------------------------
    | CHEMIN DESTINATION
    |--------------------------------------------------------------------------
    */

    $cheminDestination = $destination
        . DIRECTORY_SEPARATOR
        . $cheminRelatif;


    /*
    |--------------------------------------------------------------------------
    | LE FICHIER EXISTE DÉJÀ ?
    |--------------------------------------------------------------------------
    */

    if (is_file($cheminDestination)) {

        $dejaPresents++;

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | CRÉATION DU DOSSIER DESTINATION
    |--------------------------------------------------------------------------
    */

    $dossierDestination = dirname($cheminDestination);

    if (!is_dir($dossierDestination)) {

        if (!mkdir($dossierDestination, 0750, true)) {

            echo "ERREUR : Impossible de créer : "
                . $dossierDestination
                . PHP_EOL;

            $erreurs++;

            continue;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | COPIE DU FICHIER
    |--------------------------------------------------------------------------
    */

    if (copy($cheminSource, $cheminDestination)) {

        // On conserve la date de modification du fichier original
        touch(
            $cheminDestination,
            $fichierSource->getMTime()
        );

        echo "AJOUT : $cheminRelatif" . PHP_EOL;

        $ajoutes++;

    } else {

        echo "ERREUR : Impossible de copier : "
            . $cheminRelatif
            . PHP_EOL;

        $erreurs++;
    }
}


/*
|--------------------------------------------------------------------------
| RÉSUMÉ
|--------------------------------------------------------------------------
*/

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "Synchronisation terminée" . PHP_EOL;
echo "========================================" . PHP_EOL;
echo "Fichiers ajoutés     : $ajoutes" . PHP_EOL;
echo "Déjà présents       : $dejaPresents" . PHP_EOL;
echo "Erreurs              : $erreurs" . PHP_EOL;
echo "========================================" . PHP_EOL;

?>