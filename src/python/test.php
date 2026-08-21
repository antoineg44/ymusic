<?php

$domain = "perso.partitions.ovh";

// Vérification basique du domaine
if (!filter_var("https://" . $domain, FILTER_VALIDATE_URL)) {
    die("Nom de domaine invalide.");
}

// Récupération des enregistrements DNS
$records = dns_get_record($domain, DNS_ALL);

if ($records === false) {
    die("Impossible de récupérer les informations DNS.");
}

echo "<pre>";
print_r($records);
echo "</pre>";