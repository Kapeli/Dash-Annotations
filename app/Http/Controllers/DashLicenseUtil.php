<?php namespace App\Http\Controllers;

class DashLicenseUtil {

    public static function check_license($license)
    {
        $signature = $license['Signature'];
        unset($license['Signature']);

        uksort($license, "strcasecmp");
        $total = '';
        foreach ($license as $value)
        {            
            $total .= $value;
        }
        $key_raw = <<<EOD
-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCtl7Dgf4x0fi0lXfws7Cq/lk0d
TIEXnCu8PBMep0mtRia9WEJ8N53d+8gbuAcMzb4sW6MVOzTEKYrmtq/DTbiaXKiJ
o6osz5KgBjbcGrCzKKvk8uQuTZWusqp69LQfTYSwxwJIp45kl0g8yalewGUtpYuu
yWXBBsw7Z909BpTLBQIDAAAD
-----END PUBLIC KEY-----
EOD;

        $key = openssl_get_publickey($key_raw);
        openssl_public_decrypt(base64_decode($signature), $checkDigest, $key);
        $digest = sha1($total, true);

        if($digest === $checkDigest)
        {
            return true;
        }
        return false;
    }

    public static function check_itunes_receipt($license)
    {
        $receipt = $license['receipt'];
        // foreach(array("ssl://buy.itunes.apple.com", "ssl://sandbox.itunes.apple.com") as $verify_host)
        foreach(array("ssl://buy.itunes.apple.com") as $verify_host)
        {
            $json='{"receipt-data" : "'.$receipt.'" }';
            $fp = fsockopen ($verify_host, 443, $errno, $errstr, 30);
            if ($fp) 
            {
                $header = "POST /verifyReceipt HTTP/1.0\r\n";
                $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
                $header .= "Content-Length: " . strlen($json) . "\r\n\r\n";
                fputs ($fp, $header . $json);
                $res = '';
                while (!feof($fp)) 
                {
                    $step_res = fgets ($fp, 1024);
                    $res = $res . $step_res;
                }
                fclose ($fp);
                $json_source = substr($res, stripos($res, "\r\n\r\n{") + 4);
                $app_store_response_map = json_decode($json_source);
                $app_store_response_status = $app_store_response_map->{'status'};
                if($app_store_response_status == 0 && strcmp(trim($app_store_response_map->receipt->bundle_id), "com.kapeli.dashdoc") == 0)
                {
                    foreach($app_store_response_map->receipt->in_app as $in_app)
                    {
                        if(strcmp(trim($in_app->product_id), "FullVersion") == 0)
                        {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }
}