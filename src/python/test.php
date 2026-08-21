<?php

$ipv6 = "2a02:8424:894c:a901:211:32ff:fe99:630b";

$hostname = gethostbyaddr($ipv6);

if ($hostname === false || $hostname === $ipv6) {
    echo "Aucun nom de domaine associé à cette IPv6.";
} else {
    echo "Nom d'hôte : " . $hostname;
}