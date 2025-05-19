<?php
require('../vendor/autoload.php');
// require('./test.php');

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
  if (isset($data['post']) && !empty($data['post'])) {
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

function fetchApi($api, $post = null) {
  $res = fetch(getenv('PROVIDER_URL') . $api, [
    'post' => $post,
    'header' => [
      'x-provider-token: ' . getenv('PROVIDER_TOKEN')
    ]
  ]);
  return json_decode($res, true);
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

function delFile($fileName) {
  global $client;
	echo 'delete ', $fileName, "\n";
  // $result = $client->deleteObject([
  //   'Bucket' => getenv('R2_BUCKET'),
  //   'Key' => $fileName
	// ]);
  // return $result;
}

$workCache = [];
$userCache = [];
function checkFileUsing($key, $info) {
  global $workCache, $userCache;
  $meta = explode('/', $info['dirname']);
  $scene = $meta[0];
  
  if ($scene === 'user_avatar') {
    $userId = $meta[1];
    if (!preg_match('^/\d+/$', $userId)) {
      return true;
    }
    if (!isset($userCache[$userId])) {
      $userCache[$userId] = fetchApi('user/get?id=' . $userId);
    }
    if (is_array($userCache[$userId])) {
      if (empty($userCache[$userId]['avatar'])) {
				delFile($key);
        return false;
      }
			$urlPath = substr(parse_url($userCache[$userId]['avatar'], PHP_URL_PATH), 1);
			if ($urlPath !== $key) {
				delFile($key);
        return false;
			}
    }
		return true;
  }

	if ($scene === 'work_avatar') {
		if (strpos($meta[1], 'w_') !== 0) {
			// can not check
			return true;
		}
    $workId = substr($meta[1], 2);
    if (!preg_match('^/\d+/$', $workId)) {
      return true;
    }
    if (!isset($workCache[$workId])) {
      $workCache[$workId] = fetchApi('work/get?id=' . $workId);
    }
    if (is_array($workCache[$workId])) {
      if (empty($workCache[$workId]['avatar'])) {
				delFile($key);
        return false;
      }
			$urlPath = substr(parse_url($workCache[$workId]['avatar'], PHP_URL_PATH), 1);
			if ($urlPath !== $key) {
				delFile($key);
        return false;
			}
    }
		return true;
	}

	return true;
}

function main() {
  $dir = sys_get_temp_dir() . '/r2compress';
  // $dir = __DIR__ . '/r2compress';
  if (!file_exists($dir)) {
    mkdir($dir);
  }

  $list = fetchApi('upload/list');
  echo 'File list: ', json_encode($list), "\n\n\n";
  foreach ($list as $key) {
    $info = pathinfo($key);
    // check file is using or not
		if (!checkFileUsing($key, $info)) {
			echo 'File ', $key, ' is not using', "\n";
			continue;
		}
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