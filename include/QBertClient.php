<?php
/**
 * QBert Client PHP - Client per il gateway QBert
 *
 * Uso:
 *
 *   $qbert = new QBertClient('https://qbert.example.com', appName: 'my_chatbot');
 *
 *   // --- Bloccante: aspetta il risultato (script CLI, cron, worker) ---
 *   $response = $qbert->post('ollama', '/api/generate', ['model' => 'llama3', 'prompt' => 'ciao']);
 *   echo $response['json']['response'];
 *
 *   // Con nota per il log
 *   $response = $qbert->post('ollama', '/api/generate', ['model' => 'llama3', 'prompt' => 'ciao'], note: 'user_chat');
 *
 *   // --- Non bloccante + webhook: la modalita' giusta per una web app ---
 *   // Ritorna appena il gateway accoda il job; il risultato arriva al callback.
 *   $result = $qbert->postAsync('ollama', '/api/generate',
 *       ['model' => 'llama3', 'prompt' => 'ciao'],
 *       callbackUrl: 'https://myapp.com/qbert-callback'
 *   );
 *   if ($result['is_ticket']) {
 *       // job accodato: QBert chiamera' il callback quando ha finito
 *   } else {
 *       // job gia' finito entro la finestra sync: risposta in $result['json']
 *       // (il callback viene invocato lo stesso)
 *   }
 *
 *   // Non bloccante senza webhook (polling manuale con $qbert->poll($id))
 *   $result = $qbert->postAsync('ollama', '/api/generate', ['model' => 'llama3', 'prompt' => 'ciao']);
 *
 *   // --- Richiesta multipart/form-data (es. voice clone con file audio) ---
 *   $response = $qbert->post('qwen-tts', '/voice_clone', multipart: [
 *       'text' => 'Ciao mondo',
 *       'language' => 'it',
 *       'audio' => new CURLFile('/path/to/sample.wav', 'audio/wav'),
 *   ]);
 *
 * Ogni return di post()/get()/request()/postAsync()/getAsync() contiene sempre
 * le chiavi status_code / headers / body / body_bytes / json / done / failed,
 * anche quando la richiesta fallisce (gateway irraggiungibile, timeout,
 * ticket abbandonato). Vedi ensureResponseShape().
 */

class QBertClient {
	private string $baseUrl;
	private float $timeout;
	private float $pollInterval;
	private float $maxWait;
	private string $appName;

	const HTTP_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

	const PRIORITY_URGENT = 'urgent';
	const PRIORITY_NORMAL = 'normal';
	const PRIORITY_LAZY = 'lazy';

	public function __construct(
		string $baseUrl = 'http://127.0.0.1:1999',
		float $timeout = 40.0,
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
	 * ATTENZIONE: può bloccare per molto tempo, usare solo in script CLI/worker.
	 * Per una web app usa postAsync()/getAsync().
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
		?array $multipart = null,
		string $workload = ''
	): array {
		$result = $this->submit($method, $service, $path, $json, $priority, $headers, note: $note, multipart: $multipart, workload: $workload);

		if (!$result['is_ticket']) {
			return $result;
		}

		return $this->waitForTicket($result['ticket_id']);
	}

	public function get(string $service, string $path, string $priority = self::PRIORITY_NORMAL, string $note = '', string $workload = ''): array {
		return $this->request('GET', $service, $path, null, $priority, note: $note, workload: $workload);
	}

	public function post(string $service, string $path, ?array $json = null, string $priority = self::PRIORITY_NORMAL, string $note = '', ?array $multipart = null, string $workload = ''): array {
		return $this->request('POST', $service, $path, $json, $priority, note: $note, multipart: $multipart, workload: $workload);
	}

	/**
	 * POST non bloccante: ritorna appena il gateway ha accodato il job.
	 *
	 * È la modalità da usare in una web app. Con $callbackUrl valorizzato QBert
	 * invia il risultato via webhook (POST) a quell'URL — anche se questo
	 * processo PHP nel frattempo è morto. Senza callback, tieni il
	 * $result['ticket_id'] e usa poll().
	 *
	 * Nota: il gateway può comunque rispondere sincrono se il job finisce
	 * entro la finestra sync (~30s); in quel caso $result['is_ticket'] è false
	 * e la risposta è già in $result['json'] (il webhook parte lo stesso).
	 */
	public function postAsync(
		string $service,
		string $path,
		?array $json = null,
		?string $callbackUrl = null,
		string $priority = self::PRIORITY_NORMAL,
		string $note = '',
		?array $multipart = null,
		string $workload = ''
	): array {
		return $this->submit('POST', $service, $path, $json, $priority,
			callbackUrl: $callbackUrl, note: $note, multipart: $multipart, workload: $workload);
	}

	/** Variante GET di postAsync(). */
	public function getAsync(
		string $service,
		string $path,
		?string $callbackUrl = null,
		string $priority = self::PRIORITY_NORMAL,
		string $note = '',
		string $workload = ''
	): array {
		return $this->submit('GET', $service, $path, null, $priority,
			callbackUrl: $callbackUrl, note: $note, workload: $workload);
	}

