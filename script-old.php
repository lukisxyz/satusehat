<?php
echo "Load configuration file from config.json\n";
$config = json_decode(file_get_contents('config.json'), true);

$host = $config['database']['host'];
$dbname = $config['database']['dbname'];
$username = $config['database']['username'];
$password = $config['database']['password'];
$env = $config['env'];

function get_credential($pdo, $env) {
    try {
        $stmt = $pdo->prepare("SELECT fhirsetup_id as id, fhirsetup_client_id as client_id, fhirsetup_client_secret as client_secret, fhirsetup_client_token as token, fhirsetup_urlproxy_stg as url_stg, fhirsetup_urlproxy_prd as url_prd  FROM m_far_fhir_setup");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo = null;
        return $row;
    } catch (PDOException $e) {
        echo "Connection failed: " . $e->getMessage();
        return null;
    }
}

function get_access_token($pdo, $uri, $fhci_id, $client_id, $client_secret, $env) {
    $post_body = http_build_query([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'grant_type' => 'client_credentials'
    ]);
    $ch = curl_init($uri . '/oauth2/v1/accesstoken?grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15000);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30000);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.31 (KHTML, like Gecko) Chrome/26.0.1410.64 Safari/537.31');
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $response = curl_exec($ch);
    if ($response === false) {
        echo 'curl error: ' . curl_error($ch);
    }
    curl_close($ch);
    $accessTokenData = json_decode($response, true);
    $accessToken = $accessTokenData['access_token'];
    $issuedAt = $accessTokenData['issued_at'];
    try {
        $updateStmt = $pdo->prepare("UPDATE m_far_fhir_setup SET fhirsetup_client_token = ?, fhirsetup_client_token_issued_at = ?, fhirsetup_updated_date = NOW() WHERE fhirsetup_id = ?");
        $updateStmt->bindValue(1, $accessToken);
        $updateStmt->bindValue(2, $issuedAt);
        $updateStmt->bindValue(3, $fhci_id);
        $updateStmt->execute();
        echo "update token to database";
        return 1;
    } catch (PDOException $e) {
        echo "connection failed: " . $e->getMessage();
        return null;
    }
}

function fetch_data($pdo, $uri, $token, $fhic_id, $client_id, $client_secret, $env, $next) {
    $api_url = $uri . '/kfa-v2/products/all?page='.$next.'&size=100&product_type=farmasi';
    $ch_api = curl_init();
    curl_setopt($ch_api, CURLOPT_URL, $api_url);
    curl_setopt($ch_api, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_api, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ));
    $response_api = curl_exec($ch_api);
    if (curl_errno($ch_api)) {
        throw new Exception('error occurred while fetching data from API: ' . curl_error($ch_api));
    }
    curl_close($ch_api);
    $resp = json_decode($response_api, true);
    if (isset($resp["fault"])) {
        if ($resp["fault"]["faultstring"] == "Access Token expired") {
            echo 'error occurred while fetching data from API: Token expired, get new Token';
            get_access_token($pdo, $uri, $fhic_id, $client_id, $client_secret, $env);     
            $client_credentials = get_credential($pdo, $env);
            if ($client_credentials) {
                $client_id = $client_credentials['client_id'];
                $client_secret = $client_credentials['client_secret'];
                $token = $client_credentials['token'];
                $uri = $env == "production" ? $client_credentials['url_prd'] : $client_credentials['url_stg'];
                $id = $client_credentials['id'];
                try {
                    fetch_data($pdo, $uri, $token, $id, $client_id, $client_secret, $env, $next);
                } catch (Exception $e) {
                    echo 'error: ' . $e->getMessage();
                }
            } else {
                echo 'Failed to fetch client credentials from the database' . PHP_EOL;
            }
        }
        if ($resp["fault"]["faultstring"] == "Invalid API call as no apiproduct match found") {
            throw new Exception('error occurred while fetching data from API: ' . $resp["fault"]["faultstring"] . PHP_EOL);
        }
    }
    $msg = json_encode(array(
        'response' => json_encode(array(
            'total' => json_decode($response_api, true)['total'],
            'page' => json_decode($response_api, true)['page'],
            'size' => json_decode($response_api, true)['size']
        )),
        'request' => $api_url
    ));
    echo $msg . PHP_EOL;
    $data = $resp['items']['data'];
    return $data;
}

function convert_to_json($data, $field_convert) {
    foreach ($field_convert as $field) {
        if (isset($data[$field])) {
            $data[$field] = json_encode($data[$field]);
        }
    }
    return $data;
}

$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$client_credentials = get_credential($pdo, $env);

if ($client_credentials) {
    $client_id = $client_credentials['client_id'];
    $client_secret = $client_credentials['client_secret'];
    $token = $client_credentials['token'];
    $uri = $env == "production" ? $client_credentials['url_prd'] : $client_credentials['url_stg'];
    $id = $client_credentials['id'];
    $next = 1;
    $count = 0;
    $maxAttempts = 30000;
    $filename = 'data/'.$env . '.csv';
    $filename_sql = 'data/'.$env . '.sql';
    if (file_exists($filename)) {
        unlink($filename);
    }
    if (file_exists($filename)) {
        unlink($filename_sql);
    }
    $fp = fopen($filename, 'w');
    $fps = fopen($filename_sql, 'w');
    $field_convert = ['tags', 'replacement', 'active_ingredients', 'product_template', 'uom', 'dosage_form', 'farmalkes_type'];
    do {
        $res = null;
        $is_header = true;
        try {
            $res = fetch_data($pdo, $uri, $token, $id, $client_id, $client_secret, $env, $next, $fp);
            if (is_array($res) && count($res) > 0) {
                if ($is_header) {
                    fputcsv($fp, array_keys(convert_to_json($res[0], $field_convert)));
                    $is_header = false;
                }
                foreach ($res as $row) {
                    $row = convert_to_json($row, $field_convert);
                    fputcsv($fp, $row);
                    $barangihs_kode = $row['kfa_code'];
                    $barangihs_nama = str_replace("'", "", $row['name']);
                    $barangihs_nama = substr($barangihs_nama, 0, 254);
                    $barangihs_updated_date = $row['updated_at'];
                    $barangihs_updated_by = 'TMADMIN';
                    $cte = '"update_cte_'.$barangihs_kode.'"';
                    fwrite($fps, "WITH ".$cte." AS (UPDATE public.m_barang_ihs SET barangihs_nama = '$barangihs_nama', barangihs_updated_date = NOW(), barangihs_updated_by = '$barangihs_updated_by' WHERE barangihs_kode = '$barangihs_kode' RETURNING *) INSERT INTO public.m_barang_ihs (barangihs_kode, barangihs_nama, barangihs_created_date, barangihs_created_by) SELECT '$barangihs_kode', '$barangihs_nama', NOW(), '$barangihs_updated_by' WHERE NOT EXISTS (SELECT 1 FROM ".$cte.");".PHP_EOL);
                }
                $next += 1;
                $count += $count;
            } else {
                break;
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        }
        usleep(100000);
    } while (is_array($res) && count($res) > 0 && $next < $maxAttempts);
    fclose($fp);
    fclose($fps);
    echo 'finish' . PHP_EOL;
} else {
    echo 'failed to fetch client credentials from the database';
}
?>
