<?php

// beta.10 C12 12.5 (F-C12-4, F-C13-18). Every path that leaves a command leaves
// through one of the setters below, so the install layout is stripped HERE
// rather than at the ~48 sites that build one. A per-site fix is one whose
// completeness cannot be proven — the static scan for those sites produced both
// false positives ('files' => a COUNT) and false negatives (ZipUtilities'
// zip_path, setupToken's path) — and it would miss the getData()/toArray()
// readers that /admin/api and CommandRunner use instead of send().
require_once __DIR__ . '/../functions/publicPaths.php';
// qs_is_development() — pre-init safe and dependency-free by construction, so
// requiring it here cannot reorder anything. Used only to decide how LOUDLY a
// missing message fails; see requireMessage().
require_once __DIR__ . '/../functions/environment.php';

/**
 * EVERY RESPONSE CARRIES ITS OWN MESSAGE. There is no default and no fallback.
 *
 * There used to be a registry mapping (status, code) to a default message, so
 * that `create(400, 'validation.required')` could answer "Required parameter
 * missing" on its own. It failed at both halves of its job:
 *
 *   - It was never the message anyone wanted. All 1417 call sites in the engine
 *     pass their own withMessage(), because a useful message names the field,
 *     the file or the limit — which a table keyed on a code cannot know.
 *   - It covered a small and shrinking fraction of what the engine actually
 *     emits. Measured before its removal: 99 distinct (status, code) pairs
 *     unregistered, logging 65,498 "Unregistered response code" lines — 82% of
 *     an 8 MB error log, and every one of them describing a response that was
 *     already correct because the caller had supplied its own message.
 *
 * So the fallback existed to be unused, and the warning existed to fire on
 * responses that were fine. Both are gone, and the message is now MANDATORY.
 *
 * "Mandatory" is enforced twice, because neither mechanism covers the other's
 * blind spot:
 *
 *   1. STATICALLY, before anything ships — NOTES/tests/beta11/s210_create_scan.php
 *      walks the token stream of every command and exits non-zero if any
 *      create() reaches its terminating `;` without a withMessage(). That sees
 *      branches no test ever executes, which is exactly where a forgotten
 *      message would otherwise hide.
 *   2. AT RUNTIME, in requireMessage() below — because the scanner cannot know
 *      what a message evaluates to. A withMessage() fed an empty variable is
 *      invisible to it and caught here.
 */

class ApiResponse {
    private $status;
    private $code;
    /**
     * null until withMessage() sets it. NOT '' — an empty string is a message
     * somebody chose, and telling the two apart is what lets requireMessage()
     * name the actual mistake.
     */
    private $message = null;
    private $data;
    private $errors;

    // Logging callback - called before send
    private static $beforeSendCallback = null;

    /**
     * Open a response. The caller MUST follow with withMessage() — see the
     * class docblock for why there is no longer a default.
     */
    public static function create(int $status, string $code): self {
        $instance = new self();
        $instance->status = $status;
        $instance->code = $code;
        return $instance;
    }

    /**
     * Create a response and its message in one call.
     *
     * The message-required form of create(): it cannot produce a response
     * without a message, because PHP refuses the call. Preferred for new code
     * where the message is already in hand; create()->withMessage() stays the
     * right shape when the message is built from work done in between.
     */
    public static function custom(int $status, string $code, string $message): self {
        $instance = new self();
        $instance->status = $status;
        $instance->code = $code;
        $instance->message = qs_scrub_path_string($message);
        return $instance;
    }

