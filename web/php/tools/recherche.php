<?php

function remove_accent_and_ponctuation($textToSearch)
{
    if ($textToSearch !== '')
    {
        $normalizedTextToSearch = function_exists('mb_strtolower')
            ? mb_strtolower($textToSearch, 'UTF-8')
            : strtolower($textToSearch);
        $normalizedTextToSearch = strtr($normalizedTextToSearch, [
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'á' => 'a',
            'ã' => 'a',
            'å' => 'a',
            'æ' => 'ae',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'ö' => 'o',
            'õ' => 'o',
            'œ' => 'oe',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ý' => 'y',
            'ÿ' => 'y',
        ]);
        $normalizedTextToSearch = (string) preg_replace('/[[:punct:]\s]+/u', '', $normalizedTextToSearch);

        // Normalise accents + ponctuation SQL pour recherche souple sur les titres.
        $normalizedTitleSql = 'LOWER(m.Titre)';
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'à', 'a')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'â', 'a')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ä', 'a')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'á', 'a')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ã', 'a')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'å', 'a')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'æ', 'ae')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ç', 'c')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'è', 'e')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'é', 'e')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ê', 'e')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ë', 'e')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ì', 'i')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'í', 'i')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'î', 'i')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ï', 'i')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ñ', 'n')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ò', 'o')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ó', 'o')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ô', 'o')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ö', 'o')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'õ', 'o')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'œ', 'oe')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ù', 'u')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ú', 'u')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'û', 'u')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ü', 'u')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ý', 'y')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ÿ', 'y')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ' ', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '.', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ',', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ';', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ':', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '!', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '?', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '''', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '’', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, CHAR(34), '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '-', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '_', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '/', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '(', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ')', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '[', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ']', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '{', '')";
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '}', '')";

        if ($normalizedTextToSearch === '') {
            $whereClause = 'WHERE 1 = 0';
        } else {
            $whereClause = "WHERE {$normalizedTitleSql} LIKE :titleQueryNormalized";
            $queryParams[':titleQueryNormalized'] = '%' . $normalizedTextToSearch . '%';
        }

        return {
            "text": $normalizedTextToSearch;
            "whereClause": "WHERE {$normalizedTitleSql} LIKE :titleQueryNormalized";
            "queryParams": '%' . $normalizedTextToSearch . '%';
        };
    }
    else
    {
        return NULL;
    }
}