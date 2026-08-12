<?php

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Gestisce l'invio di notifiche (email, etc.)
 */
class NotificationManager
{
    private array $config;
    private Logger $logger;

    public function __construct(array $config)
    {
        $this->config = $config;

        // Setup logger
        $this->logger = new Logger('notifications');
        $logFile = $config['logging']['path'] ?? __DIR__ . '/../../storage/logs/notifications.log';
        $this->logger->pushHandler(new StreamHandler($logFile, Logger::INFO));
    }

    /**
     * Invia notifica per voto pubblicato
     *
     * @param array $gradeData Dati del voto pubblicato
     * @param array $studentInfo Informazioni studente
     * @return bool True se inviato con successo
     */
    public function sendGradePublishedNotification(array $gradeData, array $studentInfo): bool
    {
        // Controlla se email è abilitata
        if (!($this->config['notifications']['email']['enabled'] ?? false)) {
            $this->logger->info('Email notifications disabled, skipping grade notification');
            return false;
        }

        try {
            $mail = $this->createMailer();

            // Destinatario (email dal config o email dello studente/genitori)
            $recipientEmail = $this->getRecipientEmail($studentInfo);

            if (empty($recipientEmail)) {
                $this->logger->warning('No recipient email found for student', [
                    'student_id' => $studentInfo['id'] ?? 'unknown'
                ]);
                return false;
            }

            $mail->addAddress($recipientEmail);

            // Oggetto
            $subject = "Nuovo voto pubblicato - {$gradeData['subject_name']}";
            $mail->Subject = $subject;

            // Corpo email
            $htmlBody = $this->renderGradeEmailTemplate($gradeData, $studentInfo);
            $mail->Body = $htmlBody;
            $mail->AltBody = $this->htmlToPlainText($htmlBody);

            // Invia
            $result = $mail->send();

            if ($result) {
                $this->logger->info('Grade notification sent successfully', [
                    'student_id' => $studentInfo['id'] ?? 'unknown',
                    'grade_value' => $gradeData['grade_value'],
                    'subject' => $gradeData['subject_name']
                ]);
            }

            return $result;

        } catch (PHPMailerException $e) {
            $this->logger->error('Failed to send grade notification', [
                'error' => $e->getMessage(),
                'student_id' => $studentInfo['id'] ?? 'unknown'
            ]);
            return false;
        }
    }

    /**
     * Crea istanza PHPMailer configurata
     */
    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $emailConfig = $this->config['notifications']['email'];

        // Configurazione SMTP
        $mail->isSMTP();
        $mail->Host = $emailConfig['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $emailConfig['smtp_user'];
        $mail->Password = $emailConfig['smtp_password'];
        $mail->SMTPSecure = $emailConfig['smtp_encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $emailConfig['smtp_port'];

        // Mittente
        $mail->setFrom(
            $emailConfig['from_address'],
            $emailConfig['from_name']
        );

        // HTML
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        return $mail;
    }

    /**
     * Ottiene email destinatario dalle info studente
     */
    private function getRecipientEmail(array $studentInfo): ?string
    {
        // Priorità:
        // 1. Email genitori (se presente)
        // 2. Email studente (se presente)
        // 3. Email di default dal config (per testing)

        if (!empty($studentInfo['email_genitori'])) {
            return $studentInfo['email_genitori'];
        }

        if (!empty($studentInfo['email'])) {
            return $studentInfo['email'];
        }

        // Email di test/default dal config
        return $this->config['notifications']['email']['test_recipient'] ?? null;
    }

    /**
     * Renderizza template email per voto pubblicato
     */
    private function renderGradeEmailTemplate(array $gradeData, array $studentInfo): string
    {
        // Cerca template personalizzato
        $templatePath = $this->config['notifications']['email']['templates']['grade_published'] ?? null;

        if ($templatePath && file_exists(__DIR__ . '/../../' . $templatePath)) {
            $template = file_get_contents(__DIR__ . '/../../' . $templatePath);

            // Sostituisci variabili
            $template = str_replace('{{student_name}}', $this->getStudentFullName($studentInfo), $template);
            $template = str_replace('{{subject}}', $gradeData['subject_name'], $template);
            $template = str_replace('{{grade_value}}', $gradeData['grade_value'], $template);
            $template = str_replace('{{grade_type}}', ucfirst($gradeData['grade_type']), $template);
            $template = str_replace('{{date}}', $this->formatDate($gradeData['date']), $template);
            $template = str_replace('{{notes}}', $gradeData['notes'] ?? '', $template);

            return $template;
        }

        // Template di default se non esiste file personalizzato
        return $this->getDefaultGradeEmailTemplate($gradeData, $studentInfo);
    }

    /**
     * Template email di default per voto
     */
    private function getDefaultGradeEmailTemplate(array $gradeData, array $studentInfo): string
    {
        $studentName = $this->getStudentFullName($studentInfo);
        $subject = htmlspecialchars($gradeData['subject_name']);
        $gradeValue = htmlspecialchars($gradeData['grade_value']);
        $gradeType = htmlspecialchars(ucfirst($gradeData['grade_type']));
        $date = $this->formatDate($gradeData['date']);
        $notes = !empty($gradeData['notes']) ? htmlspecialchars($gradeData['notes']) : 'Nessuna nota';

        return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuovo Voto Pubblicato</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }
        .content {
            background: #f9f9f9;
            padding: 30px;
            border: 1px solid #ddd;
            border-radius: 0 0 10px 10px;
        }
        .grade-box {
            background: white;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .grade-value {
            font-size: 48px;
            font-weight: bold;
            color: #667eea;
            text-align: center;
            margin: 10px 0;
        }
        .info-row {
            margin: 10px 0;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .info-label {
            font-weight: bold;
            color: #666;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📚 Nuovo Voto Pubblicato</h1>
    </div>
    <div class="content">
        <p>Voto pubblicato per <strong>$studentName</strong> sul registro elettronico:</p>

        <div class="grade-box">
            <div class="grade-value">$gradeValue</div>

            <div class="info-row">
                <span class="info-label">Materia:</span> $subject
            </div>
            <div class="info-row">
                <span class="info-label">Tipo:</span> $gradeType
            </div>
            <div class="info-row">
                <span class="info-label">Data:</span> $date
            </div>
            <div class="info-row">
                <span class="info-label">Note:</span> $notes
            </div>
        </div>

        <p>Cordiali saluti,<br>
        <strong>Sistema UDA</strong></p>
    </div>
    <div class="footer">
        <p>Questa è una notifica automatica. Non rispondere a questa email.</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Converte HTML in testo semplice
     */
    private function htmlToPlainText(string $html): string
    {
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Ottiene nome completo studente
     */
    private function getStudentFullName(array $studentInfo): string
    {
        $nome = $studentInfo['nome'] ?? '';
        $cognome = $studentInfo['cognome'] ?? '';
        return trim("$nome $cognome");
    }

    /**
     * Formatta data in italiano
     */
    private function formatDate(string $date): string
    {
        try {
            $dt = new \DateTime($date);
            return $dt->format('d/m/Y');
        } catch (\Exception $e) {
            return $date;
        }
    }
}
