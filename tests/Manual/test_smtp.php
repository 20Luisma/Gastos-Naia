<?php
require 'vendor/autoload.php';
$_ENV['IMAP_USER'] = "martinpallante@gmail.com";
$_ENV['IMAP_PASS'] = "unyd iepu ohyx yruy";

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
    $mail->setFrom($_ENV['IMAP_USER'], 'Universo Naia Test');
    $mail->addAddress('martinpallante@gmail.com');
    $mail->Subject = 'Test';
    $mail->Body    = 'Test';
    $mail->send();
    echo "OK";
} catch (Exception $e) {
    echo "ERROR: {$mail->ErrorInfo}";
}
