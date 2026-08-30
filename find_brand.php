<?php

function findBrandByText(
    $db,
    $prefix,
    $text
) {

    $text = trim($text);

    if ($text === '') {
        return null;
    }

    /*
     * اول هشتگ‌ها
     */

    preg_match_all(
        '/#([^\s#]+)/u',
        $text,
        $hashtags
    );

    $candidates = [];

    foreach (
        ($hashtags[1] ?? []) as $tag
    ) {

        $tag = str_replace(
            ['_','-'],
            ' ',
            $tag
        );

        $candidates[] = $tag;
    }


    /*
     * متن کامل هم بررسی شود
     */

    $candidates[] = $text;


    $res = $db->sql_query("
        SELECT
            id,
            title
        FROM ".$prefix."_brands
        WHERE active='1'
        ORDER BY id ASC
    ");


    while ($brand = $db->sql_fetchrow($res)) {

        foreach ($candidates as $candidate) {

            if (
                mb_stripos(
                    $candidate,
                    $brand['title']
                ) !== false
            ) {

                return $brand;
            }


            /*
             * حالت هشتگ:
             *
             * #فولاد_راد_همدان
             */

            $brandNormalized =
                str_replace(
                    [' ','_','-'],
                    '',
                    $brand['title']
                );

            $candidateNormalized =
                str_replace(
                    [' ','_','-'],
                    '',
                    $candidate
                );

            if (
                mb_stripos(
                    $candidateNormalized,
                    $brandNormalized
                ) !== false
            ) {

                return $brand;
            }
        }
    }

    return null;
}