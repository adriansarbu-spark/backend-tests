<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/tests_config.php';
require_once PUBLIC_API . 'esign/author/documents.php';

if (!class_exists(TestableControllerPublicAPIV1EsignAuthorDocuments::class)) {
    class TestableControllerPublicAPIV1EsignAuthorDocuments extends ControllerPublicAPIV1EsignAuthorDocuments
    {
        public function createDocument(): void
        {
            author_docs_invoke_private($this, 'createDocument');
        }

        public function saveDocument(string $uuid): void
        {
            author_docs_invoke_private($this, 'saveDocument', $uuid);
        }

        public function createCampaign(string $uuid): void
        {
            author_docs_invoke_private($this, 'createCampaign', $uuid);
        }

        public function uploadCampaignData(string $uuid, string $campaign_uuid): void
        {
            author_docs_invoke_private($this, 'uploadCampaignData', $uuid, $campaign_uuid);
        }

        public function sendDocument(string $uuid): void
        {
            author_docs_invoke_private($this, 'sendDocument', $uuid);
        }
    }
}

/**
 * @param mixed ...$args
 */
function author_docs_invoke_private(object $controller, string $method, ...$args): void
{
    $ref = new ReflectionMethod(ControllerPublicAPIV1EsignAuthorDocuments::class, $method);
    $ref->setAccessible(true);
    $ref->invoke($controller, ...$args);
}

final class AuthorDocumentsDbStub
{
    /** @var list<string> */
    public array $queries = [];

    public function query(string $sql)
    {
        $this->queries[] = $sql;

        return new stdClass();
    }
}

final class AuthorDocumentsConfigStub
{
    public function __construct(
        private readonly string $pdfBaseUrl = 'https://pdf.example.test',
        private readonly int $languageId = 1,
    ) {
    }

    public function load(string $key): void
    {
    }

    public function get(string $key): mixed
    {
        return match ($key) {
            'esign_pdf_service_base_url' => $this->pdfBaseUrl,
            'esign_pdf_service_render_path' => '/render',
            'config_language_id' => $this->languageId,
            default => null,
        };
    }
}

final class AuthorDocumentsCustomerStub
{
    public function __construct(
        private readonly string $companyName = 'Acme Co',
        private readonly string $logoCode = '',
    ) {
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function getCompanyLogoCode(): string
    {
        return $this->logoCode;
    }
}

final class AuthorDocumentModelStub
{
    /** @var array<string, mixed>|null */
    public ?array $documentByUuid = null;

    public int $createDocumentId = 42;

    /** @var list<array<string, mixed>> */
    public array $parties = [];

    /** @var list<array<string, mixed>> */
    public array $fields = [];

    public function getDocumentByUuid(string $uuid): ?array
    {
        return $this->documentByUuid;
    }

    public function createDocument(array $data): int
    {
        return $this->createDocumentId;
    }

    public function getParties(int $doc_id): array
    {
        return $this->parties;
    }

    public function getFields(int $doc_id): array
    {
        return $this->fields;
    }

    public function replaceParties(int $doc_id, array $parties): void
    {
    }

    public function replaceFields(int $doc_id, array $fields): void
    {
    }

    public function updateDocument(string $uuid, array $update): void
    {
    }

    public function getDocumentByIdForUpdate(int $doc_id): ?array
    {
        if ($this->documentByUuid === null) {
            return null;
        }

        return $this->documentByUuid;
    }
}

final class AuthorCampaignModelStub
{
    /** @var array<string, mixed>|null */
    public ?array $campaignByUuid = null;

    /** @var array<string, mixed>|null */
    public ?array $campaignByDocumentUuid = null;

    public int $recipientCount = 0;

    /** @var array{success: bool, total_recipients?: int, error?: string} */
    public array $uploadResult = ['success' => true, 'total_recipients' => 1];

    /** @var list<array<string, mixed>> */
    public array $partyValues = [];

    public function createCampaign(int $doc_id, string $campaign_uuid): void
    {
    }

    public function getCampaignByUuid(string $campaign_uuid): ?array
    {
        return $this->campaignByUuid;
    }

    public function getCampaignByDocumentUuid(string $uuid, string $status = 'draft'): ?array
    {
        return $this->campaignByDocumentUuid;
    }

    public function getCampaignRecipientCount(int $campaign_id): int
    {
        return $this->recipientCount;
    }

    public function uploadCampaignData(string $campaign_uuid, string $doc_uuid, array $party_values, array $field_values): array
    {
        return $this->uploadResult;
    }

    public function markCampaignSending(int $campaign_id): void
    {
    }

    public function getCampaignPartyValues(int $campaign_id): array
    {
        return $this->partyValues;
    }

    public function getAllCampaignPartyValues(int $campaign_id): array
    {
        return $this->partyValues;
    }

    public function getFieldValuesForPartyValue(int $party_value_id): array
    {
        return [];
    }

    public function updatePartyValueSent(int $party_value_id, int $signing_doc_id): void
    {
    }
}

/**
 * Build a controller with document/campaign stubs for unit tests.
 *
 * @param array<string, mixed> $jsonInput
 */
function author_docs_controller(
    AuthorDocumentModelStub $documentModel,
    AuthorCampaignModelStub $campaignModel,
    array $jsonInput = [],
    ?AuthorDocumentsConfigStub $config = null,
    int $companyId = 10,
): TestableControllerPublicAPIV1EsignAuthorDocuments {
    $registry = new Registry();
    $registry->set('load', new class {
        public function model(string $route): void
        {
        }
    });
    $registry->set('config', $config ?? new AuthorDocumentsConfigStub());
    $registry->set('db', new AuthorDocumentsDbStub());

    $controller = new TestableControllerPublicAPIV1EsignAuthorDocuments($registry);
    $controller->backend_variables = [
        'company_id' => $companyId,
        'customer_role_id' => 5,
        'customer_id' => 1,
    ];
    $controller->customer = new AuthorDocumentsCustomerStub();
    $controller->json = [];
    $controller->model_esign_author_document = $documentModel;
    $controller->model_esign_author_campaign = $campaignModel;
    $controller->apiRequest = new class($jsonInput) {
        public object $request;

        public function __construct(private readonly array $payload)
        {
            $this->request = new class($payload) {
                public function __construct(private readonly array $payload)
                {
                }

                public function all(): array
                {
                    return $this->payload;
                }
            };
        }

        public function getContent(): string
        {
            return '';
        }
    };

    return $controller;
}
