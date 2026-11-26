<?php
/*
 * ui/includes/dvrui_ajax.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
	/* Ajax calls from the page (see dvruiCall in scripts/hdhomerundvr_ui.js).
	 *
	 * The page POSTs fn=<function>&args[]=... to index.php. The function must
	 * be one index.php exported with dvruiAjaxDispatch, and it returns a
	 * DVRUI_AjaxResponse - a set of element id => html that the page puts
	 * into those elements, and optionally data for the page's code. The reply
	 * is JSON: {"ok":true,"html":{id:html,...},"data":{...}} or
	 * {"ok":false,"error":"..."}. */

	/* The status bar shows only the record engine's status - every reply brings it
	 * up to date (dvr_status). What the UI has to say about the request (saved,
	 * deleted, unable to ...) goes in messages, which the page shows as toasts. */
	class DVRUI_AjaxResponse {
		private $html = array();
		private $data = array();
		private $messages = array();

		/* Replace the contents of element $id with $html */
		public function setHtml($id, $html) {
			$this->html[(string)$id] = dvruiAjaxUtf8((string)$html);
		}

		/* A value for the page's own code (reply.data.$key) */
		public function setData($key, $value) {
			$this->data[(string)$key] = $value;
		}

		/* A message for the user (plain text) - $kind is ok, error or info,
		 * or '' to tell from the text (see dvruiMessageKind) */
		public function message($text, $kind = '') {
			$text = trim(preg_replace('/\s+/', ' ', (string)$text));
			if ($text !== '') {
				$this->messages[] = array('text' => dvruiAjaxUtf8($text),
					'kind' => ($kind != '') ? $kind : dvruiMessageKind($text));
			}
		}

		/* Anything the request printed (ob_start ... ob_get_contents - PHP warnings
		 * and the like) - shown as an error message */
		public function output($printed) {
			if ($printed !== null && $printed !== false && trim((string)$printed) !== '') {
				$this->message(html_entity_decode(strip_tags((string)$printed), ENT_QUOTES), 'error');
			}
		}

		public function getString() {
			if (!isset($this->html['dvr_status']) && function_exists('dvruiStatusHtml')) {
				$this->html['dvr_status'] = dvruiAjaxUtf8(dvruiStatusHtml());
			}
			$reply = array('ok' => true, 'html' => (object)$this->html);
			if (count($this->data) > 0) {
				$reply['data'] = $this->data;
			}
			if (count($this->messages) > 0) {
				$reply['messages'] = $this->messages;
			}
			return json_encode($reply,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		}
	}

	/* error for a message that says something failed, otherwise ok */
	function dvruiMessageKind($text) {
		return preg_match('/^(unable|could not|can\'t|cannot|that |no hdhomerun|nothing selected|unknown)|\b(failed|not found|not valid|error)\b|but silicondust kept/i',
			(string)$text) ? 'error' : 'ok';
	}

	/* Text that isn't valid UTF-8 (log lines, file names) is taken as Latin-1 */
	function dvruiAjaxUtf8($text) {
		if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
			return $text;
		}
		return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
	}

	function dvruiAjaxIsCall() {
		return isset($_POST['fn']);
	}

	/* If this request is an ajax call, run it and exit. $exported lists the
	 * functions the page may call - anything else is refused. */
	function dvruiAjaxDispatch($exported) {
		if (!dvruiAjaxIsCall()) {
			return;
		}
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');

		$fn = (string)$_POST['fn'];
		if (!in_array($fn, $exported, true) || !function_exists($fn)) {
			http_response_code(400);
			echo json_encode(array('ok' => false, 'error' => $fn . ' is not available'));
			exit;
		}

		$args = array();
		if (isset($_POST['args']) && is_array($_POST['args'])) {
			foreach ($_POST['args'] as $arg) {
				$args[] = is_array($arg) ? '' : (string)$arg;
			}
		}
		// missing arguments are passed as '' rather than failing the call
		$wanted = (new ReflectionFunction($fn))->getNumberOfParameters();
		while (count($args) < $wanted) {
			$args[] = '';
		}
		$args = array_slice($args, 0, $wanted);

		try {
			$result = call_user_func_array($fn, $args);
		} catch (Throwable $e) {
			error_log('ajax ' . $fn . ' failed: ' . $e->getMessage());
			http_response_code(500);
			echo json_encode(array('ok' => false, 'error' => $fn . ' failed: ' . $e->getMessage()),
				JSON_INVALID_UTF8_SUBSTITUTE);
			exit;
		}
		echo ($result instanceof DVRUI_AjaxResponse) ? $result->getString() : (string)$result;
		exit;
	}
