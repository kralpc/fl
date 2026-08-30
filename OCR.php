<?php

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| OCR تصویر
|--------------------------------------------------------------------------
*/
function runPersianOCR($imageBinary, $mime = 'image/jpeg')
{
    if (
        !$imageBinary ||
        !strlen($imageBinary)
    ) {
        return array(
            'success' => false,
            'text' => '',
            'error' => 'تصویر خالی است.'
        );
    }

    if (
        OCR_API_KEY === '' ||
        OCR_API_KEY === 'YOUR_OCR_SPACE_API_KEY'
    ) {
        return array(
            'success' => false,
            'text' => '',
            'error' =>
                'کلید OCR تنظیم نشده است.'
        );
    }

    $base64 =
        base64_encode($imageBinary);

    $data = array(
        'apikey' =>
            OCR_API_KEY,

        'language' =>
            'fas',

        'OCREngine' =>
            '2',

        'isOverlayRequired' =>
            'false',

        'detectOrientation' =>
            'true',

        'scale' =>
            'true',

        'isTable' =>
            'true',

        'base64Image' =>
            'data:' .
            $mime .
            ';base64,' .
            $base64
    );

    $ch =
        curl_init(OCR_API_URL);

    curl_setopt_array(
        $ch,
        array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json'
            )
        )
    );

    $response =
        curl_exec($ch);

    $curlError =
        curl_error($ch);

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    if ($response === false) {

        return array(
            'success' => false,
            'text' => '',
            'error' =>
                'OCR CURL Error: ' .
                $curlError
        );
    }

    if ($httpCode < 200 || $httpCode >= 300) {

        return array(
            'success' => false,
            'text' => '',
            'error' =>
                'OCR HTTP Error: ' .
                $httpCode
        );
    }

    $json =
        json_decode(
            $response,
            true
        );

    if (
        !is_array($json)
    ) {
        return array(
            'success' => false,
            'text' => '',
            'error' =>
                'پاسخ OCR نامعتبر است.'
        );
    }

    if (
        isset($json['IsErroredOnProcessing']) &&
        $json['IsErroredOnProcessing']
    ) {

        $errorText = '';

        if (
            isset($json['ErrorMessage']) &&
            is_array($json['ErrorMessage'])
        ) {
            $errorText =
                implode(
                    "\n",
                    $json['ErrorMessage']
                );
        }

        return array(
            'success' => false,
            'text' => '',
            'error' =>
                $errorText !== ''
                    ? $errorText
                    : 'OCR نتوانست تصویر را پردازش کند.'
        );
    }

    $text = '';

    if (
        isset($json['ParsedResults']) &&
        is_array($json['ParsedResults'])
    ) {

        foreach (
            $json['ParsedResults']
            as $result
        ) {

            if (
                isset($result['ParsedText'])
            ) {
                $text .=
                    "\n" .
                    $result['ParsedText'];
            }
        }
    }

    $text =
        trim($text);

    if ($text === '') {

        return array(
            'success' => false,
            'text' => '',
            'error' =>
                'OCR هیچ متنی از تصویر استخراج نکرد.'
        );
    }

    return array(
        'success' => true,
        'text' => $text,
        'error' => ''
    );
}