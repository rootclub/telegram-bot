<?php
/**
 * QBert Client PHP - Client per il gateway QBert
 *
 * Uso:
 *   $qbert = new QBertClient('https://qbert.example.com', appName: 'my_chatbot');
 *
 *   // Richiesta sincrona (blocca finché non arriva risposta)
 *   $response = $qbert->post('ollama', '/api/generate', ['model' => 'llama3', 'prompt' => 'ciao']);
 *
 *   // Con nota per il log
 *   $response = $qbert->post('ollama', '/api/generate', ['model' => 'llama3', 'prompt' => 'ciao'], note: 'user_chat');
 *
 *   // Con webhook callback (QBert chiamerà l'URL quando il job è completato)
 *   $result = $qbert->submit('ollama', '/api/generate',
 *       json: ['model' => 'llama3', 'prompt' => 'ciao'],
 *       callbackUrl: 'https://myapp.com/qbert-callback'
 *   );
 *   // QBert chiamerà https://myapp.com/qbert-callback con il risultato
 *
 *   // Solo submit senza callback (per polling manuale)
 *   $result = $qbert->submit('ollama', '/api/generate', ['model' => 'llama3', 'prompt' => 'ciao']);
 *   if ($result['is_ticket']) {
 *       // Polling manuale con $qbert->poll($result['ticket_id'])
 *   }
 *
 *   // Richiesta multipart/form-data (es. voice clone con file audio)
 *   $response = $qbert->post('qwen-tts', '/voice_clone', multipart: [
 *       'text' => 'Ciao mondo',
 *       'language' => 'it',
 *       'audio' => new CURLFile('/path/to/sample.wav', 'audio/wav'),
 *   ]);
 */

class QBertClient {
	private string $baseUrl;
	private float $timeout;
	private float $pollInterval;
	private float $maxWait;
	private string $appName;

	const PRIORITY_URGENT = 'urgent';
	const PRIORITY_NORMAL = 'normal';
	const PRIORITY_LAZY = 'lazy';

	public function __construct(
		string $baseUrl = 'http://127.0.0.1:1999',
		float $timeout = 35.0,
		float $pollInterval = 2.0,
		float $maxWait = 600.0,
		?string $appName = null
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->timeout = $timeout;
		$this->pollInterval = $pollInterval;
		$this->maxWait = $maxWait;
		if ($appName !== null && $appName !== '') {
			$this->appName = $appName;
		} else {
			$this->appName = 'anonymous-' . getmypid();
			trigger_error(
				"QBertClient: appName non specificato, registrato come '{$this->appName}'. " .
				"Usa new QBertClient(appName: 'my_app') per identificare il client nei log.",
				E_USER_WARNING
			);
		}
	}

	/**
	 * Invia richiesta e aspetta il risultato (gestisce ticket automaticamente)
	 * ATTENZIONE: può bloccare per molto tempo, usare solo in script CLI/worker
	 *
	 * @param array|null $json Body JSON
	 * @param array|null $multipart Campi multipart/form-data. Valori possono essere:
	 *   - string/int/float: campo form normale
	 *   - CURLFile: file upload (es. new CURLFile('/path/to/file.wav', 'audio/wav'))
	 */
	public function request(
		string $method,
		string $service,
		string $path,
		?array $json = null,
		string $priority = self::PRIORITY_NORMAL,
		array $headers = [],
		string $note = '',
		?array $multipart = null
	): array {
		$result = $this->submit($method, $service, $path, $json, $priority, $headers, note: $note, multipart: $multipart);

		if (!$result['is_ticket']) {
			return $result;
		}

		return $this->waitForTicket($result['ticket_id']);
	}

	public function get(string $service, string $path, string $priority = self::PRIORITY_NORMAL, string $note = ''): array {
		return $this->request('GET', $service, $path, null, $priority, note: $note);
	}

