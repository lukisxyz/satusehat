<?php
echo "Load configuration file from config.json\n";
$config = json_decode(file_get_contents('config.json'), true);

$host = $config['database']['host'];
$dbname = $config['database']['dbname'];
$username = $config['database']['username'];
$password = $config['database']['password'];
$env = $config['env'];
$timeout_seconds = 10;
$do_logging = in_array('-l', $argv);
$log = new Log('logs/logfile.log', $do_logging);

$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$filename = "logs/" . $env . '.log';

class Log {
    private $filename;
    private $do_log;

    public function __construct($filename, $do_log) {
        $this->filename = $filename;
        $this->do_log = $do_log;
    }

    public function log($kode, $job, $message, $level) {
        if ($this->do_log) {
            $current_time = date("Y-m-d H:i:s");
            $log_entry = '{"kode":"'.$kode.'","job":"'.$job.'","message":"'.$message.'","timestamp":"'.$current_time.'","level":"'.$level.'"}' . PHP_EOL;
            file_put_contents($this->filename, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
}

function get_credential($env) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT fhirsetup_id as id, fhirsetup_client_id as client_id, fhirsetup_client_secret as client_secret, fhirsetup_client_token as token, fhirsetup_urlproxy_stg as url_stg, fhirsetup_urlproxy_prd as url_prd  FROM m_far_fhir_setup");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row;
    } catch (PDOException $e) {
        echo "Connection failed: " . $e->getMessage();
        return NULL;
    }
}

function get_by_kode($barangihs_kode) {
    global $pdo;
    try {
        $sql = "SELECT barangihs_updated_date
        FROM public.m_barang_ihs
        WHERE barangihs_kode = :barangihs_kode
        ORDER BY barangihs_id
        LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':barangihs_kode', $barangihs_kode);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return $result; 
        } else {
            return NULL; 
        }
    } catch(PDOException $e) {
        echo "Error: " . $e->getMessage();
    }
}

function insert_record($kode, $nama) {
    global $pdo;
    try {
        $sql = "INSERT INTO public.m_barang_ihs (barangihs_kode, barangihs_nama, barangihs_created_date, barangihs_created_by) 
                VALUES (:kode, :nama, NOW(), 'TMADMIN')";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':kode', $kode);
        $stmt->bindParam(':nama', $nama);
        $stmt->execute();
        return $pdo->lastInsertId();
    } catch(PDOException $e) {
        echo "Error: " . $e->getMessage();
    }
}

function update_record($kode, $nama, $updated_date) {
    global $pdo;
    try {
        $sql = "UPDATE public.m_barang_ihs 
                SET barangihs_nama = :nama,
                    barangihs_updated_by = 'TMADMIN' , 
                    barangihs_updated_date = :updated_date 
                WHERE barangihs_kode = :kode";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':kode', $kode);
        $stmt->bindParam(':nama', $nama);
        $stmt->bindParam(':updated_date', $updated_date);
        $stmt->execute();
        return $stmt->rowCount();
    } catch(PDOException $e) {
        echo "Error: " . $e->getMessage();
    }
}

function get_access_token($uri, $fhci_id, $client_id, $client_secret, $env) {
    global $pdo;
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
        return NULL;
    }
}

function fetch_data($uri, $token, $fhic_id, $client_id, $client_secret, $env, $next) {
    global $pdo;
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
            get_access_token($uri, $fhic_id, $client_id, $client_secret, $env);     
            $client_credentials = get_credential($env);
            if ($client_credentials) {
                $client_id = $client_credentials['client_id'];
                $client_secret = $client_credentials['client_secret'];
                $token = $client_credentials['token'];
                $uri = $env == "production" ? $client_credentials['url_prd'] : $client_credentials['url_stg'];
                $id = $client_credentials['id'];
                try {
                    fetch_data($uri, $token, $id, $client_id, $client_secret, $env, $next);
                } catch (Exception $e) {
                    echo 'error: ' . $e->getMessage();
                }
            } else {
                echo 'failed to fetch client credentials from the database' . PHP_EOL;
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

$client_credentials = get_credential($env);

if ($client_credentials) {
    $client_id = $client_credentials['client_id'];
    $client_secret = $client_credentials['client_secret'];
    $token = $client_credentials['token'];
    $uri = $env == "production" ? $client_credentials['url_prd'] : $client_credentials['url_stg'];
    $id = $client_credentials['id'];
    $next = 1;
    $count = 0;
    $maxAttempts = 30000;
    do {
        $res = NULL;
        $is_header = true;
        $res = fetch_data($uri, $token, $id, $client_id, $client_secret, $env, $next);
        $log->log('_', 'fetch',"$uri/kfa-v2/products/all?page=$next&size=100&product_type=farmasi", 'INFO');
        if (is_array($res) && count($res) > 0) {
            foreach ($res as $row) {
                $barangihs_kode = $row['kfa_code'];
                $barangihs_nama = str_replace("'", "", $row['name']);
                $barangihs_nama = substr($barangihs_nama, 0, 254);
                $barangihs_updated_date = $row['updated_at'];
                $barangihs_updated_by = 'TMADMIN';
                try { 
                    $result = get_by_kode($barangihs_kode);
                    if ($result) {
                        $current_barang_update = $result['barangihs_updated_date'];
                        if ($barangihs_updated_date === $current_barang_update) {
                            $log->log("$barangihs_kode", "skip", "success", 'INFO');
                        } else {
                            update_record($barangihs_kode, $barangihs_nama, $barangihs_updated_date);
                            $log->log("$barangihs_kode", "update", "success", 'INFO');
                        }
                    } else {
                        insert_record($barangihs_kode, $barangihs_nama);
                        $log->log("$barangihs_kode", "insert", "success", 'INFO');
                    }
                } catch (Exception $e) {
                    $barangihs_kode = $row['kfa_code'];
                    $err_msg = $e->getMessage();
                    $log->log("$barangihs_kode", "other", $err_msg, 'ERROR');
                    echo $err_msg;
                }
            }
            $next += 1;
        } else {
            break;
        }
        $count += count($res);
        echo 'Done data: '.$count.PHP_EOL;
        usleep(100000);
    } while (is_array($res) && count($res) > 0 && $next < $maxAttempts);
    echo 'finish' . PHP_EOL;
} else {
    echo 'failed to fetch client credentials from the database';
}
?>
