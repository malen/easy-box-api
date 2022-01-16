#!/usr/bin/php -q
<?php

namespace Malen\BoxApi;

require(dirname(dirname(__FILE__)) . '/vendor/autoload.php');

use Firebase\JWT\JWT;
use GuzzleHttp\Client;

class EasyBoxApi
{
    // 50MB
    protected const FILE_CHUNKED_SIZE = 50 * 1024 * 1024;
    protected $token_url        = "https://www.box.com/api/oauth2/token";
    protected $api_url          = "https://api.box.com/2.0";
    protected $upload_url       = "https://upload.box.com/api/2.0/files/content";
    protected $access_token_url = "https://api.box.com/oauth2/token";
    protected $items_url        = "https://api.box.com/2.0/folders/0/items";
    protected $upload_session   = "https://upload.box.com/api/2.0/files/upload_sessions";

    protected $config = "";
    protected $client;
    public $accesstoken = "";


    public function __construct($config)
    {
        $json = file_get_contents($config);
        $this->config = json_decode($json);
        $this->client = new Client();
        $this->accesstoken = $this->getAccessToken($this->config);
    }

    /**
     * トークンを返却する
     */
    protected function getAccessToken($config)
    {
        $claims = [
            'iss' => $config->boxAppSettings->clientID,
            'sub' => $config->enterpriseID,
            'box_sub_type' => 'enterprise',
            'aud' => $this->access_token_url,
            'jti' => base64_encode(random_bytes(64)),
            'exp' => time() + 45,
            'kid' => $config->boxAppSettings->appAuth->publicKeyID
        ];

        $private_key = $config->boxAppSettings->appAuth->privateKey;
        $passphrase = $config->boxAppSettings->appAuth->passphrase;
        $key = openssl_pkey_get_private($private_key, $passphrase);

        $assertion = JWT::encode($claims, $key, 'RS512');

        $params = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
            'client_id' => $config->boxAppSettings->clientID,
            'client_secret' => $config->boxAppSettings->clientSecret
        ];

        $response = $this->client->request('POST', $this->access_token_url, [
            'form_params' => $params
        ]);

