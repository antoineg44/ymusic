<?php

// Configuration du serveur distant qui exécute les scripts Python (recherche / téléchargement YouTube).
// Surcharge possible via les variables d'environnement YT_REMOTE_BASE_URL et YT_REMOTE_ENDPOINT.

return [
    // Adresse IPv6 entre crochets pour former une URL HTTP valide.
    'remote_base_url' => getenv('YT_REMOTE_BASE_URL') ?: 'http://[2a02:8424:894c:a901:211:32ff:fe99:630b]/nextcloud',
    'remote_endpoint' => getenv('YT_REMOTE_ENDPOINT') ?: '/php/yt/remote_server.php',
];
