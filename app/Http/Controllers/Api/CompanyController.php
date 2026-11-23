<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\BulkDestroyCompanyRequest;
use App\Http\Requests\Api\StoreCompanyRequest;
use App\Http\Requests\Api\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use Core\Company\Application\Contracts\CompanyCommandServiceContract;
use Core\Company\Application\Contracts\CompanyMapperContract;
use Core\Company\Application\Contracts\CompanyQueryServiceContract;
use Core\Company\Application\DTOs\Responses\CompanyDetailResponseDTO;
use Core\Company\Application\DTOs\Responses\CompanyResponseDTO;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use InvalidArgumentException;

/**
 * REST delivery for the company module.
 *
 * Every action is the same four steps, in the same order: validated input
 * becomes a DTO through the module's Request Mapper, ONE Service method runs,
 * the `Result` is projected onto a status, and a resource decides the body.
 * Nothing here reads a table, applies a rule, or builds a response array by
 * hand - the response shape belongs to {@see CompanyResource}, and the
 * business rules belong to the entity behind the Service.
 *
 * Why the failure mapping lives here rather than in the Service
 *
 * The Service reports a domain outcome as a `Result` with a stable
 * `errorCode()`; it has no idea a client is on the other end. Turning
 * `COMPANY_NOT_FOUND` into a 404 is a transport decision, so it is made at the
 * transport boundary - and made in one place, {@see failure()}, so the five
 * actions cannot drift into reporting the same failure two different ways.
 */
