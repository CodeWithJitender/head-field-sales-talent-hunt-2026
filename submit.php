<?php

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "Invalid request method."]);
    exit;
}

// ── Collect form data ──────────────────────────────────────────────
$name = htmlspecialchars($_POST['from_name'] ?? '');
$email = htmlspecialchars($_POST['from_email'] ?? '');
$phone = htmlspecialchars($_POST['phone'] ?? '');
$linkedin = htmlspecialchars($_POST['linked-in'] ?? '');
$source = htmlspecialchars($_POST['source'] ?? '');
$human_sum = htmlspecialchars($_POST['human_sum'] ?? '');

// Security check
if ($human_sum != 8) {
    echo json_encode(["success" => false, "error" => "Security verification failed."]);
    exit;
}

// ── SMTP Configuration ────────────────────────────────────────────
$smtpHost = 'smtp.gmail.com';
$smtpPort = 587;
$smtpUsername = 'jitender@digicots.com';
$smtpPassword = 'mfiw jgqq oacb lmcw';
$fromEmail = 'jitender@digicots.com';
$fromName = 'Career Form';
$toEmail = 'rimli@headfield.com';
$subject = 'New Career Application';

// ── Build HTML body for HR notification ────────────────────────────
$htmlBody = "
<h2>New Career Application</h2>
<table border='1' cellpadding='10' cellspacing='0'>
    <tr><td><strong>Name</strong></td><td>$name</td></tr>
    <tr><td><strong>Email</strong></td><td>$email</td></tr>
    <tr><td><strong>Phone</strong></td><td>$phone</td></tr>
    <tr><td><strong>LinkedIn</strong></td><td>$linkedin</td></tr>
    <tr><td><strong>Source</strong></td><td>$source</td></tr>
</table>
";

// ── Handle file attachment ─────────────────────────────────────────
$attachment = null;
if (isset($_FILES['my_file']) && $_FILES['my_file']['error'] == 0) {

    $allowed = ['pdf', 'doc', 'docx'];
    $fileExt = strtolower(pathinfo($_FILES['my_file']['name'], PATHINFO_EXTENSION));

    if (!in_array($fileExt, $allowed)) {
        echo json_encode(["success" => false, "error" => "Invalid file type. Only PDF, DOC, DOCX allowed."]);
        exit;
    }

    if ($_FILES['my_file']['size'] > 5 * 1024 * 1024) {
        echo json_encode(["success" => false, "error" => "File too large. Max 5MB."]);
        exit;
    }

    $attachment = [
        'name' => $_FILES['my_file']['name'],
        'tmp_name' => $_FILES['my_file']['tmp_name'],
        'type' => $_FILES['my_file']['type']
    ];
}

// ── 1) Send HR notification email ──────────────────────────────────
// TO: rimli@headfield.com | CC: nitin@glocalassist.com, jitender@digicots.com
$result = sendMailSMTP(
    $smtpHost,
    $smtpPort,
    $smtpUsername,
    $smtpPassword,
    $fromEmail,
    $fromName,
    $toEmail,
    $subject,
    $htmlBody,
    $email,
    $name,
    $attachment,
    ['nitin@glocalassist.com', 'jitender@digicots.com']
);

if ($result !== true) {
    echo json_encode(["success" => false, "error" => $result]);
    exit;
}

// ── 2) Send acknowledgment email to applicant ──────────────────────
$firstName = explode(' ', trim($name))[0]; // Extract first name

// Load the acknowledgment HTML template
$ackTemplate = file_get_contents(__DIR__ . '/acknowledgment.html');

// Replace the {{first_name}} placeholder with the applicant's first name
$ackBody = str_replace('{{first_name}}', $firstName, $ackTemplate);

// TO: Applicant's email | No CC needed
$ackResult = sendMailSMTP(
    $smtpHost,
    $smtpPort,
    $smtpUsername,
    $smtpPassword,
    $fromEmail,
    'Head Field',
    $email,                                     // Send to applicant
    'Thank You for Registering – Head Field Sales Talent Hunt 2026',
    $ackBody,
    '',
    '',                                     // no reply-to
    null,                                       // no attachment
    []                                          // no CC
);

if ($ackResult !== true) {
    // HR mail was sent but acknowledgment failed
    echo json_encode(["success" => true, "message" => "Application submitted, but acknowledgment email failed: $ackResult"]);
    exit;
}

echo json_encode(["success" => true, "message" => "Application submitted successfully."]);
exit;