	/**
	 * Invia richiesta senza aspettare il risultato - ritorna appena il gateway
	 * ha accodato il job (o subito, se il job è finito entro la finestra sync).
	 *
	 * Se callbackUrl è specificato, QBert chiamerà quell'URL quando il job è
	 * completato. Altrimenti, usare poll() per verificare lo stato del ticket.
	 *
	 * Ordine degli argomenti: accetta sia (method, service, path) — la forma
	 * storica di questo client — sia (service, path[, method]), che è quella
	 * dei client Python e Node. Le due forme convivono perché il codice
	 * esistente usa la prima e chi arriva dagli altri client scrive
	 * istintivamente la seconda; prima, la seconda produceva un
	 * ArgumentCountError. Se il primo argomento è un verbo HTTP si assume la
	 * forma storica.
	 *
	 *   submit('POST', 'ollama', '/api/generate', json: [...])   // storica
	 *   submit('ollama', '/api/generate', json: [...])           // Python/Node
	 *   submit('ollama', '/api/generate', ['prompt' => '...'])   // idem, body posizionale
	 *   submit('ollama', '/api/tags', 'GET')                     // idem, metodo esplicito
	 */
	public function submit(
		string $method,
		string $service,
		string|array|null $path = null,
		?array $json = null,
		string $priority = self::PRIORITY_NORMAL,
		array $headers = [],
		?string $callbackUrl = null,
		string $note = '',
		?array $multipart = null,
		string $workload = ''
	): array {
		[$method, $service, $path, $json] = self::normalizeCallArgs($method, $service, $path, $json);

		if ($json !== null && $multipart !== null) {
			throw new QBertException('Cannot use both json and multipart in the same request');
		}

		$url = $this->baseUrl . '/' . $service . '/' . ltrim($path, '/');
		$headers['X-Priority'] = $priority;
		$headers['X-App-Name'] = $this->appName;
		if ($note !== '') {
			$headers['X-Note'] = $note;
		}
		if ($workload !== '') {
			$headers['X-Workload'] = $workload;
		}
		if ($callbackUrl !== null) {
			$headers['X-Callback-Url'] = $callbackUrl;
		}

		$response = $this->httpRequest($method, $url, $json, $headers, $multipart);

		if ($response['status_code'] === 202) {
			$data = json_decode($response['body'], true);
			if (!is_array($data) || !isset($data['ticket_id'])) {
				throw new QBertException('QBert returned 202 without a ticket_id: ' . substr($response['body'], 0, 200));
			}
			return [
				'is_ticket' => true,
				'ticket_id' => $data['ticket_id'],
				'status' => $data['status'] ?? 'queued',
				'poll_url' => $data['poll_url'] ?? null,
				'callback_url' => $data['callback_url'] ?? null,
			];
		}

		// Nessuna risposta HTTP (gateway irraggiungibile, DNS, TLS, timeout del
		// client): status_code 0 sarebbe passato per un successo in qualunque
		// consumer che testa `>= 400`. Lo mappiamo su un codice parlante e
		// marchiamo l'esito come fallito, con il messaggio di cURL in 'error'.
		if ($response['status_code'] === 0) {
			return $this->ensureResponseShape([
				'is_ticket' => false,
				'status_code' => ($response['errno'] ?? 0) === CURLE_OPERATION_TIMEDOUT ? 504 : 502,
				'done' => false,
				'failed' => true,
				'error' => $response['error'] !== '' ? $response['error'] : 'request failed',
			]);
		}

		return $this->ensureResponseShape([
			'is_ticket' => false,
			'status_code' => $response['status_code'],
			'headers' => $response['headers'],
			'body' => $response['body'],
			'body_bytes' => $response['body'],
			'json' => $this->tryJsonDecode($response['body']),
			// Il backend ha risposto: la richiesta è andata a buon fine dal
			// punto di vista del trasporto, anche se lo status è 4xx/5xx.
			'done' => true,
			'failed' => false,
		]);
	}

	/**
	 * Riconosce quale dei due ordini di argomenti ha usato il chiamante e
	 * restituisce sempre [$method, $service, $path, $json].
	 */
	private static function normalizeCallArgs(string $a, string $b, string|array|null $c, ?array $json): array {
		$isVerb = static fn($v) => is_string($v) && in_array(strtoupper($v), self::HTTP_METHODS, true);

		if ($c === null) {
			// submit('ollama', '/api/generate', json: [...])
			[$method, $service, $path] = ['POST', $a, $b];
		} elseif (is_array($c)) {
			// submit('ollama', '/api/generate', ['prompt' => '...'])
			if ($json !== null) {
				throw new QBertException('Body passed twice (positional and as $json)');
			}
			[$method, $service, $path, $json] = ['POST', $a, $b, $c];
		} elseif ($isVerb($c) && !$isVerb($a)) {
			// submit('ollama', '/api/tags', 'GET')
			[$method, $service, $path] = [$c, $a, $b];
		} else {
			// submit('POST', 'ollama', '/api/generate', [...])
			[$method, $service, $path] = [$a, $b, $c];
		}

		$method = strtoupper($method);
		if (!in_array($method, self::HTTP_METHODS, true)) {
			throw new QBertException(
				"Invalid HTTP method '{$method}'. Attesi: submit(method, service, path) " .
				"oppure submit(service, path[, method])."
			);
		}
		return [$method, $service, $path, $json];
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
	 *
	 * `protected` e non `private` così i test possono sostituire il livello di
	 * trasporto e verificare che cosa il client ha effettivamente inviato.
	 */
	protected function httpRequest(
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
		$errno = curl_errno($ch);
		curl_close($ch);

		if ($body === false) {
			return [
				'status_code' => 0,
				'headers' => [],
				'body' => '',
				'error' => $error,
				'errno' => $errno,
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
