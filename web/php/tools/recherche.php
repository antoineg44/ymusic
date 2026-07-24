<?php

function build_title_search_sql(string $columnExpression): string
{
    $replacements = [
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
        ' ' => '',
        '.' => '',
        ',' => '',
        ';' => '',
        ':' => '',
        '!' => '',
        '?' => '',
        "'" => '',
        '’' => '',
        '"' => '',
        '-' => '',
        '_' => '',
        '/' => '',
        '(' => '',
        ')' => '',
        '[' => '',
        ']' => '',
        '{' => '',
        '}' => '',
    ];

    $normalizedTitleSql = "LOWER({$columnExpression})";

    foreach ($replacements as $from => $to) {
        if ($from === '"') {
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, CHAR(34), '{$to}')";
            continue;
        }

        $escapedFrom = str_replace("'", "''", $from);
        $escapedTo = str_replace("'", "''", $to);
        $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '{$escapedFrom}', '{$escapedTo}')";
    }

    return $normalizedTitleSql;
}

function remove_accent_and_ponctuation(String $textToSearch)
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

        $normalizedTitleSql = build_title_search_sql('Titre');

        $research_param["text"] = $normalizedTextToSearch;
        if ($normalizedTextToSearch === '') {
            $research_param["whereClause"] = "WHERE 1 = 0";
        }
        else
        {
            $research_param["whereClause"] = "WHERE {$normalizedTitleSql} LIKE :search";
            $research_param["queryParams"] = $normalizedTextToSearch;
        }

        return $research_param;
    }
    else
    {
        return NULL;
    }
}