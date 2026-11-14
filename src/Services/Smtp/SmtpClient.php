<?php

namespace NexaPressMail\Services\Smtp;

use NexaPressMail\Services\Attachment\AttachmentFilename;
use NexaPressMail\Services\Attachment\AttachmentValidator;
use NexaPressMail\Services\Security\HeaderInjectionGuard;
use NexaPressMail\Services\Security\TransportSecurity;
use NexaPressMail\Services\OAuth\SmtpTokenProvider;
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
        $validator = new AttachmentValidator();
        foreach ($attachments as $file) {
            $name = AttachmentFilename::clean($file['name']);
            $mime = $validator->inspectFile($name, $file['path']);
            $mail->addAttachment($file['path'], $name, PHPMailer::ENCODING_BASE64, $mime);
        }
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
        $security = TransportSecurity::validate($account, 'smtp');
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $account['smtp_host'];
        $mail->Port = (int) $account['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->Username = $account['smtp_username'];
        $mail->Password = $account['smtp_password'];
        if (($account['auth_type'] ?? 'password') === 'oauth2') {
            if (empty($account['id'])) { throw new RuntimeException('OAuthアカウントを保存して接続してください。'); }
            $mail->AuthType = 'XOAUTH2';
            $mail->Password = '';
            $mail->setOAuth(new SmtpTokenProvider($account));
        }
        $mail->SMTPSecure = $security === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPOptions = ['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
        $mail->Timeout = 20;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        return $mail;
    }
}
