<?php
/**
 * QBert Client PHP - Client per il gateway QBert
 *
 * Uso:
 *   $qbert = new QBertClient('https://qbert.example.com');
 *
 *   // Richiesta sincrona (blocca finché non arriva risposta)
 *   $response = $qbert->post('ollama', '/api/generate', ['model' => 'llama3', 'prompt' => 'ciao']);
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
 */

class QBertClient {
	private string $baseUrl;
	private float $timeout;
	private float $pollInterval;
	private float $maxWait;

	const PRIORITY_URGENT = 'urgent';
	const PRIORITY_NORMAL = 'normal';
	const PRIORITY_LAZY = 'lazy';

	public function __construct(
		string $baseUrl = 'http://127.0.0.1:1999',
		float $timeout = 35.0,
		float $pollInterval = 2.0,
		float $maxWait = 600.0
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->timeout = $timeout;
		$this->pollInterval = $pollInterval;
		$this->maxWait = $maxWait;
	}

	/**
	 * Invia richiesta e aspetta il risultato (gestisce ticket automaticamente)
	 * ATTENZIONE: può bloccare per molto tempo, usare solo in script CLI/worker
	 */
	public function request(
		string $method,
		string $service,
		string $path,
		?array $json = null,
		string $priority = self::PRIORITY_NORMAL,
		array $headers = []
	): array {
		$result = $this->submit($method, $service, $path, $json, $priority, $headers);

		if (!$result['is_ticket']) {
			return $result;
		}

		return $this->waitForTicket($result['ticket_id']);
	}

	public function get(string $service, string $path, string $priority = self::PRIORITY_NORMAL): array {
		return $this->request('GET', $service, $path, null, $priority);
	}

	public function post(string $service, string $path, ?array $json = null, string $priority = self::PRIORITY_NORMAL): array {
		return $this->request('POST', $service, $path, $json, $priority);
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
		?string $callbackUrl = null
	): array {
		$url = $this->baseUrl . '/' . $service . '/' . ltrim($path, '/');
		$headers['X-Priority'] = $priority;
		if ($callbackUrl !== null) {
			$headers['X-Callback-Url'] = $callbackUrl;
		}

		$response = $this->httpRequest($method, $url, $json, $headers);

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
			$result['status_code'] = $data['response']['status_code'] ?? 200;
			$result['headers'] = $data['response']['headers'] ?? [];
			$result['body'] = $data['response']['body'] ?? '';
			$result['json'] = $this->tryJsonDecode($result['body']);
		}

		if ($status === 'failed') {
			$result['error'] = $data['error'] ?? 'Unknown error';
		}

		return $result;
	}

	/**
	 * Aspetta che un ticket sia completato (polling loop)
	 * ATTENZIONE: blocca, usare solo in script CLI/worker
	 */
	public function waitForTicket(string $ticketId): array {
		$start = microtime(true);

		while (true) {
			$elapsed = microtime(true) - $start;
			if ($elapsed > $this->maxWait) {
				return [
					'found' => true,
					'ticket_id' => $ticketId,
					'status' => 'timeout',
					'failed' => true,
					'error' => "Timeout after {$this->maxWait}s",
				];
			}

			$result = $this->poll($ticketId);

			if (!$result['found']) {
				return $result;
			}

			if ($result['done'] || $result['failed']) {
				return $result;
			}

			usleep((int)($this->pollInterval * 1000000));
		}
	}

	/**
	 * Invia richiesta multipart/form-data e aspetta il risultato
	 * Necessario per endpoint che richiedono form-data (es. /voice-clone)
	 *
	 * @param string $service Nome del servizio QBert
	 * @param string $path Path dell'endpoint
	 * @param array $formData Dati form (chiave => valore)
	 * @param string $priority Priorità QBert
	 * @return array Risposta con 'status_code', 'headers', 'body'
	 */
	public function submitForm(
		string $service,
		string $path,
		array $formData,
		string $priority = self::PRIORITY_NORMAL
	): array {
		$url = $this->baseUrl . '/' . $service . '/' . ltrim($path, '/');

		$result = $this->httpRequestForm($url, $formData, $priority);

		if ($result['status_code'] === 202) {
			$data = json_decode($result['body'], true);
			if ($data && isset($data['ticket_id'])) {
				return $this->waitForTicket($data['ticket_id']);
			}
		}

		return $result;
	}

	/**
	 * HTTP request multipart/form-data con cURL
	 */
	private function httpRequestForm(
		string $url,
		array $formData,
		string $priority = self::PRIORITY_NORMAL
	): array {
		$ch = curl_init();

		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => (int)$this->timeout,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $formData, // array = cURL invia come multipart/form-data
			CURLOPT_HTTPHEADER => ["X-Priority: $priority"],
		]);

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

	/**
	 * HTTP request con cURL
	 */
	private function httpRequest(
		string $method,
		string $url,
		?array $json = null,
		array $headers = []
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
			if ($json !== null) {
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
