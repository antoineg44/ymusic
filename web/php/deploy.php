
<?php

// Script de deploiement simple: checkout/pull d'une branche cible sur le serveur.

if(!isset($_GET['branch_name'])) {
    die("Erreur : Veuillez indiquer un nom de branche.\n");
}

// Nom de la branche
$branch_name = (String) trim($_GET['branch_name']);
//$branch_name = "test";

// Configurations
if($branch_name == "main") {
    $path = "../../music";
} else {
    $path = "../../".$branch_name;
}

// Repo
$repoUrl = "https://github.com/antoineg44/ymusic.git"; // Remplacez par l'URL de votre dépôt

// Fonction utilitaire pour exécuter une commande shell
function runCommand($command, &$output = null, &$returnVar = null) {
    // Execute une commande shell et arrete le script en cas d'erreur.
    echo "Exécution de la commande : $command\n";
    exec($command, $output, $returnVar);
    echo implode("\n", $output) . "\n";
    if ($returnVar !== 0) {
        die("Erreur : La commande a échoué avec le code $returnVar\n");
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

// Aller dans le dossier
chdir($path);

// Mettre à jour la branche spécifique
runCommand("git checkout $branch_name");
runCommand("git pull origin $branch_name");

echo "Déploiement terminé avec succès.\n";