	public function post(string $service, string $path, ?array $json = null, string $priority = self::PRIORITY_NORMAL, string $note = '', ?array $multipart = null): array {
		return $this->request('POST', $service, $path, $json, $priority, note: $note, multipart: $multipart);
	}

	/**
	 * Invia richiesta senza aspettare - ritorna subito
	 *
	 * Se callbackUrl è specificato, QBert chiamerà quell'URL quando il job è completato.
	 * Altrimenti, usare poll() per verificare lo stato del ticket.
	 */
	public function submit(
		string $method,
		string $service,
		string $path,
		?array $json = null,
		string $priority = self::PRIORITY_NORMAL,
		array $headers = [],
		?string $callbackUrl = null,
		string $note = '',
		?array $multipart = null
	): array {
		if ($json !== null && $multipart !== null) {
			throw new QBertException('Cannot use both json and multipart in the same request');
		}

		$url = $this->baseUrl . '/' . $service . '/' . ltrim($path, '/');
		$headers['X-Priority'] = $priority;
		$headers['X-App-Name'] = $this->appName;
		if ($note !== '') {
			$headers['X-Note'] = $note;
		}
		if ($callbackUrl !== null) {
			$headers['X-Callback-Url'] = $callbackUrl;
		}

		$response = $this->httpRequest($method, $url, $json, $headers, $multipart);

		if ($response['status_code'] === 202) {
			$data = json_decode($response['body'], true);
			return [
				'is_ticket' => true,
				'ticket_id' => $data['ticket_id'],
				'status' => $data['status'] ?? 'queued',
				'poll_url' => $data['poll_url'] ?? null,
				'callback_url' => $data['callback_url'] ?? null,
			];
		}

		return [
			'is_ticket' => false,
			'status_code' => $response['status_code'],
			'headers' => $response['headers'],
			'body' => $response['body'],
			'body_bytes' => $response['body'],
			'json' => $this->tryJsonDecode($response['body']),
		];
	}

	/**
	 * Polling singolo su un ticket
	 */
	public function poll(string $ticketId): array {
		$url = $this->baseUrl . '/ticket/' . $ticketId;
		$response = $this->httpRequest('GET', $url);

		if ($response['status_code'] === 404) {
			return [
				'found' => false,
				'ticket_id' => $ticketId,
				'error' => 'Ticket not found',
			];
		}

		$data = json_decode($response['body'], true);
		$status = $data['status'] ?? 'unknown';

		$result = [
			'found' => true,
			'ticket_id' => $ticketId,
			'status' => $status,
			'done' => $status === 'done',
			'failed' => in_array($status, ['failed', 'abandoned']),
			'queue_position' => $data['queue_position'] ?? null,
			'priority' => $data['priority'] ?? null,
			'interrupt_count' => $data['interrupt_count'] ?? 0,
		];

		if ($status === 'done') {
			$inner = $data['response'] ?? [];
			$bodyBase64 = $inner['body_base64'] ?? null;
			$bodyText = $inner['body_text'] ?? null;
			if ($bodyBase64 !== null) {
				$bodyBytes = base64_decode($bodyBase64, true);
				if ($bodyBytes === false) {
					$bodyBytes = '';
				}
			} elseif ($bodyText !== null) {
				$bodyBytes = $bodyText;
			} else {
				$bodyBytes = '';
			}
			$result['status_code'] = $inner['status_code'] ?? 200;
			$result['headers'] = $inner['headers'] ?? [];
			$result['body'] = $bodyText !== null ? $bodyText : $bodyBytes;
			$result['body_bytes'] = $bodyBytes;
			$result['json'] = $inner['body_json'] ?? ($bodyText !== null ? $this->tryJsonDecode($bodyText) : null);
		}

		if ($status === 'failed') {
			$result['error'] = $data['error'] ?? 'Unknown error';
		}

		return $result;
	}

