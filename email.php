<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Email {
	public $from;
	public $subject;
	public $body;
	public $signature;
	private $to = array();
	private $cc = array();
	private $bcc = array();
	private $reply_to = array();
	private $headers = array();
	private $gpg_sign = true;

	public function __construct() {
		global $config;
		$this->from = array('email' => $config['email']['from_address'], 'name' => $config['email']['from_name']);
		$this->signature = $config['web']['baseurl']."\nYour friendly SSH key management system";
	}

	public function add_recipient($email, ?string $name = null) {
		$this->to[] = array('email' => $email, 'name' => $name);
	}

	public function add_cc($email, ?string $name = null) {
		$this->cc[] = array('email' => $email, 'name' => $name);
	}

	public function add_bcc($email, ?string $name = null) {
		$this->bcc[] = array('email' => $email, 'name' => $name);
	}

	public function add_reply_to($email, ?string $name = null) {
		$this->reply_to[] = array('email' => $email, 'name' => $name);
	}

	public function set_from($email, ?string $name = null) {
		$this->from = array('email' => $email, 'name' => $name);
		$this->gpg_sign = false;
	}

	public function send() {
		global $config;
		if(!empty($config['email']['reroute'])) {
			$rcpt_summary = '';
			foreach(array('to', 'cc', 'bcc') as $rcpt_type) {
				if(count($this->$rcpt_type) > 0) {
					$rcpt_summary .= ucfirst($rcpt_type).":\n";
					foreach($this->$rcpt_type as $rcpt) {
						if(is_null($rcpt['name'])) {
							$rcpt_summary .= " $rcpt[email]\n";
						} else {
							$rcpt_summary .= " $rcpt[name] <$rcpt[email]>\n";
						}
					}
				}
			}
			$this->body = $rcpt_summary."\n".$this->body;
			$this->to = array(array('email' => $config['email']['reroute'], 'name' => null));
			$this->cc = array();
			$this->bcc = array();
		}

		// Headers for auto-generated emails
		$this->headers[] = "Auto-Submitted: auto-generated";
		$this->headers[] = "Precedence: bulk";
		$this->flow();
		$this->append_signature();
		if(function_exists('gnupg_init') && $this->gpg_sign && isset($config['gpg']['key_id'])) {
			$this->sign();
		}

		if(!empty($config['email']['enabled'])) {
			try {
				$mailer = new PHPMailer(true);

				// SMTP Configuration (Default: localhost:25 for backward compatibility)
				$mailer->isSMTP();
				$mailer->Host = $config['email']['smtp_host'] ?? 'localhost';
				$mailer->Port = $config['email']['smtp_port'] ?? 25;

				if(!empty($config['email']['smtp_auth'])) {
					$mailer->SMTPAuth = true;
					$mailer->Username = $config['email']['smtp_username'];
					$mailer->Password = $config['email']['smtp_password'];
				}

				if(!empty($config['email']['smtp_encryption'])) {
					$mailer->SMTPSecure = $config['email']['smtp_encryption']; // 'tls' oder 'ssl'
				}

				if(!empty($config['email']['smtp_debug'])) {
					$mailer->SMTPDebug = $config['email']['smtp_debug']; // 0-4
				}

				// PHPMailer Encoding and Charset
				$mailer->CharSet = PHPMailer::CHARSET_UTF8;
				$mailer->Encoding = '8bit';

				// Adjust XMailer header (default is "PHPMailer X.X.X")
				$mailer->XMailer = 'SSH Key Authority';

				// Message-ID with hostname from baseurl (prevents MID_BARE_IP spam score)
				if(!empty($config['web']['baseurl'])) {
					$parsed = parse_url($config['web']['baseurl']);
					if(!empty($parsed['host'])) {
						$mailer->Hostname = $parsed['host'];
					}
				}

				// From
				$mailer->setFrom($this->from['email'], $this->from['name'] ?? '');

				// To
				foreach($this->to as $rcpt) {
					if(!empty($rcpt['email']) && filter_var($rcpt['email'], FILTER_VALIDATE_EMAIL)) {
						$mailer->addAddress($rcpt['email'], $rcpt['name'] ?? '');
					}
				}

				// CC
				foreach($this->cc as $rcpt) {
					if(!empty($rcpt['email']) && filter_var($rcpt['email'], FILTER_VALIDATE_EMAIL)) {
						$mailer->addCC($rcpt['email'], $rcpt['name'] ?? '');
					}
				}

				// BCC
				foreach($this->bcc as $rcpt) {
					if(!empty($rcpt['email']) && filter_var($rcpt['email'], FILTER_VALIDATE_EMAIL)) {
						$mailer->addBCC($rcpt['email'], $rcpt['name'] ?? '');
					}
				}

				// Reply-To
				foreach($this->reply_to as $rcpt) {
					if(!empty($rcpt['email']) && filter_var($rcpt['email'], FILTER_VALIDATE_EMAIL)) {
						$mailer->addReplyTo($rcpt['email'], $rcpt['name'] ?? '');
					}
				}

				// Custom Headers (filter out MIME/Content-Type headers as PHPMailer sets these)
				foreach($this->headers as $header) {
					// Skip headers that PHPMailer already sets
					if(preg_match('/^(MIME-Version|Content-Type|Content-Transfer-Encoding):/i', $header)) {
						continue;
					}
					if(preg_match('/^([^:]+):\s*(.*)$/', $header, $matches)) {
						$mailer->addCustomHeader($matches[1], $matches[2]);
					}
				}

				$mailer->Subject = $this->subject;
				$mailer->Body = $this->body;

				// Content-Type for format=flowed explicitly set
				$mailer->ContentType = 'text/plain; format=flowed';

				$mailer->send();
			} catch (Exception $e) {
				error_log("Email could not be sent. Error: {$mailer->ErrorInfo}");
				throw new RuntimeException("Email sending failed: {$mailer->ErrorInfo}");
			}
		}
	}

	private function flow() {
		$message = $this->body;
		/* Excerpt from RFC 3676 - 4.2.  Generating Format=Flowed

			A generating agent SHOULD:

			o Ensure all lines (fixed and flowed) are 78 characters or fewer in
			  length, counting any trailing space as well as a space added as
			  stuffing, but not counting the CRLF, unless a word by itself
			  exceeds 78 characters.

			o Trim spaces before user-inserted hard line breaks.

			A generating agent MUST:

			o Space-stuff lines which start with a space, "From ", or ">".

		*/
		// Trimming spaces before user-inserted hard line breaks, and wrapping.
		$lines = explode("\n", $message);
		foreach($lines as $ref => $line) {
			$lines[$ref] = wordwrap(rtrim($line), 76, " \n", false);
		}
		$message = implode("\n", $lines);
		// Space-stuffing lines which start with a space, "From ", or ">".
		$lines = explode("\n", $message);
		foreach($lines as $ref => $line) {
			if(strpos($line, " ") === 0 || strpos($line, "From ") === 0 || strpos($line, ">") === 0) $lines[$ref] = " ".$line;
		}
		$message = implode("\n", $lines);

		$message = "$message\n\n";
		$this->body = $message;
	}

	private function header_7bit_safe($string, $indent = 0) {
		// PHPMailer handles encoding automatically, this function is only needed for GPG signing
		if(is_null($string)) return null;
		return mb_encode_mimeheader($string, 'UTF-8', 'Q', "\n", $indent);
	}

	private function append_signature() {
		//Add a signature
		$this->body .= "-- \n";
		$this->body .= $this->signature;
	}

	private function sign() {
		$localheaders = array();
		foreach($this->headers as $k => $v) {
			if(preg_match('/^Content-Type:/i', $v)) {
				$localheaders[] = $v;
				unset($this->headers[$k]);
			}
		}
		$localheaders[] = "Content-Transfer-Encoding: quoted-printable";
		$lines = explode("\n", $this->body);
		foreach($lines as $ref => $line) {
			$line = quoted_printable_encode($line);
			if(substr($line, -1) == ' ') $line = substr($line, 0, -1).'=20';
			$lines[$ref] = $line;
		}
		$boundary = uniqid(php_uname('n'));
		$innerboundary = uniqid(php_uname('n').'1');
		$this->headers[] = 'Content-Type: multipart/signed; micalg=pgp-sha1; protocol="application/pgp-signature"; boundary="'.$boundary.'"';
		$message = "Content-Type: multipart/mixed; boundary=\"{$innerboundary}\";\r\n";
		$message .= " protected-headers=\"v1\"\r\n";
		$message .= "From: {$this->from['email']}\r\n";
		foreach(array('to', 'cc') as $rcpt_type) {
			foreach($this->$rcpt_type as $rcpt) {
				if(is_null($rcpt['name'])) {
					$message .= ucfirst($rcpt_type).": $rcpt[email]\r\n";
				} else {
					$message .= ucfirst($rcpt_type).": ".$this->header_7bit_safe($rcpt['name'], strlen($rcpt_type) + 2)." <$rcpt[email]>\r\n";
				}
			}
		}
		$message .= "Subject: ".$this->header_7bit_safe($this->subject, 9)."\r\n\r\n";
		$message .= "--{$innerboundary}\r\n".implode("\r\n", $localheaders)."\r\n\r\n".implode("\r\n", $lines)."\r\n--{$innerboundary}--\r\n";
		$signature = $this->get_gpg_signature($message);
		$message = "This is an OpenPGP/MIME signed message (RFC 4880 and 3156)\r\n--{$boundary}\r\n{$message}\r\n--{$boundary}\r\n";
		$message .= "Content-Type: application/pgp-signature; name=\"signature.asc\"\r\n";
		$message .= "Content-Description: OpenPGP digital signature\r\n";
		$message .= "Content-Disposition: attachment; filename=\"signature.asc\"\r\n\r\n";
		$message .= $signature;
		$message .= "\r\n--$boundary--";
		$this->body = $message;
	}

	private function get_gpg_signature($message) {
		global $config;
		$gpg = new gnupg();
		$gpg->addsignkey($config['gpg']['key_id']);
		$gpg->setsignmode(GNUPG::SIG_MODE_DETACH);
		return $gpg->sign($message);
	}
}