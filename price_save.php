<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/commonfile.php';

global $db, $prefix, $dbname, $social_lists;


/*
|--------------------------------------------------------------------------
| Escape
|--------------------------------------------------------------------------
*/

function priceBotEscape($value)
{
    global $db;

    $value = (string)$value;

    if (
        is_object($db) &&
        method_exists($db, 'sql_escape_string')
    ) {
        return $db->sql_escape_string($value);
    }

    return addslashes($value);
}


/*
|--------------------------------------------------------------------------
| خطای SQL
|--------------------------------------------------------------------------
*/

function priceBotSqlError()
{
    global $db;

    if (
        is_object($db) &&
        method_exists($db, 'sql_error')
    ) {
        return $db->sql_error();
    }

    if (
        is_object($db) &&
        isset($db->sql_error)
    ) {
        return $db->sql_error;
    }

    return 'خطای نامشخص SQL';
}


/*
|--------------------------------------------------------------------------
| قیمت قبلی
|--------------------------------------------------------------------------
*/

function getPreviousProductPrice($brandId, $productId)
{
    global $db, $prefix;

    $brandId  = intval($brandId);
    $productId = intval($productId);

    $sql = "
        SELECT price
        FROM {$prefix}_prices2
        WHERE brand_id = '{$brandId}'
          AND product_id = '{$productId}'
        ORDER BY date DESC, id DESC
        LIMIT 1
    ";

    $result = $db->sql_query($sql);

    if (!$result) {
        return null;
    }

    $row = $db->sql_fetchrow($result);

    if (!$row) {
        return null;
    }

    return (int)$row['price'];
}


/*
|--------------------------------------------------------------------------
| Update Index امروز
|--------------------------------------------------------------------------
|
| هر بار که یک محصول در همان روز ثبت شود:
|
| بار اول = 1
| بار دوم = 2
| بار سوم = 3
| ...
|
*/

function getTodayUpdateIndex($brandId, $productId, $today)
{
    global $db, $prefix;

    $brandId  = intval($brandId);
    $productId = intval($productId);
    $today = priceBotEscape($today);

    $sql = "
        SELECT COALESCE(MAX(update_index), 0) AS max_index
        FROM {$prefix}_prices2
        WHERE brand_id = '{$brandId}'
          AND product_id = '{$productId}'
          AND date = '{$today}'
    ";

    $result = $db->sql_query($sql);

    if (!$result) {
        return 0;
    }

    $row = $db->sql_fetchrow($result);

    if (!$row) {
        return 0;
    }

    return intval($row['max_index']);
}


/*
|--------------------------------------------------------------------------
| دریافت تمام محصولات متصل به برند
|--------------------------------------------------------------------------
|
| این قسمت مهم‌ترین تغییر است.
|
| اگر مثلاً برند 11 محصول داشته باشد ولی Parser فقط
| 10 قیمت پیدا کند، محصول یازدهم نیز دریافت می‌شود
| و قیمت آن صفر خواهد شد.
|
*/

function getBrandProducts($brandId)
{
    global $db, $prefix;

    $brandId = intval($brandId);

    $sql = "
        SELECT
            p.cid,
            p.title,
            p.size,
            p.grade,
            p.parentid
        FROM {$prefix}_products p
        INNER JOIN {$prefix}_product_brands pb
            ON pb.product_id = p.cid
        WHERE pb.brand_id = '{$brandId}'
          AND p.active = '1'
        ORDER BY
            p.parentid ASC,
            CAST(p.size AS UNSIGNED) ASC,
            p.cid ASC
    ";

    $result = $db->sql_query($sql);

    if (!$result) {
        return array();
    }

    $products = array();

    while ($row = $db->sql_fetchrow($result)) {

        $products[] = array(
            'product_id' => intval($row['cid']),
            'title'      => $row['title'],
            'size'       => $row['size'],
            'grade'      => $row['grade'],
            'parentid'   => intval($row['parentid'])
        );
    }

    return $products;
}


/*
|--------------------------------------------------------------------------
| ثبت قیمت‌ها
|--------------------------------------------------------------------------
*/

