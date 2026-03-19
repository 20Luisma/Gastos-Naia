<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
$mail->SMTPDebug = 3;
try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['IMAP_USER'];
    $mail->Password   = $_ENV['IMAP_PASS'];
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->setFrom($_ENV['IMAP_USER'], 'Universo Naia');
    $mail->addAddress('martinpallante@gmail.com');
    $mail->Subject = 'Test';
    $mail->Body    = 'Test';
    $mail->send();
    echo "\n[RESULT] OK\n";
} catch (Exception $e) {
    echo "\n[RESULT] ERROR: {$mail->ErrorInfo}\n";
}
