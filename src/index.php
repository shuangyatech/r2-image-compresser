<?php
require('../vendor/autoload.php');
require('./test.php');

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;

$credentials = new Credentials(getenv('R2_KEY'), getenv('R2_SECRET'));

$options = [
    'region' => 'auto',
    'endpoint' => 'https://' . getenv('R2_ACCOUNT') . '.r2.cloudflarestorage.com',
    'version' => 'latest',
    'credentials' => $credentials
];

$client = new S3Client($options);

function fetch($url, $data = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if (isset($data['header'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $data['header']);
    }
    if (isset($data['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $data['cookie']);
    }
    if (isset($data['post'])) {
        if (!isset($data['method'])) {
            curl_setopt($ch, CURLOPT_POST, 1);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data['post']);
    }
    if (isset($data['method'])) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $data['method']);
    }
    if (substr($url, 0, 8) === 'https://') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0');
    $r = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = new \Exception(curl_error($ch), curl_errno($ch));
        curl_close($ch);
        throw $error;
    } else {
        curl_close($ch);
        return $r;
    }
}

function writeFile($fileName, $value) {
    global $client;
    try {
        $client->putObject([
            'Bucket' => getenv('R2_BUCKET'),
            'Key' => $fileName,
            'ContentLength' => strlen($value),
            'Body' => $value,
        ]);
        return true;
    } catch (\Exception $exception) {
        return false;
    }
}

function getFile($fileName) {
    global $client;
    $cmd = $client->getCommand('GetObject', [
        'Bucket' => getenv('R2_BUCKET'),
        'Key' => $fileName,
    ]);
    $request = $client->createPresignedRequest($cmd, '+1 hour');
    $url = $request->getUri();
    return fetch($url);
}

function listFiles($prefix) {
    global $client;
    $token = null;
    $result = [];
    while (true) {
        $res = $client->ListObjectsV2([
            'Bucket' => getenv('R2_BUCKET'),
            'ContinuationToken' => $token,
            'MaxKeys' => 100,
            'Prefix' => $prefix
        ]);
        if (is_array($res['Contents'])) {
            $result = array_merge($result, $res['Contents']);
        }
        if ($res['ContinuationToken']) {
            $token = $res['ContinuationToken'];
        }
        if (!$res['IsTruncated']) {
            break;
        }
    }
    return $result;
}

function main() {
    // $dir = sys_get_temp_dir() . '/r2compress';
    $dir = __DIR__ . '/r2compress';
    if (!file_exists($dir)) {
        mkdir($dir);
    }
    var_dump($dir);
    $list = listFiles('detail/2025051814/');

    foreach ($list as $file) {
        $key = $file['Key'];
        $info = pathinfo($key);
        $filePath = $dir . '/' . $info['basename'];
        file_put_contents($filePath, getFile($key));
        var_dump($file);
        if (strtolower($info['extension']) === 'jpg') {
            system('jpegoptim -m 90 ' . $filePath);
        }
        if (strtolower($info['extension']) === 'png') {
            system('optipng ' . $filePath);
        }
    }
}

main();