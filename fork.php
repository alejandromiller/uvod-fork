
<?php

// define('FILES_BASE_URL','/Users/ale_miller10/Documentos/UNIV/UVOD/NewVideos');
define('FILES_BASE_URL','\\\\ENCODERGD\OTTHold');
define('ADMIN_API_URL', 'http://rjr-admin-api.univtec.com/index.php/');
//define('ADMIN_API_URL', 'http://localhost/uvod-admin-api/index.php/');
define('UVOD_ADMIN_API_USER', 'rjr_portal');
define('UVOD_ADMIN_API_PASSWORD', 'Univtec1@');
define('FTP_SERVER', 'filestrg10.streamgates.net');
define('FTP_USERNAME', 'rjr_admin');
define('FTP_PASSWORD', 'erwiuho7u44');

//Scan local directory to get the new files
$files = scandir(FILES_BASE_URL);

$allowed_files = ['mp4','mov','mkv','avi'];

// set up basic connection 
$conn_id = ftp_connect(FTP_SERVER); 
if($conn_id){

    // login with username and password 
    $login_result = ftp_login($conn_id, FTP_USERNAME, FTP_PASSWORD);
    
    if($login_result){

        ftp_pasv($conn_id, true);

        for ($i=0; $i < sizeof($files); $i++) { 

            $filename = fix_filename($files[$i]);
            $file_name = pathinfo($files[$i], PATHINFO_FILENAME);
            $file_extension = pathinfo($files[$i], PATHINFO_EXTENSION);
            $csv_file = FILES_BASE_URL . '/' . $files[$i] . '.csv';

            if(in_array($file_extension, $allowed_files) && file_exists($csv_file)){

                $is_transfering = check_transfering($filename);
                
                if(!$is_transfering){

                    $transfering_text = $filename . PHP_EOL;
                    file_put_contents(FILES_BASE_URL.'/transfering.txt', $transfering_text, FILE_APPEND);
                    
                    // upload a file 
                    $path = FILES_BASE_URL . '/' . $files[$i];

                    $filesize = filesize($path);

                    error_log('size: ' . $filesize);

                    if (ftp_put($conn_id, $filename, $path, FTP_BINARY)) { 

                        update_tranfering_list($filename);

                        //Process the CSV File to get the metadata
                        $info = process_csv_file($csv_file);
                    
                        //Ingest file into OTT system
                        $ingest = ingest_file($filename, $info);

                        //Delete file from local directory
                        if (!isset($ingest->error) || $ingest->error != 1) {
                            $text = date('Y/m/d H:i',time()) . ' - ' . $filename . PHP_EOL;
                            file_put_contents(FILES_BASE_URL.'/upload_log.txt', $text, FILE_APPEND);
                            delete_files($files[$i]);
                        }else{
                            $text = date('Y/m/d H:i',time()) . ' - ' . $filename . ' - There was a problem while ingesting'. PHP_EOL;
                            file_put_contents(FILES_BASE_URL.'/upload_error_log.txt', $text, FILE_APPEND);  
                        }   

                    } else { 
                        update_tranfering_list($filename);
                        $text = date('Y/m/d H:i',time()) . ' - ' . $filename . ' - There was a problem while uploading'. PHP_EOL;
                        file_put_contents(FILES_BASE_URL.'/upload_error_log.txt', $text, FILE_APPEND);

                    } 

                    // $ret = ftp_nb_put($conn_id, $filename, $path, FTP_BINARY);
                    // while ($ret == FTP_MOREDATA) {
                    
                    //    // Haga lo que quiera
                    //   error_log('El ret: '. $ret . ' El size: '.ftp_size($conn_id, $path));
                    
                    //    // Continuar la carga...
                    //    $ret = ftp_nb_continue($conn_id);
                    //    sleep(20);
                    // }
                    // if ($ret != FTP_FINISHED) {
                    //    error_log("Hubo un error al cargar el archivo...");
                    //    exit(1);
                    // }

                }
            }else{
                if(in_array($file_extension, $allowed_files) && !file_exists($csv_file)){ 
                    $text = date('Y/m/d H:i',time()) . ' - ' . $filename . ' - The CSV file doesnt exist'. PHP_EOL;
                    file_put_contents(FILES_BASE_URL.'/upload_error_log.txt', $text, FILE_APPEND);
                }
            }
            
        }
        
    // close the connection 
    ftp_close($conn_id);
    }

}

//Fix the file name
function fix_filename($filename){
    $new_name = str_replace(' ','_',$filename);
    return $new_name;
}

