<?php
$ftp_server = '82.29.185.22';
$ftp_user = 'u968396048';
$ftp_pass = 'Fp9!xW3#kL7@vR4$zN6*mQ2';
$ftp_conn = ftp_connect($ftp_server) or die("Could not connect to $ftp_server");
$login = ftp_login($ftp_conn, $ftp_user, $ftp_pass);
ftp_pasv($ftp_conn, true);
if (ftp_put($ftp_conn, '/domains/contenido.creawebes.com/public_html/test_drive_folders.php', __DIR__ . '/test_drive_folders.php', FTP_BINARY)) {
    echo "Successfully uploaded test_drive_folders.php\n";
} else {
    echo "There was a problem while uploading\n";
}
ftp_close($ftp_conn);
