<?php
/**
 * Simple SMTP Mailer Class
 * Sends emails via Gmail SMTP without external dependencies
 */

class SMTPMailer {
    private $host;
    private $port;
    private $username;
    private $password;
    private $socket;
    private $timeout = 30;
    private $debug = false;
    private $lastError = '';

    public function __construct($host, $port, $username, $password) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    public function setDebug($debug) {
        $this->debug = $debug;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function send($from, $fromName, $to, $subject, $htmlBody, $textBody = '', $replyTo = '', $replyToName = '') {
        try {
            // Connect to SMTP server
            $this->socket = @fsockopen('tls://' . $this->host, $this->port, $errno, $errstr, $this->timeout);

            if (!$this->socket) {
                // Try without TLS wrapper (STARTTLS)
                $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
                if (!$this->socket) {
                    $this->lastError = "Failed to connect: $errstr ($errno)";
                    return false;
                }

                stream_set_timeout($this->socket, $this->timeout);

                // Read greeting
                $this->getResponse();

                // Send EHLO
                $this->sendCommand("EHLO " . gethostname());

                // Start TLS
                $this->sendCommand("STARTTLS");

                // Enable crypto
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->lastError = "Failed to enable TLS";
                    fclose($this->socket);
                    return false;
                }

                // Send EHLO again after TLS
                $this->sendCommand("EHLO " . gethostname());
            } else {
                stream_set_timeout($this->socket, $this->timeout);

                // Read greeting
                $this->getResponse();

                // Send EHLO
                $this->sendCommand("EHLO " . gethostname());
            }

            // Authenticate
            $this->sendCommand("AUTH LOGIN");
            $this->sendCommand(base64_encode($this->username));
            $this->sendCommand(base64_encode($this->password));

            // Set sender
            $this->sendCommand("MAIL FROM:<{$from}>");

            // Set recipient
            $this->sendCommand("RCPT TO:<{$to}>");

            // Send data
            $this->sendCommand("DATA");

            // Build message
            $boundary = md5(uniqid(time()));
            $headers = $this->buildHeaders($from, $fromName, $to, $subject, $boundary, $replyTo, $replyToName);
            $body = $this->buildBody($htmlBody, $textBody, $boundary);

            // Send message content
            fputs($this->socket, $headers . "\r\n" . $body . "\r\n.\r\n");
            $response = $this->getResponse();

            if (strpos($response, '250') !== 0) {
                $this->lastError = "Failed to send message: $response";
                return false;
            }

            // Quit
            $this->sendCommand("QUIT");

            fclose($this->socket);

            return true;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            if ($this->socket) {
                fclose($this->socket);
            }
            return false;
        }
    }

    private function sendCommand($command) {
        fputs($this->socket, $command . "\r\n");
        if ($this->debug) {
            error_log("SMTP C: $command");
        }
        return $this->getResponse();
    }

    private function getResponse() {
        $response = '';
        while ($line = fgets($this->socket, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') {
                break;
            }
        }
        if ($this->debug) {
            error_log("SMTP S: $response");
        }
        return $response;
    }

    private function buildHeaders($from, $fromName, $to, $subject, $boundary, $replyTo, $replyToName) {
        $headers = [];
        $headers[] = "Date: " . date('r');
        $headers[] = "From: " . $this->encodeHeader($fromName) . " <{$from}>";
        $headers[] = "To: <{$to}>";
        $headers[] = "Subject: " . $this->encodeHeader($subject);

        if ($replyTo) {
            $headers[] = "Reply-To: " . ($replyToName ? $this->encodeHeader($replyToName) . " <{$replyTo}>" : $replyTo);
        }

        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $headers[] = "X-Mailer: PHP/" . phpversion();

        return implode("\r\n", $headers);
    }

    private function buildBody($htmlBody, $textBody, $boundary) {
        $body = [];

        // Plain text part
        $body[] = "--{$boundary}";
        $body[] = "Content-Type: text/plain; charset=UTF-8";
        $body[] = "Content-Transfer-Encoding: base64";
        $body[] = "";
        $body[] = chunk_split(base64_encode($textBody ?: strip_tags($htmlBody)));

        // HTML part
        $body[] = "--{$boundary}";
        $body[] = "Content-Type: text/html; charset=UTF-8";
        $body[] = "Content-Transfer-Encoding: base64";
        $body[] = "";
        $body[] = chunk_split(base64_encode($htmlBody));

        // End boundary
        $body[] = "--{$boundary}--";

        return implode("\r\n", $body);
    }

    private function encodeHeader($string) {
        if (preg_match('/[^\x20-\x7E]/', $string)) {
            return '=?UTF-8?B?' . base64_encode($string) . '?=';
        }
        return $string;
    }
}
?>
