<?php

declare(strict_types=1);

namespace Core\CrmSync\Infrastructure\Adapters;

use Core\CrmSync\Application\Contracts\CrmContactGatewayContract;
use Core\CrmSync\Application\DTOs\Responses\CrmPushResultDTO;
use Core\CrmSync\Domain\Enums\CrmObjectType;
use Core\CrmSync\Domain\Enums\CrmProviderName;
use Core\CrmSync\Domain\Exceptions\CrmPermanentException;
use Core\CrmSync\Domain\Exceptions\CrmTransientException;
use Core\CrmSync\Domain\ValueObjects\RemoteId;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Talks to HubSpot's CRM v3 API over `wp_remote_request`.
 *
 * The adapter's whole job is to turn HTTP into the two outcomes the Core
 * understands: a result, or a typed exception that says whether retrying is
 * worth it. That decision is made HERE and nowhere above, because this is the
 * only layer that can read the response — a status code reaching the Core would
 * put the transport inside the Domain.
 *
 * Status mapping (plan §5 Step 2.5):
 *  - 429 and every 5xx are TRANSIENT: the provider is busy or briefly broken and
 *    the identical payload has a real chance later.
 *  - every other 4xx is PERMANENT: the request itself was unacceptable, so
 *    retrying it unchanged would fail identically.
 *
 * The access token arrives as a scalar by contextual binding (D2, D8) and is
 * never logged: not in a message, not in a context array, not in an exception.
 *
 * @implements CrmContactGatewayContract
 */
final class HubSpotAdapter implements CrmContactGatewayContract
{
    /** HubSpot's API version path segment. */
    private const API_VERSION = 'crm/v3';

    /** The property HubSpot carries our non-sequential lead identifier in. */
    public const EXTERNAL_ID_PROPERTY = 'leadbridge_external_id';

    /** HubSpot properties used by Lead inbound enrichment and state hashing. */
    private const LEAD_CONTACT_PROPERTIES = [
        self::EXTERNAL_ID_PROPERTY,
        'email',
        'firstname',
        'lastname',
        'phone',
        'company',
        'website',
    ];

    /**
     * The timeout for one HTTP call, in seconds.
     *
     * Must stay comfortably below the reaper's lease timeout
     * (`SyncRetryRules::LEASE_TIMEOUT_SECONDS`, 300): a call that outlived its own
     * lease would let the reaper reclaim the event and hand it to a second worker
     * while this one was still waiting for a response, and both would then write
     * the event's outcome.
     */
    private const TIMEOUT_SECONDS = 20;

    /**
     * @param string          $accessToken The private-app access token.
     * @param string          $baseUrl     The API base, without a trailing slash.
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly string $accessToken,
        private readonly string $baseUrl,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Creates a remote record for a local entity.
     *
     * @param CrmObjectType        $objectType
     * @param array<string, mixed> $properties The CRM-facing field map, built by the caller.
     *
     * @return CrmPushResultDTO The created record's canonical id.
     *
     * @throws CrmTransientException When the provider was busy or failed.
     * @throws CrmPermanentException When the provider rejected the request.
     */
    public function createRecord(CrmObjectType $objectType, array $properties): CrmPushResultDTO
    {
        $response = $this->request('POST', $this->collectionPath($objectType), ['properties' => $properties]);

        return new CrmPushResultDTO(
            remoteId: $this->remoteIdFrom($response['body'], $objectType),
            httpStatusCode: $response['status'],
            created: true,
        );
    }

    /**
     * Updates an existing remote record.
     *
     * @param CrmObjectType        $objectType
     * @param RemoteId             $remoteId
     * @param array<string, mixed> $properties
     *
     * @return CrmPushResultDTO The updated record's canonical id.
     *
     * @throws CrmTransientException When the provider was busy or failed.
     * @throws CrmPermanentException When the provider rejected the request.
     */
    public function updateRecord(CrmObjectType $objectType, RemoteId $remoteId, array $properties): CrmPushResultDTO
    {
        $response = $this->request(
            'PATCH',
            $this->recordPath($objectType, $remoteId),
            ['properties' => $properties]
        );

        // A PATCH that succeeds may answer `204 No Content`, carrying no body to
        // read an id from. The id is already known here — it addressed the
        // request — so it is reused rather than re-parsed, and a 204 is not
        // treated as "the provider returned nothing".
        return new CrmPushResultDTO(
            remoteId: $remoteId,
            httpStatusCode: $response['status'],
            created: false,
        );
    }

    /**
     * Reads one remote record.
     *
     * @param CrmObjectType $objectType
     * @param RemoteId      $remoteId
     *
     * @return array<string, mixed> The record's properties, as the provider returns them.
     *
     * @throws CrmTransientException When the provider was busy or failed.
     * @throws CrmPermanentException When the provider rejected the request, including a 404.
     */
    public function fetchRecord(CrmObjectType $objectType, RemoteId $remoteId): array
    {
        $response = $this->request(
            'GET',
            $this->recordPath($objectType, $remoteId) . '?properties=' . rawurlencode(implode(',', self::LEAD_CONTACT_PROPERTIES)),
            null
        );

        $decoded = $this->decode($response['body']);

        $properties = $decoded['properties'] ?? null;

        if (! is_array($properties)) {
            throw new CrmPermanentException(
                sprintf('HubSpot returned no properties for a %s record.', $objectType->value),
                $response['status']
            );
        }

        return $properties;
    }