// ════════════════════════════════════════════════════════════════════
//  Raw SMTP function – no external libraries needed
// ════════════════════════════════════════════════════════════════════
function sendMailSMTP($host, $port, $username, $password, $fromEmail, $fromName, $toEmail, $subject, $htmlBody, $replyToEmail = '', $replyToName = '', $attachment = null, $ccEmails = [])
{

    // ── Connect ────────────────────────────────────────────────────
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 30);

    if (!$socket) {
        return "Connection failed: $errstr ($errno)";
    }

    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '220') {
        fclose($socket);
        return "Unexpected greeting: $response";
    }

    // ── EHLO ───────────────────────────────────────────────────────
    $ehloResult = smtpCommand($socket, "EHLO localhost");
    if (!$ehloResult)
        return "EHLO failed.";

    // ── STARTTLS ───────────────────────────────────────────────────
    fwrite($socket, "STARTTLS\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '220') {
        fclose($socket);
        return "STARTTLS failed: $response";
    }

    // Upgrade to TLS
    $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
    if (!$crypto) {
        fclose($socket);
        return "TLS encryption failed.";
    }

    // ── EHLO again after TLS ───────────────────────────────────────
    $ehloResult = smtpCommand($socket, "EHLO localhost");
    if (!$ehloResult)
        return "EHLO after TLS failed.";

    // ── AUTH LOGIN ─────────────────────────────────────────────────
    fwrite($socket, "AUTH LOGIN\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '334') {
        fclose($socket);
        return "AUTH LOGIN failed: $response";
    }

    fwrite($socket, base64_encode($username) . "\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '334') {
        fclose($socket);
        return "Username rejected: $response";
    }

    fwrite($socket, base64_encode($password) . "\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '235') {
        fclose($socket);
        return "Authentication failed. Check your App Password.";
    }

    // ── MAIL FROM ──────────────────────────────────────────────────
    fwrite($socket, "MAIL FROM:<$fromEmail>\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '250') {
        fclose($socket);
        return "MAIL FROM rejected: $response";
    }

    // ── RCPT TO (primary recipient) ────────────────────────────────
    fwrite($socket, "RCPT TO:<$toEmail>\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '250') {
        fclose($socket);
        return "RCPT TO rejected: $response";
    }

    // ── RCPT TO (CC recipients) ────────────────────────────────────
    foreach ($ccEmails as $ccEmail) {
        fwrite($socket, "RCPT TO:<$ccEmail>\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '250') {
            fclose($socket);
            return "CC RCPT TO rejected for $ccEmail: $response";
        }
    }

    // ── DATA ───────────────────────────────────────────────────────
    fwrite($socket, "DATA\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '354') {
        fclose($socket);
        return "DATA command failed: $response";
    }

    // ── Build the email message ────────────────────────────────────
    $boundary = "----=_Boundary_" . md5(uniqid(time()));

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "From: $fromName <$fromEmail>\r\n";
    $headers .= "To: <$toEmail>\r\n";

    // Add CC header
    if (!empty($ccEmails)) {
        $headers .= "Cc: <" . implode(">, <", $ccEmails) . ">\r\n";
    }

    if ($replyToEmail) {
        $headers .= "Reply-To: $replyToName <$replyToEmail>\r\n";
    }

    $headers .= "Subject: $subject\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    if ($attachment) {
        // Multipart message with attachment
        $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        $headers .= "\r\n";

        $body = "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";

        // Attach file
        $fileContent = file_get_contents($attachment['tmp_name']);
        $fileBase64 = chunk_split(base64_encode($fileContent));
        $fileName = $attachment['name'];
        $fileType = $attachment['type'] ?: 'application/octet-stream';

        $body .= "--$boundary\r\n";
        $body .= "Content-Type: $fileType; name=\"$fileName\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"$fileName\"\r\n\r\n";
        $body .= $fileBase64 . "\r\n";
        $body .= "--$boundary--\r\n";
    } else {
        // Simple HTML message
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "\r\n";
        $body = $htmlBody . "\r\n";
    }

    // Send headers + body + terminator
    fwrite($socket, $headers . $body . "\r\n.\r\n");

    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '250') {
        fclose($socket);
        return "Message delivery failed: $response";
    }

    // ── QUIT ───────────────────────────────────────────────────────
    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}


// ════════════════════════════════════════════════════════════════════
//  Helper: send SMTP command and read multi-line response
// ════════════════════════════════════════════════════════════════════
function smtpCommand($socket, $command)
{
    fwrite($socket, $command . "\r\n");

    $response = '';
    while ($line = fgets($socket, 512)) {
        $response .= $line;
        // Last line of multi-line response has a space after the code (e.g., "250 OK")
        if (substr($line, 3, 1) === ' ')
            break;
    }

    return (substr($response, 0, 3) === '250') ? $response : false;
}

?>