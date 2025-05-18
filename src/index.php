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
    $result = fetch($url);
    if (strlen($result) < 200 && strpos($result, '<?xml') === 0 && strpos($result, 'NoSuchKey') !== false) {
        return null;
    }
    return $result;
}

function main() {
    $dir = sys_get_temp_dir() . '/r2compress';
    // $dir = __DIR__ . '/r2compress';
    if (!file_exists($dir)) {
        mkdir($dir);
    }
    var_dump($dir);

    // TODO: get key list
    $list = ['test.1', 'user_avatar/2984/2025051615_c3e29fe87a9a.png'];

    foreach ($list as $key) {
        $info = pathinfo($key);
        $filePath = $dir . '/' . $info['basename'];
        $fileContent = getFile($key);
        if ($fileContent === null) {
            // file not exists
            echo 'File ', $key, ' not exists', "\n";
            continue;
        }
        file_put_contents($filePath, $fileContent);
        $oldSize = strlen($fileContent);
        if (strtolower($info['extension']) === 'jpg') {
            $command = '/opt/mozjpeg/bin/cjpeg -quality 90 ' . $filePath;
        }
        if (strtolower($info['extension']) === 'png') {
            // $command = 'pngquant --speed=1 --quality=90-100 - < ' . escapeshellarg($filePath);
            exec('optipng -o5 ' . $filePath);
            $newSize = filesize($filePath);
        }
        if (isset($command)) {
            echo '[', $info['basename'], ']: ';
            $result = shell_exec($command);
            if (strlen($result) > 100) {
                file_put_contents($filePath, $result);
                $newSize = filesize($filePath);
                echo 'success: ', $oldSize, '->', $newSize, "\n";
            } else {
                echo 'fail: ', $result, "\n";
            }
            unset($command, $result);
        }
        // check file size
        if ($newSize < $oldSize) {
            echo 'update file: ', $key, "\n";
            writeFile($key, file_get_contents($filePath));
        }
    }
}

main();