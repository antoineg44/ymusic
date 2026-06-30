<?php
declare(strict_types=1);

define('db_user', 'partithbase');
define('db_pass', 'LiturgieToutEn1');
define('serveur', 'partithbase.mysql.db');
define('nom_bd', 'partithbase');

function connexion(): void
{
    try {
        $db_user = 'partithbase';
        $db_pass = 'LiturgieToutEn1';
        $serveur = 'partithbase.mysql.db';
        $nom_bd = 'partithbase';

        $_SESSION['session'] = new PDO("mysql:host=$serveur;dbname=$nom_bd", $db_user, $db_pass);
    } catch (PDOException $e) {
        print "Erreur !: " . $e->getMessage() . "<br/>";
        die();
    }
}

function mres($value)
{
    $search = array("\\", "\x00", "\n", "\r", "'", '"', "\x1a");
    $replace = array("\\\\", "\\0", "\\n", "\\r", "\'", '\"', "\\Z");

    return str_replace($search, $replace, $value);
}


