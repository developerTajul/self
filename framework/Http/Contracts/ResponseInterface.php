<?php
declare(strict_types=1);

namespace Framework\Http\Contracts;

use Framework\Http\Exceptions\HttpException;

/**
 * Something that can be sent to the client.
 *
 * Everything a response needs to decide - status, headers, body, whether it
 * terminates the request - it already knows by the time `send()` is called;
 * nothing about the request is passed back in, because a response that re-reads
 * the request it answered is a response whose behaviour cannot be predicted from
 * its own state.
 *
 * Why the body is readable, not only sendable
 *
 * `send()` is how one domain emits a response, and the *web* domain is the one
 * that can: it owns the output buffer end to end. The REST domain cannot - it
 * hands a value back to WordPress's REST server, which serializes whatever it
 * receives and knows nothing about this framework. For one controller contract to
 * serve both, the REST adapter has to read a response rather than emit it, and
 * that means the body and the status have to be part of the contract. Without
 * `data()` the adapter can only guess, and its guess for a response object is an
 * empty JSON body under a success status.
 *
 * `data()` is deliberately untyped beyond `mixed`: a JSON body is an array, a view
 * response's is the view's variables, an HTML response's is a string. Callers that
 * need one of those narrow it themselves.
 *
 * `send()` is terminal. Some implementations (`JsonResponse`) hand off to a
 * WordPress function that ends the request; others (`ViewResponse`) echo and
 * return. A pipeline treats both the same way - it stops after sending - so a
 * caller must not assume control returns.
 */
interface ResponseInterface
{
    /**
     * Emit this response.
     *
     * @throws HttpException When the response cannot be sent, or was sent twice.
     */
    public function send(): void;

    /**
     * The HTTP status code this response will carry.
     *
     * Exposed so middleware can branch on it on the way out - logging a failure,
     * adding a header to a success - without consuming the response. It is also
     * what makes a response assertable in a test that does not want to send it,
     * and what lets a domain that cannot send (REST) still honour the status.
     */
    public function status(): int;

    /**
     * The body this response carries, in whatever shape it holds it.
     *
     * What a caller reads when it needs the payload without emitting it - the
     * REST adapter building a `WP_REST_Response`, a middleware inspecting a
     * failure, a test asserting a body. A response that can only be sent is
     * unusable to any of them.
     */
    public function data(): mixed;
}