	/**
	 * Aspetta che un ticket sia completato (polling loop)
	 * ATTENZIONE: blocca, usare solo in script CLI/worker
	 *
	 * Il return è normalizzato tramite ensureResponseShape() così che le chiavi
	 * status_code / headers / body / body_bytes / json siano sempre presenti
	 * (anche su timeout, ticket non trovato, abbandono, fallimento backend).
	 * Codice consumer del tipo `if ($resp['status_code'] >= 400)` funziona
	 * uniformemente per sync e ticket.
	 */
	public function waitForTicket(string $ticketId): array {
		$start = microtime(true);

		while (true) {
			$elapsed = microtime(true) - $start;
			if ($elapsed > $this->maxWait) {
				return $this->ensureResponseShape([
					'found' => true,
					'ticket_id' => $ticketId,
					'status' => 'timeout',
					'done' => false,
					'failed' => true,
					'status_code' => 504,
					'error' => "Timeout after {$this->maxWait}s",
				]);
			}

			$result = $this->poll($ticketId);

			if (!$result['found']) {
				$result['status_code'] = 404;
				return $this->ensureResponseShape($result);
			}

			if ($result['done']) {
				return $this->ensureResponseShape($result);
			}

			if ($result['failed']) {
				// abbandono vs fallimento backend
				if (!isset($result['status_code'])) {
					$result['status_code'] = ($result['status'] ?? '') === 'abandoned' ? 410 : 502;
				}
				return $this->ensureResponseShape($result);
			}

			usleep((int)($this->pollInterval * 1000000));
		}
	}

	/**
	 * Garantisce che l'array di risposta abbia sempre le chiavi
	 * status_code / headers / body / body_bytes / json / done / failed,
	 * popolandole con default sensati se mancanti. Serve a uniformare la
	 * shape tra path sync e path ticket per i consumer di request().
	 */
	private function ensureResponseShape(array $result): array {
		$result['done'] = $result['done'] ?? false;
		$result['failed'] = $result['failed'] ?? !$result['done'];
		$result['status_code'] = $result['status_code'] ?? 502;
		$result['headers'] = $result['headers'] ?? [];
		$result['body'] = $result['body'] ?? '';
		$result['body_bytes'] = $result['body_bytes'] ?? '';
		if (!array_key_exists('json', $result)) {
			$result['json'] = null;
		}
		return $result;
	}

	/**
	 * HTTP request con cURL
	 */
	private function httpRequest(
		string $method,
		string $url,
		?array $json = null,
		array $headers = [],
		?array $multipart = null
	): array {
		$ch = curl_init();

		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => (int)$this->timeout,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
		]);

		$curlHeaders = [];
		foreach ($headers as $key => $value) {
			$curlHeaders[] = "$key: $value";
		}

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			if ($multipart !== null) {
				// multipart/form-data — cURL gestisce boundary e Content-Type automaticamente
				curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
			} elseif ($json !== null) {
				$body = json_encode($json);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
				$curlHeaders[] = 'Content-Type: application/json';
				$curlHeaders[] = 'Content-Length: ' . strlen($body);
			}
		} elseif ($method !== 'GET') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		}

		if (!empty($curlHeaders)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
		}

		// Cattura headers risposta
		$responseHeaders = [];
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$responseHeaders) {
			$len = strlen($header);
			$parts = explode(':', $header, 2);
			if (count($parts) === 2) {
				$responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
			}
			return $len;
		});

		$body = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($body === false) {
			return [
				'status_code' => 0,
				'headers' => [],
				'body' => '',
				'error' => $error,
			];
		}

		return [
			'status_code' => $statusCode,
			'headers' => $responseHeaders,
			'body' => $body,
		];
	}

	private function tryJsonDecode(string $body): ?array {
		$data = json_decode($body, true);
		return json_last_error() === JSON_ERROR_NONE ? $data : null;
	}
}

class QBertException extends Exception {}
class QBertTimeoutException extends QBertException {}
