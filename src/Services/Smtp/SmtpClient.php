<?php

namespace NexaPressMail\Services\Smtp;

use NexaPressMail\Services\Security\HeaderInjectionGuard;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class SmtpClient
{
    public function send(array $account, array $message, array $attachments = []): array
    {
        HeaderInjectionGuard::assertSafe($message['subject'], $account['email'], $account['name']);
        $mail = $this->configured($account);
        $mail->setFrom($account['email'], $account['name']);
        foreach (['to'=>'addAddress','cc'=>'addCC','bcc'=>'addBCC'] as $type => $method) {
            foreach ($message[$type] ?? [] as $address) { $mail->{$method}($address); }
        }
        $mail->Subject = $message['subject'];
        $mail->Body = $message['body_html'] ?: nl2br(htmlspecialchars($message['body_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $mail->AltBody = $message['body_text'];
        if (!empty($message['in_reply_to'])) {
            HeaderInjectionGuard::assertSafe($message['in_reply_to']);
            $mail->addCustomHeader('In-Reply-To', $message['in_reply_to']);
            $mail->addCustomHeader('References', $message['in_reply_to']);
        }
        foreach ($attachments as $file) { $mail->addAttachment($file['path'], $file['name'], PHPMailer::ENCODING_BASE64, $file['mime']); }
        if (!$mail->send()) { throw new RuntimeException('SMTP送信に失敗しました: ' . $mail->ErrorInfo); }
        return ['message_id'=>trim($mail->getLastMessageID(), '<>'), 'mime'=>$mail->getSentMIMEMessage()];
    }

    public function test(array $account): void
    {
        $mail = $this->configured($account);
        if (!$mail->smtpConnect()) { throw new RuntimeException('SMTPへ接続できません: ' . $mail->ErrorInfo); }
        $mail->smtpClose();
    }

    private function configured(array $account): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $account['smtp_host'];
        $mail->Port = (int) $account['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->Username = $account['smtp_username'];
        $mail->Password = $account['smtp_password'];
        $security = $account['smtp_security'];
        $mail->SMTPSecure = $security === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS
            : ($security === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
        $mail->Timeout = 20;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        return $mail;
    }
}