    /**
     * Performs one authenticated call and maps its outcome.
     *
     * @param string                    $method
     * @param string                    $path   Appended to the configured base URL.
     * @param array<string, mixed>|null $payload JSON body, or null for no body.
     *
     * @return array{status: int, body: string}
     *
     * @throws CrmTransientException When the call failed in a retryable way.
     * @throws CrmPermanentException When the call was rejected.
     * @throws RuntimeException When the adapter is misconfigured.
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        if ($this->accessToken === '' || $this->baseUrl === '') {
            // A misconfiguration, not a provider failure: retrying with the same
            // empty credentials would fail identically, so this is raised as a
            // runtime fault rather than spent against the retry budget.
            throw new RuntimeException(
                'The HubSpot adapter has no access token or no base URL configured, so no call can be made.'
            );
        }

        $args = [
            'method' => $method,
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        if ($payload !== null) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                throw new CrmPermanentException(
                    'The payload for a HubSpot call could not be encoded as JSON: ' . json_last_error_msg()
                );
            }

            $args['body'] = $encoded;
        }

        $response = wp_remote_request(rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/'), $args);

        // A `WP_Error` is a transport failure — DNS, TLS, timeout — which is the
        // definition of a transient condition.
        if (is_wp_error($response)) {
            // The error's own message is logged, but the code is what reaches the
            // exception: a `WP_Error` message can echo back the request, and the
            // request carries the Authorization header.
            $this->logger->warning('A HubSpot call failed before a response was received.', [
                'method' => $method,
                'path' => $path,
                'error_code' => $response->get_error_code(),
            ]);

            throw new CrmTransientException(sprintf(
                'The HubSpot call could not be completed: %s.',
                (string) $response->get_error_code()
            ));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status >= 200 && $status < 300) {
            return ['status' => $status, 'body' => $body];
        }

        // 429 and 5xx are worth another attempt; everything else in the 4xx range
        // is not. `Retry-After` is deliberately NOT honoured here: the worker's
        // own backoff decides when the retry happens, and this adapter has no way
        // to make the process wait without holding its lease.
        $message = sprintf('HubSpot answered %d for %s %s.', $status, $method, $path);

        if ($status === 429 || $status >= 500) {
            throw new CrmTransientException($message, $status);
        }

        throw new CrmPermanentException($message, $status);
    }

    /**
     * The collection path for an object type.
     *
     * @param CrmObjectType $objectType
     *
     * @return string
     */
    private function collectionPath(CrmObjectType $objectType): string
    {
        return sprintf('%s/objects/%s', self::API_VERSION, $this->objectPlural($objectType));
    }

    /**
     * The path addressing one record.
     *
     * The remote id is a canonical identifier this adapter received from
     * `RemoteId`, and it is URL-encoded before it is placed in the path so a
     * value containing a slash or a space cannot reshape the URL.
     *
     * @param CrmObjectType $objectType
     * @param RemoteId      $remoteId
     *
     * @return string
     */
    private function recordPath(CrmObjectType $objectType, RemoteId $remoteId): string
    {
        return sprintf(
            '%s/%s',
            $this->collectionPath($objectType),
            rawurlencode($remoteId->toString())
        );
    }

    /**
     * The CRM's own name for an object type.
     *
     * A mapping rather than the enum's value: `CrmObjectType::deal` is this
     * plugin's word for the concept, and HubSpot's URL segment is its own. The
     * two happen to match today, and keeping them as separate facts means a
     * rename on either side cannot silently change a URL.
     *
     * @param CrmObjectType $objectType
     *
     * @return string
     */
    private function objectPlural(CrmObjectType $objectType): string
    {
        return match ($objectType) {
            CrmObjectType::contact => 'contacts',
            CrmObjectType::deal => 'deals',
        };
    }

    /**
     * The canonical id out of a create response.
     *
     * @param string        $body
     * @param CrmObjectType $objectType
     *
     * @return RemoteId
     *
     * @throws CrmPermanentException When the response carries no usable id.
     */
    private function remoteIdFrom(string $body, CrmObjectType $objectType): RemoteId
    {
        $decoded = $this->decode($body);

        $id = $decoded['id'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            // A 2xx with no id means the record may exist remotely but cannot be
            // addressed, so retrying is pointless and the event must be surfaced
            // rather than recorded as a success with no mapping.
            throw new CrmPermanentException(sprintf(
                'HubSpot accepted a %s write but returned no record id.',
                $objectType->value
            ));
        }

        return RemoteId::fromString($id, CrmProviderName::hubspot);
    }

    /**
     * Decodes a response body, or raises a permanent failure.
     *
     * An unreadable body is permanent rather than transient: the provider
     * answered, and its answer cannot be understood, so the same request would
     * produce the same unreadable answer.
     *
     * @param string $body
     *
     * @return array<string, mixed>
     *
     * @throws CrmPermanentException When the body is not a JSON object.
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            throw new CrmPermanentException('HubSpot returned an empty response body where JSON was expected.');
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new CrmPermanentException(
                'HubSpot returned a response that is not JSON: ' . json_last_error_msg()
            );
        }

        return $decoded;
    }
}