final class CompanyController
{
    /**
     * Capability a caller must hold to reach any company endpoint.
     */
    public const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly CompanyCommandServiceContract $companyCommandService,
        private readonly CompanyQueryServiceContract $companyQueryService,
        private readonly CompanyMapperContract $mapper,
    ) {}

    /**
     * Lists one page of companies.
     */
    public function index(Request $request): JsonResponse
    {
        // The mapper refuses an unusable page size by throwing, and it is right
        // to: it is a pure translation with no way to report an outcome. Catching
        // here is what turns a bad query string into a 422 the client can fix
        // rather than a 500 that accuses the server of a fault nobody has
        // evidence for. This is the translation boundary doing its job - not a
        // business rule leaking upward.
        try {
            $filterDTO = $this->mapper->mapToFilterDTO($this->listCriteria($request));
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(
                ['error' => Result::ERROR_VALIDATION, 'message' => $exception->getMessage()],
                ['status_code' => 422],
            );
        }

        $result = $this->companyQueryService->getPaginatedCompanies($filterDTO);

        if ($result->isFailure()) {
            return $this->failure($result);
        }

        /** @var PaginatedResult $page */
        $page = $result->getData();

        return new JsonResponse(
            CompanyResource::collection($page->items())
                ->with('meta', $page->meta())
        );
    }

    /**
     * The list criteria, in the vocabulary a REST client uses.
     *
     * The mapper reads the page number from `paged`, which is WordPress's own
     * key for an admin list table and the right name on a wp-admin screen. An
     * API client has no reason to know that: `?page=3` is the conventional
     * spelling everywhere else, and the mapper would ignore it and silently
     * serve page 1 - a 200 carrying the wrong rows, which is the failure mode
     * that never gets reported.
     *
     * So the translation happens here, at the boundary that owns the query
     * vocabulary, and `paged` still wins when both are sent: it is the more
     * specific of the two, and a client that sends both has said which it means
     * in the more deliberate spelling.
     *
     * @return array<string, mixed>
     */
    private function listCriteria(Request $request): array
    {
        $criteria = $request->all();

        if (! array_key_exists('paged', $criteria) && array_key_exists('page', $criteria)) {
            $criteria['paged'] = $criteria['page'];
        }

        return $criteria;
    }

    /**
     * Returns one company's 360-degree profile.
     */
    public function show(int $id): JsonResponse
    {
        $result = $this->companyQueryService->getCompanyDetails($id);

        if ($result->isFailure()) {
            return $this->failure($result);
        }

        /** @var CompanyDetailResponseDTO $company */
        $company = $result->getData();

        return new JsonResponse(CompanyResource::make($company));
    }

    /**
     * Creates a company.
     */
    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $result = $this->companyCommandService->createCompany(
            $this->mapper->mapToStoreDTO($request->validated())
        );

        if ($result->isFailure()) {
            return $this->failure($result);
        }

        /** @var CompanyResponseDTO $company */
        $company = $result->getData();

        return new JsonResponse(
            CompanyResource::make($company),
            ['status_code' => 201, 'headers' => ['Location' => $this->locationFor($company->id)]]
        );
    }

    /**
     * Updates a company.
     *
     * The record is addressed by the route's `{id}` placeholder, which the
     * adapter pins ahead of any body parameter of the same name - so a payload
     * carrying `{"id": 999}` cannot redirect the write to another company.
     */
    public function update(UpdateCompanyRequest $request, int $id): JsonResponse
    {
        $result = $this->companyCommandService->updateCompany(
            $this->mapper->mapToUpdateDTO(
                companyId: $id,
                validatedData: $request->validated(),
            )
        );

        if ($result->isFailure()) {
            return $this->failure($result);
        }

        /** @var CompanyResponseDTO $company */
        $company = $result->getData();

        return new JsonResponse(CompanyResource::make($company));
    }

    /**
     * Deletes one company.
     */
    public function destroy(int $id): JsonResponse
    {
        $result = $this->companyCommandService->deleteCompany($id);

        if ($result->isFailure()) {
            return $this->failure($result);
        }

        return new JsonResponse(null, ['status_code' => 204]);
    }

    /**
     * Deletes several companies as one unit of work.
     *
     * One Service call for the whole set: the batch runs inside a single
     * transaction, so a failure deletes none of them. Looping here would commit
     * each deletion on its own and leave a half-applied selection behind.
     */
    public function bulkDestroy(BulkDestroyCompanyRequest $request): JsonResponse
    {
        $result = $this->companyCommandService->deleteMultiple($this->intIds($request->validated()['ids']));

        if ($result->isFailure()) {
            return $this->failure($result);
        }

        return new JsonResponse(null, ['status_code' => 204]);
    }

    /**
     * The submitted ids as integers.
     *
     * The `integer` rule validates a value without retyping it - deliberately,
     * so a rule never silently changes what it was given - which means a JSON
     * body of `{"ids": ["3"]}` passes validation and arrives here as strings.
     * The write port and its adapter declare `int` under `strict_types`, so an
     * unconverted string is a `TypeError` several frames down: a 500 for a
     * request that was perfectly well formed. The cast belongs at the boundary
     * that received the string.
     *
     * @param array<int, mixed> $ids
     *
     * @return list<int>
     */
    private function intIds(array $ids): array
    {
        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    /**
     * Projects a failed `Result` onto a JSON error response.
     *
     * The `errorCode()` the Service chose is the only thing classified here,
     * because it is the only stable fact a failure carries: matching on message
     * text would break the first time a wording changed, and every client would
     * carry a copy of the same guess. Anything unrecognised is reported as a
     * 422 rather than a 500 - the Service reaches its generic fallback for a
     * rejected payload as often as for a fault, and a 500 would tell the client
     * to retry something only it can fix.
     *
     * The message is passed through: the Service resolves its own wording from
     * the translator, so a client sees the same sentence the admin screen shows.
     */
    private function failure(Result $result): JsonResponse
    {
        return new JsonResponse([
            'error'   => $result->errorCode() ?? Result::DEFAULT_GENERIC_CODE,
            'message' => $result->message(),
        ], ['status_code' => $this->statusFor($result->errorCode())]);
    }

    /**
     * The HTTP status a domain error code means.
     */
    private function statusFor(?string $errorCode): int
    {
        return match ($errorCode) {
            'COMPANY_NOT_FOUND'     => 404,
            'COMPANY_ALREADY_EXISTS' => 409,
            default                 => 422,
        };
    }

    /**
     * The address of a newly created company.
     *
     * `route()` cannot carry the id for this surface, and the reason is worth
     * stating because the obvious spelling looks correct: the generator
     * dispatches on a route's domain, and the REST domain compiles a path by
     * substituting placeholders - which `companies` has none of. The `Location`
     * header therefore names the created record after the fact, where the id is
     * finally known.
     */
    private function locationFor(int $companyId): string
    {
        return route('api.companies.show', ['id' => $companyId]);
    }
}
