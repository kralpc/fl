<?php

require_once __DIR__ . '/config.php';

class PriceParser
{
    private $db;
    private $prefix;

    private $brand;
    private $template;
    private $products = array();

    public function __construct($db, $prefix, $brand)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->brand = $brand;

        $template = isset($brand['price_parser_template'])
            ? trim($brand['price_parser_template'])
            : '';

        if ($template !== '') {
            $decoded = json_decode($template, true);

            if (is_array($decoded)) {
                $this->template = $decoded;
            } else {
                $this->template = array();
            }
        } else {
            $this->template = array();
        }

        $this->loadProducts();
    }

    /*
    |--------------------------------------------------------------------------
    | محصولات متصل به برند
    |--------------------------------------------------------------------------
    */
    private function loadProducts()
    {
        $brandId = intval($this->brand['id']);

        $sql = "
            SELECT
                p.*,
                c.title AS category_title
            FROM {$this->prefix}_product_brands pb
            INNER JOIN {$this->prefix}_products p
                ON p.cid = pb.product_id
            LEFT JOIN {$this->prefix}_categories c
                ON c.cid = p.parentid
            WHERE pb.brand_id = '{$brandId}'
              AND p.active = '1'
            ORDER BY p.cid ASC
        ";

        $result = $this->db->sql_query($sql);

        while ($row = $this->db->sql_fetchrow($result)) {
            $row['_attributes'] = $this->productAttributes($row);
            $this->products[] = $row;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | تبدیل اعداد فارسی و عربی
    |--------------------------------------------------------------------------
    */
    private function normalizeNumbers($text)
    {
        $from = array(
            '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹',
            '٠','١','٢','٣','٤','٥','٦','٧','٨','٩'
        );

        $to = array(
            '0','1','2','3','4','5','6','7','8','9',
            '0','1','2','3','4','5','6','7','8','9'
        );

        return str_replace($from, $to, $text);
    }

    /*
    |--------------------------------------------------------------------------
    | نرمال سازی متن
    |--------------------------------------------------------------------------
    */
    private function normalizeText($text)
    {
        $text = $this->normalizeNumbers($text);

        $text = str_replace(
            array('ي', 'ى', 'ئ'),
            array('ی', 'ی', 'ی'),
            $text
        );

        $text = str_replace(
            array('ك'),
            array('ک'),
            $text
        );

        $text = str_replace(
            array('×', '✕', '✖'),
            'x',
            $text
        );

        $text = str_replace(
            array('٬', '،'),
            ',',
            $text
        );

        $text = str_replace(
            array("\xC2\xA0", "\xE2\x80\x8C"),
            ' ',
            $text
        );

        $text = preg_replace('/[ \t]+/u', ' ', $text);

        return trim($text);
    }

    /*
    |--------------------------------------------------------------------------
    | حذف URL
    |--------------------------------------------------------------------------
    */
    private function removeUrls($text)
    {
        return preg_replace(
            '~https?://[^\s]+~iu',
            ' ',
            $text
        );
    }

    /*
    |--------------------------------------------------------------------------
    | اعداد کلمه ای طول
    |--------------------------------------------------------------------------
    */
    private function wordNumber($word)
    {
        $map = array(
            'یک' => 1,
            'دو' => 2,
            'سه' => 3,
            'چهار' => 4,
            'پنج' => 5,
            'شش' => 6,
            'هفت' => 7,
            'هشت' => 8,
            'نه' => 9,
            'ده' => 10,
            'یازده' => 11,
            'دوازده' => 12,
            'سیزده' => 13,
            'چهارده' => 14,
            'پانزده' => 15,
            'شانزده' => 16,
            'هفده' => 17,
            'هجده' => 18,
            'نوزده' => 19,
            'بیست' => 20
        );

        return isset($map[$word])
            ? $map[$word]
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | استخراج مشخصات محصول
    |--------------------------------------------------------------------------
    */
    private function extractAttributes($text, $dbSize = '', $dbGrade = '')
    {
        $text = $this->normalizeText($text);

        $attributes = array(
            'size'       => null,
            'sizes'      => array(),
            'range_from' => null,
            'range_to'   => null,
            'grade'      => null,
            'type' => null,
            'profile'    => null,
            'dimensions' => null,
            'length'     => null,
            'thickness'  => null
        );

        /*
        |--------------------------------------------------------------------------
        | ابعاد
        |--------------------------------------------------------------------------
        */

        if (preg_match(
            '/(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)/iu',
            $text,
            $m
        )) {
            $attributes['dimensions'] =
                $m[1] . 'x' . $m[2];
        }

        /*
        |--------------------------------------------------------------------------
        | ضخامت
        |--------------------------------------------------------------------------
        */

        if (preg_match(
            '/ضخامت\s*[:：]?\s*(\d+(?:\.\d+)?)/iu',
            $text,
            $m
        )) {
            $attributes['thickness'] = $m[1];
        }

        /*
        |--------------------------------------------------------------------------
        | طول عددی
        |--------------------------------------------------------------------------
        */

        if (preg_match(
            '/(\d+(?:\.\d+)?)\s*متری/iu',
            $text,
            $m
        )) {
            $attributes['length'] = $m[1];
        }

        /*
        |--------------------------------------------------------------------------
        | طول کلمه ای
        |--------------------------------------------------------------------------
        */

        if ($attributes['length'] === null) {

            if (preg_match(
                '/(یک|دو|سه|چهار|پنج|شش|هفت|هشت|نه|ده|یازده|دوازده|سیزده|چهارده|پانزده|شانزده|هفده|هجده|نوزده|بیست)\s*متری/iu',
                $text,
                $m
            )) {
                $wordNumber = $this->wordNumber($m[1]);

                if ($wordNumber !== null) {
                    $attributes['length'] = (string)$wordNumber;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | پروفیل
        |--------------------------------------------------------------------------
        */

        if (preg_match(
            '/\b(IPE|IPN|HEA|HEB|UPN|UPE)\b/iu',
            $text,
            $m
        )) {
            $attributes['profile'] =
                strtoupper($m[1]);
        }

        /*
        |--------------------------------------------------------------------------
        | گرید
        |--------------------------------------------------------------------------
        */

       /*
|--------------------------------------------------------------------------
| گرید
|--------------------------------------------------------------------------
|
| پشتیبانی:
| A1
| A2
| A3
| A4
| گرید A1
| گرید A2
| گرید A3
| گرید A4
|
*/

if (preg_match(
    '/(?:گرید\s*)?(A[1-4])(?=\s|$|:|：|\)|\()/iu',
    $text,
    $m
)) {

    $attributes['grade'] =
        strtoupper(
            trim($m[1])
        );
}
/*
|--------------------------------------------------------------------------
| نوع میلگرد
|--------------------------------------------------------------------------
| میلگرد ساده
| میلگرد آجدار
*/

if (
    preg_match(
        '/میلگرد\s*\(?\s*(ساده|آجدار)\s*\)?/iu',
        $text,
        $m
    )
) {
    $attributes['type'] =
        trim($m[1]);
}

/*
|--------------------------------------------------------------------------
| گرید پیش فرض برند
|--------------------------------------------------------------------------
*/

if (
    $attributes['grade'] === null &&
    isset($this->template['default_grade']) &&
    $this->template['default_grade'] !== ''
) {
    $attributes['grade'] =
        strtoupper(
            trim(
                $this->template['default_grade']
            )
        );
}
        /*
        |--------------------------------------------------------------------------
        | سایز با عبارت "سایز"
        |--------------------------------------------------------------------------
        */

        if (preg_match(
            '/سایز\s*([0-9]+(?:\s*(?:تا|الی|-|_|و|,)\s*[0-9]+)*)/iu',
            $text,
            $m
        )) {

            $sizeExpression = trim($m[1]);

            preg_match_all(
                '/\d+(?:\.\d+)?/',
                $sizeExpression,
                $numbers
            );

            $numbers = isset($numbers[0])
                ? $numbers[0]
                : array();

            if (
                preg_match('/(تا|الی|-|_|و)/iu', $sizeExpression) &&
                count($numbers) >= 2
            ) {

                $attributes['range_from'] = $numbers[0];
                $attributes['range_to']   = $numbers[1];

            } else {

                foreach ($numbers as $number) {
                    $attributes['sizes'][] = $number;
                }
            }
        }
        
        /*
|--------------------------------------------------------------------------
| سایز بعد از کلمه میلگرد
|--------------------------------------------------------------------------
|
| مثال:
| میلگرد 14
| میلگرد 16
| میلگرد 52
|
*/

if (
    empty($attributes['sizes']) &&
    $attributes['size'] === null &&
    $attributes['range_from'] === null
) {

    if (
        preg_match(
            '/میلگرد\s*(\d+(?:\.\d+)?)/iu',
            $text,
            $m
        )
    ) {

        $attributes['sizes'][] =
            $m[1];
    }
}

        /*
        |--------------------------------------------------------------------------
        | اگر ابعاد داریم، آن را size هم در نظر بگیر
        |--------------------------------------------------------------------------
        */

        if (
            empty($attributes['sizes']) &&
            $attributes['dimensions'] !== null
        ) {
            $attributes['size'] =
                $attributes['dimensions'];
        }

        /*
        |--------------------------------------------------------------------------
        | سایز از دیتابیس محصول
        |--------------------------------------------------------------------------
        */

        if (
            empty($attributes['sizes']) &&
            $attributes['size'] === null &&
            $dbSize !== ''
        ) {
            $attributes['size'] =
                trim($dbSize);
        }

        /*
        |--------------------------------------------------------------------------
        | گرید دیتابیس
        |--------------------------------------------------------------------------
        */

        if (
            $attributes['grade'] === null &&
            $dbGrade !== ''
        ) {
            $attributes['grade'] =
                strtoupper(trim($dbGrade));
        }

        return $attributes;
    }

    /*
    |--------------------------------------------------------------------------
    | مشخصات محصول دیتابیس
    |--------------------------------------------------------------------------
    */
    private function productAttributes($product)
    {
        return $this->extractAttributes(
            $product['title'],
            isset($product['size']) ? $product['size'] : '',
            isset($product['grade']) ? $product['grade'] : ''
        );
    }

    /*
    |--------------------------------------------------------------------------
    | کلمات غیرمرتبط
    |--------------------------------------------------------------------------
    */
    private function isIgnoredLine($line)
    {
        $keywords = array();

        if (
            isset($this->template['ignore_keywords']) &&
            is_array($this->template['ignore_keywords'])
        ) {
            $keywords = $this->template['ignore_keywords'];
        }

        foreach ($keywords as $keyword) {
            if (
                $keyword !== '' &&
                mb_stripos($line, $keyword, 0, 'UTF-8') !== false
            ) {
                return true;
            }
        }

        return false;
    }

   /*
|--------------------------------------------------------------------------
| استخراج قیمت از خط
|--------------------------------------------------------------------------
*/
private function extractPrice($line)
{
    $line = $this->normalizeText($line);

    /*
     * قیمت‌های قابل قبول:
     *
     * 786950
     * 786,950
     * 786٬950
     * 786.950
     * 77/300
     *
     * جداکننده‌های رایج:
     * ,  .  ٬  /
     */

    preg_match_all(
        '/(?<![\d])(\d{1,3}(?:[,\.\x{066C}\/]\d{3})+|\d{5,9})(?![\d])/u',
        $line,
        $matches
    );

    if (
        !isset($matches[1]) ||
        !count($matches[1])
    ) {
        return null;
    }

    $values = array();

    foreach ($matches[1] as $value) {

        /*
         * حذف جداکننده هزارگان
         */
        $value = str_replace(
            array(',', '.', '/', '٬'),
            '',
            $value
        );

        /*
         * قیمت حداقل 5 رقم
         */
        if ((int)$value >= 10000) {

            $values[] =
                (int)$value;
        }
    }

    if (!count($values)) {
        return null;
    }

    /*
     * معمولاً آخرین عدد موجود در خط قیمت است.
     */
    return end($values);
}
    /*
    |--------------------------------------------------------------------------
    | استخراج سایزهای موردنظر از ردیف
    |--------------------------------------------------------------------------
    */
    private function rowSizes($attributes)
    {
        if (!empty($attributes['sizes'])) {
            return $attributes['sizes'];
        }

        if (
            $attributes['range_from'] !== null &&
            $attributes['range_to'] !== null
        ) {
            return array(
                '__RANGE__' .
                $attributes['range_from'] .
                '-' .
                $attributes['range_to']
            );
        }

        if ($attributes['size'] !== null) {
            return array($attributes['size']);
        }

        return array();
    }

    /*
    |--------------------------------------------------------------------------
    | مقایسه مقدار
    |--------------------------------------------------------------------------
    */
    private function sameValue($a, $b)
    {
        if ($a === null || $b === null) {
            return false;
        }

        $a = strtoupper(trim((string)$a));
        $b = strtoupper(trim((string)$b));

        $a = str_replace(' ', '', $a);
        $b = str_replace(' ', '', $b);

        return $a === $b;
    }

    /*
    |--------------------------------------------------------------------------
    | آیا سایز محصول داخل بازه است؟
    |--------------------------------------------------------------------------
    */
    private function sizeInRange($size, $from, $to)
    {
        $size = str_replace(',', '.', trim((string)$size));

        if (!is_numeric($size)) {
            return false;
        }

        return (
            (float)$size >= (float)$from &&
            (float)$size <= (float)$to
        );
    }

    /*
    |--------------------------------------------------------------------------
    | امتیاز تطبیق
    |--------------------------------------------------------------------------
    */
    private function matchProduct(
        $rowAttributes,
        $product
    ) {
        $pa = $product['_attributes'];

        /*
        |--------------------------------------------------------------------------
        | دسته
        |--------------------------------------------------------------------------
        */

        /*
|--------------------------------------------------------------------------
| دسته‌بندی
|--------------------------------------------------------------------------
*/

$categoryIds = array();

if (
    isset($this->template['category_ids']) &&
    is_array($this->template['category_ids'])
) {

    foreach ($this->template['category_ids'] as $categoryId) {

        $categoryId = intval($categoryId);

        if ($categoryId > 0) {
            $categoryIds[] = $categoryId;
        }
    }
}

/*
 * سازگاری با قالب‌های قدیمی
 */
if (
    !count($categoryIds) &&
    isset($this->template['category_id'])
) {

    $categoryId =
        intval($this->template['category_id']);

    if ($categoryId > 0) {
        $categoryIds[] = $categoryId;
    }
}

/*
 * اگر دسته مشخص شده باشد،
 * محصول باید در یکی از دسته‌ها باشد.
 */
if (
    count($categoryIds) &&
    !in_array(
        intval($product['parentid']),
        $categoryIds,
        true
    )
) {
    return false;
}

        /*
        |--------------------------------------------------------------------------
        | ابعاد
        |--------------------------------------------------------------------------
        */

        if (
            $rowAttributes['dimensions'] !== null &&
            $pa['dimensions'] !== null &&
            !$this->sameValue(
                $rowAttributes['dimensions'],
                $pa['dimensions']
            )
        ) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | گرید
        |--------------------------------------------------------------------------
        */

        /*
|--------------------------------------------------------------------------
| گرید
|--------------------------------------------------------------------------
*/

if (
    $rowAttributes['grade'] !== null &&
    $pa['grade'] !== null &&
    !$this->sameValue(
        $rowAttributes['grade'],
        $pa['grade']
    )
) {
    return false;
}

/*
|--------------------------------------------------------------------------
| نوع محصول
|--------------------------------------------------------------------------
*/

if (
    $rowAttributes['type'] !== null &&
    $pa['type'] !== null &&
    !$this->sameValue(
        $rowAttributes['type'],
        $pa['type']
    )
) {
    return false;
}


        /*
        |--------------------------------------------------------------------------
        | پروفیل
        |--------------------------------------------------------------------------
        */

        if (
            $rowAttributes['profile'] !== null &&
            $pa['profile'] !== null &&
            !$this->sameValue(
                $rowAttributes['profile'],
                $pa['profile']
            )
        ) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | طول
        |--------------------------------------------------------------------------
        */

        if (
            $rowAttributes['length'] !== null &&
            $pa['length'] !== null &&
            !$this->sameValue(
                $rowAttributes['length'],
                $pa['length']
            )
        ) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | ضخامت
        |--------------------------------------------------------------------------
        */

        if (
            $rowAttributes['thickness'] !== null &&
            $pa['thickness'] !== null &&
            !$this->sameValue(
                $rowAttributes['thickness'],
                $pa['thickness']
            )
        ) {
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | تشخیص گرید از متن کلی
    |--------------------------------------------------------------------------
    |
    | مثال:
    | سایز 8 و 10 A2 می باشد
    |
    */
    private function extractGradeContext($text)
    {
        $map = array();

        $text = $this->normalizeText($text);

        preg_match_all(
            '/سایز\s*([0-9,\sو]+).*?(A[1-4])\b/iu',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {

            preg_match_all(
                '/\d+/',
                $match[1],
                $sizes
            );

            foreach ($sizes[0] as $size) {
                $map[$size] =
                    strtoupper($match[2]);
            }
        }

        return $map;
    }

    /*
    |--------------------------------------------------------------------------
    | تبدیل قیمت
    |--------------------------------------------------------------------------
    */
    private function normalizePrice($price)
    {
        $unit = isset($this->template['price_unit'])
            ? strtolower(trim($this->template['price_unit']))
            : '';

        if (
            $unit === 'rial' ||
            $unit === 'ریال'
        ) {
            $price = $price / 10;
        }

        /*
         * تومان:
         * بدون تغییر
         */

        $vatMode = isset($this->template['vat'])
            ? strtolower(trim($this->template['vat']))
            : '';

        if (
            $vatMode === 'without' ||
            $vatMode === 'no' ||
            $vatMode === 'excluded' ||
            $vatMode === 'بدون'
        ) {

            $vat = $this->getVatPercent();

            $price =
                $price * (1 + ($vat / 100));
        }

        return (int)round($price);
    }

    /*
    |--------------------------------------------------------------------------
    | درصد ارزش افزوده
    |--------------------------------------------------------------------------
    */
    private function getVatPercent()
    {
        if (
            isset($this->template['vat_percent']) &&
            is_numeric($this->template['vat_percent'])
        ) {
            return (float)$this->template['vat_percent'];
        }

        global $other_configs;

        if (
            isset($other_configs) &&
            is_array($other_configs) &&
            isset($other_configs['vat_value']) &&
            is_numeric($other_configs['vat_value'])
        ) {
            return (float)$other_configs['vat_value'];
        }

        return DEFAULT_VAT_PERCENT;
    }

    /*
    |--------------------------------------------------------------------------
    | Parser اصلی
    |--------------------------------------------------------------------------
    */
    public function parse($text)
    {
        $text = $this->removeUrls($text);
        $text = $this->normalizeText($text);

        $lines = preg_split(
            '/\r\n|\r|\n/',
            $text
        );

        $gradeContext =
            $this->extractGradeContext($text);

        $parsed = array();
        $unmatched = array();

        $pending = null;

        foreach ($lines as $originalLine) {

            $line = trim($originalLine);

            if ($line === '') {
                continue;
            }

            $line = $this->normalizeText($line);

            if ($this->isIgnoredLine($line)) {
                $pending = null;
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | استخراج مشخصات از خط
            |--------------------------------------------------------------------------
            */

            $attributes =
                $this->extractAttributes($line);

            $sizes =
                $this->rowSizes($attributes);

            /*
            |--------------------------------------------------------------------------
            | قیمت خط
            |--------------------------------------------------------------------------
            */

            $price =
                $this->extractPrice($line);

            /*
            |--------------------------------------------------------------------------
            | اگر مشخصات نداریم
            |--------------------------------------------------------------------------
            */

            if (!count($sizes)) {

                /*
                 * اگر فقط قیمت است و pending داریم
                 */
                if (
                    $price !== null &&
                    $pending !== null
                ) {

                    $attributes =
                        $pending['attributes'];

                    $sizes =
                        $pending['sizes'];

                    $pending = null;

                } else {
                    continue;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | اگر قیمت نداریم، برای خط بعدی نگه دار
            |--------------------------------------------------------------------------
            */

            if ($price === null) {

                $pending = array(
                    'attributes' => $attributes,
                    'sizes'      => $sizes,
                    'line'       => $line
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | گرید Context
            |--------------------------------------------------------------------------
            */

            if ($attributes['grade'] === null) {

                foreach ($sizes as $size) {

                    if (
                        isset($gradeContext[$size])
                    ) {
                        $attributes['grade'] =
                            $gradeContext[$size];

                        break;
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | پیدا کردن محصولات
            |--------------------------------------------------------------------------
            */

            foreach ($sizes as $requestedSize) {

                $candidates = array();

                $isRange = false;
                $rangeFrom = null;
                $rangeTo = null;

                if (
                    strpos(
                        $requestedSize,
                        '__RANGE__'
                    ) === 0
                ) {

                    $isRange = true;

                    $range =
                        substr(
                            $requestedSize,
                            strlen('__RANGE__')
                        );

                    $parts =
                        explode('-', $range);

                    $rangeFrom = $parts[0];
                    $rangeTo   = $parts[1];

                }

                foreach ($this->products as $product) {

                    $pa =
                        $product['_attributes'];

                    /*
                    |--------------------------------------------------------------------------
                    | سایز
                    |--------------------------------------------------------------------------
                    */

                    if ($isRange) {

                        $productSize =
                            $product['size'];

                        if (
                            !$this->sizeInRange(
                                $productSize,
                                $rangeFrom,
                                $rangeTo
                            )
                        ) {
                            continue;
                        }

                    } else {

                        /*
                         * برای ابعاد
                         */
                        if (
                            $attributes['dimensions'] !== null
                        ) {

                            if (
                                !$this->sameValue(
                                    $attributes['dimensions'],
                                    $pa['dimensions']
                                )
                            ) {
                                continue;
                            }

                        } else {

                            if (
                                !$this->sameValue(
                                    $requestedSize,
                                    $product['size']
                                )
                            ) {
                                continue;
                            }
                        }
                    }

                    if (
                        $this->matchProduct(
                            $attributes,
                            $product
                        )
                    ) {
                        $candidates[] =
                            $product;
                    }
                }

                /*
|--------------------------------------------------------------------------
| ثبت محصول / بازه
|--------------------------------------------------------------------------
*/

$finalPrice =
    $this->normalizePrice($price);


/*
|--------------------------------------------------------------------------
| حالت عادی: فقط یک محصول
|--------------------------------------------------------------------------
*/

if (count($candidates) === 1) {

    $product = $candidates[0];

    $key = $product['cid'];

    $parsed[$key] = array(
        'product_id' => intval($product['cid']),
        'title'      => $product['title'],
        'size'       => $product['size'],
        'grade'      => $product['grade'],
        'price'      => $finalPrice,
        'raw_price'  => $price,
        'attributes' => $attributes
    );

    continue;
}


/*
|--------------------------------------------------------------------------
| بازه: چند محصول
|--------------------------------------------------------------------------
|
| مثال:
|
| سایز 14 الی 25
|
| محصولات:
| 14
| 16
| 18
| 20
| 22
| 25
|
| همه باید همان قیمت را بگیرند.
|--------------------------------------------------------------------------
*/

if (
    $isRange &&
    count($candidates) > 1
) {

    foreach ($candidates as $product) {

        $key = $product['cid'];

        $parsed[$key] = array(
            'product_id' => intval($product['cid']),
            'title'      => $product['title'],
            'size'       => $product['size'],
            'grade'      => $product['grade'],
            'price'      => $finalPrice,
            'raw_price'  => $price,
            'attributes' => $attributes,
            'range'      => true,
            'range_from' => $rangeFrom,
            'range_to'   => $rangeTo
        );
    }

    continue;
}


/*
|--------------------------------------------------------------------------
| چند محصول ولی بدون بازه
|--------------------------------------------------------------------------
*/

if (count($candidates) > 1) {

    $titles = array();

    foreach ($candidates as $candidate) {
        $titles[] =
            $candidate['title'];
    }

    $unmatched[] = array(
        'line'       => $line,
        'price'      => $price,
        'reason'     => 'چند محصول با این مشخصات وجود دارد',
        'candidates' => $titles
    );

} else {

    $unmatched[] = array(
        'line'   => $line,
        'price'  => $price,
        'reason' => 'محصول متناظر در دیتابیس پیدا نشد'
    );
}

                /*
                |--------------------------------------------------------------------------
                | چند محصول یعنی ابهام
                |--------------------------------------------------------------------------
                */

                if (count($candidates) > 1) {

                    $titles = array();

                    foreach ($candidates as $candidate) {
                        $titles[] =
                            $candidate['title'];
                    }

                    $unmatched[] = array(
                        'line' => $line,
                        'price' => $price,
                        'reason' => 'چند محصول با این مشخصات وجود دارد',
                        'candidates' => $titles
                    );

                } else {

                    $unmatched[] = array(
                        'line' => $line,
                        'price' => $price,
                        'reason' => 'محصول متناظر در دیتابیس پیدا نشد'
                    );
                }
            }

            $pending = null;
        }

        return array(
            'items' => array_values($parsed),
            'unmatched' => $unmatched,
            'count' => count($parsed)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | اطلاعات قالب
    |--------------------------------------------------------------------------
    */
    public function getTemplate()
    {
        return $this->template;
    }

    /*
    |--------------------------------------------------------------------------
    | محصولات
    |--------------------------------------------------------------------------
    */
    public function getProducts()
    {
        return $this->products;
    }
}