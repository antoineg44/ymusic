
<?php

// Script de deploiement simple: checkout/pull d'une branche cible sur le serveur.

if(!isset($_GET['branch_name'])) {
    die("Erreur : Veuillez indiquer un nom de branche.\n");
}

// Nom de la branche
$branch_name = (String) trim($_GET['branch_name']);
//$branch_name = "test";

// Détermination robuste du chemin de destination à partir de l'emplacement du script.
// Cela évite les problèmes lorsque PHP est lancé depuis un autre répertoire.
$repoRoot = realpath(dirname(__DIR__, 2));
$parentDir = dirname($repoRoot);

if ($branch_name === "main") {
    $path = $repoRoot;
} else {
    $path = $parentDir . "/" . $branch_name;
}
$path = rtrim($path, "/");

// Repo
$repoUrl = "https://github.com/antoineg44/ymusic.git"; // Remplacez par l'URL de votre dépôt

// Fonction utilitaire pour exécuter une commande shell
function runCommand($command, &$output = null, &$returnVar = null) {
    // Execute une commande shell et arrete le script en cas d'erreur.
    echo "Exécution de la commande : $command\n";
    exec($command . " 2>&1", $output, $returnVar);
    $outputText = implode("\n", $output);
    echo $outputText . "\n";
    if ($returnVar !== 0) {
        $errorMessage = "Erreur : La commande a échoué avec le code $returnVar\n";
        if ($returnVar === 128 && (strpos($outputText, 'permission denied') !== false || strpos($outputText, 'could not create work tree dir') !== false || strpos($outputText, 'unable to create file') !== false)) {
            $errorMessage .= "Le dépôt Git n'est peut-être pas accessible en écriture pour l'utilisateur du serveur web. Vérifiez les permissions du dossier et du dépôt Git.\n";
        }
        die($errorMessage);
    }
}

echo "Déploiement de la branche '$branch_name' dans '$path'\n";

/*
//At the first execution run:
chdir("../../");
runCommand("git clone ".$repoUrl);
// And rename the folder from serveur_chant to $branch_name
*/

// Vérifiez si le dossier existe et contient un dépôt Git
if (!is_dir($path) || !is_dir("$path/.git")) {
    die("Erreur : Le dossier '$path' n'existe pas ou n'est pas un dépôt Git valide.\n");
}

if (!is_writable($path) || !is_writable("$path/.git")) {
    die("Erreur : Le dépôt Git '$path' n'est pas accessible en écriture pour l'utilisateur PHP/serveur web. Vérifiez les permissions avec une commande du type : chmod -R a+rwX '$path'\n");
}

// Aller dans le dossier
chdir($path);

// Mettre à jour la branche spécifique
runCommand("git checkout " . escapeshellarg($branch_name));
runCommand("git pull origin " . escapeshellarg($branch_name));

echo "Déploiement terminé avec succès.\n";