        $data = $response->getBody()->getContents();
        return json_decode($data)->access_token;
    }

    /**
     * フォルダ名よりフォルダIDを取得する
     */
    public function getFolderByName($folderName)
    {
        $curl = curl_init($this->items_url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_FAILONERROR => true,
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer $this->accesstoken",
            ),
        ]);
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if (CURLE_OK !== $errno) {
            throw new RuntimeException($error, $errno);
        }
        //print_r(json_decode($body)->entries);
        foreach (json_decode($body)->entries as $key => $v) {
            //print_r($v->id);
            if ($v->name == $folderName) {
                return $v->id;
            }
        }
    }

    /**
     * 
     */
    public function uploadFile($folder_id, $upload_file)
    {
        // アップロードファイルの存在チェック
        if (is_file($upload_file)) {

            //print(realpath($upload_file));
            // 拡張子のチェック
            // if (pathinfo($upload_file, PATHINFO_EXTENSION) != "zip") {
            //     print("xxxxxxxxxxxx");
            //     return;
            // }
            // アップロードファイルのサイズチェック
            print(basename($upload_file));
            print(dirname(__FILE__));
            print(dirname($upload_file));
            print(pathinfo($upload_file, PATHINFO_EXTENSION));

            print("ファイルサイズ：" . filesize($upload_file));
            // ファイルサイズチェック
            if (filesize($upload_file) > self::FILE_CHUNKED_SIZE) {
                print("50MB 超えた");
                $this->uploadLargeFile($folder_id, filesize($upload_file), $upload_file);
            } else {
                print("50MB 未満");
                $this->uploadSmallFile($folder_id, $upload_file);
            }
        } else {
            print("not exists!");
        }
    }

    protected function uploadSmallFile($folder_id, $upload_file)
    {
        $curl = curl_init($this->upload_url);
        // cURL 転送用オプションを設定する
        curl_setopt_array($curl, array(
            //CURLOPT_URL => "https://upload.box.com/api/2.0/files/content",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => array(
                'attributes' => sprintf('{"name": "%s", "parent": {"id": "%s"}}', basename($upload_file), $folder_id),
                'file' => new CURLFILE(realpath($upload_file))
            ),
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer $this->accesstoken",
            ),
        ));
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if (CURLE_OK !== $errno) {
            throw new RuntimeException($error, $errno);
        }
        print($body);
    }

    /**
     * ファイルサイズが５０MBを超えた場合、このAPIを使う
     */
    protected function uploadLargeFile($folder_id, $file_size, $upload_file)
    {
        $session = $this->getUploadSession($folder_id, $file_size, basename($upload_file));
        print($session->part_size);
        print($session->id);
        $this->uploadPartsBySession($session->id, $upload_file, $session->total_parts);

        $this->getPartsList($session->id, basename($upload_file));
    }

    protected function getUploadSession($folder_id, $file_size, $file_name)
    {
        $curl = curl_init($this->upload_session);
        $post_data = array(
            "folder_id" => "$folder_id",
            "file_size" => $file_size,
            "file_name" => "$file_name"
        );

        //print_r($post_data);
        $post_data = json_encode($post_data);

        // cURL 転送用オプションを設定する
        curl_setopt_array($curl, array(
            //CURLOPT_URL => "https://upload.box.com/api/2.0/files/content",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer $this->accesstoken",
                "Content-Type: application/json",
                "Content-Length: " . strlen($post_data),
            ),
        ));
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if (CURLE_OK !== $errno) {
            throw new RuntimeException($error, $errno);
        }
        print_r(json_decode($body));
        return json_decode($body);
    }

    protected function uploadPartsBySession($session_id, $upload_file, $total_parts)
    {
        // print("---------------------------");
        // print($this->upload_session . '/' . $session_id);

        // print("---------------------------");
        // $header = "Authorization: Bearer $this->accesstoken";
        // $result = shell_exec("curl -X PUT $this->upload_session.'/'.$session_id -H $header -H 'Digest: sha=fpRyg5eVQletdZqEKaFlqwBXJzM=' -H 
        //'Content-Range: bytes 8388608-16777215/445856194' -H 'Content-Type: application/octet-stream' --data-binary @$file_name");

        // print_r($result);

        $commit_data = [];
        $curl = curl_init($this->upload_session . '/' . $session_id);
        $openfile = fopen($upload_file, "rb") or die("Couldn't open the file");
        for ($i = 0; $i < $total_parts; $i++) {
            fseek($openfile, $i * 8388608);

            $sp_data = fread($openfile, 8388608);
            $range_end = min($i * 8388608 + 8388608 - 1, filesize($upload_file) - 1);
            print(strlen($sp_data) . PHP_EOL);
            // cURL 転送用オプションを設定する
            curl_setopt_array($curl, array(
                //CURLOPT_URL => "https://upload.box.com/api/2.0/files/content",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "PUT",
                CURLOPT_POSTFIELDS => $sp_data,
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer $this->accesstoken",
                    "Content-Type: application/octet-stream",
                    "Digest: sha=" . base64_encode(sha1($sp_data, true)),
                    sprintf("Content-Range: bytes %d-%d/%d", $i * 8388608, $range_end, filesize($upload_file)),
                ),
            ));
            print("--------------------xxxx");
            $body = curl_exec($curl);
            array_push($commit_data, json_decode($body)->part);
        }
        //$filesize = filesize($myfile);
        //fseek($openfile, 8388608);
        // $sp_data = fread($openfile, 8388608);

        $post_data = array(
            "parts" => $commit_data
        );

        print(base64_encode(sha1_file($upload_file, true)) . PHP_EOL);

        $curl = curl_init($this->upload_session . '/' . $session_id . '/commit');
        // cURL 転送用オプションを設定する
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($post_data),
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer $this->accesstoken",
                "Content-Type: application/json",
                "Digest: sha=" . base64_encode(sha1_file($upload_file, true)),
            ),
        ));
        // print("--------------------xxxx");
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if (CURLE_OK !== $errno) {
            throw new RuntimeException($error, $errno);
        }
        print_r(json_decode($body));
        return json_decode($body);
    }

    protected function getPartsList($session_id, $file_name)
    {
        $header = "Authorization: Bearer $this->accesstoken";
        $result = shell_exec("curl -X GET $this->upload_session.'/'.$session_id.'/parts' -H $header");

        print_r($result);
    }

    protected function commitSession()
    {
    }
}

try {
    $test = new EasyBoxApi("config.json");
    $folderID = $test->getFolderByName("bin");
    $test->uploadFile($folderID, 'C:\Users\malen\Downloads\OpenAM-13.0.0.war');
} catch (Exception $e) {
    print($e);
}
?>