//Check if the file is tranfering
function check_transfering($filename){

    try {
        $is_transfering = false;
        if(file_exists(FILES_BASE_URL.'/transfering.txt')){

            $file = file(FILES_BASE_URL.'/transfering.txt');
          
            foreach($file as $line) {
                error_log('FILE: ' . $filename . ' LINE: '.$line);
                if(rtrim(str_replace(' ','',$line)) == rtrim(str_replace(' ','',$filename))) {
                    error_log('SON IGUALES');
                    $is_transfering = true;
                    break;
                }else{
                    error_log('NO SON IGUALES');
                }
            }
        }
        return $is_transfering;
    } catch (Exception $e) {
        throw new Exception('Cannot open the file');
    }
}

//Delete filename from transfering list
function update_tranfering_list($file){
    
    try {

        if(file_exists(FILES_BASE_URL.'/transfering.txt')){

            $data = file(FILES_BASE_URL.'/transfering.txt');
            
            $out = array();

            foreach($data as $line) {
                if(trim($line) != trim($file)) {
                    $out[] = $line;
                }
            }
           
            $fp = fopen(FILES_BASE_URL.'/transfering.txt', "w+");
            flock($fp, LOCK_EX);
            if(sizeof($out) > 0){
                foreach($out as $line) {
                    fwrite($fp, $line);
                }
            }else{
                fwrite($fp, ''); 
            }
            flock($fp, LOCK_UN);
            fclose($fp);  
        }
    } catch (Exception $e) {
        throw new Exception('Cannot open the file');
    }
}

//Process CSV file to get the metadata
function process_csv_file($url){

        try {
            $file = fopen($url, 'r');
            $result = array();
            $i = 0;

            $delimiter = find_delimiter($file);
            setlocale(LC_CTYPE, "UTF-8");
            
            $size = filesize($url);
            $file_str = fgets($file,intval($size));

            $result = explode($delimiter, $file_str);
            fclose($file);

        } catch (Exception $e) {
            throw new Exception('Cannot open the file');
        }

        $title = htmlentities("<title>".$result[0]."</title>\n");
        $aired_date = strtotime($result[3]) . '000';
        $description = htmlentities("<description>".$result[2]."</description>\n");
        $aired_date_str = htmlentities("<pl1:aired_date>$aired_date</pl1:aired_date>\n");
        $duration = htmlentities("<pl1:runtime>" . $result[1]. "</pl1:runtime>\n");
        $custom = $title . $description . $aired_date_str . $duration;

        return $custom;
}

//Admin Login to OTT system
function admin_login(){

    $url = ADMIN_API_URL . 'user/login_admin';

    $parameters = array();
    $parameters['username'] = 'rjr.admin';
    $parameters['password'] = 'Univtec1@';

    $parameters_str = json_encode($parameters);

    $curl_handle = curl_init();
    curl_setopt($curl_handle, CURLOPT_URL, $url);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl_handle, CURLOPT_USERPWD, UVOD_ADMIN_API_USER . ':' . UVOD_ADMIN_API_PASSWORD);
    curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($parameters_str)));
    curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $parameters_str);

    $buffer = curl_exec($curl_handle);
    curl_close($curl_handle);

    $response = json_decode($buffer);

    return $response;
}

//Ingest file function
function ingest_file($file, $data){

    $login = admin_login();

    $url = ADMIN_API_URL . 'ingest/ingest_files';

    $parameters = array();
    $parameters['token'] = $login->content->token;
    $parameters['ftp_user'] = FTP_USERNAME;
    $parameters['ftp_password'] = FTP_PASSWORD;
    $parameters['files'] = $file;
    $parameters['custom'] = $data;

    $parameters_str = json_encode($parameters);

    $curl_handle = curl_init();
    curl_setopt($curl_handle, CURLOPT_URL, $url);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl_handle, CURLOPT_USERPWD, UVOD_ADMIN_API_USER . ':' . UVOD_ADMIN_API_PASSWORD);
    curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($parameters_str)));
    curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $parameters_str);

    $buffer = curl_exec($curl_handle);
    curl_close($curl_handle);

    $response = json_decode($buffer);

    return $response;
}

//Delete file from local directory
function delete_files($file){

    $file_name = pathinfo($file, PATHINFO_FILENAME);
    $video_path = FILES_BASE_URL . '/' . $file;
    $csv_path = FILES_BASE_URL . '/' .$file_name . '.csv';

    unlink($video_path);
    unlink($csv_path);
}

//Find the delimiter into a CSV file
function find_delimiter($file)
{
    $delimiters = array(
        ';' => 0,
        ',' => 0,
        "\t" => 0,
        "|" => 0
    );

    $firstLine = fgets($file);
    foreach ($delimiters as $delimiter => &$count) {
        $count = count(str_getcsv($firstLine, $delimiter));
    }
    rewind($file);
    return array_search(max($delimiters), $delimiters);
}


?>