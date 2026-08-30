<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/commonfile.php';
require_once __DIR__ . '/PriceParser.php';
require_once __DIR__ . '/price_save.php';
require_once __DIR__ . '/OCR.php';

global $db, $prefix, $dbname, $social_lists;


/*
|--------------------------------------------------------------------------
| Telegram API
|--------------------------------------------------------------------------
*/

function telegramApi($method, $params = array())
{
    $url =
        rtrim(
            TELEGRAM_API_BASE,
            '/'
        ) .
        '/bot' .
        BOT_TOKEN .
        '/' .
        $method;

    $ch =
        curl_init($url);

    curl_setopt_array(
        $ch,
        array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true
        )
    );

    $response =
        curl_exec($ch);

    $error =
        curl_error($ch);

    curl_close($ch);

    if ($response === false) {

        return array(
            'ok' => false,
            'description' =>
                'Telegram CURL Error: ' .
                $error
        );
    }

    $json =
        json_decode(
            $response,
            true
        );

    if (!is_array($json)) {

        return array(
            'ok' => false,
            'description' =>
                'Telegram response invalid'
        );
    }

    return $json;
}


/*
|--------------------------------------------------------------------------
| Send Message
|--------------------------------------------------------------------------
*/

function telegramSendMessage(
    $chatId,
    $text,
    $keyboard = null
) {

    $params = array(
        'chat_id' => $chatId,
        'text' => $text
    );

    if ($keyboard !== null) {

        $params['reply_markup'] =
            json_encode(
                $keyboard,
                JSON_UNESCAPED_UNICODE
            );
    }

    return telegramApi(
        'sendMessage',
        $params
    );
}


/*
|--------------------------------------------------------------------------
| Answer Callback
|--------------------------------------------------------------------------
*/

function telegramAnswerCallback(
    $callbackId,
    $text = ''
) {

    return telegramApi(
        'answerCallbackQuery',
        array(
            'callback_query_id' =>
                $callbackId,
            'text' => $text,
            'show_alert' => false
        )
    );
}


/*
|--------------------------------------------------------------------------
| حذف پیام
|--------------------------------------------------------------------------
*/

function telegramDeleteMessage(
    $chatId,
    $messageId
) {

    return telegramApi(
        'deleteMessage',
        array(
            'chat_id' =>
                $chatId,
            'message_id' =>
                $messageId
        )
    );
}


/*
|--------------------------------------------------------------------------
| دریافت فایل تلگرام
|--------------------------------------------------------------------------
*/

function telegramDownloadFile(
    $filePath
) {

    $url =
        rtrim(
            TELEGRAM_API_BASE,
            '/'
        ) .
        '/file/bot' .
        BOT_TOKEN .
        '/' .
        ltrim(
            $filePath,
            '/'
        );

    $ch =
        curl_init($url);

    curl_setopt_array(
        $ch,
        array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        )
    );

    $data =
        curl_exec($ch);

    $error =
        curl_error($ch);

    curl_close($ch);

    if ($data === false) {
        return array(
            'success' => false,
            'data' => '',
            'error' => $error
        );
    }

    return array(
        'success' => true,
        'data' => $data,
        'error' => ''
    );
}


/*
|--------------------------------------------------------------------------
| دریافت تصویر Telegram
|--------------------------------------------------------------------------
*/

function getTelegramImage(
    $message
) {

    $fileId = '';

    $mime = 'image/jpeg';

    /*
     * Photo
     */
    if (
        isset($message['photo']) &&
        is_array($message['photo']) &&
        count($message['photo'])
    ) {

        $photos =
            $message['photo'];

        $last =
            end($photos);

        if (
            isset($last['file_id'])
        ) {
            $fileId =
                $last['file_id'];
        }
    }

    /*
     * Image Document
     */
    elseif (
        isset($message['document']) &&
        isset($message['document']['mime_type']) &&
        strpos(
            $message['document']['mime_type'],
            'image/'
        ) === 0
    ) {

        $fileId =
            $message['document']['file_id'];

        $mime =
            $message['document']['mime_type'];
    }

    if ($fileId === '') {

        return array(
            'success' => false,
            'data' => '',
            'mime' => '',
            'error' => 'تصویری وجود ندارد.'
        );
    }

    $fileInfo =
        telegramApi(
            'getFile',
            array(
                'file_id' =>
                    $fileId
            )
        );

    if (
        !isset($fileInfo['ok']) ||
        !$fileInfo['ok'] ||
        !isset($fileInfo['result']['file_path'])
    ) {

        return array(
            'success' => false,
            'data' => '',
            'mime' => $mime,
            'error' =>
                isset($fileInfo['description'])
                    ? $fileInfo['description']
                    : 'دریافت مسیر تصویر ناموفق بود.'
        );
    }

    return telegramDownloadFile(
        $fileInfo['result']['file_path']
    ) + array(
        'mime' => $mime
    );
}