    /**
     * The message this response will send — or a hard failure if there is none.
     *
     * WHY THIS EXISTS ALONGSIDE THE STATIC SCAN. The scanner proves every
     * create() is followed by a withMessage(); it cannot prove what that call
     * evaluates to. `->withMessage($e->getMessage())` on an exception with an
     * empty message reads as correct to any static check and produces a
     * response that says nothing. This is the net under that.
     *
     * WHY IT DOES NOT INVENT A MESSAGE. Answering "Unknown response" is what
     * the deleted registry did, and it is the failure mode that hides: the
     * caller gets a 200-shaped envelope describing nothing, and nobody
     * investigates a response that looks like an answer. A response with no
     * message is a bug in the command, so it is reported as one.
     *
     * DEVELOPMENT THROWS, PRODUCTION DEGRADES. In development the exception
     * stops the request where the mistake is, with a stack trace. In production
     * the visitor gets a 500 — true, since the command really is malformed —
     * and the operator gets one actionable log line naming the file and line,
     * rather than the 65,498 unactionable ones this class used to write.
     *
     * ⚠ UNREACHABLE AS SHIPPED. Every one of the engine's 1417 create() sites
     * carries a message, so nothing existing can reach this. That is what makes
     * it safe to fail hard: it can only fire on code written after this change.
     */
    private function requireMessage(): string {
        if (is_string($this->message) && $this->message !== '') {
            return $this->message;
        }
        // ⚠ THE FILTER IS ON THE FILE, NOT THE CLASS. A backtrace frame's
        // `class` names the method being CALLED while its `file`/`line` name
        // where the call came FROM — so the frame that carries the command's
        // location is `toArray`/`send`, whose class is this one. Filtering by
        // class discards exactly the frame worth having and reports "unknown".
        $where = 'unknown';
        $self = __FILE__;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['file']) && $frame['file'] !== $self) {
                $where = qs_scrub_path_string($frame['file']) . ':' . ($frame['line'] ?? '?');
                break;
            }
        }
        $why = $this->message === '' ? 'an EMPTY message' : 'no message at all';
        $detail = "ApiResponse {$this->status}.{$this->code} was built with {$why}"
                . " — every response must call withMessage(). Built at {$where}.";
        error_log($detail);
        if (qs_is_development()) {
            throw new LogicException($detail);
        }
        return 'Internal server error';
    }

    /**
     * The wire envelope, built in ONE place.
     *
     * send() and toArray() are the two ways a response leaves a command and
     * they used to compose this shape independently, which is how a rule
     * enforced in one of them would quietly not apply to the other.
     */
    private function envelope(): array {
        $message = $this->requireMessage();
        // A response with no message is a malformed command, not a result, so
        // the status is corrected to match what is actually being said. In
        // development requireMessage() has already thrown and this is dead.
        $status = ($this->message === null || $this->message === '') ? 500 : $this->status;

        $response = [
            'status'  => $status,
            'code'    => $status === $this->status ? $this->code : 'server.internal_error',
            'message' => $message,
        ];
        if (!empty($this->data)) {
            $response['data'] = $this->data;
        }
        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;
        }
        return $response;
    }

    /**
     * Set response data/payload
     *
     * Scrubbed at SET time, not at send() time, so the three ways a response
     * leaves a command all see the same public view: send() on /management,
     * getData() on /admin/api's internal relay, and toArray() in CommandRunner.
     */
    public function withData(array $data): self {
        $this->data = qs_scrub_paths($data);
        return $this;
    }

    /**
     * Set validation errors
     */
    public function withErrors(array $errors): self {
        $this->errors = qs_scrub_paths($errors);
        return $this;
    }

    /**
     * Override the default message
     *
     * Messages are interpolated ("Failed to copy file: {$destPath}"), so the
     * message carries paths as often as the data does.
     */
    public function withMessage(string $message): self {
        $this->message = qs_scrub_path_string($message);
        return $this;
    }

    /**
     * Set a callback to be executed before send (for logging)
     */
    public static function setBeforeSendCallback(callable $callback): void {
        self::$beforeSendCallback = $callback;
    }

    /**
     * Get response info without sending (for logging)
     *
     * DELIBERATELY DOES NOT ENFORCE. This is the logging path, and a logger
     * that throws turns a diagnosable bug into a lost record — the exact
     * moment the log matters most. It reports the missing message as a fact
     * instead, and send()/toArray() do the enforcing on the paths that
     * actually answer a caller.
     */
    public function getResponseInfo(): array {
        return [
            'status' => $this->status,
            'code' => $this->code,
            'message' => $this->message ?? '(no message set)'
        ];
    }

    /**
     * Get the HTTP status code
     */
    public function getStatus(): int {
        return $this->status;
    }

    /**
     * Get the response code string
     */
    public function getCode(): string {
        return $this->code;
    }

    /**
     * Get response data
     */
    public function getData(): ?array {
        return $this->data;
    }

    /**
     * Convert response to array (for internal use without HTTP)
     */
    public function toArray(): array {
        return $this->envelope();
    }

    /**
     * Send the JSON response and exit
     */
    public function send(): void {
        // Call logging callback if set
        if (self::$beforeSendCallback !== null) {
            call_user_func(self::$beforeSendCallback, $this->status, $this->code);
        }
        
        // Clear any output buffering
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // ONE composer, shared with toArray(), so the message rule cannot apply
        // to one exit path and not the other.
        $response = $this->envelope();

        // beta.10 C13 F-C13-14(b): SERIALISE FIRST, WRITE SECOND.
        //
        // The status and Content-Type used to be sent before json_encode ran. On a
        // large payload the encode is exactly where the memory ceiling is reached,
        // and errorHygiene's fatal handler bails once headers_sent() is true — so a
        // fatal here landed in a window no handler could cover, and the client got
        // a 200 whose body was raw PHP error text. Building the body first puts
        // that failure back BEFORE the first byte, where the handler still works.
        $body = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            // The envelope itself could not be encoded (malformed UTF-8 in the
            // data, or depth > 512). Echoing false would have written an EMPTY
            // body under the original status — a 200 that says nothing. Answer
            // 500 with a payload that is guaranteed to encode.
            error_log('ApiResponse::send: response could not be serialised ('
                . json_last_error_msg() . ') for code ' . $this->code);
            http_response_code(500);
            header('Content-Type: application/json');
            echo '{"status":500,"code":"server.internal_error",'
               . '"message":"Response could not be serialised"}';
            exit;
        }

        // THE ENVELOPE'S STATUS, not $this->status. envelope() corrects the
        // status to 500 when the message was missing, and sending the original
        // here would put a 500-shaped body under a 200 header — the one
        // combination a client cannot recover from.
        http_response_code($response['status']);
        header('Content-Type: application/json');
        echo $body;
        exit;
    }
}