function saveParsedPrices($brand, $items)
{
    global $db, $prefix;

    if (
        !is_array($brand) ||
        empty($brand['id'])
    ) {
        return array(
            'success' => false,
            'message' => 'برند نامعتبر است.'
        );
    }

    $brandId = intval($brand['id']);

    /*
    |--------------------------------------------------------------------------
    | محصولات Parser
    |--------------------------------------------------------------------------
    */

    if (!is_array($items)) {
        $items = array();
    }


    /*
    |--------------------------------------------------------------------------
    | تبدیل خروجی Parser به Map
    |--------------------------------------------------------------------------
    |
    | مثال:
    |
    | $parsedPrices[1] = 786950;
    | $parsedPrices[2] = 773950;
    |
    */

    $parsedPrices = array();

    $parsedItems = array();

    foreach ($items as $item) {

        if (
            !isset($item['product_id'])
        ) {
            continue;
        }

        $productId = intval($item['product_id']);

        if ($productId <= 0) {
            continue;
        }

        /*
        |--------------------------------------------------------------
        | قیمت
        |--------------------------------------------------------------
        */

        $price = 0;

        if (
            isset($item['price']) &&
            $item['price'] !== '' &&
            $item['price'] !== null
        ) {
            $price = intval($item['price']);
        }

        $parsedPrices[$productId] = $price;

        $parsedItems[$productId] = $item;
    }


    /*
    |--------------------------------------------------------------------------
    | تمام محصولات برند
    |--------------------------------------------------------------------------
    */

    $brandProducts = getBrandProducts($brandId);

    if (!count($brandProducts)) {

        return array(
            'success' => false,
            'message' => 'هیچ محصول فعالی برای این برند پیدا نشد.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | تاریخ
    |--------------------------------------------------------------------------
    */

    $today = date('Y-m-d');

    $detected = date('Y-m-d H:i:s');


    /*
    |--------------------------------------------------------------------------
    | خروجی
    |--------------------------------------------------------------------------
    */

    $saved = array();

    $zeroPrices = array();


    /*
    |--------------------------------------------------------------------------
    | Transaction
    |--------------------------------------------------------------------------
    */

    @$db->sql_query("START TRANSACTION");


    /*
    |--------------------------------------------------------------------------
    | ثبت تمام محصولات برند
    |--------------------------------------------------------------------------
    */

    foreach ($brandProducts as $product) {

        $productId = intval($product['product_id']);


        /*
        |--------------------------------------------------------------------------
        | اگر Parser قیمت پیدا کرده:
        |
        | قیمت واقعی
        |
        | اگر پیدا نکرده:
        |
        | صفر
        |--------------------------------------------------------------------------
        */

        if (array_key_exists($productId, $parsedPrices)) {

            $price = intval(
                $parsedPrices[$productId]
            );

            $isMissing = false;

        } else {

            $price = 0;

            $isMissing = true;

            $zeroPrices[] = $product;
        }


        /*
        |--------------------------------------------------------------------------
        | قیمت قبلی
        |--------------------------------------------------------------------------
        */

        $oldPrice = getPreviousProductPrice(
            $brandId,
            $productId
        );


        /*
        |--------------------------------------------------------------------------
        | Update Index
        |--------------------------------------------------------------------------
        */

        $lastIndex = getTodayUpdateIndex(
            $brandId,
            $productId,
            $today
        );

        $updateIndex = $lastIndex + 1;


        /*
        |--------------------------------------------------------------------------
        | Parser
        |--------------------------------------------------------------------------
        */

        $parserName = 'TelegramBot';


        /*
        |--------------------------------------------------------------------------
        | INSERT
        |--------------------------------------------------------------------------
        */

        $sql = "
            INSERT INTO {$prefix}_prices2
            (
                brand_id,
                product_id,
                price,
                date,
                detected,
                update_index,
                parser
            )
            VALUES
            (
                '{$brandId}',
                '{$productId}',
                '{$price}',
                '" . priceBotEscape($today) . "',
                '" . priceBotEscape($detected) . "',
                '{$updateIndex}',
                '" . priceBotEscape($parserName) . "'
            )
        ";


        $result = $db->sql_query($sql);


        /*
        |--------------------------------------------------------------------------
        | خطا
        |--------------------------------------------------------------------------
        */

        if (!$result) {

            @$db->sql_query("ROLLBACK");

            return array(
                'success' => false,
                'message' =>
                    'خطا هنگام ثبت قیمت محصول ' .
                    $productId .
                    ': ' .
                    priceBotSqlError()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | محاسبه اختلاف
        |--------------------------------------------------------------------------
        */

        $diff = null;

        if ($oldPrice !== null) {

            $diff = $price - $oldPrice;
        }


        /*
        |--------------------------------------------------------------------------
        | اطلاعات Parser
        |--------------------------------------------------------------------------
        */

        $parserItem = null;

        if (isset($parsedItems[$productId])) {

            $parserItem =
                $parsedItems[$productId];
        }


        /*
        |--------------------------------------------------------------------------
        | خروجی
        |--------------------------------------------------------------------------
        */

        $saved[] = array(

            'product_id' =>
                $productId,

            'title' =>
                $product['title'],

            'size' =>
                $product['size'],

            'grade' =>
                $product['grade'],

            'price' =>
                $price,

            'old_price' =>
                $oldPrice,

            'diff' =>
                $diff,

            'update_index' =>
                $updateIndex,

            /*
            | مشخص می‌کند قیمت توسط Parser پیدا نشده
            */

            'missing' =>
                $isMissing
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    @$db->sql_query("COMMIT");


    /*
    |--------------------------------------------------------------------------
    | نتیجه
    |--------------------------------------------------------------------------
    */

    return array(

        'success' =>
            true,

        /*
        | تعداد کل محصولات ثبت‌شده
        */

        'count' =>
            count($saved),

        /*
        | تعداد قیمت‌های واقعی پیدا شده
        */

        'parsed_count' =>
            count($parsedItems),

        /*
        | تعداد محصولاتی که صفر ثبت شدند
        */

        'zero_count' =>
            count($zeroPrices),

        /*
        | محصولات
        */

        'items' =>
            $saved,

        /*
        | تاریخ
        */

        'date' =>
            $today
    );
}


/*
|--------------------------------------------------------------------------
| فرمت قیمت
|--------------------------------------------------------------------------
*/

function formatBotPrice($price)
{
    return number_format(
        intval($price),
        0,
        '.',
        ','
    );
}


/*
|--------------------------------------------------------------------------
| نمایش تغییر
|--------------------------------------------------------------------------
*/

function formatBotDiff($diff)
{
    if ($diff === null) {

        return '—';
    }

    $diff = intval($diff);


    if ($diff > 0) {

        return '🔺 +' .
            formatBotPrice($diff);
    }


    if ($diff < 0) {

        return '🔻 ' .
            formatBotPrice(abs($diff));
    }


    return '➖ 0';
}