/*
|--------------------------------------------------------------------------
| نرمال سازی برند
|--------------------------------------------------------------------------
*/

function normalizeBrandText($text)
{
    $text =
        str_replace(
            array(
                '_',
                '-',
                '‌'
            ),
            ' ',
            $text
        );

    $text =
        preg_replace(
            '/\s+/u',
            ' ',
            $text
        );

    return trim($text);
}


/*
|--------------------------------------------------------------------------
| تشخیص برند
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| تشخیص برند
|--------------------------------------------------------------------------
*/

function detectBrand($text)
{
    global $db, $prefix;

    $cleanText =
        normalizeBrandText($text);

    $result =
        $db->sql_query("
            SELECT *
            FROM {$prefix}_brands
            WHERE active='1'
            ORDER BY id ASC
        ");

    $bestBrand = null;
    $bestScore = 0;

    while (
        $brand =
        $db->sql_fetchrow($result)
    ) {

        $title =
            normalizeBrandText(
                $brand['title']
            );

        $slug =
            normalizeBrandText(
                isset($brand['slug'])
                    ? $brand['slug']
                    : ''
            );

        $score = 0;

        /*
        |--------------------------------------------------------------------------
        | قالب Parser
        |--------------------------------------------------------------------------
        */

        $template = array();

        if (
            isset($brand['price_parser_template']) &&
            trim($brand['price_parser_template']) !== ''
        ) {

            $decoded =
                json_decode(
                    $brand['price_parser_template'],
                    true
                );

            if (is_array($decoded)) {
                $template = $decoded;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | KEYWORDS اختصاصی برند
        |--------------------------------------------------------------------------
        |
        | مثال:
        | "keywords": [
        |     "SFK",
        |     "SFKsteels",
        |     "فولاد صائب"
        | ]
        |
        */

        if (
            isset($template['keywords']) &&
            is_array($template['keywords'])
        ) {

            foreach (
                $template['keywords']
                as $keyword
            ) {

                $keyword =
                    trim(
                        (string)$keyword
                    );

                if ($keyword === '') {
                    continue;
                }

                $normalizedKeyword =
                    normalizeBrandText(
                        $keyword
                    );

                /*
                |------------------------------------------------------------------
                | تطبیق دقیق‌تر برای کیورد
                |------------------------------------------------------------------
                */

                if (
                    mb_stripos(
                        $cleanText,
                        $normalizedKeyword,
                        0,
                        'UTF-8'
                    ) !== false
                ) {

                    /*
                     * کیورد اختصاصی امتیاز بالایی دارد.
                     */
                    $score += 120;

                    /*
                     * کیوردهای طولانی‌تر قابل اعتمادتر هستند.
                     */
                    $length =
                        mb_strlen(
                            $normalizedKeyword,
                            'UTF-8'
                        );

                    if ($length >= 8) {
                        $score += 30;
                    }

                    elseif ($length >= 5) {
                        $score += 15;
                    }

                    /*
                     * یک تطبیق کافی است؛
                     * ولی اگر چند کیورد وجود داشت
                     * می‌توان امتیاز بیشتری گرفت.
                     */
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | نام کامل برند
        |--------------------------------------------------------------------------
        */

        if (
            $title !== '' &&
            mb_stripos(
                $cleanText,
                $title,
                0,
                'UTF-8'
            ) !== false
        ) {

            $score += 100;
        }


        /*
        |--------------------------------------------------------------------------
        | هشتگ برند
        |--------------------------------------------------------------------------
        */

        if ($title !== '') {

            $hashtag =
                '#' .
                str_replace(
                    ' ',
                    '_',
                    $title
                );

            if (
                mb_stripos(
                    $text,
                    $hashtag,
                    0,
                    'UTF-8'
                ) !== false
            ) {

                $score += 150;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Slug
        |--------------------------------------------------------------------------
        */

        if (
            $slug !== '' &&
            stripos(
                $text,
                $slug
            ) !== false
        ) {

            $score += 70;
        }


        /*
        |--------------------------------------------------------------------------
        | بخش‌های نام برند
        |--------------------------------------------------------------------------
        */

        if ($title !== '') {

            $parts =
                preg_split(
                    '/\s+/u',
                    $title
                );

            foreach (
                $parts
                as $part
            ) {

                if (
                    mb_strlen(
                        $part,
                        'UTF-8'
                    ) < 3
                ) {
                    continue;
                }

                if (
                    mb_stripos(
                        $cleanText,
                        $part,
                        0,
                        'UTF-8'
                    ) !== false
                ) {

                    $score += 5;
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | بهترین برند
        |--------------------------------------------------------------------------
        */

        if (
            $score > $bestScore
        ) {

            $bestScore =
                $score;

            $bestBrand =
                $brand;
        }
    }

    return $bestBrand;
}

/*
|--------------------------------------------------------------------------
| ساخت متن پیش نمایش
|--------------------------------------------------------------------------
*/

function buildPreview(
    $brand,
    $parsed
) {

    $text =
        "🏭 " .
        $brand['title'] .
        "\n\n";

    $text .=
        "📋 قیمت‌های تشخیص داده شده\n\n";

    foreach (
        $parsed['items']
        as $item
    ) {

        $text .=
            "• " .
            $item['title'] .
            " ← " .
            formatBotPrice(
                $item['price']
            ) .
            " تومان\n";
    }

    $text .=
        "\n━━━━━━━━━━━━━━\n";

    $text .=
        "تعداد محصولات: " .
        count($parsed['items']) .
        "\n";

    $text .=
        "واحد نهایی: تومان\n";

    $template =
        json_decode(
            $brand['price_parser_template'],
            true
        );

    if (
        is_array($template) &&
        isset($template['vat'])
    ) {

        if (
            strtolower(
                $template['vat']
            ) === 'without'
        ) {

            $vat =
                isset(
                    $template['vat_percent']
                )
                ? $template['vat_percent']
                : DEFAULT_VAT_PERCENT;

            $text .=
                "ارزش افزوده: " .
                $vat .
                "% محاسبه شده\n";
        } else {
            $text .=
                "ارزش افزوده: شامل شده\n";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | موارد بدون تطبیق
    |--------------------------------------------------------------------------
    */

    if (
        isset($parsed['unmatched']) &&
        count($parsed['unmatched'])
    ) {

        $text .=
            "\n⚠️ مواردی که تطبیق داده نشد:\n";

        foreach (
            $parsed['unmatched']
            as $unmatched
        ) {

            $text .=
                "• " .
                $unmatched['line'] .
                "\n";

            $text .=
                "  علت: " .
                $unmatched['reason'] .
                "\n";
        }
    }

    return $text;
}


/*
|--------------------------------------------------------------------------
| ذخیره تأیید موقت
|--------------------------------------------------------------------------
*/

function saveConfirmation(
    $userId,
    $brand,
    $parsed
) {

    if (
        !is_dir(CONFIRMATION_DIR)
    ) {

        @mkdir(
            CONFIRMATION_DIR,
            0755,
            true
        );
    }

    if (
        !is_dir(CONFIRMATION_DIR) ||
        !is_writable(CONFIRMATION_DIR)
    ) {

        return false;
    }

    try {

        $token =
            bin2hex(
                random_bytes(16)
            );

    } catch (Exception $e) {

        $token =
            md5(
                uniqid(
                    '',
                    true
                )
            );
    }

    $data = array(
        'user_id' =>
            intval($userId),

        'brand_id' =>
            intval($brand['id']),

        'brand' => array(
            'id' =>
                intval($brand['id']),

            'title' =>
                $brand['title'],

            'price_parser_template' =>
                $brand['price_parser_template']
        ),

        'items' =>
            $parsed['items'],

        'created_at' =>
            time()
    );

    $file =
        CONFIRMATION_DIR .
        '/' .
        $token .
        '.json';

    $ok =
        file_put_contents(
            $file,
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ),
            LOCK_EX
        );

    if ($ok === false) {
        return false;
    }

    return $token;
}


/*
|--------------------------------------------------------------------------
| خواندن تأیید
|--------------------------------------------------------------------------
*/

function readConfirmation($token)
{
    if (
        !preg_match(
            '/^[a-f0-9]{32}$/',
            $token
        )
    ) {
        return false;
    }

    $file =
        CONFIRMATION_DIR .
        '/' .
        $token .
        '.json';

    if (!is_file($file)) {
        return false;
    }

    /*
     * اعتبار 30 دقیقه
     */
    if (
        filemtime($file) <
        time() - 1800
    ) {

        @unlink($file);

        return false;
    }

    $content =
        file_get_contents($file);

    if (!$content) {
        return false;
    }

    $data =
        json_decode(
            $content,
            true
        );

    return is_array($data)
        ? $data
        : false;
}


/*
|--------------------------------------------------------------------------
| پاک کردن فایل‌های قدیمی
|--------------------------------------------------------------------------
*/

function cleanOldConfirmations()
{
    if (
        !is_dir(CONFIRMATION_DIR)
    ) {
        return;
    }

    $files =
        glob(
            CONFIRMATION_DIR .
            '/*.json'
        );

    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {

        if (
            is_file($file) &&
            filemtime($file) <
            time() - 3600
        ) {
            @unlink($file);
        }
    }
}


/*
|--------------------------------------------------------------------------
| نمایش نتیجه ثبت
|--------------------------------------------------------------------------
*/

function buildSaveResultMessage(
    $brand,
    $saveResult
) {

    $text =
        "✅ قیمت‌ها با موفقیت ثبت شدند.\n\n";

    $text .=
        "🏭 " .
        $brand['title'] .
        "\n";

    $text .=
        "📅 " .
        date('Y-m-d') .
        "\n\n";

    foreach (
        $saveResult['items']
        as $item
    ) {

        $text .=
            "• " .
            $item['title'] .
            "\n";

        $text .=
            "  " .
            formatBotPrice(
                $item['price']
            ) .
            " تومان";

        if (
            $item['diff'] !== null
        ) {

            $text .=
                "  " .
                formatBotDiff(
                    $item['diff']
                );
        }

        $text .=
            "\n";

        $text .=
            "  Update Index: " .
            $item['update_index'] .
            "\n";
    }

    return $text;
}


/*
|--------------------------------------------------------------------------
| پردازش Callback
|--------------------------------------------------------------------------
*/

function handleCallback($callback)
{
    $callbackId =
        $callback['id'];

    $data =
        isset($callback['data'])
            ? $callback['data']
            : '';

    $fromId =
        intval(
            $callback['from']['id']
        );

    if (
        strpos(
            $data,
            'ps_ok_'
        ) === 0
    ) {

        $token =
            substr(
                $data,
                6
            );

        $confirmation =
            readConfirmation(
                $token
            );

        if (
            !$confirmation
        ) {

            telegramAnswerCallback(
                $callbackId,
                'این تأیید منقضی شده است.'
            );

            return;
        }

        if (
            intval(
                $confirmation['user_id']
            ) !== $fromId
        ) {

            telegramAnswerCallback(
                $callbackId,
                'دسترسی غیرمجاز.'
            );

            return;
        }

        telegramAnswerCallback(
            $callbackId,
            'در حال ثبت قیمت‌ها...'
        );

        $saveResult =
            saveParsedPrices(
                $confirmation['brand'],
                $confirmation['items']
            );

        @unlink(
            CONFIRMATION_DIR .
            '/' .
            $token .
            '.json'
        );

        if (
            !$saveResult['success']
        ) {

            telegramSendMessage(
                $fromId,
                "❌ " .
                $saveResult['message']
            );

            return;
        }

        telegramSendMessage(
            $fromId,
            buildSaveResultMessage(
                $confirmation['brand'],
                $saveResult
            )
        );

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Cancel
    |--------------------------------------------------------------------------
    */

    if (
        strpos(
            $data,
            'ps_no_'
        ) === 0
    ) {

        $token =
            substr(
                $data,
                6
            );

        $confirmation =
            readConfirmation(
                $token
            );

        if (
            $confirmation &&
            intval(
                $confirmation['user_id']
            ) === $fromId
        ) {

            @unlink(
                CONFIRMATION_DIR .
                '/' .
                $token .
                '.json'
            );
        }

        telegramAnswerCallback(
            $callbackId,
            'لغو شد.'
        );

        telegramSendMessage(
            $fromId,
            "❌ ثبت قیمت لغو شد."
        );

        return;
    }
}


/*
|--------------------------------------------------------------------------
| شروع
|--------------------------------------------------------------------------
*/

cleanOldConfirmations();

$raw =
    file_get_contents(
        'php://input'
    );

if (
    !$raw
) {
    echo 'OK';
    exit;
}

$update =
    json_decode(
        $raw,
        true
    );

if (
    !is_array($update)
) {
    echo 'OK';
    exit;
}


/*
|--------------------------------------------------------------------------
| Callback
|--------------------------------------------------------------------------
*/

if (
    isset($update['callback_query'])
) {

    handleCallback(
        $update['callback_query']
    );

    echo 'OK';
    exit;
}


/*
|--------------------------------------------------------------------------
| Message
|--------------------------------------------------------------------------
*/

if (
    !isset($update['message'])
) {
    echo 'OK';
    exit;
}

$message =
    $update['message'];

$chatId =
    isset($message['chat']['id'])
        ? $message['chat']['id']
        : 0;

$userId =
    isset($message['from']['id'])
        ? $message['from']['id']
        : $chatId;

if (!$chatId) {
    echo 'OK';
    exit;
}


/*
|--------------------------------------------------------------------------
| متن
|--------------------------------------------------------------------------
*/

$text = '';

if (
    isset($message['text']) &&
    trim($message['text']) !== ''
) {

    $text =
        trim($message['text']);
}


/*
|--------------------------------------------------------------------------
| Caption تصویر
|--------------------------------------------------------------------------
*/

if (
    isset($message['caption']) &&
    trim($message['caption']) !== ''
) {

    if ($text !== '') {
        $text .= "\n";
    }

    $text .=
        trim($message['caption']);
}


/*
|--------------------------------------------------------------------------
| اگر تصویر است OCR
|--------------------------------------------------------------------------
|
| نکته:
| اگر پیام فقط متن باشد این قسمت اصلاً اجرا نمی‌شود.
|
*/

$hasImage =
    isset($message['photo']) ||
    (
        isset($message['document']['mime_type']) &&
        strpos(
            $message['document']['mime_type'],
            'image/'
        ) === 0
    );

if ($hasImage) {

    $image =
        getTelegramImage(
            $message
        );

    if (
        !$image['success']
    ) {

        telegramSendMessage(
            $chatId,
            "❌ خطا در دریافت تصویر:\n" .
            $image['error']
        );

        echo 'OK';
        exit;
    }

    $ocr =
        runPersianOCR(
            $image['data'],
            isset($image['mime'])
                ? $image['mime']
                : 'image/jpeg'
        );

    if (
        !$ocr['success']
    ) {

        telegramSendMessage(
            $chatId,
            "❌ خطا در پردازش تصویر:\n" .
            $ocr['error']
        );

        echo 'OK';
        exit;
    }

    if ($text !== '') {
        $text .= "\n";
    }

    $text .=
        $ocr['text'];
}


/*
|--------------------------------------------------------------------------
| متن نهایی
|--------------------------------------------------------------------------
*/

$text =
    trim($text);

if ($text === '') {

    telegramSendMessage(
        $chatId,
        "⚠️ متن یا تصویر لیست قیمت ارسال کن."
    );

    echo 'OK';
    exit;
}


/*
|--------------------------------------------------------------------------
| تشخیص برند
|--------------------------------------------------------------------------
*/

$brand =
    detectBrand($text);

if (!$brand) {

    telegramSendMessage(
        $chatId,
        "❌ نام شرکت/کارخانه از لیست قیمت تشخیص داده نشد.\n\n" .
        "نام برند یا هشتگ برند را داخل پیام قرار بده."
    );

    echo 'OK';
    exit;
}


/*
|--------------------------------------------------------------------------
| بررسی قالب
|--------------------------------------------------------------------------
*/

$template =
    json_decode(
        $brand['price_parser_template'],
        true
    );

if (
    !is_array($template)
) {

    telegramSendMessage(
        $chatId,
        "❌ قالب Parser برای برند «" .
        $brand['title'] .
        "» معتبر نیست."
    );

    echo 'OK';
    exit;
}


/*
|--------------------------------------------------------------------------
| بررسی واحد و VAT
|--------------------------------------------------------------------------
*/

if (
    !isset($template['price_unit']) ||
    !in_array(
        strtolower(
            $template['price_unit']
        ),
        array(
            'rial',
            'toman',
            'ریال',
            'تومان'
        )
    )
) {

    telegramSendMessage(
        $chatId,
        "❌ واحد قیمت برای برند «" .
        $brand['title'] .
        "» در قالب مشخص نشده است.\n\n" .
        "مثال:\n" .
        "\"price_unit\":\"toman\""
    );

    echo 'OK';
    exit;
}

if (
    !isset($template['vat'])
) {

    telegramSendMessage(
        $chatId,
        "❌ وضعیت ارزش افزوده برای برند «" .
        $brand['title'] .
        "» در قالب مشخص نشده است.\n\n" .
        "مثال:\n" .
        "\"vat\":\"without\""
    );

    echo 'OK';
    exit;
}


/*
|--------------------------------------------------------------------------
| Parser
|--------------------------------------------------------------------------
*/

$parser =
    new PriceParser(
        $db,
        $prefix,
        $brand
    );

$parsed =
    $parser->parse($text);


/*
|--------------------------------------------------------------------------
| هیچ محصول
|--------------------------------------------------------------------------
*/

if (
    !isset($parsed['items']) ||
    !count($parsed['items'])
) {

    $error =
        "❌ هیچ محصولی با محصولات متصل به «" .
        $brand['title'] .
        "» تطبیق داده نشد.";

    if (
        isset($parsed['unmatched']) &&
        count($parsed['unmatched'])
    ) {

        $error .=
            "\n\nموارد تشخیص داده شده:";

        foreach (
            array_slice(
                $parsed['unmatched'],
                0,
                10
            )
            as $u
        ) {

            $error .=
                "\n• " .
                $u['line'] .
                "\n  " .
                $u['reason'];
        }
    }

    telegramSendMessage(
        $chatId,
        $error
    );

    echo 'OK';
    exit;
}


/*
|--------------------------------------------------------------------------
| Preview
|--------------------------------------------------------------------------
*/

$preview =
    buildPreview(
        $brand,
        $parsed
    );


/*
|--------------------------------------------------------------------------
| ذخیره موقت جهت تأیید
|--------------------------------------------------------------------------
*/

$token =
    saveConfirmation(
        $userId,
        $brand,
        $parsed
    );

if (!$token) {

    telegramSendMessage(
        $chatId,
        "❌ امکان ایجاد اطلاعات تأیید موقت وجود ندارد.\n" .
        "دسترسی نوشتن پوشه bot_data/confirmations را بررسی کن."
    );

    echo 'OK';
    exit;
}


/*
|--------------------------------------------------------------------------
| دکمه‌ها
|--------------------------------------------------------------------------
*/

$keyboard = array(
    'inline_keyboard' => array(
        array(
            array(
                'text' =>
                    '✅ تأیید و ثبت در سایت',
                'callback_data' =>
                    'ps_ok_' . $token
            )
        ),
        array(
            array(
                'text' =>
                    '❌ لغو',
                'callback_data' =>
                    'ps_no_' . $token
            )
        )
    )
);


/*
|--------------------------------------------------------------------------
| ارسال Preview
|--------------------------------------------------------------------------
*/

telegramSendMessage(
    $chatId,
    $preview .
    "\n\n⚠️ قبل از ثبت اطلاعات را بررسی کن.",
    $keyboard
);

echo 'OK';
exit;